<?php

namespace Tests\Feature\Accounts;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\Role;
use App\Core\Accounts\RoleSource;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\AuthorizationRoleMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountClassifierTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: ?string, 2: string, 3: bool}>
     */
    public static function accounts(): array
    {
        return [
            'local public' => [User::IDENTITY_LOCAL, null, AccountClassifier::PUBLIC, false],
            'local student' => [User::IDENTITY_LOCAL, AuthorizationProfile::ROLE_STUDENT, AccountClassifier::STUDENT, false],
            'local volunteer' => [User::IDENTITY_LOCAL, AuthorizationProfile::ROLE_VOLUNTEER, AccountClassifier::VOLUNTEER, false],
            'local staff' => [User::IDENTITY_LOCAL, AuthorizationProfile::ROLE_STAFF, AccountClassifier::STAFF, false],
            'local admin' => [User::IDENTITY_LOCAL, AuthorizationProfile::ROLE_ADMINISTRATOR, AccountClassifier::ADMIN, false],
            'cloudron with no role' => [User::IDENTITY_CLOUDRON_OIDC, null, AccountClassifier::STAFF, true],
            'cloudron volunteer' => [User::IDENTITY_CLOUDRON_OIDC, AuthorizationProfile::ROLE_VOLUNTEER, AccountClassifier::VOLUNTEER, false],
            'cloudron admin' => [User::IDENTITY_CLOUDRON_OIDC, AuthorizationProfile::ROLE_ADMINISTRATOR, AccountClassifier::ADMIN, false],
            'hybrid with no role' => [User::IDENTITY_HYBRID, null, AccountClassifier::STAFF, true],
            'hybrid student' => [User::IDENTITY_HYBRID, AuthorizationProfile::ROLE_STUDENT, AccountClassifier::STUDENT, false],
        ];
    }

    #[DataProvider('accounts')]
    public function test_it_classifies_every_identity_and_role(string $identity, ?string $role, string $expected, bool $unmapped): void
    {
        $user = $this->account($identity, $role);
        $classifier = app(AccountClassifier::class);

        $this->assertSame($expected, $classifier->classify($user));
        $this->assertSame($expected !== AccountClassifier::PUBLIC, $classifier->isStaffSide($user));
        $this->assertSame($unmapped, $classifier->isUnmapped($user));
        $this->assertSame($role !== null, $classifier->hasStaffSideRole($user));
        $this->assertSame($expected, $classifier->classify($user->load('roles')));
    }

    public function test_an_account_with_two_roles_takes_the_highest(): void
    {
        $user = $this->account(User::IDENTITY_LOCAL, AuthorizationProfile::ROLE_VOLUNTEER);
        app(AuthorizationRoleMaterializer::class)->grant(
            $user,
            Role::query()->where('guard_name', 'web')->where('name', AuthorizationProfile::ROLE_STAFF)->firstOrFail(),
            RoleSource::SOURCE_SYSTEM,
        );

        $this->assertSame(AccountClassifier::STAFF, app(AccountClassifier::class)->classify($user->fresh()));
    }

    public function test_pending_first_login_cloudron_account_is_unmapped_staff(): void
    {
        $user = User::factory()->create([
            'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
            'oidc_sub' => null,
        ]);
        $classifier = app(AccountClassifier::class);

        $this->assertSame(AccountClassifier::STAFF, $classifier->classify($user));
        $this->assertTrue($classifier->isUnmapped($user));
    }

    public function test_query_scopes_match_classify_for_every_account(): void
    {
        foreach (self::accounts() as [$identity, $role]) {
            $this->account($identity, $role);
        }
        $classifier = app(AccountClassifier::class);
        $users = User::query()->with('roles')->get();

        $expect = static fn (callable $keep): array => $users->filter($keep)->pluck('id')->sort()->values()->all();
        $actual = static fn (callable $scope): array => $scope(User::query())->pluck('id')->sort()->values()->all();

        $this->assertSame(
            $expect(fn (User $user): bool => ! $classifier->isStaffSide($user)),
            $actual(fn ($query) => $classifier->scopePublic($query)),
        );
        $this->assertSame(
            $expect(fn (User $user): bool => $classifier->isStaffSide($user)),
            $actual(fn ($query) => $classifier->scopeStaffSide($query)),
        );
        $this->assertSame(
            $expect(fn (User $user): bool => $classifier->isUnmapped($user)),
            $actual(fn ($query) => $classifier->scopeUnmapped($query)),
        );

        foreach ([AccountClassifier::PUBLIC, ...AccountClassifier::STAFF_SIDE_TYPES] as $type) {
            $this->assertSame(
                $expect(fn (User $user): bool => $classifier->classify($user) === $type),
                $actual(fn ($query) => $classifier->scopeType($query, $type)),
                "Scope for [{$type}] differs from classify().",
            );
        }
    }

    private function account(string $identity, ?string $role): User
    {
        $factory = User::factory()->state([
            'identity_type' => $identity,
            'oidc_sub' => $identity === User::IDENTITY_LOCAL ? null : 'sub-'.fake()->uuid(),
        ]);

        return ($role === null ? $factory : $factory->protectedRole($role))->create();
    }
}
