<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Support\SeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicChromeNamingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function platformChromePages(): array
    {
        return [
            'home' => ['/', 'Welcome | MyAPES Account'],
            'privacy' => ['/privacy', 'Privacy notice | MyAPES Account'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function accountSurfacePages(): array
    {
        return [
            'public login' => ['/login', 'Public Login | MyAPES Account'],
            'register' => ['/register', 'Register | MyAPES Account'],
            'staff login' => ['/staff/login', 'Staff Login | MyAPES Account'],
            'forgot password' => ['/forgot-password', 'Forgot password | MyAPES Account'],
            'reset password' => ['/reset-password/test-token', 'Reset password | MyAPES Account'],
        ];
    }

    #[DataProvider('platformChromePages')]
    public function test_guest_platform_pages_use_account_titles_and_chrome(string $path, string $title): void
    {
        $response = $this->get($path);

        $response->assertOk();
        $this->assertSeesAccountChrome($response);
        $response->assertSee('<title>'.$title.'</title>', false);
        $response->assertSeeText('MyAPES Account');
    }

    #[DataProvider('accountSurfacePages')]
    public function test_login_and_register_pages_keep_account_titles_with_account_chrome(string $path, string $title): void
    {
        $response = $this->get($path);

        $response->assertOk();
        $this->assertSeesAccountChrome($response);
        $response->assertSee('<title>'.$title.'</title>', false);
        $response->assertSeeText('MyAPES Account');
    }

    public function test_pwa_manifest_names_the_product_myapes_account(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(public_path('site.webmanifest')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('MyAPES Account', $manifest['name']);
        $this->assertSame('MyAPES', $manifest['short_name']);
        $this->assertSame('MyAPES Account web application', $manifest['description']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('site.webmanifest', false);
    }

    public function test_titles_without_a_product_suffix_get_one(): void
    {
        $this->assertSame('Ticket #12 | MyAPES Account', SeoMeta::withProductSuffix('Ticket #12'));
        $this->assertSame('Help | MyAPES Account', SeoMeta::withProductSuffix('Help | MyAPES Account'));
        $this->assertSame('MyAPES Account', SeoMeta::withProductSuffix('MyAPES Account'));
    }

    public function test_retired_product_name_is_absent_from_views_and_lang(): void
    {
        $roots = [resource_path('views'), lang_path(), base_path('plugins'), base_path('modules'), public_path('site.webmanifest')];
        $offenders = [];

        foreach ($roots as $root) {
            $files = is_file($root)
                ? [new \SplFileInfo($root)]
                : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! $file->isFile() || ! preg_match('/\.(php|json|webmanifest)$/', $file->getFilename())) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                if (preg_match('#MyAPES(?:</strong>)?\s+Core#i', $contents) === 1) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'Use "MyAPES Account"; "MyAPES Core" was retired in 0.45.0.');
    }

    public function test_signed_in_onboarding_uses_account_title_and_meta(): void
    {
        $user = User::factory()->create([
            'onboarding_completed_at' => null,
        ]);

        $response = $this->actingAs($user)->get(route('onboarding.edit'));

        $response->assertOk();
        $this->assertSeesAccountChrome($response);
        $response->assertSee('<title>Complete account setup | MyAPES Account</title>', false);
        $response->assertSee('name="robots" content="noindex, nofollow"', false);
    }

    public function test_email_verification_keeps_account_title_with_account_chrome(): void
    {
        $user = User::factory()->unverified()->create([
            'onboarding_completed_at' => null,
        ]);

        $response = $this->actingAs($user)->get(route('verification.notice'));

        $response->assertOk();
        $this->assertSeesAccountChrome($response);
        $response->assertSee('<title>Verify email | MyAPES Account</title>', false);
        $response->assertSeeText('MyAPES Account');
        $response->assertSee('name="robots" content="noindex, nofollow"', false);
    }

    private function assertSeesAccountChrome(TestResponse $response): void
    {
        $response
            ->assertSee('property="og:site_name" content="MyAPES Account"', false)
            ->assertSee('name="application-name" content="MyAPES Account"', false)
            ->assertSee('name="apple-mobile-web-app-title" content="MyAPES Account"', false)
            ->assertSee('<span class="sg-brand__name">MyAPES Account</span>', false)
            ->assertSee('<span><strong>MyAPES</strong> Account</span>', false)
            ->assertSee('rel="manifest"', false)
            ->assertSee('site.webmanifest', false)
            ->assertSee('lang="en-GB"', false);
    }
}
