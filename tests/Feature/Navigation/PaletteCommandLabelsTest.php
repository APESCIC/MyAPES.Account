<?php

namespace Tests\Feature\Navigation;

use App\Core\Accounts\User;
use App\Support\Navigation\WorkspaceNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Command palette labels stay unique per module (#895, #896).
 */
class PaletteCommandLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_palette_labels_are_all_unique(): void
    {
        $admin = User::factory()->protectedRole('administrator')->create();
        $labels = array_column($this->paletteFor($admin, 'dashboard'), 'label');

        $this->assertSame(count($labels), count(array_unique($labels)), 'Duplicates: '.implode(', ', array_diff_key($labels, array_unique($labels))));
    }

    public function test_repeatable_item_labels_include_the_module_name(): void
    {
        $admin = User::factory()->protectedRole('administrator')->create();
        $ticketLabels = collect($this->paletteFor($admin, 'dashboard'))
            ->filter(static fn (array $command): bool => str_contains($command['label'], 'Tickets')
                && (str_starts_with($command['id'], 'top:') || str_starts_with($command['id'], 'staff:')))
            ->pluck('label')
            ->all();

        $this->assertGreaterThan(1, count($ticketLabels));

        foreach ($ticketLabels as $label) {
            $this->assertStringContainsString('—', $label);
        }
    }

    public function test_plugin_settings_palette_rows_include_the_module_name(): void
    {
        $admin = User::factory()->protectedRole('administrator')->create();
        $labels = collect($this->paletteFor($admin, 'admin.index'))
            ->filter(static fn (array $command): bool => str_starts_with($command['id'], 'search:settings.'))
            ->pluck('label')
            ->all();

        $this->assertNotSame([], $labels);
        $this->assertSame($labels, array_values(array_unique($labels)));

        foreach ($labels as $label) {
            $this->assertStringContainsString(__('terms.plugin_settings'), $label);
            $this->assertGreaterThanOrEqual(2, substr_count($label, '—'));
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function paletteFor(User $user, string $routeName): array
    {
        $request = Request::create(route($routeName));
        $request->setLaravelSession(app('session.store'));
        $route = app('router')->getRoutes()->match($request);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);
        $this->app->instance('request', $request);
        $this->actingAs($user);

        return app(WorkspaceNavigation::class)->forRequest($user, $request)->paletteCommands();
    }
}