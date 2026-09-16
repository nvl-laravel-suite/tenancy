<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests;

use InvalidArgumentException;

/** Runs representative core/adoption proofs on the actual CI-selected disposable database. */
abstract class TenancySupportedDatabaseTestCase extends TenancyDatabaseTestCase
{
    /** Preserve the supported engine selected by CI while isolating prefixed fixture tables. */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $driver = env('DB_CONNECTION', 'sqlite');
        if (! in_array($driver, ['sqlite', 'pgsql', 'mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException('Unsupported Tenancy test database.');
        }
        $app['config']->set('database.default', $driver);
        $app['config']->set('database.connections.'.$driver.'.prefix', 'f6_');
        $app['config']->set('tenancy.directory.driver', 'host');
    }
}
