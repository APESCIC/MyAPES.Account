<?php

namespace Tests\Unit\Navigation;

use App\Support\Navigation\NavGroup;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavPanel;
use App\Support\Navigation\QuickAction;
use App\Support\Navigation\WorkspaceMenu;
use Tests\TestCase;

class WorkspaceMenuTest extends TestCase
{
    public function test_panel_switch_needs_both_staff_and_admin_panels(): void
    {
        $this->assertFalse($this->menu([NavPanel::STAFF => $this->staffPanel()])->showsPanelSwitch());
        $this->assertFalse($this->menu([NavPanel::ADMIN => $this->adminPanel()], NavPanel::ADMIN)->showsPanelSwitch());

        $both = $this->menu([NavPanel::STAFF => $this->staffPanel(), NavPanel::ADMIN => $this->adminPanel()]);
        $this->assertTrue($both->showsPanelSwitch());
        $this->assertSame([NavPanel::STAFF, NavPanel::ADMIN], array_map(static fn (NavPanel $panel): string => $panel->key, $both->switchablePanels()));
    }

    public function test_panel_home_is_its_first_item(): void
    {
        $this->assertSame('/dashboard', $this->staffPanel()->home()?->url);
        $this->assertSame('/admin', $this->adminPanel()->home()?->url);
    }

    public function test_breadcrumb_runs_panel_group_item_and_links_all_but_the_last(): void
    {
        $menu = $this->menu([NavPanel::STAFF => $this->staffPanel()], NavPanel::STAFF, 'module.shelter.tickets');

        $this->assertSame([
            ['label' => 'Staff', 'url' => '/dashboard'],
            ['label' => 'APES Shelter and Rescue', 'url' => '/shelter'],
            ['label' => 'Tickets', 'url' => null],
        ], $menu->breadcrumb());

        $this->assertSame([
            ['label' => 'Staff', 'url' => '/dashboard'],
            ['label' => 'APES Shelter and Rescue', 'url' => '/shelter'],
            ['label' => 'Tickets', 'url' => '/shelter/tickets'],
            ['label' => 'Ticket #42', 'url' => null],
        ], $menu->breadcrumb('Ticket #42'));
    }

    public function test_breadcrumb_drops_a_group_that_repeats_the_item(): void
    {
        $panel = new NavPanel(NavPanel::ADMIN, 'Admin', [
            new NavGroup('overview', 'Overview', [new NavItem('admin.overview', 'Overview', '/admin', 'layout-dashboard')]),
        ]);

        $this->assertSame([
            ['label' => 'Admin', 'url' => '/admin'],
            ['label' => 'Overview', 'url' => null],
        ], $this->menu([NavPanel::ADMIN => $panel], NavPanel::ADMIN, 'admin.overview')->breadcrumb('Overview'));
    }

    public function test_only_the_resolved_item_is_active(): void
    {
        $menu = $this->menu([NavPanel::STAFF => $this->staffPanel()], NavPanel::STAFF, 'dashboard');
        $active = array_filter($this->staffPanel()->items(), $menu->isActive(...));

        $this->assertSame(['dashboard'], array_values(array_map(static fn (NavItem $item): string => $item->key, $active)));
        $this->assertSame('Overview', $menu->activeGroup()?->heading);
    }

    public function test_palette_lists_quick_actions_first_then_every_panel_item_with_keywords(): void
    {
        $menu = new WorkspaceMenu(
            [NavPanel::STAFF => $this->staffPanel(), NavPanel::ADMIN => $this->adminPanel()],
            NavPanel::STAFF,
            'dashboard',
            [new QuickAction('create.shelter.tickets', 'Create ticket', '/shelter/tickets#create', 'circle-plus', 'APES Shelter and Rescue', ['new'])],
            [['id' => 'search:settings', 'label' => 'Settings', 'section' => 'Admin · Settings', 'url' => '/admin/settings', 'icon' => 'settings', 'keywords' => '']],
        );

        $commands = $menu->paletteCommands();

        $this->assertSame('action:create.shelter.tickets', $commands[0]['id']);
        $this->assertSame(__('workspace.palette.quick_actions').' · APES Shelter and Rescue', $commands[0]['section']);
        $this->assertSame(
            ['staff:dashboard', 'staff:module.shelter.home', 'staff:module.shelter.tickets', 'admin:admin.overview', 'search:settings'],
            array_column(array_slice($commands, 1), 'id'),
        );
        $this->assertStringContainsString('helpdesk', $commands[3]['keywords']);
        $this->assertSame('Staff · APES Shelter and Rescue', $commands[3]['section']);
    }

    /**
     * @param  array<string, NavPanel>  $panels
     */
    private function menu(array $panels, string $current = NavPanel::STAFF, ?string $active = null): WorkspaceMenu
    {
        return new WorkspaceMenu($panels, $current, $active);
    }

    private function staffPanel(): NavPanel
    {
        return new NavPanel(NavPanel::STAFF, 'Staff', [
            new NavGroup('overview', 'Overview', [new NavItem('dashboard', 'Dashboard', '/dashboard', 'layout-dashboard', ['dashboard'])]),
            new NavGroup('module.shelter', 'APES Shelter and Rescue', [
                new NavItem('module.shelter.home', 'Service home', '/shelter', 'house', ['shelter.*']),
                new NavItem('module.shelter.tickets', 'Tickets', '/shelter/tickets', 'ticket', ['shelter.tickets.*'], ['helpdesk']),
            ]),
        ]);
    }

    private function adminPanel(): NavPanel
    {
        return new NavPanel(NavPanel::ADMIN, 'Admin', [
            new NavGroup('overview', 'Overview', [new NavItem('admin.overview', 'Overview', '/admin', 'layout-dashboard', ['admin.index'])]),
        ]);
    }
}
