<?php

use App\Http\Controllers\Admin\AdminAccessController;
use App\Http\Controllers\Admin\AdminEmailController;
use App\Http\Controllers\Admin\AdminGroupController;
use App\Http\Controllers\Admin\AdminMaintenanceController;
use App\Http\Controllers\Admin\AdminModuleController;
use App\Http\Controllers\Admin\AdminOrganisationModuleController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\StaffAdminController;
use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Controllers\Auth\EmailChangeController;
use App\Http\Controllers\Auth\OidcAuthController;
use App\Http\Controllers\Auth\PublicAuthController;
use App\Http\Controllers\Auth\PublicPasswordResetController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\ChangeLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RobotsTxtController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SubCoreController;
use App\Http\Controllers\SupportAttachmentController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::view('/', 'auth.landing')->name('home');
});

Route::get('/robots.txt', RobotsTxtController::class)->name('robots');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

Route::post('/locale', [LocaleController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('locale.store');

Route::get('/change-log', ChangeLogController::class)->name('change-log.index');
Route::view('/privacy', 'legal.privacy')->name('privacy');
Route::view('/cookies', 'legal.cookies')->name('cookies');
Route::view('/help', 'legal.help')->name('help');
Route::view('/terms', 'legal.terms')->name('terms');
Route::get('/email/change/confirm/{user}/{hash}', [EmailChangeController::class, 'confirm'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('email.change.confirm');
Route::middleware('plugin.enabled:apes-cic,recruitment')->group(function (): void {
    require base_path('plugins/recruitment/routes/public.php');
});
Route::get('/storage/pet-profiles/{path?}', static fn () => abort(404))
    ->where('path', '.*');

Route::middleware('guest')->controller(PublicAuthController::class)->group(function (): void {
    Route::get('/login', 'showLogin')->name('public.login');
    Route::post('/login', 'login')->middleware('throttle:public-login')->name('public.login.submit');
    Route::get('/register', 'showRegister')->name('public.register');
    Route::post('/register', 'register')->name('public.register.submit');
});

Route::middleware('guest')->controller(TwoFactorChallengeController::class)->group(function (): void {
    Route::get('/two-factor-challenge', 'create')->name('two-factor.login');
    Route::post('/two-factor-challenge', 'store')
        ->middleware('throttle:two-factor-challenge')
        ->name('two-factor.login.store');
});

Route::middleware('guest')->controller(PublicPasswordResetController::class)->group(function (): void {
    Route::get('/forgot-password', 'create')->name('password.request');
    Route::post('/forgot-password', 'store')->middleware('throttle:public-password-reset-request')->name('password.email');
    Route::get('/reset-password/{token}', 'edit')->name('password.reset');
    Route::post('/reset-password', 'update')->middleware('throttle:public-password-reset-submit')->name('password.update');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/staff/login', function () {
        return view('auth.staff-login');
    })->name('staff.login');

    if (app()->environment(['local', 'testing'])) {
        Route::post('/staff/login', [PublicAuthController::class, 'localStaffLogin'])
            ->name('staff.local-login.submit');
    }
});

Route::post('/qa/switch-role', [PublicAuthController::class, 'qaSwitchRole'])->name('qa.switch-role');

Route::prefix('staff/auth')->controller(OidcAuthController::class)->group(function (): void {
    Route::get('/login', 'login')->name('staff.auth.login');
    Route::get('/callback', 'callback')->name('staff.auth.callback');
    Route::post('/logout', 'logout')->middleware('auth')->name('auth.logout');
});

Route::middleware([
    'auth',
    'authorization.context',
    'directory.current',
])->group(function (): void {
    Route::prefix('admin/maintenance')
        ->name('admin.maintenance.')
        ->middleware([
            'maintenance.recovery',
            'admin.denial-audit',
            'can:admin.maintenance.manage',
        ])
        ->group(function (): void {
            Route::get('/', [AdminMaintenanceController::class, 'index'])
                ->name('index');
            Route::post('/activate', [AdminMaintenanceController::class, 'activate'])
                ->name('activate');
            Route::post('/deactivate', [AdminMaintenanceController::class, 'deactivate'])
                ->name('deactivate');
        });

    Route::get('/email/verify', function (Request $request) {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('onboarding.edit')
            : view('auth.verify-email');
    })->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route('onboarding.edit');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', function (Request $request) {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', __('auth.verify_email.sent'));
    })->middleware('throttle:verification-resend')->name('verification.send');

    Route::get('/onboarding', [OnboardingController::class, 'edit'])->name('onboarding.edit');
    Route::put('/onboarding', [OnboardingController::class, 'update'])->name('onboarding.update');
});

