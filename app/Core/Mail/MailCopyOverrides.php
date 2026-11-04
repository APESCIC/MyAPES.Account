<?php

namespace App\Core\Mail;

use App\Core\Accounts\User;
use App\Core\Content\RichText;
use App\Services\AuditLogger;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies audited subject and intro overrides on top of the shipped lang lines (#361).
 *
 * Overrides are pushed into the translator so every `__()` call (notifications and
 * Blade views alike) sees them. Re-applying restores shipped lines for overrides that
 * were reset, so long-running queue workers stay current.
 */
final class MailCopyOverrides
{
    private const CACHE_KEY = 'mail-copy-overrides';

    /** @var array<string, array{locale: string, key: string}> */
    private array $applied = [];

    public function __construct(
        private readonly MailTemplateRegistry $registry,
        private readonly Translator $translator,
        private readonly Cache $cache,
        private readonly AuditLogger $audit,
    ) {}

    public function apply(): void
    {
        $wanted = [];

        foreach ($this->rows() as $row) {
            $template = $this->registry->find($row['template_key']);

            if ($template === null) {
                continue;
            }

            foreach (['subject' => $template->subjectKey, 'intro' => $template->introKey] as $field => $langKey) {
                if ($langKey !== null && is_string($row[$field]) && $row[$field] !== '') {
                    $wanted[$row['locale'].'|'.$langKey] = ['locale' => $row['locale'], 'key' => $langKey, 'text' => $row[$field]];
                }
            }
        }

        foreach ($this->applied as $id => $entry) {
            if (! isset($wanted[$id])) {
                $this->setLine($entry['locale'], $entry['key'], $this->shipped($entry['key'], $entry['locale']));
            }
        }

        $this->applied = [];

        foreach ($wanted as $id => $entry) {
            $this->setLine($entry['locale'], $entry['key'], $entry['text']);
            $this->applied[$id] = ['locale' => $entry['locale'], 'key' => $entry['key']];
        }
    }

    public function current(MailTemplate $template, string $locale): ?MailTemplateOverride
    {
        return MailTemplateOverride::query()
            ->where('template_key', $template->key)
            ->where('locale', $locale)
            ->first();
    }

    /**
     * The lang line as shipped in the repository, ignoring any override.
     */
    public function shipped(string $langKey, string $locale): string
    {
        [$namespace, $group, $item] = $this->translator->parseKey($langKey);
        $lines = $this->translator->getLoader()->load($locale, $group, $namespace);
        $line = Arr::get($lines, $item);

        if (! is_string($line) && $locale !== $this->translator->getFallback()) {
            return $this->shipped($langKey, $this->translator->getFallback());
        }

        return is_string($line) ? $line : $langKey;
    }

    /**
     * @throws ValidationException
     */
    public function save(MailTemplate $template, User $actor, string $locale, ?string $subject, ?string $intro, int $lockVersion, ?string $body = null): MailTemplateOverride
    {
        $subject = $this->clean($subject);
        $intro = $this->clean($intro);
        $body = $this->cleanBody($body);
        $this->assertBodyMergeFields($body, $template, $locale);

        $this->assertPlaceholders('subject', $subject, $template->subjectKey, $locale);
        $this->assertPlaceholders('intro', $intro, $template->introKey, $locale);

        $override = DB::transaction(function () use ($template, $actor, $locale, $subject, $intro, $lockVersion, $body): MailTemplateOverride {
            $existing = MailTemplateOverride::query()
                ->where('template_key', $template->key)
                ->where('locale', $locale)
                ->lockForUpdate()
                ->first();

            if (($existing?->lock_version ?? 0) !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => __('admin.emails.copy_conflict')]);
            }

            $before = ['subject' => $existing?->subject, 'intro' => $existing?->intro, 'body_length' => mb_strlen((string) $existing?->body)];
            $override = $existing ?? new MailTemplateOverride(['template_key' => $template->key, 'locale' => $locale]);
            $override->fill([
                'subject' => $template->subjectKey === null ? null : $subject,
                'intro' => $template->introKey === null ? null : $intro,
                'body' => $body,
                'lock_version' => $lockVersion + 1,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit->record('admin.emails.copy_updated', $actor, context: [
                'template' => $template->key,
                'locale' => $locale,
                'before' => $before,
                'after' => ['subject' => $override->subject, 'intro' => $override->intro, 'body_length' => mb_strlen((string) $override->body)],
            ]);

            return $override;
        });

        $this->forgetAndApply();

        return $override;
    }

    public function reset(MailTemplate $template, User $actor, string $locale): void
    {
        $existing = $this->current($template, $locale);

        if ($existing === null) {
            return;
        }

        $existing->delete();

        $this->audit->record('admin.emails.copy_reset', $actor, context: [
            'template' => $template->key,
            'locale' => $locale,
            'before' => ['subject' => $existing->subject, 'intro' => $existing->intro, 'body_length' => mb_strlen((string) $existing->body)],
        ]);

        $this->forgetAndApply();
    }

    public function bodyFor(MailTemplate $template, string $locale): ?string
    {
        foreach ($this->rows() as $row) {
            if ($row['template_key'] === $template->key && $row['locale'] === $locale && is_string($row['body'] ?? null) && $row['body'] !== '') {
                return $row['body'];
            }
        }

        return null;
    }

    /**
     * Merge fields an edited body may use (#486): recipient name, app name and
     * every placeholder in the template's shipped subject and intro.
     *
     * @return list<string>
     */
    public function mergeFields(MailTemplate $template, string $locale): array
    {
        $fields = ['name', 'app'];

        foreach ([$template->subjectKey, $template->introKey] as $langKey) {
            if ($langKey !== null) {
                $fields = [...$fields, ...$this->placeholders($this->shipped($langKey, $locale))];
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Swap a built message's content for the admin-edited body, keeping the
     * greeting, action button, subject and footer.
     */
    public function applyBody(MailMessage $message, MailTemplate $template, object $notifiable, string $locale): MailMessage
    {
        $body = $this->bodyFor($template, $locale);

        if ($body === null) {
            return $message;
        }

        $values = $this->mergeValues($message, $template, $notifiable);
        $names = $this->mergeFields($template, $locale);
        usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($names as $name) {
            $body = str_replace(':'.$name, e($values[$name] ?? ''), $body);
        }

        $data = $message->viewData;
        $data['customBody'] = new HtmlString(trim((string) preg_replace('/\s*\n\s*/', ' ', $body)));

        return $message->markdown('mail.brand.custom-body', $data);
    }

    public static function plainText(string $html): string
    {
        $text = (string) preg_replace(['/<br\s*\/?>/i', '/<\/(p|h[1-6]|li|tr|blockquote|pre|table)>/i', '/<li[^>]*>/i'], ["\n", "\n\n", '- '], $html);

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @return array<string, string>
     */
    private function mergeValues(MailMessage $message, MailTemplate $template, object $notifiable): array
    {
        $name = $notifiable->name ?? null;
        $values = [
            'name' => is_string($name) && trim($name) !== '' ? trim($name) : __('mail.brand.greeting_fallback'),
            'app' => __('terms.app_name'),
        ];

        if ($template->subjectKey !== null && is_string($message->subject)) {
            $values += $this->extract($this->translator->get($template->subjectKey), $message->subject);
        }

        if ($template->introKey !== null) {
            foreach ($message->introLines as $line) {
                $values += $this->extract($this->translator->get($template->introKey), (string) $line);
            }
        }

        return $values;
    }

    /**
     * Read placeholder values back out of a rendered line, e.g. ":id" from
     * "Ticket #:id was updated" against "Ticket #1024 was updated".
     *
     * @return array<string, string>
     */
    private function extract(mixed $pattern, string $rendered): array
    {
        if (! is_string($pattern) || $this->placeholders($pattern) === []) {
            return [];
        }

        $parts = preg_split('/:([A-Za-z_][A-Za-z0-9_]*)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $index => $part) {
            $regex .= $index % 2 === 0 ? preg_quote($part, '/') : '(?P<'.$part.'>.+?)';
        }

        if (@preg_match('/^(?J)'.$regex.'$/su', $rendered, $matches) !== 1) {
            return [];
        }

        return array_filter($matches, static fn (mixed $value, int|string $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH);
    }

    private function cleanBody(?string $body): ?string
    {
        $body = $this->clean($body);

        return $body === null ? null : $this->clean(app(RichText::class)->clean($body));
    }

    /**
     * @throws ValidationException
     */
    private function assertBodyMergeFields(?string $body, MailTemplate $template, string $locale): void
    {
        if ($body === null) {
            return;
        }

        $unknown = array_diff($this->placeholders(strip_tags($body)), $this->mergeFields($template, $locale));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => __('admin.emails.copy_unknown_placeholder', [
                'placeholders' => ':'.implode(', :', $unknown),
            ])]);
        }
    }

    /**
     * @return list<string>
     */
    public function placeholders(string $text): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    private function forgetAndApply(): void
    {
        $this->cache->forget(self::CACHE_KEY);
        $this->apply();
    }

    /**
     * Boot and build steps (package discovery, first migrate) run before the table or
     * cache exists, so an unavailable store means "no overrides" rather than a failure.
     *
     * @return list<array{template_key: string, locale: string, subject: string|null, intro: string|null, body?: string|null}>
     */
    private function rows(): array
    {
        try {
            return $this->cache->rememberForever(self::CACHE_KEY, static fn (): array => MailTemplateOverride::query()
                ->get(['template_key', 'locale', 'subject', 'intro', 'body'])
                ->map(static fn (MailTemplateOverride $row): array => $row->only(['template_key', 'locale', 'subject', 'intro', 'body']))
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    private function setLine(string $locale, string $langKey, string $text): void
    {
        [$namespace, $group, $item] = $this->translator->parseKey($langKey);
        $this->translator->load($namespace, $group, $locale);
        $this->translator->addLines([$group.'.'.$item => $text], $locale, $namespace);
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @throws ValidationException
     */
    private function assertPlaceholders(string $field, ?string $text, ?string $langKey, string $locale): void
    {
        if ($text === null || $langKey === null) {
            return;
        }

        if (preg_match('/[<>]/', $text) === 1) {
            throw ValidationException::withMessages([$field => __('admin.emails.copy_plain_text')]);
        }

        $unknown = array_diff($this->placeholders($text), $this->placeholders($this->shipped($langKey, $locale)));

        if ($unknown !== []) {
            throw ValidationException::withMessages([$field => __('admin.emails.copy_unknown_placeholder', [
                'placeholders' => ':'.implode(', :', $unknown),
            ])]);
        }
    }
}
