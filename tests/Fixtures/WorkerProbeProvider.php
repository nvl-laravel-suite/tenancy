<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Isolated copied consumer configuration for actual worker subprocesses. */
final class WorkerProbeProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(Repository::class)->set([
            'tenancy.enabled' => true,
            'tenancy.directory.driver' => 'host',
            'tenancy_queue_probe.persist' => true,
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'sqlite',
            'queue.connections.database.retry_after' => 1,
            'queue.failed.driver' => null,
        ]);
        $this->app->make(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', ProbeRestoredModel::class));
        $tenants = [];
        foreach (['10000000-0000-4000-8000-000000000001', '10000000-0000-4000-8000-000000000002'] as $value) {
            $id = new TenantId($value);
            $tenants[$value] = new TenantDescriptor($id, TenantStatus::Active);
        }
        $this->app->instance(TenantDirectory::class, new ArrayTenantDirectory($tenants));
    }

    public function boot(): void
    {
        Queue::looping(static function (): void {
            DB::table('worker_scopes')->insert(['pid' => getmypid(), 'mode' => app(TenantContext::class)->snapshot()->mode->value]);
        });
        $this->app->terminating(static function (): void {
            DB::table('worker_scopes')->insert(['pid' => getmypid(), 'mode' => app(TenantContext::class)->snapshot()->mode->value]);
        });
    }
}
