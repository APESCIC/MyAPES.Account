<?php

namespace Tests\Feature;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class NonAdminRolesAdminRouteDenialTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every /admin and legacy /superadmin route. A new admin route must raise
     * this number so it is covered by the denial matrix below.
     */
    private const ADMIN_ROUTE_COUNT = 78;

    /**
     * @var array<string, string>
     */
    private const PARAMETER_VALUES = [
        'template' => 'auth.verify',
        'subCoreKey' => 'apes-cic',
        'moduleKey' => 'tickets',
        'slug' => 'apes-cic',
        'listKey' => 'service_areas',
        'itemKey' => 'other',
        'tab' => 'reply-mailbox',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'staff' => [User::ROLE_STAFF],
            'volunteer' => [User::ROLE_VOLUNTEER],
            'student' => [User::ROLE_STUDENT],
        ];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_role_is_forbidden_on_every_admin_route(string $accessLevel): void
    {
        $this->assertFalse(app()->isDownForMaintenance());

        $this->actingAs(User::factory()->accessLevel($accessLevel)->create());

        foreach ($this->adminRequests() as [$method, $uri]) {
            $status = $this->call($method, $uri)->getStatusCode();

            $this->assertSame(
                403,
                $status,
                "{$accessLevel} {$method} /{$uri} returned {$status}, expected 403.",
            );
        }
    }

    #[DataProvider('nonAdminRoles')]
    public function test_role_holds_no_admin_permission(string $accessLevel): void
    {
        $user = User::factory()->accessLevel($accessLevel)->create();
        $this->actingAs($user);
        $adminPermissions = Permission::query()
            ->where('name', 'like', 'admin.%')
            ->pluck('name');

        $this->assertNotEmpty($adminPermissions);

        foreach ($adminPermissions as $permission) {
            $this->assertFalse(
                $user->can($permission),
                "{$accessLevel} unexpectedly holds {$permission}.",
            );
        }
    }

    public function test_admin_route_inventory_is_pinned(): void
    {
        $this->assertCount(self::ADMIN_ROUTE_COUNT, $this->adminRoutes());
    }

    /**
     * @return list<RoutingRoute>
     */
    private function adminRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RoutingRoute $route): bool => preg_match('#^(admin|superadmin)(/|$)#', $route->uri()) === 1,
        ));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function adminRequests(): array
    {
        $requests = [];

        foreach ($this->adminRoutes() as $route) {
            $uri = preg_replace_callback(
                '/\{(\w+)\??\}/',
                static fn (array $match): string => self::PARAMETER_VALUES[$match[1]] ?? '1',
                $route->uri(),
            );

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $requests[] = [$method, $uri];
            }
        }

        return $requests;
    }
}
