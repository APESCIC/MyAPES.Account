<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Services\ModuleInstallationSynchronizer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Models\SupportTicketMessage;
use Tests\TestCase;

class ArchiveQaDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
        Storage::fake('local');
    }

    public function test_dry_run_lists_matches_and_changes_nothing(): void
    {
        [$walkthroughTicket, $realTicket, $qaUser] = $this->fixtures();

        $this->artisan('myapes:qa-data:archive')
            ->expectsOutputToContain('Walkthrough test ticket')
            ->expectsOutputToContain('Dry run: 3 record(s) would be archived and deleted.')
            ->assertSuccessful();

        $this->assertModelExists($walkthroughTicket);
        $this->assertModelExists($realTicket);
        $this->assertModelExists($qaUser);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_force_archives_then_removes_only_test_data(): void
    {
        [$walkthroughTicket, $realTicket, $qaUser, $walkthroughAccount] = $this->fixtures();

        $this->artisan('myapes:qa-data:archive', ['--force' => true])
            ->expectsOutputToContain('Archived 3 record(s)')
            ->assertSuccessful();

        $this->assertModelMissing($walkthroughTicket);
        $this->assertModelMissing($qaUser);
        $this->assertSame(0, SupportTicketMessage::query()->where('support_ticket_id', $walkthroughTicket->id)->count());
        $this->assertModelExists($realTicket);
        $this->assertModelExists($walkthroughAccount);

        $files = Storage::disk('local')->files('qa-data-archive');
        $this->assertCount(1, $files);
        $archive = json_decode((string) Storage::disk('local')->get($files[0]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            ['Walkthrough test ticket — please ignore', 'QA Seed: Documentation follow-up'],
            array_column($archive['tickets'], 'subject'),
        );
        $this->assertSame('Thanks, testing the walkthrough.', $archive['tickets'][0]['messages'][0]['message']);
        $this->assertSame(['qa.service.user@myapes.local'], array_column($archive['users'], 'email'));
    }

    public function test_reports_when_nothing_matches(): void
    {
        $this->artisan('myapes:qa-data:archive', ['--force' => true])
            ->expectsOutputToContain('No walkthrough or QA seed data found.')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_production_seeding_never_creates_qa_accounts(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame(0, User::query()->where('email', 'like', '%@myapes.local')->count());
        $this->assertSame(0, SupportTicket::query()->where('subject', 'like', 'QA Seed:%')->count());
    }

    /**
     * @return array{0: SupportTicket, 1: SupportTicket, 2: User, 3: User}
     */
    private function fixtures(): array
    {
        $walkthroughAccount = User::factory()->create(['email' => 'developer@apes.org.uk', 'username' => 'walkthrough-public']);
        $qaUser = User::factory()->create(['email' => 'qa.service.user@myapes.local']);
        $realOwner = User::factory()->create(['email' => 'someone@example.org']);

        $walkthroughTicket = $this->ticket($walkthroughAccount, 'Walkthrough test ticket — please ignore');
        SupportTicketMessage::query()->create([
            'support_ticket_id' => $walkthroughTicket->id,
            'user_id' => $walkthroughAccount->id,
            'message' => 'Thanks, testing the walkthrough.',
            'is_staff_note' => false,
        ]);
        $this->ticket($qaUser, 'QA Seed: Documentation follow-up');
        $realTicket = $this->ticket($realOwner, 'Website contact form is broken');

        return [$walkthroughTicket, $realTicket, $qaUser, $walkthroughAccount];
    }

    private function ticket(User $owner, string $subject): SupportTicket
    {
        return SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'operations_facilities',
            'sub_category' => 'premises',
            'subject' => $subject,
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Fixture.',
        ]);
    }
}
