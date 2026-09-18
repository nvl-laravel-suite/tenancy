<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Tests\Fixtures\ArrayTenantDirectory;
use Nvl\Tenancy\Tests\Fixtures\InMemoryMaintenanceMode;
use Nvl\Tenancy\Tests\Fixtures\TestPlatformAccess;
use Orchestra\Testbench\TestCase;

/** Runs real adoption DDL outside any outer test transaction. */
abstract class TenancyDatabaseTestCase extends TestCase
{
    use DatabaseMigrations;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [SupportServiceProvider::class, DataServiceProvider::class, TenancyServiceProvider::class];
    }

    /** Configure the real opt-in core schema and explicit test authorization. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'queue.batching.database' => 'sqlite',
            'queue.connections.database.connection' => 'sqlite',
            'queue.failed.database' => 'sqlite',
            'tenancy.enabled' => true,
            'tenancy.migrations.enabled' => true,
        ]);
        $app->instance(TenantDirectory::class, new ArrayTenantDirectory([]));
        $app->instance(PlatformAccess::class, new TestPlatformAccess);
        $app->instance(MaintenanceMode::class, new InMemoryMaintenanceMode);
    }
}
