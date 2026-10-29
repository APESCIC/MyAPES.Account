<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use App\Support\ChangeLogPresenter;
use App\Support\ReleaseHistoryRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChangeLogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_read_the_public_progressive_change_log(): void
    {
        $response = $this->get('/change-log');
        $publicReleases = $this->publicReleases();
        $publicVersions = array_column($publicReleases, 'version');

        $response
            ->assertOk()
            ->assertSeeText('Change Log Hub')
            ->assertSeeText('Feedback & source')
            ->assertSeeText('Current version v0.43.6')
            ->assertSee('data-change-log', false)
            ->assertSee('data-change-log-controls hidden', false)
            ->assertSee('href="#release-v0-43-6"', false)
            ->assertSee('<details', false)
            ->assertSeeText('Recruitment application model and status workflow')
            ->assertSeeText('Public recruitment roles board')
            ->assertSeeText('Recruitment role staff CRUD on APES CIC')
            ->assertSeeText('Staff lists show empty states and list first')
            ->assertSeeText('Staff create forms explain an empty pet dropdown')
            ->assertSeeText('Staff hub Create and View use distinct URLs')
            ->assertSeeText('Smaller Spike and logo images')
            ->assertSeeText('Add SECURITY.md with a private reporting path')
            ->assertSeeText('Public chrome uses MyAPES Core consistently')
            ->assertSeeText('Register stores a visible consent timestamp')
            ->assertSeeText('Public privacy, cookies, help, and terms pages')
            ->assertSeeText('Redirect stale Super Admin plugins and groups URLs')
            ->assertSeeText('Hide Internal-only changelog notes from guests and public')
            ->assertSeeText('Read-only account email on profile')
            ->assertSeeText('Signed-in local public password change')
            ->assertSeeText('Public local forgot-password')
            ->assertSeeText('Admin reset of local public passwords')
            ->assertSeeText('Harden public frontends vs Cloudron accounts')
            ->assertSeeText('Simplify Access admin for groups and job roles')
            ->assertSeeText('Seed default organisational job roles')
            ->assertSeeText('Mirror Cloudron directory disable during user sync')
            ->assertSeeText('Catalogue and list only myapesaccount Cloudron groups')
            ->assertSeeText('Exclude delete abilities for volunteers and students')
            ->assertSeeText('Accept misspelled Cloudron volunteer group names')
            ->assertSeeText('Harden merge and deploy reliability gates')
            ->assertSeeText('Make LDAP group rename migration idempotent')
            ->assertSeeText('Accept legacy myapes LDAP groups during deploy readiness')
            ->assertSeeText('Fix deploy preflight for authorization guard upgrade')
            ->assertSeeText('Migrate LDAP groups to myapesaccount prefix with volunteer and student roles')
            ->assertSeeText('Accept plural Cloudron superadmins group')
            ->assertSeeText('Core, Services, and Plugins product language')
            ->assertSeeText('Clearer directory groups on staff profile')
            ->assertSeeText('APES CIC tickets, cases and module settings')
            ->assertSeeText('Service hub dashboards and public wording')
            ->assertSeeText('Compact reporting range controls on admin overviews')
            ->assertSeeText('Polish Spike tip dock dismiss and avatar')
            ->assertSeeText('Group dashboard service totals by subcore panels')
            ->assertSeeText('Clearer Admin Modules registry layout')
            ->assertSeeText('Agent release metadata prepare command')
            ->assertSeeText('Fix sub-core hub Recent activity layout')
            ->assertSeeText('Separate public and staff profiles')
            ->assertSeeText('Desert theme and Spike helper')
            ->assertSeeText('APES Pet Care Clinic modules')
            ->assertSeeText('APES CIC Tickets and Cases');

        $this->assertGuestOrPublicAudience($response, $publicVersions);
        $this->assertPublicGithubLinks($response);

        $this->assertProgressiveDetailsContainReleaseContent($response);
    }

    public function test_signed_in_public_accounts_see_the_same_public_change_log_without_internal_only_controls(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();
        $publicVersions = array_column($this->publicReleases(), 'version');

        $response = $this->actingAs($user)->get('/change-log');

        $response
            ->assertOk()
            ->assertSeeText('Current version v0.43.6');

        $this->assertGuestOrPublicAudience($response, $publicVersions);
        $this->assertPublicGithubLinks($response);
    }

    public function test_staff_and_admin_can_read_internal_only_change_log_notes_and_links(): void
    {
        $allVersions = array_column(app(ReleaseHistoryRepository::class)->all(), 'version');

        foreach ([User::ROLE_STAFF, User::ROLE_ADMIN] as $role) {
            $user = User::factory()->accessLevel($role)->create();

            $response = $this->actingAs($user)->get('/change-log');

            $response
                ->assertOk()
                ->assertSeeText('Current version v0.43.6')
                ->assertSee('data-change-log-filter="internal-only"', false)
                ->assertSeeText('Internal-only')
                ->assertSeeText('Rollback notes')
                ->assertSeeText('Remediate development nanoid advisory')
                ->assertSeeText('GHSA-2v37-7h3g-55p8')
                ->assertSeeText('Authorization access matrix for Cloudron groups')
                ->assertSeeText('Document live public walkthrough account')
                ->assertSeeText('Deployment and local setup foundation')
                ->assertSee('https://github.com/APESCIC/MyAPES-Account/issues/133', false)
                ->assertSee('https://github.com/APESCIC/MyAPES-Account/pull/165', false)
                ->assertSee('https://github.com/APESCIC/MyAPES-Account/issues/122', false)
                ->assertSee('https://github.com/APESCIC/MyAPES-Account/pull/164', false)
                ->assertSee('https://github.com/APESCIC/MyAPES-Account/issues/148', false)
                ->assertSee('https://github.com/APESCIC/MyAPES-Account/pull/163', false)
                ->assertSee('https://github.com/APESCIC/MyAPES-Account/releases/tag/v0.31.10', false);

            $this->assertPublicGithubLinks($response);

            $this->assertSame(
                count($allVersions),
                substr_count($response->getContent(), 'data-release-record'),
            );

            foreach ($allVersions as $version) {
                $response->assertSeeText("v{$version}");
            }

            $this->post(route('auth.logout'));
        }
    }

    public function test_public_and_staff_accounts_can_read_the_change_log(): void
    {
        foreach ([User::ROLE_SERVICE_USER, User::ROLE_STAFF] as $role) {
            $user = User::factory()->accessLevel($role)->create();

            $this->actingAs($user)
                ->get('/change-log')
                ->assertOk()
                ->assertSeeText('Current version v0.43.6');

            $this->post(route('auth.logout'));
        }
    }

    public function test_shared_layout_exposes_the_accessible_current_version_link(): void
    {
        foreach (['/', '/register', '/staff/login', '/change-log'] as $path) {
            $response = $this->get($path);

            $response
                ->assertOk()
                ->assertSee('href="'.route('change-log.index').'"', false)
                ->assertSee('aria-label="View the MyAPES Core change log for version v0.43.6"', false)
                ->assertSeeText('v0.43.6');

            $this->assertPublicGithubLinks($response);
        }

        $loginResponse = $this->get('/login');

        $loginResponse
            ->assertOk()
            ->assertSee('href="'.route('change-log.index').'"', false)
            ->assertSeeText('v0.43.6');

        $this->assertPublicGithubLinks($loginResponse);

        $user = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();

        $dashboardResponse = $this->actingAs($user)->get('/dashboard');

        $dashboardResponse
            ->assertOk()
            ->assertSee('href="'.route('change-log.index').'"', false)
            ->assertSeeText('v0.43.6');

        $this->assertPublicGithubLinks($dashboardResponse);
    }

    private function assertPublicGithubLinks(TestResponse $response): void
    {
        $response
            ->assertSee('sidebar-support', false)
            ->assertSeeText('App Support')
            ->assertSee('https://github.com/APESCIC/MyAPES-Account"', false)
            ->assertSee('https://github.com/APESCIC/MyAPES-Account/issues/new/choose"', false)
            ->assertSee('https://github.com/APESCIC/MyAPES-Account/discussions"', false)
            ->assertSeeText('GitHub')
            ->assertSeeText('Open an issue')
            ->assertSeeText('Discussions')
            ->assertSee('site-footer__links', false)
            ->assertSee('href="'.route('privacy').'"', false)
            ->assertSee('href="'.route('cookies').'"', false)
            ->assertSee('href="'.route('help').'"', false)
            ->assertSee('href="'.route('terms').'"', false);
    }

    /**
     * @param  list<string>  $publicVersions
     */
    private function assertGuestOrPublicAudience(TestResponse $response, array $publicVersions): void
    {
        $content = $response->getContent();

        $response
            ->assertDontSee('data-change-log-filter="internal-only"', false)
            ->assertDontSeeText('Rollback notes')
            ->assertDontSeeText('Remediate development nanoid advisory')
            ->assertDontSeeText('GHSA-2v37-7h3g-55p8')
            ->assertDontSeeText('Authorization access matrix for Cloudron groups')
            ->assertDontSeeText('Document live public walkthrough account')
            ->assertDontSeeText('Document local preview standard for agents')
            ->assertDontSeeText('Enable or disable Cloudron groups for app access')
            ->assertDontSeeText('Admin Super Admin panel and overview chart fix')
            ->assertDontSeeText('Deployment and local setup foundation')
            ->assertDontSee('https://github.com/APESCIC/MyAPES-Account/issues/133', false)
            ->assertDontSee('https://github.com/APESCIC/MyAPES-Account/pull/165', false)
            ->assertDontSee('https://github.com/APESCIC/MyAPES-Account/issues/122', false)
            ->assertDontSee('https://github.com/APESCIC/MyAPES-Account/pull/164', false);

        $this->assertPublicGithubLinks($response);

        $this->assertSame(count($publicVersions), substr_count($content, 'data-release-record'));
        $this->assertStringNotContainsString('>Internal-only<', $content);
        $this->assertStringNotContainsString('>Internal only<', $content);

        foreach ($publicVersions as $version) {
            $response->assertSeeText("v{$version}");
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publicReleases(): array
    {
        return ChangeLogPresenter::forPublicAudience(
            app(ReleaseHistoryRepository::class)->all(),
        );
    }

    private function assertProgressiveDetailsContainReleaseContent(TestResponse $response): void
    {
        $content = $response->getContent();
        $firstDetails = strpos($content, '<details');
        $firstChange = strpos($content, 'Make the myapes-to-myapesaccount directory group rename migration');

        $this->assertNotFalse($firstDetails);
        $this->assertNotFalse($firstChange);
        $this->assertGreaterThan($firstDetails, $firstChange);
    }
}
