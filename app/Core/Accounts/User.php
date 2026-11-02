<?php

namespace App\Core\Accounts;

use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Services\AuthorizationProfile;
use App\Services\LegacyAccessCompatibilityAdapter;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Cast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use LogicException;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'oidc_sub',
    'username',
    'identity_type',
    'name',
    'email',
    'locale',
    'password',
    'onboarding_completed_at',
    'ldap_groups',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    public const IDENTITY_LOCAL = 'local';

    public const IDENTITY_CLOUDRON_OIDC = 'cloudron_oidc';

    public const IDENTITY_HYBRID = 'hybrid';

    public const ROLE_SERVICE_USER = 'service_user';

    public const ROLE_STAFF = 'staff';

    public const ROLE_VOLUNTEER = 'volunteer';

    public const ROLE_STUDENT = 'student';

    public const ROLE_ADMIN = 'admin';

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, MustVerifyEmailTrait, Notifiable, PasskeyAuthenticatable;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (mixed $value): mixed => is_string($value)
                ? strtolower(trim($value))
                : $value,
        );
    }

    protected function username(): Attribute
    {
        return Attribute::make(
            set: static fn (mixed $value): mixed => is_string($value)
                ? strtolower(trim($value))
                : $value,
        );
    }

    public function preferredLocale(): string
    {
        $locale = $this->attributes['locale'] ?? null;
        $supported = array_keys(config('app.supported_locales', ['en_GB' => 'English (UK)']));

        if (is_string($locale) && $locale !== '' && in_array($locale, $supported, true)) {
            return $locale;
        }

        return in_array('en_GB', $supported, true) ? 'en_GB' : ($supported[0] ?? 'en_GB');
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public static function bootHasRoles(): void
    {
        // The verified database guard owns atomic provenance/pivot cleanup.
    }

    public static function bootHasPermissions(): void
    {
        // Direct permissions are closed and orphan cleanup is database-owned.
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[Cast]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'registration_consented_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'ldap_groups' => 'array',
            'authorization_epoch' => 'integer',
            'suspended_at' => 'datetime',
        ];
    }

    public function hasEnabledTwoFactor(): bool
    {
        return filled($this->two_factor_secret)
            && $this->two_factor_confirmed_at !== null;
    }

    public function getPasskeyUsername(): string
    {
        return is_string($this->username) && $this->username !== ''
            ? $this->username
            : (string) $this->email;
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    public function serviceSelections(): HasMany
    {
        return $this->hasMany(UserServiceSelection::class);
    }

    public function contactPreference(): HasOne
    {
        return $this->hasOne(UserContactPreference::class);
    }

    public function pendingEmailChange(): HasOne
    {
        return $this->hasOne(PendingEmailChange::class);
    }

    public function contactConsentEvents(): HasMany
    {
        return $this->hasMany(ContactConsentEvent::class);
    }

    public function oidcLinkIntents(): HasMany
    {
        return $this->hasMany(OidcLinkIntent::class);
    }

    public function supportTickets(): HasMany
    {
        // String FQCN keeps Core free of plugin package imports (#289).
        return $this->hasMany('Plugins\\Tickets\\Models\\SupportTicket');
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'suspended_by');
    }

    public function roleSources(): HasMany
    {
        return $this->hasMany(RoleSource::class);
    }

    public function permissionSources(): HasMany
    {
        return $this->hasMany(PermissionSource::class);
    }

    public function givePermissionTo(...$permissions): static
    {
        throw new LogicException('Direct user permissions are disabled.');
    }

    public function syncPermissions(...$permissions): static
    {
        throw new LogicException('Direct user permissions are disabled.');
    }

    public function revokePermissionTo($permission): static
    {
        throw new LogicException('Direct user permissions are disabled.');
    }

    public function assignRole(...$roles): static
    {
        throw new LogicException('Unprovenanced user role mutations are disabled.');
    }

    public function removeRole(...$role): static
    {
        throw new LogicException('Unprovenanced user role mutations are disabled.');
    }

    public function syncRoles(...$roles): static
    {
        throw new LogicException('Unprovenanced user role mutations are disabled.');
    }

    public function accessLevel(): string
    {
        return app(LegacyAccessCompatibilityAdapter::class)->read($this);
    }

    public static function accessLevelColumn(): string
    {
        return LegacyAccessCompatibilityAdapter::COLUMN;
    }

    /**
     * @return array<int, string>
     */
    public static function accessLevels(): array
    {
        return [
            self::ROLE_SERVICE_USER,
            self::ROLE_STUDENT,
            self::ROLE_VOLUNTEER,
            self::ROLE_STAFF,
            self::ROLE_ADMIN,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function staffAccessLevels(): array
    {
        return [
            self::ROLE_STUDENT,
            self::ROLE_VOLUNTEER,
            self::ROLE_STAFF,
            self::ROLE_ADMIN,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function adminAccessLevels(): array
    {
        return [self::ROLE_ADMIN];
    }

    public function setAccessLevel(string $accessLevel): static
    {
        return app(LegacyAccessCompatibilityAdapter::class)
            ->write($this, $accessLevel);
    }

    /**
     * @param  array<int, string>  $accessLevels
     */
    public function scopeWithAccessLevels(Builder $query, array $accessLevels): Builder
    {
        return app(LegacyAccessCompatibilityAdapter::class)
            ->scope($query, $accessLevels);
    }

    public function scopeWithAuthorizationPermission(
        Builder $query,
        string $permission,
    ): Builder {
        return $query
            ->whereNull('suspended_at')
            ->whereHas(
                'roles.permissions',
                static fn (Builder $permissionQuery): Builder => $permissionQuery
                    ->where('permissions.guard_name', 'web')
                    ->where('permissions.name', $permission),
            );
    }

    public function scopeEligibleStaff(Builder $query): Builder
    {
        return $this->scopeEligibleForProtectedRoles($query, [
            AuthorizationProfile::ROLE_STUDENT,
            AuthorizationProfile::ROLE_VOLUNTEER,
            AuthorizationProfile::ROLE_STAFF,
            AuthorizationProfile::ROLE_ADMINISTRATOR,
        ]);
    }

    public function scopeEligibleAdministrators(Builder $query): Builder
    {
        return $this->scopeEligibleForProtectedRoles($query, [
            AuthorizationProfile::ROLE_ADMINISTRATOR,
        ]);
    }

    /**
     * @param  array<int, string>  $roleNames
     */
    public function scopeEligibleForProtectedRoles(
        Builder $query,
        array $roleNames,
    ): Builder {
        $modelType = $query->getModel()->getMorphClass();
        $allowCompatibilitySources = app()->environment(['local', 'testing']);
        $directorySourceKey = match (
            $query->getModel()->getConnection()->getDriverName()
        ) {
            'sqlite' => "'directory:' || CAST(eligible_sources.directory_group_id AS TEXT)",
            'mysql' => "CONCAT('directory:', eligible_sources.directory_group_id)",
            default => throw new LogicException(
                'Staff eligibility requires a supported database driver.',
            ),
        };

        return $query
            ->whereNull('suspended_at')
            ->whereExists(
                static function ($eligible) use (
                    $allowCompatibilitySources,
                    $directorySourceKey,
                    $modelType,
                    $roleNames,
                ): void {
                    $eligible
                        ->selectRaw('1')
                        ->from('role_sources as eligible_sources')
                        ->join(
                            'roles as eligible_roles',
                            'eligible_roles.id',
                            '=',
                            'eligible_sources.role_id',
                        )
                        ->join(
                            'model_has_roles as eligible_pivots',
                            static function ($join): void {
                                $join
                                    ->on(
                                        'eligible_pivots.role_id',
                                        '=',
                                        'eligible_sources.role_id',
                                    )
                                    ->on(
                                        'eligible_pivots.model_id',
                                        '=',
                                        'eligible_sources.user_id',
                                    );
                            },
                        )
                        ->whereColumn(
                            'eligible_sources.user_id',
                            'users.id',
                        )
                        ->where(
                            'eligible_pivots.model_type',
                            $modelType,
                        )
                        ->where('eligible_roles.guard_name', 'web')
                        ->where('eligible_roles.is_protected', true)
                        ->whereIn('eligible_roles.name', $roleNames)
                        ->where(
                            static function ($provenance) use (
                                $allowCompatibilitySources,
                                $directorySourceKey,
                            ): void {
                                $provenance->where(
                                    static function ($directory) use (
                                        $directorySourceKey,
                                    ): void {
                                        $directory
                                            ->where(
                                                'eligible_sources.source',
                                                RoleSource::SOURCE_DIRECTORY,
                                            )
                                            ->whereNotNull(
                                                'eligible_sources.directory_group_id',
                                            )
                                            ->whereRaw(
                                                'eligible_sources.source_key = '
                                                .$directorySourceKey,
                                            )
                                            ->whereExists(
                                                static function ($mapping): void {
                                                    $mapping
                                                        ->selectRaw('1')
                                                        ->from(
                                                            'directory_group_role_mappings as eligible_mappings',
                                                        )
                                                        ->join(
                                                            'directory_groups as eligible_groups',
                                                            'eligible_groups.id',
                                                            '=',
                                                            'eligible_mappings.directory_group_id',
                                                        )
                                                        ->whereColumn(
                                                            'eligible_mappings.directory_group_id',
                                                            'eligible_sources.directory_group_id',
                                                        )
                                                        ->whereColumn(
                                                            'eligible_mappings.role_id',
                                                            'eligible_sources.role_id',
                                                        )
                                                        ->where(
                                                            'eligible_groups.status',
                                                            DirectoryGroup::STATUS_PRESENT,
                                                        );

                                                    if (Schema::hasColumn('directory_groups', 'app_enabled')) {
                                                        $mapping->where(
                                                            'eligible_groups.app_enabled',
                                                            true,
                                                        );
                                                    }
                                                },
                                            );
                                    },
                                );

                                if ($allowCompatibilitySources) {
                                    $provenance->orWhere(
                                        static function ($compatibility): void {
                                            $compatibility
                                                ->whereIn(
                                                    'eligible_sources.source',
                                                    [
                                                        RoleSource::SOURCE_SYSTEM,
                                                        RoleSource::SOURCE_LEGACY_COMPATIBILITY,
                                                    ],
                                                )
                                                ->whereNull(
                                                    'eligible_sources.directory_group_id',
                                                )
                                                ->whereColumn(
                                                    'eligible_sources.source_key',
                                                    'eligible_sources.source',
                                                );
                                        },
                                    );
                                }
                            },
                        );
                },
            );
    }

    public function isCloudronIdentity(): bool
    {
        return $this->identity_type === self::IDENTITY_CLOUDRON_OIDC
            && is_string($this->oidc_sub)
            && trim($this->oidc_sub) !== '';
    }

    public function isPendingFirstLogin(): bool
    {
        return $this->identity_type === self::IDENTITY_CLOUDRON_OIDC
            && (! is_string($this->oidc_sub) || trim($this->oidc_sub) === '');
    }

    public function isLocalPasswordIdentity(): bool
    {
        return $this->identity_type === self::IDENTITY_LOCAL
            && ! $this->hasDirectoryIdentity()
            && ! $this->isPendingFirstLogin();
    }

    public function hasDirectoryIdentity(): bool
    {
        return in_array(
            $this->identity_type,
            [self::IDENTITY_CLOUDRON_OIDC, self::IDENTITY_HYBRID],
            true,
        )
            && is_string($this->oidc_sub)
            && trim($this->oidc_sub) !== '';
    }

    public function isStaff(): bool
    {
        return app(LegacyAccessCompatibilityAdapter::class)->staff($this);
    }

    public function isAdmin(): bool
    {
        return app(LegacyAccessCompatibilityAdapter::class)->admin($this);
    }
}
