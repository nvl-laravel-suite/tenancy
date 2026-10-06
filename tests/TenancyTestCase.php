<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests;

use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots the inert Tenancy package against an isolated configured application.
 */
abstract class TenancyTestCase extends Orchestra
{
    /**
     * Register Tenancy and its package foundations.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LocaleServiceProvider::class,
            SupportServiceProvider::class,
            DataServiceProvider::class,
            TenancyServiceProvider::class,
        ];
    }

    /**
     * Configure disabled tenancy without loading optional migrations.
     */
    protected function defineEnvironment($app): void
    {
        $driver = getenv('NVL_FULL_DATABASE') === '1' ? (getenv('DB_CONNECTION') ?: 'sqlite') : 'sqlite';
        $database = $driver === 'sqlite' ? ':memory:' : (getenv('DB_DATABASE') ?: 'testing');

        $app['config']->set([
            'database.default' => $driver,
            'database.connections.'.$driver.'.url' => null,
            'database.connections.'.$driver.'.database' => $database,
            'queue.batching.database' => $driver,
            'queue.connections.database.connection' => $driver,
            'queue.failed.database' => $driver,
            'nvl-tenancy.enabled' => false,
            'nvl-tenancy.migrations.enabled' => false,
        ]);
    }
}
