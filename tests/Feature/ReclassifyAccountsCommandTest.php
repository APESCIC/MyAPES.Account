<?php

namespace Tests\Feature;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReclassifyAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_misclassified_accounts_and_changes_nothing(): void
    {
        [$resync, $unmapped, $localInGroup] = $this->accounts();

        $this->artisan('myapes:accounts:reclassify', ['--dry-run' => true])
            ->expectsTable(
                ['ID', 'Email', 'Identity', 'Finding', 'Action'],
                [
                    [$resync->id, $resync->email, User::IDENTITY_CLOUDRON_OIDC, 'Cloudron account with no role', 'Resync to staff'],
                    [$unmapped->id, $unmapped->email, User::IDENTITY_CLOUDRON_OIDC, 'Cloudron account with no role', 'Unmapped'],
                    [$localInGroup->id, $localInGroup->email, User::IDENTITY_LOCAL, 'Public account in a mapped Cloudron group', 'Review'],
                ],
            )
            ->expectsOutputToContain('Dry run: 1 account(s) would be resynced')
            ->assertSuccessful();

        $this->assertTrue(app(AccountClassifier::class)->isUnmapped($resync->fresh()));
        $this->assertSame(0, AuditLog::query()->where('event', 'accounts.reclassified')->count());
    }

    public function test_apply_resyncs_directory_accounts_audits_and_never_deletes(): void
    {
        [$resync, $unmapped, $localInGroup] = $this->accounts();
        $users = User::query()->count();

        $this->artisan('myapes:accounts:reclassify', ['--force' => true])
            ->expectsOutputToContain('Resynced 1 account(s)')
            ->assertSuccessful();

        $classifier = app(AccountClassifier::class);
        $this->assertSame(AccountClassifier::STAFF, $classifier->classify($resync->fresh()));
        $this->assertFalse($classifier->isUnmapped($resync->fresh()));
        $this->assertTrue($classifier->isUnmapped($unmapped->fresh()));
        $this->assertSame(AccountClassifier::PUBLIC, $classifier->classify($localInGroup->fresh()));

        $this->assertSame($users, User::query()->count());
        $this->assertNull($resync->fresh()->suspended_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'accounts.reclassified',
            'auditable_id' => $resync->id,
        ]);
        $this->assertSame(1, AuditLog::query()->where('event', 'accounts.reclassified')->count());
    }

    public function test_apply_needs_confirmation(): void
    {
        [$resync] = $this->accounts();

        $this->artisan('myapes:accounts:reclassify')
            ->expectsConfirmation('Resync 1 account(s) from their Cloudron groups?', 'no')
            ->expectsOutputToContain('Cancelled. Nothing was changed.')
            ->assertFailed();

        $this->assertTrue(app(AccountClassifier::class)->isUnmapped($resync->fresh()));
        $this->assertSame(0, AuditLog::query()->where('event', 'accounts.reclassified')->count());
    }

    public function test_it_reports_when_nothing_is_misclassified(): void
    {
        User::factory()->create();
        User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->artisan('myapes:accounts:reclassify', ['--dry-run' => true])
            ->expectsOutputToContain('No misclassified accounts found.')
            ->assertSuccessful();
    }

    /**
     * @return array{0: User, 1: User, 2: User}
     */
    private function accounts(): array
    {
        User::factory()->create(['name' => 'Plain Public']);

        return [
            User::factory()->directoryIdentity('reclassify-resync')->create(['ldap_groups' => ['myapesaccount.staff']]),
            User::factory()->directoryIdentity('reclassify-unmapped')->create(),
            User::factory()->create(['ldap_groups' => ['myapesaccount.staff']]),
        ];
    }
}
