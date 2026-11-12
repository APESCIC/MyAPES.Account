<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Core\Integrations\CoreIntegrations;
use App\Core\Integrations\IntegrationRegistry;
use App\Services\AuthorizationProfile;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Plugins\Recruitment\Models\RecruitmentRole;
use Plugins\Tickets\Models\SupportTicket;
use Tests\TestCase;

/**
 * TinyMCE 8 on description fields (#483), with its key in Admin → Settings → Integrations (#636).
 */
class RichTextEditorFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app-settings.env.TINYMCE_API_KEY' => null]);
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_without_a_key_fields_stay_plain_textareas(): void
    {
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->get(route('apes-cic.tickets.index'))
            ->assertOk()
            ->assertSee('<textarea', false)
            ->assertDontSee('data-rich-text', false)
            ->assertDontSee('cdn.tiny.cloud', false);
    }

    public function test_with_a_key_the_editor_loads_from_the_cdn_using_the_configured_key(): void
    {
        config(['app-settings.env.TINYMCE_API_KEY' => 'testkeyfromenv']);
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();

        $page = $this->actingAs($user)->get(route('apes-cic.tickets.index'))->assertOk();
        $page->assertSee('data-rich-text', false)
            ->assertSee('https://cdn.tiny.cloud/1/testkeyfromenv/tinymce/8/tinymce.min.js', false);
        $this->assertSame(1, substr_count((string) $page->getContent(), 'tinymce.min.js'));
        $this->assertMatchesRegularExpression('/<script src="https:\/\/cdn\.tiny\.cloud[^"]+" referrerpolicy="origin" nonce="[^"]+"><\/script>/', (string) $page->getContent());
    }

    public function test_the_editor_loads_with_the_key_saved_in_integrations(): void
    {
        $admin = User::factory()->protectedRole(AuthorizationProfile::ROLE_ADMINISTRATOR)->create();
        $this->actingAs($admin)
            ->put(route('admin.settings.integrations.update', CoreIntegrations::TINYMCE), ['api_key' => 'testkeyfromdatabase'])
            ->assertSessionHasNoErrors();

        $this->assertSame(IntegrationRegistry::STATUS_SET, app(IntegrationRegistry::class)->status(CoreIntegrations::TINYMCE));
        $user = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $this->actingAs($user)->get(route('apes-cic.tickets.index'))
            ->assertOk()
            ->assertSee('https://cdn.tiny.cloud/1/testkeyfromdatabase/tinymce/8/tinymce.min.js', false);

        config(['app-settings.env.TINYMCE_API_KEY' => 'testkeyfromenv']);
        $this->actingAs($user)->get(route('apes-cic.tickets.index'))
            ->assertSee('https://cdn.tiny.cloud/1/testkeyfromenv/tinymce/8/tinymce.min.js', false);
    }

    public function test_the_key_is_never_hard_coded(): void
    {
        foreach ([base_path('resources'), base_path('plugins'), base_path('config'), base_path('app')] as $root) {
            foreach (File::allFiles($root) as $file) {
                $this->assertDoesNotMatchRegularExpression('#cdn\.tiny\.cloud/1/[a-z0-9]{20,}#i', $file->getContents(), $file->getRelativePathname());
            }
        }
    }

    public function test_saved_html_is_sanitised_and_rendered_safely(): void
    {
        $owner = User::factory()->protectedRole(AuthorizationProfile::ROLE_SERVICE_USER)->create();
        $ticket = SupportTicket::query()->create([
            'sub_core_key' => 'apes-cic',
            'user_id' => $owner->id,
            'service_area' => 'it_systems',
            'sub_category' => 'account_access',
            'subject' => 'Rich text ticket',
            'priority' => 'medium',
            'status' => 'open',
            'description' => '<p>My <strong>login</strong> fails</p><script>alert(1)</script><img src="x" onerror="alert(2)">',
        ]);

        $stored = $ticket->fresh()->getRawOriginal('description');
        $this->assertStringContainsString('<strong>login</strong>', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);

        $this->actingAs($owner)
            ->get(route('apes-cic.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('<strong>login</strong>', false)
            ->assertDontSee('alert(1)', false);
    }

    public function test_plain_text_keeps_line_breaks_and_is_escaped_once(): void
    {
        $creator = User::factory()->create();
        $role = RecruitmentRole::factory()->open()->create([
            'created_by' => $creator->id,
            'description' => "Line one\nTea & biscuits",
        ]);

        $this->assertSame("Line one\nTea & biscuits", $role->fresh()->getRawOriginal('description'));
        $this->get(route('recruitment.show', $role))
            ->assertOk()
            ->assertSee('Line one<br />', false)
            ->assertSee('Tea &amp; biscuits', false)
            ->assertDontSee('&amp;amp;', false);
    }
}
