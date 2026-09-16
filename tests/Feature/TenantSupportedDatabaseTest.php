<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Actions\ProvisionTenantAction;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\Services\PackageTenantDirectory;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Tests\Fixtures\OwnedRecord;
use Nvl\Tenancy\Tests\Fixtures\RecordAdoptionAdapter;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

it('proves prefixed core schema and interrupted real adoption on the selected engine', function (): void {
    $connection = DB::connection();
    $driver = env('DB_CONNECTION', 'sqlite');
    expect($connection->getDriverName())->toBe($driver)
        ->and($connection->getTablePrefix())->toBe('f6_');
    $version = $connection->selectOne($driver === 'sqlite' ? 'select sqlite_version() as version' : 'select version() as version')->version;
    if ($driver === 'mysql') {
        expect($version)->toStartWith('8.4.')->not->toContain('MariaDB');
    } elseif ($driver === 'mariadb') {
        expect($version)->toContain('MariaDB');
    } elseif ($driver === 'pgsql') {
        expect($version)->toContain('PostgreSQL 17.');
    }
    foreach (['tenants', 'installation_state', 'operations', 'adoption_runs', 'adoption_mappings'] as $table) {
        expect(Schema::hasTable('nvl_tenancy_'.$table))->toBeTrue();
    }
    expect(collect(Schema::getForeignKeys('nvl_tenancy_adoption_mappings'))->pluck('columns')->all())->not->toContain(['tenant_id']);
    Schema::create('tenancy_test_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->uuid('parent_id')->nullable();
        $table->softDeletes();
    });
    $tenant = new TenantId('11111111-1111-4111-8111-111111111111');
    app(TenantDirectory::class)->tenants[$tenant->value] = new TenantDescriptor($tenant, TenantStatus::Active);
    $ids = ['22222222-2222-4222-8222-222222222222', '33333333-3333-4333-8333-333333333333'];
    foreach ($ids as $index => $id) {
        DB::table('tenancy_test_records')->insert(['id' => $id, 'name' => 'legacy', 'deleted_at' => $index === 1 ? now() : null]);
    }
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', OwnedRecord::class));
    app(TenantAdoptionRegistry::class)->register('tests', RecordAdoptionAdapter::class);
    $adapter = app(RecordAdoptionAdapter::class);
    app()->instance(RecordAdoptionAdapter::class, $adapter);
    $coordinator = app(TenantAdoptionCoordinator::class);
    $operation = new PlatformOperation('supported engine proof', 'operator', 'fixture');
    $plan = $coordinator->prepare(['tests'], array_map(fn (string $id): TenantAssignment => new TenantAssignment('tests.records', $id, $tenant), $ids), $operation);
    expect(fn () => app(TenantInstallationState::class)->assertUsable('tests.records'))->toThrow(TenantSchemaNotReady::class);
    $adapter->afterBackfill = static fn () => throw new RuntimeException('interrupted batch');
    expect(fn () => $coordinator->backfill($plan, 1, $operation))->toThrow(RuntimeException::class, 'interrupted batch');
    $adapter->afterBackfill = null;
    $plan = $coordinator->resume($plan->id);
    expect($coordinator->backfill($plan, 10, $operation))->toBeTrue()
        ->and($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, $operation);
    app(TenantInstallationState::class)->assertUsable('tests.records');
    expect(DB::table('tenancy_test_records')->where('tenant_id', $tenant->value)->count())->toBe(2)
        ->and(DB::table('nvl_tenancy_installation_state')->value('state'))->toBe('active')
        ->and(Schema::hasIndex('tenancy_test_records', 'tests_records_tenant_idx'))->toBeTrue()
        ->and(fn () => DB::table('tenancy_test_records')->insert(['id' => '44444444-4444-4444-8444-444444444444', 'name' => 'missing owner']))->toThrow(QueryException::class);
    expect(Artisan::call('nvl:tenancy:doctor', ['--json' => true]))->toBe(0);
    Schema::drop('tenancy_test_records');
});

it('rehearses consumer-owned migrations and package directory foreign keys on the selected engine', function (): void {
    expect(Artisan::call('migrate:rollback', ['--force' => true]))->toBe(0);
    foreach (['tenants', 'installation_state', 'operations', 'adoption_runs', 'adoption_mappings'] as $table) {
        expect(Schema::hasTable('nvl_tenancy_'.$table))->toBeFalse();
    }
    config()->set('tenancy.migrations.enabled', false);
    config()->set('tenancy.directory.driver', 'package');
    app()->forgetInstance(TenantDirectory::class);
    app()->bind(TenantDirectory::class, PackageTenantDirectory::class);
    $path = sys_get_temp_dir().'/f6-consumer-migrations-'.bin2hex(random_bytes(5));
    mkdir($path);
    $source = glob(__DIR__.'/../../database/migrations/tenancy/*.php')[0];
    $copy = $path.'/'.basename($source);
    copy($source, $copy);
    try {
        expect(Artisan::call('migrate', ['--path' => [$path], '--realpath' => true, '--force' => true]))->toBe(0);
        $tenant = app(ProvisionTenantAction::class)->execute('Engine fixture', new PlatformOperation('provision engine fixture', 'operator', 'fixture'));
        expect(app(TenantDirectory::class)->find($tenant->id)->status)->toBe(TenantStatus::Active)
            ->and(collect(Schema::getForeignKeys('nvl_tenancy_adoption_mappings'))->pluck('columns')->all())->toContain(['tenant_id']);
        expect(Artisan::call('migrate:rollback', ['--path' => [$path], '--realpath' => true, '--force' => true]))->toBe(0)
            ->and(Schema::hasTable('nvl_tenancy_tenants'))->toBeFalse();
    } finally {
        unlink($copy);
        rmdir($path);
    }
});
