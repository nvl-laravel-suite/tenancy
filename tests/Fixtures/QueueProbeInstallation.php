<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use RuntimeException;

/** Activates empty queue model storage through the real reviewed adoption protocol. */
final class QueueProbeInstallation
{
    public static function install(): void
    {
        config()->set(['tenancy.enabled' => true, 'tenancy.migrations.enabled' => true, 'tenancy.directory.driver' => 'host']);
        (new TenancyServiceProvider(app()))->boot();
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        if (! Schema::hasTable('tenancy_test_records')) {
            Schema::create('tenancy_test_records', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->uuid('parent_id')->nullable();
                $table->softDeletes();
            });
        }
        app()->instance(PlatformAccess::class, new TestPlatformAccess);
        app()->instance(MaintenanceMode::class, new InMemoryMaintenanceMode);
        app(TenantAdoptionRegistry::class)->register('tests', RecordAdoptionAdapter::class);
        $operation = new PlatformOperation('Queue fixture adoption', 'test', 'queue-probe');
        $coordinator = app(TenantAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['tests'], [], $operation);
        if (! $coordinator->backfill($plan, 50, $operation) || ! $coordinator->verify($plan)->passed()) {
            throw new RuntimeException('Queue fixture adoption did not verify.');
        }
        $coordinator->activate($plan, $operation);
        app(MaintenanceMode::class)->deactivate();
    }
}
