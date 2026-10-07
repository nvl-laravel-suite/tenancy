<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Tests\Fixtures\ArrayTenantDirectory;
use Nvl\Tenancy\Tests\Fixtures\InMemoryMaintenanceMode;
use Nvl\Tenancy\Tests\Fixtures\TestPlatformAccess;
use Orchestra\Testbench\TestCase;

/** Runs real adoption DDL outside any outer test transaction. */
abstract class TenancyDatabaseTestCase extends TestCase
{
    use DatabaseMigrations;

    /**
     * Enable the opted fixture before registration installs runtime queue hooks.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        $app['config']->set('nvl-tenancy.enabled', true);

        return [
            LocaleServiceProvider::class, SupportServiceProvider::class, DataServiceProvider::class, TenancyServiceProvider::class];
    }

    /** Configure the real opt-in core schema and explicit test authorization. */
    protected function defineEnvironment($app): void
    {
        $driver = getenv('NVL_FULL_DATABASE') === '1' ? (getenv('DB_CONNECTION') ?: 'sqlite') : 'sqlite';
        $database = $driver === 'sqlite' ? ':memory:' : (getenv('DB_DATABASE') ?: 'testing');

        $app['config']->set([
            'database.default' => $driver,
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'database.connections.'.$driver.'.database' => $database,
            'database.connections.'.$driver.'.url' => null,
            'queue.batching.database' => $driver,
            'queue.connections.database.connection' => $driver,
            'queue.failed.database' => $driver,
            'nvl-tenancy.enabled' => true,
            'nvl-tenancy.migrations.enabled' => true,
        ]);
        $app->instance(TenantDirectory::class, new ArrayTenantDirectory([]));
        $app->instance(PlatformAccess::class, new TestPlatformAccess);
        $app->instance(MaintenanceMode::class, new InMemoryMaintenanceMode);
    }
}
