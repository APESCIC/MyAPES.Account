<?php

namespace Tests\Feature\Api;

use App\Core\Api\ApiRoutes;
use App\Core\Webhooks\WebhookEvents;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * docs/api/openapi-v1.yaml matches routes/api.php (#643): every /api/v1 route
 * is in the spec with the ability it needs, the spec has no route that does
 * not exist, and every webhook event is documented.
 */
class OpenApiSpecTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spec = Yaml::parseFile(base_path('docs/api/openapi-v1.yaml'));
    }

    public function test_the_spec_and_the_routes_list_the_same_operations(): void
    {
        $this->assertSame(array_keys($this->routes()), array_keys($this->operations()), 'docs/api/openapi-v1.yaml and routes/api.php disagree.');
    }

    public function test_each_operation_names_the_ability_its_route_needs(): void
    {
        $operations = $this->operations();

        foreach ($this->routes() as $key => $ability) {
            $this->assertSame($ability, $operations[$key]['x-myapes-ability'] ?? null, $key.' ability');
        }
    }

    public function test_every_webhook_event_is_documented(): void
    {
        $documented = array_keys($this->spec['webhooks']);
        sort($documented);
        $events = WebhookEvents::all();
        sort($events);

        $this->assertSame($events, $documented);
    }

    /**
     * @return array<string, string|null> "GET /path" => ability
     */
    private function routes(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! str_starts_with($route->uri(), ApiRoutes::PREFIX.'/')) {
                continue;
            }

            $path = '/'.substr($route->uri(), strlen(ApiRoutes::PREFIX) + 1);
            $ability = null;
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'abilities:')) {
                    $ability = substr($middleware, strlen('abilities:'));
                }
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[$method.' '.$path] = $ability;
            }
        }
        ksort($routes);

        return $routes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function operations(): array
    {
        $operations = [];
        foreach ($this->spec['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                $operations[strtoupper($method).' '.$path] = $operation;
            }
        }
        ksort($operations);

        return $operations;
    }
}
