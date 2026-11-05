<?php

namespace App\Console\Commands;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\User;
use App\Services\AuditLogger;
use App\Services\DirectoryRoleSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Reports, and with confirmation fixes, accounts that sit in the wrong People
 * list (#496).
 *
 * Only directory-linked accounts are changed, by re-running the directory sync
 * on their stored Cloudron groups, which is what their next sign-in would do.
 * Local accounts in a mapped Cloudron group are reported for review and never
 * granted a role here. No account is ever deleted or suspended.
 */
class ReclassifyAccounts extends Command
{
    public const ACTION_RESYNC = 'resync';

    public const ACTION_UNMAPPED = 'unmapped';

    public const ACTION_REVIEW = 'review';

    protected $signature = 'myapes:accounts:reclassify
        {--dry-run : List misclassified accounts without changing anything}
        {--force : Apply without asking for confirmation}';

    protected $description = 'Report and fix accounts listed under the wrong People list';

    public function handle(AccountClassifier $classifier, DirectoryRoleSynchronizer $directory, AuditLogger $audit): int
    {
        $findings = $this->findings($classifier, $directory);

        if ($findings->isEmpty()) {
            $this->components->info('No misclassified accounts found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Email', 'Identity', 'Finding', 'Action'],
            $findings->map(static fn (array $finding): array => [
                $finding['user']->id,
                $finding['user']->email,
                $finding['user']->identity_type,
                $finding['finding'],
                $finding['action'] === self::ACTION_RESYNC ? 'Resync to '.$finding['role'] : ucfirst($finding['action']),
            ])->all(),
        );

        $resync = $findings->where('action', self::ACTION_RESYNC);

        if ($this->option('dry-run')) {
            $this->components->warn("Dry run: {$resync->count()} account(s) would be resynced. Nothing was changed.");

            return self::SUCCESS;
        }

        if ($resync->isEmpty()) {
            $this->components->info('Nothing to apply: the remaining findings need a Cloudron group mapping or a manual review.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Resync {$resync->count()} account(s) from their Cloudron groups?")) {
            $this->components->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        foreach ($resync as $finding) {
            $user = $finding['user'];
            $result = $directory->synchronize($user, $user->ldap_groups ?? []);

            $audit->record('accounts.reclassified', null, $user, [
                'command' => 'myapes:accounts:reclassify',
                'previous_role' => $result->previousProtectedRole,
                'role' => $result->protectedRole,
            ]);
        }

        $this->components->info("Resynced {$resync->count()} account(s). No account was deleted or suspended.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{user: User, finding: string, action: string, role: ?string}>
     */
    private function findings(AccountClassifier $classifier, DirectoryRoleSynchronizer $directory): Collection
    {
        $unmapped = $classifier->scopeUnmapped(User::query())->orderBy('id')->get()
            ->map(function (User $user) use ($directory): array {
                $role = $directory->protectedRoleForGroups($user->ldap_groups ?? []);

                return [
                    'user' => $user,
                    'finding' => 'Cloudron account with no role',
                    'action' => $role === null ? self::ACTION_UNMAPPED : self::ACTION_RESYNC,
                    'role' => $role,
                ];
            });

        $publicInGroups = $classifier->scopePublic(User::query())->orderBy('id')->get()
            ->filter(static fn (User $user): bool => ($user->ldap_groups ?? []) !== [])
            ->map(function (User $user) use ($directory): ?array {
                $role = $directory->protectedRoleForGroups($user->ldap_groups);

                return $role === null ? null : [
                    'user' => $user,
                    'finding' => 'Public account in a mapped Cloudron group',
                    'action' => self::ACTION_REVIEW,
                    'role' => $role,
                ];
            })
            ->filter();

        return $unmapped->concat($publicInGroups)->values();
    }
}
