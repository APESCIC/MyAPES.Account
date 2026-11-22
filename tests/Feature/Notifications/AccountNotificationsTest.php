<?php

namespace Tests\Feature\Notifications;

use App\Core\Accounts\User;
use App\Core\Push\PushSameOriginPath;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use App\Support\Notifications\AccountNotificationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Plugins\Tickets\Models\SupportTicket;
use Plugins\Tickets\Notifications\TicketUpdatedNotification;
use Plugins\Tickets\TicketsArea;
use Tests\TestCase;

/**
 * In-app notification bell and list (#801).
 */
class AccountNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_unread_count_in_shell_matches_unread_notifications(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->seedNotification($user, ['subject' => 'First'], read: false);
        $this->seedNotification($user, ['subject' => 'Second'], read: false);
        $this->seedNotification($user, ['subject' => 'Third'], read: true);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-testid="notification-unread-count"', false)
            ->assertSee('>2<', false);
    }

    public function test_users_see_only_their_own_notifications_on_the_list_page(): void
    {
        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();
        $other = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $mine = $this->seedNotification($owner, ['subject' => 'Mine only'], read: false);
        $this->seedNotification($other, ['subject' => 'Someone else']);

        $this->actingAs($owner)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Mine only')
            ->assertDontSee('Someone else');

        $this->actingAs($owner)
            ->patch(route('notifications.read', $other->notifications()->first()->id))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('notifications.open', $other->notifications()->first()->id))
            ->assertNotFound();

        $this->assertNull($mine->fresh()->read_at);
    }

    public function test_mark_read_and_mark_all_read_are_available_and_work(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $first = $this->seedNotification($user, ['subject' => 'Alpha'], read: false);
        $second = $this->seedNotification($user, ['subject' => 'Beta'], read: false);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee(__('notifications.mark_all_read'))
            ->assertSee(__('notifications.mark_read'));

        $this->actingAs($user)
            ->patch(route('notifications.read', $first->id))
            ->assertRedirect()
            ->assertSessionHas('status', __('notifications.marked_read'));

        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($second->fresh()->read_at);

        $this->actingAs($user)
            ->post(route('notifications.read-all'))
            ->assertRedirect()
            ->assertSessionHas('status', __('notifications.marked_all_read'));

        $this->assertNotNull($second->fresh()->read_at);
    }

    public function test_notification_open_link_matches_push_deep_link(): void
    {
        $client = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $ticket = SupportTicket::query()->create([
            'sub_core_key' => 'shelter-rescue',
            'user_id' => $client->id,
            'service_area' => 'rescue',
            'subject' => 'Welfare check',
            'priority' => 'medium',
            'status' => 'open',
            'description' => 'Details.',
        ]);

        $area = TicketsArea::forModule('shelter-rescue');
        $notification = new TicketUpdatedNotification(
            $ticket,
            $staff,
            'updated',
            'shelter-rescue',
            $area->showRouteName(),
            'Shelter rescue',
        );

        $database = $notification->toArray($staff);
        $pushPath = $notification->toWebPush($staff)->toArray()['data']['url'];

        $this->assertSame(
            $pushPath,
            AccountNotificationPresenter::sameOriginPath($database['url']),
        );
        $this->assertSame(
            PushSameOriginPath::fromRoute($area->showRouteName(), ['ticket' => $ticket]),
            $pushPath,
        );

        $stored = $this->seedNotification($staff, $database);
        $presented = AccountNotificationPresenter::present($stored);

        $this->actingAs($staff)
            ->get($presented['open_url'])
            ->assertRedirect($database['url']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function seedNotification(User $user, array $data, bool $read = true): \Illuminate\Notifications\DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => TicketUpdatedNotification::class,
            'data' => [
                'event' => 'updated',
                'url' => route('dashboard'),
                ...$data,
            ],
            'read_at' => $read ? now() : null,
        ]);
    }
}