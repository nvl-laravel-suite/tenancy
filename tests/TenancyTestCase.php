<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests;

use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots the inert Tenancy package against an isolated SQLite application.
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
        $app['config']->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => ':memory:',
            'queue.batching.database' => 'sqlite',
            'queue.connections.database.connection' => 'sqlite',
            'queue.failed.database' => 'sqlite',
            'tenancy.enabled' => false,
            'tenancy.migrations.enabled' => false,
        ]);
    }
}
