<?php

namespace Tests\Feature\Mail;

use App\Core\Accounts\User;
use App\Core\Mail\MailTemplateRegistry;
use App\Notifications\Auth\EmailChangeCompletedNotification;
use App\Notifications\Auth\EmailChangeConfirmNotification;
use App\Notifications\Auth\EmailChangeStartedNotification;
use App\Notifications\Auth\PasskeyAddedNotification;
use App\Notifications\Auth\PasskeyRemovedNotification;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\TwoFactorDisabledNotification;
use App\Notifications\Auth\TwoFactorEnabledNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\PendingFirstLoginChaseNotification;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Cases\Models\ShelterCase;
use Plugins\Cases\Notifications\ApesCicCaseUpdatedNotification;
use Plugins\Cases\Notifications\ShelterCaseUpdatedNotification;
use Plugins\Consultations\Models\PetCareConsultation;
use Plugins\Consultations\Notifications\ConsultationUpdatedNotification;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Tests\TestCase;

class OutboundMailQueueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string<Notification>}>
     */
    public static function mailNotificationClasses(): array
    {
        return [
            'verify email' => [VerifyEmailNotification::class],
            'reset password' => [ResetPasswordNotification::class],
            'password changed' => [PasswordChangedNotification::class],
            'two factor enabled' => [TwoFactorEnabledNotification::class],
            'two factor disabled' => [TwoFactorDisabledNotification::class],
            'passkey added' => [PasskeyAddedNotification::class],
            'passkey removed' => [PasskeyRemovedNotification::class],
            'email change started' => [EmailChangeStartedNotification::class],
            'email change confirm' => [EmailChangeConfirmNotification::class],
            'email change completed' => [EmailChangeCompletedNotification::class],
            'staff login chase' => [PendingFirstLoginChaseNotification::class],
            'ticket updated' => [TicketUpdatedNotification::class],
            'apes cic case updated' => [ApesCicCaseUpdatedNotification::class],
            'shelter case updated' => [ShelterCaseUpdatedNotification::class],
            'consultation updated' => [ConsultationUpdatedNotification::class],
        ];
    }

    /**
     * @param  class-string<Notification>  $class
     */
    #[DataProvider('mailNotificationClasses')]
    public function test_every_outbound_mail_notification_is_queued(string $class): void
    {
        $this->assertTrue(is_subclass_of($class, ShouldQueue::class), "{$class} must implement ShouldQueue.");
    }

    public function test_every_registered_mail_template_is_queued(): void
    {
        foreach (app(MailTemplateRegistry::class)->all() as $template) {
            $this->assertTrue(
                is_subclass_of($template->notification, ShouldQueue::class),
                "Template {$template->key} must queue.",
            );
        }
    }

    /**
     * @return array<string, array{Closure(User): Notification}>
     */
    public static function formerlySyncNotifications(): array
    {
        return [
            'staff login chase' => [static fn (User $actor): Notification => new PendingFirstLoginChaseNotification($actor)],
            'ticket updated' => [static fn (User $actor): Notification => new TicketUpdatedNotification(
                (new SupportTicket)->forceFill(['id' => 1, 'subject' => 'Queued', 'status' => 'open', 'priority' => 'low']),
                $actor,
                'updated',
                'apes-cic',
                'apes-cic.tickets.show',
                'APES CIC',
            )],
            'apes cic case updated' => [static fn (User $actor): Notification => new ApesCicCaseUpdatedNotification(
                (new ShelterCase)->forceFill(['id' => 1, 'status' => 'open', 'priority' => 'low']),
                $actor,
                'updated',
                'apes-cic',
                'apes-cic.cases.show',
            )],
            'shelter case updated' => [static fn (User $actor): Notification => new ShelterCaseUpdatedNotification(
                (new ShelterCase)->forceFill(['id' => 1, 'title' => 'Queued', 'case_type' => 'rescue', 'status' => 'open']),
                $actor,
                'updated',
            )],
            'consultation updated' => [static fn (User $actor): Notification => new ConsultationUpdatedNotification(
                (new PetCareConsultation)->forceFill(['id' => 1, 'subject' => 'Queued', 'status' => 'open']),
                $actor,
                'updated',
            )],
        ];
    }

    /**
     * @param  Closure(User): Notification  $make
     */
    #[DataProvider('formerlySyncNotifications')]
    public function test_formerly_sync_notifications_queue_after_commit(Closure $make): void
    {
        Queue::fake();
        $recipient = User::factory()->create();
        $notification = $make($recipient);

        $this->assertTrue($notification->afterCommit);

        $recipient->notify($notification);

        Queue::assertPushed(
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job): bool => $notification::class === $job->notification::class
                && $job->notification->afterCommit === true,
        );
    }

    public function test_notification_sent_inside_a_transaction_waits_for_commit(): void
    {
        $sent = 0;
        Event::listen(NotificationSent::class, function () use (&$sent): void {
            $sent++;
        });
        $recipient = User::factory()->create();

        DB::transaction(function () use ($recipient, &$sent): void {
            $recipient->notify(new PendingFirstLoginChaseNotification($recipient));

            $this->assertSame(0, $sent);
        });

        $this->assertSame(1, $sent);
    }

    public function test_staff_login_chase_from_admin_is_queued(): void
    {
        Queue::fake();

        $administrator = User::factory()->accessLevel(User::ROLE_ADMIN)->create()->refresh();
        $pending = User::factory()->create([
            'identity_type' => User::IDENTITY_CLOUDRON_OIDC,
            'oidc_sub' => null,
            'password' => 'password',
        ]);

        $this->actingAs($administrator)
            ->post(route('admin.users.pending-first-login-chase', $pending), ['confirm_chase' => '1'])
            ->assertRedirect();

        Queue::assertPushed(
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job): bool => $job->notification instanceof PendingFirstLoginChaseNotification
                && $job->notifiables->contains('id', $pending->id),
        );
    }
}
