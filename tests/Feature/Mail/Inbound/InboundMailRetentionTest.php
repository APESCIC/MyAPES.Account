<?php

namespace Tests\Feature\Mail\Inbound;

use App\Core\Accounts\User;
use App\Core\Mail\Inbound\InboundEmail;
use App\Core\Mail\Inbound\InboundMailStore;
use App\Core\Mail\Inbound\MailReplyThread;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Retention and privacy for replies by email (#586).
 */
class InboundMailRetentionTest extends TestCase
{
    use InboundMailTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Queue::fake();
        $this->enableEmailReplies();
    }

    public function test_raw_mail_older_than_30_days_is_deleted_and_metadata_kept(): void
    {
        $user = User::factory()->create();
        $old = $this->stored('old@mail.example.org', InboundEmail::STATUS_POSTED, ['user_id' => $user->id, 'record_prefix' => 'T', 'record_id' => 7]);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        $recent = $this->stored('recent@mail.example.org', InboundEmail::STATUS_POSTED);
        $oldPath = (string) $old->raw_path;

        $this->artisan('inbound-mail:prune')->expectsOutputToContain('Pruned 1 inbound email')->assertSuccessful();

        $old->refresh();
        Storage::disk('private')->assertMissing($oldPath);
        $this->assertNotNull($old->raw_pruned_at);
        $this->assertNull($old->raw_path);
        $this->assertNull($old->subject);
        $this->assertNull($old->from_address);
        $this->assertNull($old->from_name);
        $this->assertNull($old->message_id);
        $this->assertSame([InboundEmail::STATUS_POSTED, $user->id, 'T', 7, 'example.org'], [$old->status, $old->user_id, $old->record_prefix, $old->record_id, $old->from_domain]);
        $this->assertNotNull($old->from_hash);

        $this->assertNull($recent->fresh()->raw_pruned_at);
        Storage::disk('private')->assertExists((string) $recent->raw_path);
    }

    public function test_mail_still_waiting_to_be_processed_is_not_pruned(): void
    {
        $waiting = $this->stored('waiting@mail.example.org', InboundEmail::STATUS_RECEIVED);
        $waiting->forceFill(['created_at' => now()->subDays(40)])->save();

        $this->artisan('inbound-mail:prune')->assertSuccessful();

        $this->assertNull($waiting->fresh()->raw_pruned_at);
    }

    public function test_old_reply_threads_are_removed(): void
    {
        $user = User::factory()->create();
        MailReplyThread::query()->create(['message_id' => 'old@example.test', 'record_prefix' => 'T', 'record_id' => 1, 'user_id' => $user->id, 'created_at' => now()->subDays(181)]);
        MailReplyThread::query()->create(['message_id' => 'new@example.test', 'record_prefix' => 'T', 'record_id' => 1, 'user_id' => $user->id, 'created_at' => now()->subDays(10)]);

        $this->artisan('inbound-mail:prune')->assertSuccessful();

        $this->assertSame(['new@example.test'], MailReplyThread::query()->pluck('message_id')->all());
    }

    public function test_the_prune_runs_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'inbound-mail:prune'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }

    public function test_the_privacy_notice_explains_replies_by_email(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee('data-privacy-email-replies', false)
            ->assertSee(__('public.legal.privacy.blade.email_replies'))
            ->assertSee('30 days');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stored(string $messageId, string $status, array $attributes = []): InboundEmail
    {
        $email = app(InboundMailStore::class)->store($this->fixture('plain-reply', ['MESSAGE_ID' => $messageId]));
        $this->assertNotNull($email);
        $email->forceFill(['status' => $status, 'processed_at' => now(), ...$attributes])->save();

        return $email->fresh();
    }
}
