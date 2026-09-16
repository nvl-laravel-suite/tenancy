<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Tenancy\Actions\ChangeTenantStatusAction;
use Nvl\Tenancy\Actions\ProvisionTenantAction;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantInactive;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Services\DenyPlatformAccess;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\Tests\Fixtures\ArrayTenantDirectory;
use Nvl\Tenancy\Tests\Fixtures\InMemoryMaintenanceMode;
use Nvl\Tenancy\Tests\Fixtures\TestPlatformAccess;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** @return list<string> */
function tenancyCoreTables(): array
{
    return [
        'nvl_tenancy_tenants',
        'nvl_tenancy_installation_state',
        'nvl_tenancy_operations',
        'nvl_tenancy_adoption_runs',
        'nvl_tenancy_adoption_mappings',
    ];
}

function tenancyCoreConnection(): Connection
{
    return app(EffectiveTenantConnection::class)->core();
}

function tenancyCoreSchema(): Builder
{
    return tenancyCoreConnection()->getSchemaBuilder();
}

function registerAndRunTenancyCoreMigrations(): void
{
    config()->set('tenancy.migrations.enabled', true);
    (new TenancyServiceProvider(app()))->boot();
    Artisan::call('migrate', ['--force' => true]);
}

beforeEach(function (): void {
    app()->instance(MaintenanceMode::class, new InMemoryMaintenanceMode);
});

it('keeps the core schema absent from default and feature-only migrations', function (bool $featureEnabled): void {
    config()->set('tenancy.enabled', $featureEnabled);
    (new TenancyServiceProvider(app()))->boot();

    Artisan::call('migrate', ['--force' => true]);

    foreach (tenancyCoreTables() as $table) {
        expect(tenancyCoreSchema()->hasTable($table))->toBeFalse();
    }
})->with([false, true]);

it('registers all five core tables only on the configured connection', function (): void {
    config()->set('database.connections.tenancy_core', [
        ...config('database.connections.sqlite'),
        'database' => ':memory:',
    ]);
    config()->set('tenancy.connection', 'tenancy_core');

    registerAndRunTenancyCoreMigrations();

    foreach (tenancyCoreTables() as $table) {
        expect(tenancyCoreSchema()->hasTable($table))->toBeTrue()
            ->and(DB::connection()->getSchemaBuilder()->hasTable($table))->toBeFalse();
    }

    expect(tenancyCoreSchema()->getColumnListing('nvl_tenancy_tenants'))
        ->toBe(['id', 'name', 'status', 'created_at', 'updated_at'])
        ->and(tenancyCoreSchema()->getColumnListing('nvl_tenancy_installation_state'))
        ->toBe(['resource', 'schema_version', 'state', 'configuration_hash', 'run_id', 'created_at', 'updated_at'])
        ->and(tenancyCoreSchema()->getColumnListing('nvl_tenancy_operations'))
        ->toBe(['id', 'actor_type', 'actor_id', 'purpose', 'created_at', 'updated_at'])
        ->and(tenancyCoreSchema()->getColumnListing('nvl_tenancy_adoption_runs'))
        ->toBe(['id', 'status', 'mapping_hash', 'configuration_hash', 'packages', 'checkpoints', 'created_at', 'updated_at'])
        ->and(tenancyCoreSchema()->getColumnListing('nvl_tenancy_adoption_mappings'))
        ->toBe(['run_id', 'resource', 'record_id', 'tenant_id', 'metadata']);

    expect(collect(tenancyCoreSchema()->getIndexes('nvl_tenancy_tenants'))->pluck('name'))
        ->toContain('nvl_tenancy_tenants_name_idx', 'nvl_tenancy_tenants_status_idx')
        ->and(collect(tenancyCoreSchema()->getIndexes('nvl_tenancy_installation_state'))->pluck('name'))
        ->toContain('nvl_tenancy_installation_state_state_idx', 'nvl_tenancy_installation_state_run_idx')
        ->and(collect(tenancyCoreSchema()->getIndexes('nvl_tenancy_operations'))->pluck('name'))
        ->toContain('nvl_tenancy_operations_actor_idx', 'nvl_tenancy_operations_created_idx')
        ->and(collect(tenancyCoreSchema()->getIndexes('nvl_tenancy_adoption_runs'))->pluck('name'))
        ->toContain('nvl_tenancy_adoption_runs_status_idx', 'nvl_tenancy_adoption_runs_mapping_idx')
        ->and(collect(tenancyCoreSchema()->getIndexes('nvl_tenancy_adoption_mappings'))->pluck('name'))
        ->toContain('nvl_tenancy_adoption_mappings_record_unique', 'nvl_tenancy_adoption_mappings_tenant_idx');

    $tenantForeignKeys = collect(tenancyCoreSchema()->getForeignKeys('nvl_tenancy_adoption_mappings'))
        ->filter(fn (array $foreignKey): bool => in_array('tenant_id', $foreignKey['columns'], true));
    expect($tenantForeignKeys)->toHaveCount(1);
});

