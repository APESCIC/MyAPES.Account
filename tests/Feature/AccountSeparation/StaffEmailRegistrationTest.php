<?php

namespace Tests\Feature\AccountSeparation;

use App\Contracts\OidcIdentityProvider;
use App\Core\Accounts\User;
use App\Core\Auth\OidcIdentity;
use App\Rules\NotStaffEmail;
use App\Services\AuthorizationProfile;
use App\Services\LdapGroupResolver;
use App\Services\LdapUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeLdapGroupResolver;
use Tests\Fakes\FakeLdapUserResolver;
use Tests\Fakes\FakeOidcIdentityProvider;
use Tests\TestCase;

class StaffEmailRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Correct-horse-42-separation!';

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function domainCases(): array
    {
        return [
            'staff domain' => ['jo.bloggs@apes.org.uk', true],
            'staff subdomain' => ['jo.bloggs@mail.apes.org.uk', true],
            'upper case' => ['Jo.Bloggs@APES.ORG.UK', true],
            'lookalike domain' => ['jo.bloggs@notapes.org.uk', false],
            'personal email' => ['jo.bloggs@example.com', false],
        ];
    }

    #[DataProvider('domainCases')]
    public function test_staff_domain_matching(string $email, bool $isStaff): void
    {
        $this->assertSame($isStaff, NotStaffEmail::isStaffDomain($email));
    }

    public function test_staff_domains_are_configurable(): void
    {
        config(['myapes.accounts.staff_email_domains' => ['apes-shelter.example']]);

        $this->assertTrue(NotStaffEmail::isStaffDomain('jo@apes-shelter.example'));
        $this->assertFalse(NotStaffEmail::isStaffDomain('jo@apes.org.uk'));
    }

    public function test_registration_rejects_a_staff_domain_and_asks_for_a_personal_email(): void
    {
        Notification::fake();
        $this->stubUncompromisedPasswords();

        $this->from(route('public.register'))
            ->post(route('public.register.submit'), $this->registerPayload('jo.bloggs@apes.org.uk'))
            ->assertRedirect(route('public.register'))
            ->assertSessionHasErrors(['email' => __('auth.register.staff_email_domain')]);

        $this->assertDatabaseMissing('users', ['email' => 'jo.bloggs@apes.org.uk']);
    }

    public function test_registration_rejects_an_email_already_held_by_a_staff_account(): void
    {
        Notification::fake();
        $this->stubUncompromisedPasswords();
        User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->cloudronIdentity('staff-held-email')
            ->create(['email' => 'staff.personal@example.com']);

        $this->from(route('public.register'))
            ->post(route('public.register.submit'), $this->registerPayload('staff.personal@example.com'))
            ->assertRedirect(route('public.register'))
            ->assertSessionHasErrors(['email' => __('auth.register.email_unavailable')]);

        $this->assertSame(1, User::query()->where('email', 'staff.personal@example.com')->count());
    }

    public function test_registration_with_a_personal_email_creates_a_local_account(): void
    {
        Notification::fake();
        $this->stubUncompromisedPasswords();

        $this->post(route('public.register.submit'), $this->registerPayload('jo.bloggs@example.com'))
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'jo.bloggs@example.com')->sole();
        $this->assertSame(User::IDENTITY_LOCAL, $user->identity_type);
        $this->assertNull($user->oidc_sub);
    }

    public function test_public_email_change_rejects_a_staff_domain(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'public.person@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->unix()])
            ->from(route('profile.edit'))
            ->post(route('profile.email.change'), ['email' => 'public.person@apes.org.uk'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors(['email' => __('auth.register.staff_email_domain')]);

        $this->assertDatabaseMissing('pending_email_changes', ['user_id' => $user->id]);
    }

    public function test_oidc_never_turns_a_local_public_account_into_a_staff_account(): void
    {
        $identityProvider = new FakeOidcIdentityProvider;
        $groups = new FakeLdapGroupResolver;
        $directoryUsers = new FakeLdapUserResolver;
        $groups->groups = ['myapesaccount.staff'];
        $directoryUsers->groups = ['myapesaccount.staff'];
        $this->app->instance(OidcIdentityProvider::class, $identityProvider);
        $this->app->instance(LdapGroupResolver::class, $groups);
        $this->app->instance(LdapUserResolver::class, $directoryUsers);

        $public = User::factory()->create([
            'email' => 'public.person@example.com',
            'oidc_sub' => 'leaked-subject',
        ]);
        $identityProvider->identity = new OidcIdentity(
            'leaked-subject',
            'staff.person@example.com',
            'Staff Person',
        );

        $this->get(route('staff.auth.callback'))->assertForbidden();

        $this->assertGuest();
        $public->refresh();
        $this->assertSame(User::IDENTITY_LOCAL, $public->identity_type);
        $this->assertSame('public.person@example.com', $public->email);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'auth.oidc_local_account_denied',
            'user_id' => $public->id,
        ]);
    }

    public function test_oidc_creates_staff_accounts_as_cloudron_identities_only(): void
    {
        $identityProvider = new FakeOidcIdentityProvider;
        $groups = new FakeLdapGroupResolver;
        $directoryUsers = new FakeLdapUserResolver;
        $groups->groups = ['myapesaccount.staff'];
        $directoryUsers->groups = ['myapesaccount.staff'];
        $this->app->instance(OidcIdentityProvider::class, $identityProvider);
        $this->app->instance(LdapGroupResolver::class, $groups);
        $this->app->instance(LdapUserResolver::class, $directoryUsers);
        User::factory()->create(['email' => 'shared@example.com']);
        $identityProvider->identity = new OidcIdentity('new-staff', 'shared@example.com', 'Shared Email');

        $this->get(route('staff.auth.callback'))->assertForbidden();

        $this->assertSame(1, User::query()->where('email', 'shared@example.com')->count());
        $this->assertSame(
            User::IDENTITY_LOCAL,
            User::query()->where('email', 'shared@example.com')->value('identity_type'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function registerPayload(string $email): array
    {
        return [
            'name' => 'Jo Bloggs',
            'username' => 'jo.bloggs.'.substr(md5($email), 0, 6),
            'email' => $email,
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'services' => ['apes-cic'],
            'registration_consent' => '1',
        ];
    }
}
