<?php

namespace App\Services;

use App\Core\Accounts\User;
use DomainException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Admin resend of the verify-email message (#358).
 *
 * Every resend builds a brand-new signed link at send time; no stored or logged link is reused.
 */
class VerificationResendService
{
    public const MAX_PER_HOUR = 3;

    public function __construct(
        private readonly AuthorizationMutationService $mutations,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function canResend(User $actor, User $target): bool
    {
        return $this->refusalMessage($actor, $target) === null;
    }

    public function resend(User $actor, User $target): void
    {
        $refusal = $this->refusalMessage($actor, $target);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        if (! RateLimiter::attempt($this->rateLimitKey($target), self::MAX_PER_HOUR, static fn (): bool => true, 3600)) {
            throw new DomainException(__('admin.emails.resend_rate_limited'));
        }

        $target->sendEmailVerificationNotification();

        $this->auditLogger->record('auth.verification_resent', $actor, $target, [
            'target_user_id' => $target->id,
            'template' => 'auth.verify_email',
        ]);
    }

    public function refusalMessage(User $actor, User $target): ?string
    {
        if (! $this->mutations->canManageTarget($actor, $target)) {
            return __('admin.emails.resend_not_allowed');
        }

        if (! $target->isLocalPasswordIdentity()) {
            return __('admin.emails.resend_local_only');
        }

        if ($target->hasVerifiedEmail()) {
            return __('admin.emails.resend_already_verified');
        }

        return null;
    }

    private function rateLimitKey(User $target): string
    {
        return 'admin-verification-resend:'.$target->id;
    }
}