it('can register the opt-in migration path after ordinary migrations already ran', function (): void {
    Artisan::call('migrate', ['--force' => true]);

    registerAndRunTenancyCoreMigrations();

    foreach (tenancyCoreTables() as $table) {
        expect(tenancyCoreSchema()->hasTable($table))->toBeTrue();
    }
});

it('reverses the complete opt-in core migration set', function (): void {
    registerAndRunTenancyCoreMigrations();

    Artisan::call('migrate:rollback', ['--force' => true]);

    foreach (tenancyCoreTables() as $table) {
        expect(tenancyCoreSchema()->hasTable($table))->toBeFalse();
    }
});

it('provisions a package tenant after authorization and a durable audit', function (): void {
    registerAndRunTenancyCoreMigrations();
    config()->set('tenancy.enabled', true);
    $access = new TestPlatformAccess;
    app()->instance(PlatformAccess::class, $access);
    $operation = new PlatformOperation('provision tenant', 'user', 'operator-1');

    $tenant = app(ProvisionTenantAction::class)->execute('  Acme  ', $operation);

    expect(Str::isUuid($tenant->id->value))->toBeTrue()
        ->and($tenant->status)->toBe(TenantStatus::Active)
        ->and(tenancyCoreConnection()->table('nvl_tenancy_tenants')->where('id', $tenant->id->value)->first())
        ->toMatchObject(['name' => 'Acme', 'status' => TenantStatus::Active->value])
        ->and(tenancyCoreConnection()->table('nvl_tenancy_operations')->count())->toBe(1)
        ->and($access->operations)->toEqual([$operation]);
});

it('rejects invalid tenant names before writing package storage', function (string $name): void {
    registerAndRunTenancyCoreMigrations();
    app()->instance(PlatformAccess::class, new TestPlatformAccess);

    expect(fn () => app(ProvisionTenantAction::class)->execute(
        $name,
        new PlatformOperation('provision tenant', 'user', 'operator-1'),
    ))->toThrow(TenantConfigurationInvalid::class, 'Tenant names must contain 1 to 255 characters.');

    expect(tenancyCoreConnection()->table('nvl_tenancy_tenants')->count())->toBe(0)
        ->and(tenancyCoreConnection()->table('nvl_tenancy_operations')->count())->toBe(0);
})->with(['', '   ', str_repeat('x', 256)]);

it('denies provisioning before audit and tenant insertion', function (): void {
    registerAndRunTenancyCoreMigrations();
    app()->instance(PlatformAccess::class, new DenyPlatformAccess);

    expect(fn () => app(ProvisionTenantAction::class)->execute(
        'Denied tenant',
        new PlatformOperation('provision tenant', 'user', 'operator-1'),
    ))->toThrow(TenantBoundaryViolation::class);

    expect(tenancyCoreConnection()->table('nvl_tenancy_operations')->count())->toBe(0)
        ->and(tenancyCoreConnection()->table('nvl_tenancy_tenants')->count())->toBe(0);
});

