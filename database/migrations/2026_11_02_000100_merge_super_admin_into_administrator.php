<?php

use App\Services\AuthorizationMetadataSynchronizer;
use App\Services\AuthorizationProfile;
use App\Support\AuthorizationCompatibilityDatabaseGuard;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(AuthorizationProfile::class)->flushRuntimeCache();
        app(AuthorizationMetadataSynchronizer::class)->synchronize();
        app(AuthorizationCompatibilityDatabaseGuard::class)->upgrade();
    }

    public function down(): void
    {
        // Authorization metadata downgrades are handled manually.
    }
};
