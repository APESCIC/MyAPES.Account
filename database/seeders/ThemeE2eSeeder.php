<?php

namespace Database\Seeders;

use App\Core\Accounts\User;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Minimal staff account for Playwright theme snapshots (#908).
 */
class ThemeE2eSeeder extends Seeder
{
    public const STAFF_EMAIL = 'theme-e2e@example.test';

    public const STAFF_PASSWORD = 'Local-Demo-Only-2026!';

    public function run(): void
    {
        app(ModuleInstallationSynchronizer::class)->synchronize();

        User::query()->where('email', self::STAFF_EMAIL)->delete();

        User::factory()
            ->protectedRole(AuthorizationProfile::ROLE_STAFF)
            ->create([
                'email' => self::STAFF_EMAIL,
                'password' => Hash::make(self::STAFF_PASSWORD),
                'email_verified_at' => now(),
                'guided_tours' => [
                    'staff' => ['at' => now()->toIso8601String(), 'skipped' => false],
                ],
            ]);
    }
}