Route::middleware([
    'auth',
    'authorization.context',
    'directory.current',
    'account.ready',
])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/support/attachments/{attachment}', [SupportAttachmentController::class, 'download'])
        ->name('support.attachments.download');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    // Step-up (#233): password.confirm via account.step-up. Wire later children
    // (MFA #231/#232) with the same middleware alias. Username #227 / email #228 use it here.
    Route::put('/profile/username', [ProfileController::class, 'updateUsername'])
        ->middleware(['account.step-up', 'throttle:public-username-change'])
        ->name('profile.username.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])
        ->middleware(['account.step-up', 'throttle:public-password-change'])
        ->name('profile.password.update');
    Route::post('/profile/email/change', [EmailChangeController::class, 'store'])
        ->middleware(['account.step-up', 'throttle:public-email-change'])
        ->name('profile.email.change');
    Route::delete('/profile/email/pending', [EmailChangeController::class, 'destroy'])
        ->middleware(['throttle:public-email-change'])
        ->name('profile.email.cancel');
    Route::get('/profile/two-factor', [TwoFactorController::class, 'show'])
        ->name('two-factor.setup');
    Route::post('/profile/two-factor', [TwoFactorController::class, 'store'])
        ->middleware(['throttle:two-factor-manage'])
        ->name('two-factor.enable');
    Route::post('/profile/two-factor/confirm', [TwoFactorController::class, 'confirm'])
        ->middleware(['throttle:two-factor-manage'])
        ->name('two-factor.confirm');
    Route::delete('/profile/two-factor', [TwoFactorController::class, 'destroy'])
        ->middleware(['account.step-up', 'throttle:two-factor-manage'])
        ->name('two-factor.disable');
    Route::post('/profile/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])
        ->middleware(['account.step-up', 'throttle:two-factor-manage'])
        ->name('two-factor.recovery.regenerate');
    Route::get('/profile/staff-photo', [ProfileController::class, 'staffPhoto'])->name('profile.staff-photo');

    Route::get('/user/confirm-password', [ConfirmPasswordController::class, 'show'])
        ->name('password.confirm');
    Route::post('/user/confirm-password', [ConfirmPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.confirm.store');

    Route::middleware('plugin.enabled:apes-cic,recruitment')->group(function (): void {
        require base_path('plugins/recruitment/routes/public-auth.php');
    });

    Route::prefix('apes-cic')->name('apes-cic.')->group(function (): void {
        Route::get('/', [SubCoreController::class, 'show'])
            ->defaults('subCoreKey', 'apes-cic')
            ->middleware('service.selected:apes-cic')
            ->name('index');
        Route::middleware(['plugin.enabled:apes-cic,tickets', 'service.selected:apes-cic'])
            ->group(function (): void {
                require base_path('plugins/tickets/routes/apes-cic.php');
            });
        Route::middleware(['plugin.enabled:apes-cic,cases', 'service.selected:apes-cic'])
            ->group(function (): void {
                require base_path('plugins/cases/routes/apes-cic.php');
            });
        Route::middleware(['plugin.enabled:apes-cic,recruitment', 'service.selected:apes-cic'])
            ->group(function (): void {
                require base_path('plugins/recruitment/routes/apes-cic.php');
            });
    });

    Route::prefix('shelter')->name('shelter.')->group(function (): void {
        Route::get('/', [SubCoreController::class, 'show'])
            ->defaults('subCoreKey', 'shelter-rescue')
            ->middleware('service.selected:shelter-rescue')
            ->name('index');
        Route::middleware(['plugin.enabled:shelter-rescue,tickets', 'service.selected:shelter-rescue'])
            ->group(function (): void {
                require base_path('plugins/tickets/routes/shelter.php');
            });
        Route::middleware(['plugin.enabled:shelter-rescue,pet-profiles', 'service.selected:shelter-rescue'])
            ->group(function (): void {
                require base_path('plugins/pet-profiles/routes/shelter.php');
            });
        Route::middleware(['plugin.enabled:shelter-rescue,cases', 'service.selected:shelter-rescue'])
            ->group(function (): void {
                require base_path('plugins/cases/routes/shelter.php');
            });
    });

    Route::prefix('petcare')->name('petcare.')->group(function (): void {
        Route::get('/', [SubCoreController::class, 'show'])
            ->defaults('subCoreKey', 'pet-care-clinic')
            ->middleware('service.selected:pet-care-clinic')
            ->name('index');
        Route::middleware(['plugin.enabled:pet-care-clinic,tickets', 'service.selected:pet-care-clinic'])
            ->group(function (): void {
                require base_path('plugins/tickets/routes/petcare.php');
            });
        Route::middleware(['plugin.enabled:pet-care-clinic,pet-profiles', 'service.selected:pet-care-clinic'])
            ->group(function (): void {
                require base_path('plugins/pet-profiles/routes/petcare.php');
            });
        Route::middleware(['plugin.enabled:pet-care-clinic,consultations', 'service.selected:pet-care-clinic'])
            ->group(function (): void {
                require base_path('plugins/consultations/routes/petcare.php');
            });
    });

    Route::prefix('superadmin')
        ->name('superadmin.')
        ->middleware('admin.denial-audit')
        ->group(function (): void {
            // Legacy Super Admin shell URLs collapse into the unified Admin area (#252 / #293 → 301).
            Route::get('/', fn () => redirect()->route('admin.index', request()->query(), 301))
                ->middleware('can:superadmin.access')
                ->name('index');
            Route::get('/groups', fn () => redirect()->route('admin.groups.index', [], 301))
                ->middleware('can:admin.groups.view')
                ->name('groups');
            Route::get('/plugins', fn () => redirect()->route('admin.modules.index', [], 301))
                ->middleware('can:admin.modules.view')
                ->name('plugins');
            Route::get('/modules', fn () => redirect()->route('admin.modules.index', [], 301))
                ->middleware('can:admin.modules.view')
                ->name('modules');
        });

    Route::prefix('admin')
        ->name('admin.')
        ->middleware('admin.denial-audit')
        ->group(function (): void {
            Route::get('/', StaffAdminController::class)
                ->middleware('can:admin.analytics.view')
                ->name('index');

            Route::get('/users', [AdminUserController::class, 'index'])
                ->middleware('can:admin.users.view')
                ->name('users.index');
            Route::get('/users/{user}', [AdminUserController::class, 'show'])
                ->middleware('can:admin.users.view')
                ->name('users.show');
            Route::put('/users/{user}/profile', [AdminUserController::class, 'updateProfile'])
                ->middleware('can:admin.users.manage')
                ->name('users.profile.update');
            Route::put('/users/{user}/staff-profile', [AdminUserController::class, 'updateStaffProfile'])
                ->middleware('can:admin.users.manage')
                ->name('users.staff-profile.update');
            Route::get('/users/{user}/staff-photo', [AdminUserController::class, 'staffPhoto'])
                ->middleware('can:admin.users.view')
                ->name('users.staff-photo');
            Route::put('/users/{user}/roles', [AdminUserController::class, 'updateRoles'])
                ->middleware('can:admin.users.manage')
                ->name('users.roles.update');
            Route::post('/users/{user}/suspension', [AdminUserController::class, 'suspend'])
                ->middleware('can:admin.users.manage')
                ->name('users.suspension.store');
            Route::delete('/users/{user}/suspension', [AdminUserController::class, 'reactivate'])
                ->middleware('can:admin.users.manage')
                ->name('users.suspension.destroy');
            Route::post('/users/{user}/password-reset', [AdminUserController::class, 'resetLocalPassword'])
                ->middleware('can:admin.users.manage')
                ->name('users.password-reset');
            Route::post('/users/{user}/pending-first-login-chase', [AdminUserController::class, 'chasePendingFirstLogin'])
                ->middleware('can:admin.users.manage')
                ->name('users.pending-first-login-chase');

            Route::get('/access', [AdminAccessController::class, 'index'])
                ->name('access.index');
            Route::post('/access/sync', [AdminAccessController::class, 'sync'])
                ->middleware('can:admin.group-mappings.manage')
                ->name('access.sync');
            Route::post('/access/groups/{directoryGroup}/mappings', [AdminAccessController::class, 'storeMapping'])
                ->middleware('can:admin.group-mappings.manage')
                ->name('access.mappings.store');
            Route::delete('/access/mappings/{mapping}', [AdminAccessController::class, 'destroyMapping'])
                ->middleware('can:admin.group-mappings.manage')
                ->name('access.mappings.destroy');
            Route::post('/access/job-roles', [AdminAccessController::class, 'storeJobRole'])
                ->middleware('can:admin.roles.manage')
                ->name('access.job-roles.store');
            Route::get('/access/job-roles/{role}', [AdminAccessController::class, 'showJobRole'])
                ->middleware('can:admin.roles.view')
                ->name('access.job-roles.show');
            Route::put('/access/job-roles/{role}', [AdminAccessController::class, 'updateJobRole'])
                ->middleware('can:admin.roles.manage')
                ->name('access.job-roles.update');
            Route::delete('/access/job-roles/{role}', [AdminAccessController::class, 'destroyJobRole'])
                ->middleware('can:admin.roles.manage')
                ->name('access.job-roles.destroy');

            Route::get('/groups', fn () => redirect()->route('admin.access.index', ['tab' => 'groups'], 301))
                ->middleware('can:admin.groups.view')
                ->name('groups.index');
            Route::get('/groups/{directoryGroup}', [AdminGroupController::class, 'show'])
                ->middleware('can:admin.groups.view')
                ->name('groups.show');
            Route::get('/roles', fn () => redirect()->route('admin.access.index', ['tab' => 'job-roles'], 301))
                ->middleware('can:admin.roles.view')
                ->name('roles.index');
            Route::get('/roles/{role}', fn (string $role) => redirect()->route('admin.access.job-roles.show', $role, 301))
                ->middleware('can:admin.roles.view')
                ->name('roles.show');
            Route::get('/permissions', fn () => redirect()->route('admin.access.index', ['tab' => 'permissions'], 301))
                ->middleware('can:admin.permissions.view')
                ->name('permissions.index');

            Route::get('/emails', [AdminEmailController::class, 'index'])
                ->middleware('can:admin.emails.view')
                ->name('emails.index');
            Route::get('/emails/log', [AdminEmailController::class, 'log'])
                ->middleware('can:admin.emails.view')
                ->name('emails.log');
            Route::get('/emails/{template}', [AdminEmailController::class, 'show'])
                ->middleware('can:admin.emails.view')
                ->where('template', '[a-z0-9_]+\.[a-z0-9_]+')
                ->name('emails.show');
            Route::get('/emails/{template}/preview', [AdminEmailController::class, 'preview'])
                ->middleware('can:admin.emails.view')
                ->where('template', '[a-z0-9_]+\.[a-z0-9_]+')
                ->name('emails.preview');
            Route::post('/emails/{template}/test', [AdminEmailController::class, 'sendTest'])
                ->middleware('can:admin.emails.manage')
                ->where('template', '[a-z0-9_]+\.[a-z0-9_]+')
                ->name('emails.test');
            Route::put('/emails/{template}/copy', [AdminEmailController::class, 'updateCopy'])
                ->middleware('can:admin.emails.manage')
                ->where('template', '[a-z0-9_]+\.[a-z0-9_]+')
                ->name('emails.copy.update');
            Route::delete('/emails/{template}/copy', [AdminEmailController::class, 'resetCopy'])
                ->middleware('can:admin.emails.manage')
                ->where('template', '[a-z0-9_]+\.[a-z0-9_]+')
                ->name('emails.copy.reset');
            Route::post('/users/{user}/resend-verification', [AdminEmailController::class, 'resendVerification'])
                ->middleware('can:admin.users.manage')
                ->name('users.resend-verification');

            Route::get('/organisation-modules', [AdminOrganisationModuleController::class, 'index'])
                ->middleware('can:admin.modules.view')
                ->name('organisation-modules.index');
            Route::post('/organisation-modules/{slug}/transition', [AdminOrganisationModuleController::class, 'transition'])
                ->middleware('can:admin.modules.manage')
                ->name('organisation-modules.transition');

            Route::get('/modules', [AdminModuleController::class, 'index'])
                ->middleware('can:admin.modules.view')
                ->name('modules.index');
            Route::get('/modules/{subCoreKey}/{moduleKey}/settings', [AdminModuleController::class, 'editSettings'])
                ->middleware('can:admin.modules.view')
                ->name('modules.settings.edit');
            Route::put('/modules/{subCoreKey}/{moduleKey}/settings', [AdminModuleController::class, 'updateSettings'])
                ->middleware('can:admin.modules.manage')
                ->name('modules.settings.update');
            Route::post('/modules/{subCoreKey}/{moduleKey}/transition', [AdminModuleController::class, 'transition'])
                ->middleware('can:admin.modules.manage')
                ->name('modules.transition');
        });
});
