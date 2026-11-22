<?php

namespace App\Core\Push;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds a {@see PushTarget} from an in-app URL (#798).
 */
final class PushTargetFromUrl
{
    public static function resolve(string $url, string $referenceLabel = ''): PushTarget
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return new PushTarget('dashboard', [], self::referenceLabel($referenceLabel));
        }

        try {
            $request = Request::create($path);
            $route = app('router')->getRoutes()->match($request);
            $routeName = (string) $route->getName();
            $parameters = $route->parameters();

            return new PushTarget(
                $routeName,
                self::routeParameters($parameters),
                self::referenceLabel($referenceLabel, $parameters),
                app(PushRouteServiceLabelRegistry::class)->resolve($routeName, $parameters),
            );
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return new PushTarget('dashboard', [], self::referenceLabel($referenceLabel));
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private static function routeParameters(array $parameters): array
    {
        $resolved = [];
        foreach ($parameters as $key => $value) {
            $resolved[$key] = is_object($value) && method_exists($value, 'getKey')
                ? $value->getKey()
                : $value;
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private static function referenceLabel(string $referenceLabel, array $parameters = []): string
    {
        if (preg_match('/#(\d+)/', $referenceLabel, $matches) === 1) {
            return $matches[1];
        }

        foreach (['ticket', 'case', 'consultation', 'recruitmentApplication'] as $key) {
            if (! array_key_exists($key, $parameters)) {
                continue;
            }

            $value = $parameters[$key];

            return (string) (is_object($value) && method_exists($value, 'getKey') ? $value->getKey() : $value);
        }

        if (preg_match('/(\d+)/', $referenceLabel, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }
}
