<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Runs representative core/adoption proofs on the actual CI-selected disposable database. */
abstract class TenancySupportedDatabaseTestCase extends TenancyDatabaseTestCase
{
    /** Clear prior unprefixed fixtures before the prefixed migration repository exists. */
    protected function beforeRefreshingDatabase(): void
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        if ($connection->getDriverName() === 'sqlite' ? $database !== ':memory:' : preg_match('/^nvl_tenancy_test_[a-f0-9]{8}_ci$/', $database) !== 1) {
            throw new InvalidArgumentException('Tenancy schema cleanup requires the runner disposable database.');
        }
        $connection->getSchemaBuilder()->dropAllTables();
    }

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
        $app['config']->set('queue.batching.database', $driver);
        $app['config']->set('queue.connections.database.connection', $driver);
        $app['config']->set('queue.failed.database', $driver);
        $app['config']->set('nvl-tenancy.directory.driver', 'host');
    }
}
