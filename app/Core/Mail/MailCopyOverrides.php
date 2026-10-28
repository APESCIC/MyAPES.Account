<?php

namespace App\Core\Mail;

use App\Core\Accounts\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
    public function save(MailTemplate $template, User $actor, string $locale, ?string $subject, ?string $intro, int $lockVersion): MailTemplateOverride
    {
        $subject = $this->clean($subject);
        $intro = $this->clean($intro);

        $this->assertPlaceholders('subject', $subject, $template->subjectKey, $locale);
        $this->assertPlaceholders('intro', $intro, $template->introKey, $locale);

        $override = DB::transaction(function () use ($template, $actor, $locale, $subject, $intro, $lockVersion): MailTemplateOverride {
            $existing = MailTemplateOverride::query()
                ->where('template_key', $template->key)
                ->where('locale', $locale)
                ->lockForUpdate()
                ->first();

            if (($existing?->lock_version ?? 0) !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => __('admin.emails.copy_conflict')]);
            }

            $before = ['subject' => $existing?->subject, 'intro' => $existing?->intro];
            $override = $existing ?? new MailTemplateOverride(['template_key' => $template->key, 'locale' => $locale]);
            $override->fill([
                'subject' => $template->subjectKey === null ? null : $subject,
                'intro' => $template->introKey === null ? null : $intro,
                'lock_version' => $lockVersion + 1,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit->record('admin.emails.copy_updated', $actor, context: [
                'template' => $template->key,
                'locale' => $locale,
                'before' => $before,
                'after' => ['subject' => $override->subject, 'intro' => $override->intro],
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
            'before' => ['subject' => $existing->subject, 'intro' => $existing->intro],
        ]);

        $this->forgetAndApply();
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
     * @return list<array{template_key: string, locale: string, subject: string|null, intro: string|null}>
     */
    private function rows(): array
    {
        try {
            return $this->cache->rememberForever(self::CACHE_KEY, static fn (): array => MailTemplateOverride::query()
                ->get(['template_key', 'locale', 'subject', 'intro'])
                ->map(static fn (MailTemplateOverride $row): array => $row->only(['template_key', 'locale', 'subject', 'intro']))
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