it('preserves platform audits when privileged callbacks fail', function (): void {
    registerAndRunTenancyCoreMigrations();
    app()->instance(PlatformAccess::class, new TestPlatformAccess);

    expect(fn () => app(TenantRunner::class)->platform(
        new PlatformOperation('failing platform work', 'user', 'operator-1'),
        fn () => throw new RuntimeException('callback failed'),
    ))->toThrow(RuntimeException::class, 'callback failed');

    expect(tenancyCoreConnection()->table('nvl_tenancy_operations')->count())->toBe(1);
});

it('locks canonical rows for status changes and immediately denies inactive admission', function (TenantStatus $status): void {
    registerAndRunTenancyCoreMigrations();
    config()->set('tenancy.enabled', true);
    app()->instance(PlatformAccess::class, new TestPlatformAccess);
    $provision = new PlatformOperation('provision tenant', 'user', 'operator-1');
    $changeStatus = new PlatformOperation('change tenant status', 'user', 'operator-1');
    $tenant = app(ProvisionTenantAction::class)->execute('Acme', $provision);

    app(ChangeTenantStatusAction::class)->execute($tenant->id, $status, $changeStatus);

    expect(app(TenantDirectory::class)->find($tenant->id)->status)->toBe($status)
        ->and(fn () => app(TenantRunner::class)->run($tenant->id, fn () => test()->fail('entered')))
        ->toThrow(TenantInactive::class)
        ->and(tenancyCoreConnection()->table('nvl_tenancy_operations')->count())->toBe(2);
})->with([TenantStatus::Suspended, TenantStatus::Deleted]);

it('keeps unknown directory identities and status transitions fail closed', function (): void {
    registerAndRunTenancyCoreMigrations();
    app()->instance(PlatformAccess::class, new TestPlatformAccess);
    $unknown = new TenantId('10000000-0000-4000-8000-000000000099');

    expect(fn () => app(TenantDirectory::class)->find($unknown))->toThrow(TenantNotFound::class)
        ->and(fn () => app(ChangeTenantStatusAction::class)->execute(
            $unknown,
            TenantStatus::Deleted,
            new PlatformOperation('delete tenant', 'user', 'operator-1'),
        ))->toThrow(TenantNotFound::class);

    expect(tenancyCoreConnection()->table('nvl_tenancy_operations')->count())->toBe(1)
        ->and(tenancyCoreConnection()->table('nvl_tenancy_tenants')->count())->toBe(0);
});

it('uses effective host directory ownership for foreign keys and rejects package writes', function (): void {
    $directory = new ArrayTenantDirectory([]);
    app()->instance(TenantDirectory::class, $directory);
    app()->instance(PlatformAccess::class, new TestPlatformAccess);

    registerAndRunTenancyCoreMigrations();

    $tenantForeignKeys = collect(tenancyCoreSchema()->getForeignKeys('nvl_tenancy_adoption_mappings'))
        ->filter(fn (array $foreignKey): bool => in_array('tenant_id', $foreignKey['columns'], true));
    expect($tenantForeignKeys)->toBeEmpty();

    expect(fn () => app(ProvisionTenantAction::class)->execute(
        'Host tenant',
        new PlatformOperation('provision tenant', 'user', 'operator-1'),
    ))->toThrow(TenantConfigurationInvalid::class, 'package-owned tenant directory');

    expect(fn () => app(ChangeTenantStatusAction::class)->execute(
        new TenantId('10000000-0000-4000-8000-000000000001'),
        TenantStatus::Suspended,
        new PlatformOperation('suspend tenant', 'user', 'operator-1'),
    ))->toThrow(TenantConfigurationInvalid::class, 'package-owned tenant directory');

    expect(tenancyCoreConnection()->table('nvl_tenancy_operations')->count())->toBe(2)
        ->and(tenancyCoreConnection()->table('nvl_tenancy_tenants')->count())->toBe(0);
});
