<?php

namespace Tests\Feature\Admin;

use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Core\Settings\AppSettings;
use App\Core\Settings\GeneralSettings;
use App\Services\AuthorizationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin → Settings → General (#734).
 */
class GeneralSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'support_email' => 'support@example.org',
            'support_phone' => '+44 (0)1234 567890',
            'registration_enabled' => '1',
            'banner_enabled' => '0',
            'banner_text' => '',
            ...$overrides,
        ];
    }

    public function test_the_tab_shows_the_defaults_summaries_and_links(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings.show', 'general'))
            ->assertOk()
            ->assertSee('data-general-settings-form', false)
            ->assertSee('name="registration_enabled" value="1" checked', false)
            ->assertSee('data-general-retention', false)
            ->assertSee(trans_choice('admin.settings.general.days', 180, ['count' => 180]))
            ->assertSee('data-general-uploads', false)
            ->assertSee('data-general-link="emails_import"', false)
            ->assertSee(route('admin.emails.import.index'), false)
            ->assertSee(route('admin.maintenance.index'), false)
            ->assertSee(route('admin.organisation-modules.index'), false)
            ->assertSee(route('admin.emails.inbound.index'), false)
            ->assertDontSee('data-reply-mailbox-form', false);
    }

    public function test_people_who_are_not_administrators_cannot_save(): void
    {
        $staff = User::factory()->protectedRole(AuthorizationProfile::ROLE_STAFF)->create();

        $this->actingAs($staff)->put(route('admin.settings.general.update'), $this->form(['registration_enabled' => '0']))->assertForbidden();
        $this->assertTrue(app(GeneralSettings::class)->registrationOpen());
    }

    public function test_an_administrator_saves_the_settings_and_the_audit_lists_changed_keys_only(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.general.update'), $this->form([
                'registration_enabled' => '0',
                'banner_enabled' => '1',
                'banner_text' => 'Planned maintenance on Saturday morning.',
            ]))
            ->assertRedirect(route('admin.settings.show', 'general'))
            ->assertSessionHas('status', __('admin.settings.general.saved'));

        $general = app(GeneralSettings::class);
        $this->assertFalse($general->registrationOpen());
        $this->assertSame('Planned maintenance on Saturday morning.', $general->banner());
        $this->assertSame('support@example.org', $general->supportEmail());
        $this->assertSame('+44 (0)1234 567890', $general->supportPhone());

        $audit = AuditLog::query()->where('event', 'admin.settings.general_saved')->sole();
        $this->assertSame(['support_email', 'support_phone', 'registration_enabled', 'banner_enabled', 'banner_text'], $audit->context['changed']);
        $this->assertSame(['changed'], array_keys($audit->context));
        $this->assertStringNotContainsString('support@example.org', (string) json_encode($audit->context));

        $this->actingAs($this->admin)
            ->put(route('admin.settings.general.update'), $this->form([
                'registration_enabled' => '0',
                'banner_enabled' => '0',
                'banner_text' => 'Planned maintenance on Saturday morning.',
            ]))
            ->assertSessionHas('status', __('admin.settings.general.saved'));

        $second = AuditLog::query()->where('event', 'admin.settings.general_saved')->latest('id')->first();
        $this->assertSame(['banner_enabled'], $second->context['changed']);
        $this->assertNull($general->banner());
    }

    public function test_saving_nothing_new_writes_no_audit(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.general.update'), $this->form());
        $this->actingAs($this->admin)->put(route('admin.settings.general.update'), $this->form())
            ->assertSessionHas('status', __('admin.settings.general.no_changes'));

        $this->assertSame(1, AuditLog::query()->where('event', 'admin.settings.general_saved')->count());
    }

    public function test_validation_messages_are_in_uk_english(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.settings.show', 'general'))
            ->put(route('admin.settings.general.update'), $this->form([
                'support_email' => 'not-an-address',
                'support_phone' => 'call me maybe',
                'banner_enabled' => '1',
                'banner_text' => '',
            ]))
            ->assertRedirect(route('admin.settings.show', 'general'))
            ->assertSessionHasErrors([
                'support_email' => __('admin.settings.general.validation.support_email'),
                'support_phone' => __('admin.settings.general.validation.support_phone'),
                'banner_text' => __('admin.settings.general.validation.banner_text_required'),
            ]);

        $this->actingAs($this->admin)
            ->put(route('admin.settings.general.update'), $this->form(['banner_text' => str_repeat('a', GeneralSettings::BANNER_MAX_LENGTH + 1)]))
            ->assertSessionHasErrors(['banner_text' => 'The banner text field must not be greater than 500 characters.']);

        $this->assertFalse(app(AppSettings::class)->stored(GeneralSettings::GROUP, 'support_email'));
    }

    public function test_blank_contact_details_clear_the_saved_ones(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.general.update'), $this->form());
        $this->actingAs($this->admin)->put(route('admin.settings.general.update'), $this->form(['support_email' => '', 'support_phone' => '']));

        $this->assertNull(app(GeneralSettings::class)->supportEmail());
        $this->assertNull(app(GeneralSettings::class)->supportPhone());
    }
}
