<?php

namespace Tests\Feature;

use App\Core\Access\GroupAccessMatrix;
use App\Core\Accounts\AuditLog;
use App\Core\Accounts\User;
use App\Services\ModuleInstallationSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleInstallationSynchronizer::class)->synchronize();
    }

    public function test_defaults_match_todays_access(): void
    {
        $rows = collect(app(GroupAccessMatrix::class)->rows())->keyBy('key');

        $this->assertSame(
            ['staff' => true, 'volunteer' => true, 'student' => true],
            $rows['apes-cic:tickets']['groups'],
        );
        $this->assertSame(
            ['staff' => null, 'volunteer' => null, 'student' => null],
            $rows['apes-cic:recruitment']['groups'],
        );

        $student = User::factory()->accessLevel(User::ROLE_STUDENT)->create();
        $this->actingAs($student)
            ->get(route('apes-cic.tickets.index'))
            ->assertOk();
    }

    public function test_admin_sees_the_matrix_tab(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.access.index', ['tab' => 'matrix']))
            ->assertOk()
            ->assertSee(__('admin.access.matrix_title'))
            ->assertSee('name="allowed[student][apes-cic:tickets]"', false)
            ->assertDontSee('name="allowed[student][apes-cic:recruitment]"', false);
    }

    public function test_turning_a_plugin_off_for_students_hides_it_and_returns_403(): void
    {
        $admin = $this->admin();
        $student = User::factory()->accessLevel(User::ROLE_STUDENT)->create();
        $ticketsLink = 'href="'.route('apes-cic.tickets.index').'"';

        $this->actingAs($student)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($ticketsLink, false);

        $this->actingAs($admin)
            ->put(route('admin.access.matrix.update'), [
                'allowed' => $this->allDefaultsExcept('student', 'apes-cic:tickets'),
            ])
            ->assertRedirect(route('admin.access.index', ['tab' => 'matrix']))
            ->assertSessionHas('status', __('admin.flash.access_matrix_updated'));

        $this->assertDatabaseHas('group_plugin_access', [
            'role_name' => 'student',
            'sub_core_key' => 'apes-cic',
            'module_key' => 'tickets',
            'allowed' => false,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($student)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee($ticketsLink, false);
        $this->actingAs($student)
            ->get(route('apes-cic.tickets.index'))
            ->assertForbidden();
        $this->assertFalse($student->can('apes-cic.tickets.view-all'));

        $volunteer = User::factory()->accessLevel(User::ROLE_VOLUNTEER)->create();
        $this->actingAs($volunteer)
            ->get(route('apes-cic.tickets.index'))
            ->assertOk();
    }

    public function test_admins_keep_full_access_when_every_group_is_switched_off(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.access.matrix.update'), ['allowed' => []])
            ->assertRedirect();

        $this->assertTrue($admin->can('apes-cic.tickets.view-all'));
        $this->assertTrue($admin->can('apes-cic.recruitment.view-all'));
        $this->actingAs($admin)
            ->get(route('apes-cic.tickets.index'))
            ->assertOk();

        $staff = User::factory()->accessLevel(User::ROLE_STAFF)->create();
        $this->actingAs($staff)
            ->get(route('apes-cic.tickets.index'))
            ->assertForbidden();
    }

    public function test_each_change_is_audit_logged_and_switching_back_on_restores_access(): void
    {
        $admin = $this->admin();
        $student = User::factory()->accessLevel(User::ROLE_STUDENT)->create();

        $this->actingAs($admin)->put(route('admin.access.matrix.update'), [
            'allowed' => $this->allDefaultsExcept('student', 'apes-cic:tickets'),
        ]);
        $this->actingAs($admin)->put(route('admin.access.matrix.update'), [
            'allowed' => $this->allDefaultsExcept('student', 'apes-cic:tickets'),
        ])->assertSessionHas('status', __('admin.flash.access_matrix_unchanged'));
        $this->actingAs($admin)->put(route('admin.access.matrix.update'), [
            'allowed' => $this->allDefaultsExcept(null, null),
        ]);

        $events = AuditLog::query()
            ->where('event', 'authorization.access_matrix_updated')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame($admin->id, $events[0]->user_id);
        $this->assertSame(
            ['student', 'apes-cic', 'tickets', 'on', 'off'],
            [
                $events[0]->context['group'],
                $events[0]->context['sub_core_key'],
                $events[0]->context['module_key'],
                $events[0]->context['before'],
                $events[0]->context['after'],
            ],
        );
        $this->assertSame('on', $events[1]->context['after']);

        $this->actingAs($student)
            ->get(route('apes-cic.tickets.index'))
            ->assertOk();
    }

    public function test_non_admins_cannot_view_or_change_the_matrix(): void
    {
        foreach ([User::ROLE_STAFF, User::ROLE_VOLUNTEER, User::ROLE_STUDENT] as $accessLevel) {
            $user = User::factory()->accessLevel($accessLevel)->create();

            $this->actingAs($user)
                ->get(route('admin.access.index', ['tab' => 'matrix']))
                ->assertForbidden();
            $this->actingAs($user)
                ->put(route('admin.access.matrix.update'), ['allowed' => []])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('group_plugin_access', 0);
    }

    private function admin(): User
    {
        return User::factory()->accessLevel(User::ROLE_ADMIN)->create();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function allDefaultsExcept(?string $role, ?string $cell): array
    {
        $allowed = [];

        foreach (app(GroupAccessMatrix::class)->rows() as $row) {
            foreach ($row['groups'] as $group => $state) {
                if ($state !== null && ! ($group === $role && $row['key'] === $cell)) {
                    $allowed[$group][$row['key']] = '1';
                }
            }
        }

        return $allowed;
    }
}
