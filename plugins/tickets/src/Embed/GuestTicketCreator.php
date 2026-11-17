<?php

namespace Plugins\Tickets\Embed;

use App\Core\Accounts\AccountClassifier;
use App\Core\Accounts\User;
use App\Rules\NotStaffEmail;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Models\TicketEmbedForm;
use Plugins\Tickets\Models\TicketEmbedVerification;
use Plugins\Tickets\Notifications\GuestTicketConfirmationNotification;
use Plugins\Tickets\TicketCategoryResolver;
use Plugins\Tickets\TicketServiceConfiguration;
use Plugins\Tickets\TicketStakeholderNotifier;

/**
 * Creates unverified guest embed tickets and completes ownership after email verify (#688, #691).
 */
final class GuestTicketCreator
{
    public const CONSENT_METHOD = 'embed-guest';

    public function __construct(
        private readonly TicketServiceConfiguration $ticketServices,
        private readonly TicketCategoryResolver $categories,
        private readonly TicketStakeholderNotifier $notifier,
        private readonly AccountClassifier $accounts,
    ) {}

    /**
     * @param  array{name: string, email: string, service: string, service_area: string, subject: string, details: string, sub_category?: string|null, affected_website_key?: string|null}  $data
     */
    public function createUnverified(
        TicketEmbedForm $form,
        array $data,
        AuditLogger $auditLogger,
    ): SupportTicket {
        if (NotStaffEmail::isStaffDomain($data['email'])) {
            throw ValidationException::withMessages([
                'email' => __('tickets::embed.validation.staff_email'),
            ]);
        }

        $service = $data['service'];
        abort_unless($form->allowsService($service), 404);
        $ticketService = $this->ticketServices->for($service);

        $categoryFields = [
            'service_area' => $data['service_area'],
            'sub_category' => null,
            'affected_website_key' => null,
        ];

        if ($service === 'apes-cic') {
            $categoryFields = $this->categories->validateSelection(
                $service,
                $data['service_area'],
                $data['sub_category'] ?? 'general',
                $data['affected_website_key'] ?? null,
            );
        }

        $policyVersion = (string) config('myapes.consent.policy_version');

        return DB::transaction(function () use ($form, $data, $service, $categoryFields, $policyVersion, $ticketService, $auditLogger): SupportTicket {
            $ticket = SupportTicket::query()->create([
                'service_area' => $categoryFields['service_area'],
                'sub_category' => $categoryFields['sub_category'],
                'affected_website_key' => $categoryFields['affected_website_key'],
                'subject' => $data['subject'],
                'priority' => 'medium',
                'description' => $data['details'],
                'sub_core_key' => $service,
                'user_id' => null,
                'guest_name' => $data['name'],
                'guest_email' => strtolower(trim($data['email'])),
                'guest_email_verified_at' => null,
                'embed_form_id' => $form->id,
                'staff_notified_at' => null,
                'guest_consent_method' => self::CONSENT_METHOD,
                'guest_consent_policy_version' => $policyVersion,
                'guest_consented_at' => now(),
                'status' => 'open',
            ]);

            $plain = Str::random(64);
            TicketEmbedVerification::query()->create([
                'support_ticket_id' => $ticket->id,
                'token_hash' => hash('sha256', $plain),
                'expires_at' => now()->addDays(7),
            ]);

            $auditLogger->record("{$ticketService->auditPrefix}.embed_created", null, $ticket, [
                'embed_form_id' => $form->id,
                'guest_email_domain' => Str::after($ticket->guest_email, '@'),
            ]);

            Notification::route('mail', $ticket->guest_email)
                ->notify(new GuestTicketConfirmationNotification($ticket, $plain, verified: false));

            return $ticket;
        });
    }

    public function verifyEmail(string $plainToken, AuditLogger $auditLogger): SupportTicket
    {
        $hash = hash('sha256', $plainToken);
        $verification = TicketEmbedVerification::query()
            ->where('token_hash', $hash)
            ->first();

        abort_unless($verification !== null && $verification->isValid(), 404);

        return DB::transaction(function () use ($verification, $auditLogger): SupportTicket {
            $ticket = SupportTicket::query()->lockForUpdate()->findOrFail($verification->support_ticket_id);
            abort_unless($ticket->guest_email_verified_at === null, 410);

            $owner = $this->matchPublicOwner((string) $ticket->guest_email);
            $ticket->forceFill([
                'guest_email_verified_at' => now(),
                'user_id' => $owner?->id,
            ])->save();

            $verification->forceFill(['consumed_at' => now()])->save();

            if ($ticket->staff_notified_at === null) {
                $this->notifier->notifyStaffOfVerifiedGuest($ticket);
                $ticket->forceFill(['staff_notified_at' => now()])->save();
            }

            $auditLogger->record('tickets.embed_verified', $owner, $ticket, [
                'matched_user' => $owner !== null,
            ]);

            Notification::route('mail', (string) $ticket->guest_email)
                ->notify(new GuestTicketConfirmationNotification($ticket, null, verified: true));

            return $ticket->refresh();
        });
    }

    private function matchPublicOwner(string $email): ?User
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        if ($user === null) {
            return null;
        }

        if ($this->accounts->isStaffSide($user) || NotStaffEmail::isStaffDomain($user->email)) {
            return null;
        }

        return $user;
    }
}
