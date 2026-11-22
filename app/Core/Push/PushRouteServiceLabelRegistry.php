<?php

namespace App\Core\Push;

/**
 * Service labels for push deep links. Core owns the registry; plugins register
 * resolvers without Core importing them (#805).
 */
final class PushRouteServiceLabelRegistry
{
    /** @var list<callable(string, array<string, mixed>): ?string> */
    private array $resolvers = [];

    /**
     * @param  callable(string, array<string, mixed>): ?string  $resolver
     */
    public function register(callable $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function resolve(string $routeName, array $parameters): ?string
    {
        foreach ($this->resolvers as $resolver) {
            $label = $resolver($routeName, $parameters);

            if ($label !== null && $label !== '') {
                return $label;
            }
        }

        return null;
    }
}
