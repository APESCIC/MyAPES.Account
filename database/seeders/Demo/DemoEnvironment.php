<?php

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Decides whether demo data may be written. Demo data is local-only: it must never
 * run in production, on Cloudron or against any database but this project's own.
 */
final class DemoEnvironment
{
    /** @var list<string> */
    private const CLOUDRON_VARIABLES = ['CLOUDRON', 'CLOUDRON_APP_ORIGIN', 'CLOUDRON_MYSQL_HOST'];

    public static function refusalReason(): ?string
    {
        if (! app()->environment('local')) {
            return sprintf(
                'Refusing to load demo data: APP_ENV is "%s". Demo data only runs with APP_ENV=local.',
                app()->environment(),
            );
        }

        foreach (self::CLOUDRON_VARIABLES as $variable) {
            $value = $_SERVER[$variable] ?? $_ENV[$variable] ?? getenv($variable);
            if ($value !== false && $value !== null && $value !== '') {
                return "Refusing to load demo data: {$variable} is set, so this looks like Cloudron.";
            }
        }

        $connection = DB::connection();
        if ($connection->getDriverName() === 'mysql'
            && ! str_ends_with((string) $connection->getDatabaseName(), '_local')) {
            return sprintf(
                'Refusing to load demo data: the database "%s" is not a local database (its name must end in _local).',
                $connection->getDatabaseName(),
            );
        }

        return null;
    }

    public static function assertAllowed(): void
    {
        $reason = self::refusalReason();

        if ($reason !== null) {
            throw new LogicException($reason);
        }
    }
}
