<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fails when a page shows an unresolved translation key or raw snake_case text (#453).
 */
class RenderedRawKeyGuardTest extends TestCase
{
    use RefreshDatabase;

    private const LANG_KEY = '/^[a-z0-9_-]+(?:::[a-z0-9_.-]+|(?:\.[a-z0-9_-]+)+)$/';

    private const SNAKE_CASE = '/\b[a-z][a-z0-9]*_[a-z0-9_]+\b/';

    /** Append-only release notes quote code identifiers, so only translation keys are checked here. */
    private const HISTORY_PATHS = ['/change-log'];

    /** @var list<string> */
    private array $missingKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();

        app('translator')->handleMissingKeysUsing(function (string $key): string {
            if (preg_match(self::LANG_KEY, $key) === 1) {
                $this->missingKeys[] = $key;
            }

            return $key;
        });
    }

    protected function tearDown(): void
    {
        app('translator')->handleMissingKeysUsing(null);

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: ?string, 1: list<string>}>
     */
    public static function personaPages(): array
    {
        return [
            'guest' => [null, ['/', '/login', '/register', '/staff/login', '/forgot-password', '/help', '/privacy', '/cookies', '/terms', '/change-log', '/recruitment']],
            'public user' => ['public', ['/dashboard', '/profile', '/apes-cic', '/apes-cic/tickets', '/apes-cic/cases', '/shelter', '/shelter/tickets', '/shelter/cases', '/shelter/pets', '/petcare', '/petcare/tickets', '/petcare/pets', '/petcare/consultations', '/recruitment', '/recruitment/applications', '/help']],
            'staff' => ['staff', ['/dashboard', '/profile', '/apes-cic', '/apes-cic/tickets', '/apes-cic/cases', '/shelter', '/shelter/tickets', '/shelter/cases', '/shelter/pets', '/petcare', '/petcare/tickets', '/petcare/pets', '/petcare/consultations']],
            'super admin' => ['super-admin', ['/admin', '/admin/access', '/admin/emails', '/admin/emails/log', '/admin/maintenance', '/admin/modules', '/admin/organisation-modules', '/admin/permissions', '/admin/roles', '/admin/groups', '/admin/users', '/admin/users?account_type=staff', '/change-log']],
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    #[DataProvider('personaPages')]
    public function test_pages_never_render_raw_keys_or_snake_case_text(?string $persona, array $paths): void
    {
        if ($persona !== null) {
            $user = $persona === 'public'
                ? User::factory()->create()
                : User::factory()->protectedRole($persona)->create();
            $this->actingAs($user);
        }

        $problems = [];
        foreach ($paths as $path) {
            $this->missingKeys = [];
            $response = $this->followingRedirects()->get($path);

            $this->assertSame(200, $response->getStatusCode(), "{$path} did not render for ".($persona ?? 'guest').'.');

            foreach (array_unique($this->missingKeys) as $key) {
                $problems[] = "{$path}: missing translation {$key}";
            }

            if (in_array($path, self::HISTORY_PATHS, true)) {
                continue;
            }

            foreach ($this->rawTextIn((string) $response->getContent()) as $raw) {
                $problems[] = "{$path}: raw text \"{$raw}\"";
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_the_guard_catches_a_raw_key_and_snake_case_text(): void
    {
        $this->missingKeys = [];
        __('public.this_key_does_not_exist');

        $this->assertSame(['public.this_key_does_not_exist'], $this->missingKeys);
        $this->assertSame(['animal_welfare'], $this->rawTextIn('<p>Area: animal_welfare</p><script>var a_b = 1;</script><code>verify_email</code>'));
        $this->assertSame([], $this->rawTextIn('<a href="/x_y" data-k="a_b">Animal Welfare</a>'));
    }

    /**
     * @return list<string>
     */
    private function rawTextIn(string $html): array
    {
        $visible = preg_replace('#<(script|style|template|textarea|code)\b[^>]*>.*?</\1>#si', ' ', $html) ?? '';
        $visible = html_entity_decode(strip_tags($visible), ENT_QUOTES | ENT_HTML5);

        preg_match_all(self::SNAKE_CASE, $visible, $matches);

        return array_values(array_unique($matches[0]));
    }
}
