<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\OidcIdentityProvider;
use App\Core\Accounts\User;
use App\Core\Auth\OidcFlow;
use App\Exceptions\DirectoryIdentityNotFound;
use App\Exceptions\DirectoryUnavailable;
use App\Exceptions\OidcProviderException;
use App\Http\Controllers\Controller;
use App\Http\Cookies\OidcReauthenticationCookie;
use App\Services\AuditLogger;
use App\Services\DirectoryRoleSynchronizer;
use App\Services\DirectoryUserSynchronizer;
use App\Services\LdapUserResolver;
use App\Services\MaintenanceResponseFactory;
use App\Services\SessionAuthorizationContext;
use App\Services\StaffProfileDirectorySynchronizer;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class OidcAuthController extends Controller
{
    public function login(
        Request $request,
        OidcIdentityProvider $identityProvider,
        OidcReauthenticationCookie $reauthenticationCookie,
        AuditLogger $auditLogger,
        MaintenanceMode $maintenanceMode,
        MaintenanceResponseFactory $maintenanceResponses,
    ): RedirectResponse|Response {
        if (Auth::check()) {
            if ($maintenanceMode->active()) {
                return $request->user()?->can('admin.maintenance.manage')
                    ? redirect()->route('admin.maintenance.index')
                    : $maintenanceResponses->make($request);
            }

            return redirect()->route('dashboard');
        }

        $forceReauthentication = $reauthenticationCookie->isRequired($request);

        if ($forceReauthentication) {
            $auditLogger->record('auth.oidc_reauthentication_required', context: [
                'reason' => 'explicit_logout',
            ]);
        }

        try {
            return $identityProvider->authorizationRedirect(
                OidcFlow::StaffLogin,
                $forceReauthentication,
            );
        } catch (OidcProviderException) {
            $auditLogger->record('auth.oidc_provider_unavailable', context: [
                'reason' => 'provider_unavailable',
            ]);
            abort(503, __('auth.oidc.unavailable'));
        }
    }

    public function callback(
        Request $request,
        OidcIdentityProvider $identityProvider,
        LdapUserResolver $ldapUserResolver,
        DirectoryRoleSynchronizer $roles,
        StaffProfileDirectorySynchronizer $staffProfiles,
        SessionAuthorizationContext $authorizationContext,
        AuditLogger $auditLogger,
        OidcReauthenticationCookie $reauthenticationCookie,
        MaintenanceMode $maintenanceMode,
        MaintenanceResponseFactory $maintenanceResponses,
    ): RedirectResponse|Response {
        try {
            $identity = $identityProvider->callbackIdentity(OidcFlow::StaffLogin);
        } catch (OidcProviderException) {
            $auditLogger->record('auth.oidc_provider_unavailable', context: [
                'reason' => 'provider_unavailable',
            ]);
            abort(503, __('auth.oidc.unavailable'));
        }

        if ($identity->email === null) {
            $auditLogger->record('auth.oidc_missing_email');
            abort(403, __('auth.oidc.missing_email'));
        }

        if ($identity->subject === null) {
            $auditLogger->record('auth.oidc_missing_subject');
            abort(403, __('auth.oidc.missing_subject'));
        }

        $email = Str::lower($identity->email);
        $sub = $identity->subject;

        $knownUser = User::query()->where('oidc_sub', $sub)->first();

        if ($knownUser?->identity_type === User::IDENTITY_LOCAL) {
            $auditLogger->record('auth.oidc_local_account_denied', $knownUser, $knownUser, [
                'reason' => 'local_public_account',
            ]);
            abort(403, __('auth.oidc.local_account'));
        }

        if ($knownUser === null) {
            $knownUser = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->where('identity_type', User::IDENTITY_CLOUDRON_OIDC)
                ->first();
        }

        if ($knownUser?->suspended_at !== null) {
            $directoryDisabled = $knownUser->suspension_reason
                === DirectoryUserSynchronizer::SUSPENSION_REASON_DIRECTORY_DISABLED;

            if (! $directoryDisabled) {
                $auditLogger->record(
                    'auth.suspended_login_denied',
                    $knownUser,
                    $knownUser,
                    ['method' => SessionAuthorizationContext::METHOD_CLOUDRON_OIDC],
                );
                abort(403, __('auth.oidc.suspended'));
            }
        }

        try {
            $directoryProfile = $ldapUserResolver->profileForEmail($email);
        } catch (DirectoryIdentityNotFound) {
            $this->disableKnownDirectoryIdentity(
                $knownUser,
                $sub,
                $roles,
                $auditLogger,
                'identity_not_found',
            );
            $auditLogger->record('auth.oidc_access_denied', context: [
                'reason' => 'identity_not_found',
            ]);
            abort(403, __('auth.oidc.no_directory_group'));
        } catch (DirectoryUnavailable) {
            $auditLogger->record('auth.ldap_resolution_failed', null, null, [
                'reason' => 'directory_unavailable',
            ]);
            abort(503, __('auth.oidc.directory_unavailable'));
        }

        $groups = $directoryProfile->groups;

        $role = $roles->protectedRoleForGroups($groups);

        if ($role === null) {
            $this->disableKnownDirectoryIdentity(
                $knownUser,
                $sub,
                $roles,
                $auditLogger,
                'no_approved_group',
            );
            $auditLogger->record('auth.oidc_access_denied', context: [
                'reason' => 'no_approved_group',
                'group_count' => count($groups),
            ]);
            abort(403, __('auth.oidc.no_directory_group'));
        }

        $user = $knownUser;

        if ($user === null) {
            $emailCollision = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->exists();

            if ($emailCollision) {
                $auditLogger->record('auth.oidc_email_conflict', null, null, [
                    'reason' => 'email_already_in_use',
                ]);
                abort(403, __('auth.oidc.email_conflict'));
            }

            $user = new User;
        } elseif (User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereKeyNot($user->getKey())
            ->exists()) {
            $auditLogger->record('auth.oidc_email_conflict', $user, $user, [
                'reason' => 'email_already_in_use',
            ]);
            abort(403, __('auth.oidc.email_conflict'));
        }

        $user->oidc_sub = $sub;
        $user->identity_type = User::IDENTITY_CLOUDRON_OIDC;
        $user->name = $identity->name ?? $directoryProfile->name ?? $email;
        $user->email = $email;
        $user->email_verified_at = now();
        // Never touch users.locale — OIDC must not wipe a stored preference (#275).

        if ($user->suspended_at !== null
            && $user->suspension_reason
                === DirectoryUserSynchronizer::SUSPENSION_REASON_DIRECTORY_DISABLED) {
            $user->forceFill([
                'suspended_at' => null,
                'suspended_by' => null,
                'suspension_reason' => null,
                'authorization_epoch' => (int) $user->authorization_epoch + 1,
            ]);
            $user->setRememberToken(Str::random(60));
        }

        $user->save();
        $staffProfiles->apply($user, $directoryProfile);
        $result = $roles->synchronize($user, $groups);

        if (! $result->eligible) {
            $auditLogger->record('auth.oidc_access_denied', $user, $user, [
                'reason' => 'eligibility_changed_before_reconciliation',
                'group_count' => count($groups),
            ]);
            abort(403, __('auth.oidc.no_directory_group'));
        }

        $user->refresh();

        Auth::login($user, false);
        $request->session()->regenerate();
        $authorizationContext->recordCloudronOidc($request, $user);
        $auditLogger->record('auth.login_success', $user, $user, [
            'role' => $result->protectedRole,
            'group_count' => count($groups),
        ]);

        if ($maintenanceMode->active()) {
            $response = $user->can('admin.maintenance.manage')
                ? redirect()->route('admin.maintenance.index')
                : $maintenanceResponses->make($request);
        } else {
            $response = redirect()->intended(route('dashboard'));
        }

        return $reauthenticationCookie->isRequired($request)
            ? $response->withCookie($reauthenticationCookie->clear())
            : $response;
    }

    public function logout(
        Request $request,
        AuditLogger $auditLogger,
        OidcReauthenticationCookie $reauthenticationCookie,
    ): RedirectResponse {
        /** @var User|null $user */
        $user = $request->user();
        $directoryBacked = $user?->hasDirectoryIdentity() ?? false;

        if ($user !== null) {
            $auditLogger->record('auth.logout', $user, $user);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $response = redirect()->route('home');

        return $directoryBacked
            ? $response->withCookie($reauthenticationCookie->mark())
            : $response;
    }

    private function disableKnownDirectoryIdentity(
        ?User $knownUser,
        string $subject,
        DirectoryRoleSynchronizer $roles,
        AuditLogger $auditLogger,
        string $reason,
    ): void {
        $user = $knownUser
            ?? User::query()->where('oidc_sub', $subject)->first();

        if ($user === null) {
            return;
        }

        $result = $roles->revoke($user);

        if ($user->suspended_at === null) {
            $user->forceFill([
                'suspended_at' => now(),
                'suspended_by' => null,
                'suspension_reason' => DirectoryUserSynchronizer::SUSPENSION_REASON_DIRECTORY_DISABLED,
                'authorization_epoch' => (int) $user->authorization_epoch + 1,
            ]);
            $user->setRememberToken(Str::random(60));
            $user->save();
        }

        $auditLogger->record('auth.directory_access_revoked', $user, $user, [
            'from_role' => $result->previousProtectedRole,
            'reason' => $reason,
        ]);
    }
}
