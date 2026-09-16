<?php

declare(strict_types=1);

use Illuminate\Bus\Dispatcher as NativeDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenancyException;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\Services\DenyPlatformAccess;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantAdoptionGraph;
use Nvl\Tenancy\Services\TenantAdoptionLock;
use Nvl\Tenancy\Services\TenantAdoptionMappings;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantAdoptionScope;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantGlobalJobRegistry;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\Services\TenantMaintenanceLease;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Tests\Fixtures\EmptyAdoptionAdapter;
use Nvl\Tenancy\Tests\Fixtures\InheritedRecord;
use Nvl\Tenancy\Tests\Fixtures\MaintenanceProbeJob;
use Nvl\Tenancy\Tests\Fixtures\OwnedRecord;
use Nvl\Tenancy\Tests\Fixtures\RecordAdoptionAdapter;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use Symfony\Component\Process\Process as ProcessWorker;

use function Illuminate\Support\defer;

beforeEach(function (): void {
    app(TenantGlobalJobRegistry::class)->register(MaintenanceProbeJob::class);
    Schema::create('tenancy_test_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->uuid('parent_id')->nullable();
        $table->softDeletes();
    });
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', OwnedRecord::class));
});

it('activates verified empty registered resources through the public coordinator', function (): void {
    app(TenantAdoptionRegistry::class)->register('tests', RecordAdoptionAdapter::class);
    $operation = new PlatformOperation('test adoption', 'test', 'operator-1');
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['tests'], [], $operation);
    expect($coordinator->backfill($plan, 50, $operation))->toBeTrue()
        ->and($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, $operation);
    expect(DB::table('nvl_tenancy_installation_state')->where('resource', 'tests.records')->value('state'))->toBe('active')
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

/** Install an explicit directory entry without writing or fabricating core tenant rows. */
function adoptionTenant(string $suffix = '1'): TenantId
{
    $tenant = new TenantId('11111111-1111-4111-8111-'.str_pad($suffix, 12, '0', STR_PAD_LEFT));
    app(TenantDirectory::class)->tenants[$tenant->value] = new TenantDescriptor($tenant, TenantStatus::Active);

    return $tenant;
}

function adoptionOperation(): PlatformOperation
{
    return new PlatformOperation('reviewed adoption', 'operator', 'operator-1');
}

function adoptionAdapter(): RecordAdoptionAdapter
{
    app(TenantAdoptionRegistry::class)->register('tests', RecordAdoptionAdapter::class);
    $adapter = app(RecordAdoptionAdapter::class);
    app()->instance(RecordAdoptionAdapter::class, $adapter);

    return $adapter;
}

it('resumes prepare interruption with markers and durable audit already visible', function (): void {
    $adapter = adoptionAdapter();
    $adapter->afterPrepare = function (): void {
        expect(DB::table('nvl_tenancy_installation_state')->value('state'))->toBe('prepared')
            ->and(DB::table('nvl_tenancy_operations')->count())->toBe(1)
            ->and(DB::connection()->transactionLevel())->toBe(0);
        throw new RuntimeException('interrupted prepare');
    };
    $coordinator = app(TenantAdoptionCoordinator::class);
    expect(fn () => $coordinator->prepare(['tests'], [], adoptionOperation()))->toThrow(RuntimeException::class, 'interrupted prepare');
    $id = DB::table('nvl_tenancy_adoption_runs')->value('id');
    $adapter->afterPrepare = null;
    $plan = $coordinator->resume($id);
    expect($coordinator->backfill($plan, 2, adoptionOperation()))->toBeTrue();
    $coordinator->activate($plan, adoptionOperation());
    expect(DB::table('nvl_tenancy_installation_state')->value('state'))->toBe('active');
});

it('streams mappings and resumes a crash after committed batch writes without changing ownership', function (): void {
    $tenant = adoptionTenant();
    $adapter = adoptionAdapter();
    foreach (['a', 'b', 'c'] as $id) {
        DB::table('tenancy_test_records')->insert(['id' => $id, 'name' => $id, 'deleted_at' => $id === 'b' ? now() : null]);
    }
    $mappings = (function () use ($tenant): Generator {
        foreach (['c', 'a', 'b'] as $id) {
            yield new TenantAssignment('tests.records', $id, $tenant);
        }
    })();
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['tests'], $mappings, adoptionOperation());
    $adapter->afterBackfill = static fn () => throw new RuntimeException('batch interruption');
    expect(fn () => $coordinator->backfill($plan, 2, adoptionOperation()))->toThrow(RuntimeException::class)
        ->and(DB::table('tenancy_test_records')->whereNotNull('tenant_id')->count())->toBe(2);
    $adapter->afterBackfill = null;
    $plan = $coordinator->resume($plan->id);
    expect($coordinator->backfill($plan, 2, adoptionOperation()))->toBeFalse()
        ->and($coordinator->backfill($plan, 2, adoptionOperation()))->toBeTrue();
    $coordinator->activate($plan, adoptionOperation());
    expect(DB::table('tenancy_test_records')->where('tenant_id', $tenant->value)->count())->toBe(3)
        ->and(DB::table('nvl_tenancy_adoption_mappings')->count())->toBe(3);
    expect(fn () => DB::table('tenancy_test_records')->insert(['id' => 'd', 'name' => 'd']))->toThrow(QueryException::class);
});

it('keeps the whole graph prepared after first adapter DDL and safely retries activation', function (): void {
    adoptionAdapter();
    app(TenantAdoptionRegistry::class)->register('zzempty', EmptyAdoptionAdapter::class);
    $empty = new EmptyAdoptionAdapter;
    app()->instance($empty::class, $empty);
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['zzempty', 'tests'], [], adoptionOperation());
    $coordinator->backfill($plan, 2, adoptionOperation());
    $empty->onActivate = static fn () => throw new RuntimeException('second DDL interrupted');
    expect(fn () => $coordinator->activate($plan, adoptionOperation()))->toThrow(RuntimeException::class)
        ->and(Schema::hasIndex('tenancy_test_records', 'tests_records_tenant_idx'))->toBeTrue()
        ->and(DB::table('nvl_tenancy_installation_state')->value('state'))->toBe('prepared');
    $empty->onActivate = null;
    $coordinator->activate($coordinator->resume($plan->id), adoptionOperation());
    $coordinator->activate($plan, adoptionOperation());
    expect(DB::table('nvl_tenancy_adoption_runs')->value('status'))->toBe('active');
});

it('supports manifest-only adapters without synthetic markers and blocks interrupted package overlap', function (): void {
    app(TenantAdoptionRegistry::class)->register('csv', EmptyAdoptionAdapter::class);
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['csv'], [], adoptionOperation());
    expect(DB::table('nvl_tenancy_installation_state')->count())->toBe(0);
    expect(fn () => $coordinator->prepare(['csv'], [], adoptionOperation()))->toThrow(TenantSchemaNotReady::class);
    expect($coordinator->backfill($coordinator->resume($plan->id), 2, adoptionOperation()))->toBeTrue()
        ->and($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, adoptionOperation());
    expect(DB::table('nvl_tenancy_adoption_runs')->value('status'))->toBe('active');
});

it('requires authorization and actual maintenance for every mutation', function (string $phase, bool $maintenance): void {
    adoptionAdapter();
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['tests'], [], adoptionOperation());
    $coordinator->backfill($plan, 2, adoptionOperation());
    $before = DB::table('nvl_tenancy_operations')->count();
    if ($maintenance) {
        app()->bind(PlatformAccess::class, DenyPlatformAccess::class);
    } else {
        app(MaintenanceMode::class)->deactivate();
    }
    expect(fn () => match ($phase) {
        'prepare' => $coordinator->prepare(['tests'], [], adoptionOperation()),
        'backfill' => $coordinator->backfill($plan, 2, adoptionOperation()),
        'activate' => $coordinator->activate($plan, adoptionOperation()),
    })->toThrow(TenantBoundaryViolation::class)
        ->and(DB::table('nvl_tenancy_operations')->count())->toBe($before)
        ->and(DB::table('nvl_tenancy_installation_state')->value('state'))->toBe('prepared');
})->with(['prepare', 'backfill', 'activate'])->with([true, false]);

it('rejects immutable input or installation mutations on resume', function (string $mutation): void {
    $tenant = adoptionTenant();
    adoptionAdapter();
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['tests'], [new TenantAssignment('tests.records', 'a', $tenant)], adoptionOperation());
    match ($mutation) {
        'mapping' => DB::table('nvl_tenancy_adoption_mappings')->update(['record_id' => 'changed']),
        'metadata' => DB::table('nvl_tenancy_adoption_mappings')->update(['metadata' => json_encode(['destination' => ['id' => $tenant->value]])]),
        'configuration' => config(['tenancy.profile' => 'platform']),
        'marker' => DB::table('nvl_tenancy_installation_state')->update(['schema_version' => 2]),
        'packages' => DB::table('nvl_tenancy_adoption_runs')->update(['packages' => '["unknown"]']),
    };
    expect(fn () => $coordinator->resume($plan->id))->toThrow(TenancyException::class);
})->with(['mapping', 'metadata', 'configuration', 'marker', 'packages']);

it('rejects invalid mapping input before markers or schema callbacks', function (string $invalid): void {
    $tenant = adoptionTenant();
    adoptionAdapter();
    $assignment = new TenantAssignment('tests.records', 'a', $tenant);
    $mappings = match ($invalid) {
        'duplicate' => [$assignment, $assignment],
        'conflict' => [$assignment, new TenantAssignment('tests.records', 'a', adoptionTenant('2'))],
        'unknown_resource' => [new TenantAssignment('unknown', 'a', $tenant)],
        'oversized_id' => [new TenantAssignment('tests.records', str_repeat('a', 192), $tenant)],
        'unknown_metadata' => [new TenantAssignment('tests.records', 'a', $tenant, ['credential' => 'secret'])],
        'oversized_metadata' => [new TenantAssignment('tests.records', 'a', $tenant, ['destination' => str_repeat('a', 17000)])],
        'unknown_tenant' => [new TenantAssignment('tests.records', 'a', new TenantId('22222222-2222-4222-8222-222222222222'))],
    };
    expect(fn () => app(TenantAdoptionCoordinator::class)->prepare(['tests'], $mappings, adoptionOperation()))->toThrow(TenancyException::class)
        ->and(DB::table('nvl_tenancy_adoption_runs')->count())->toBe(0)
        ->and(DB::table('nvl_tenancy_installation_state')->count())->toBe(0)
        ->and(DB::table('nvl_tenancy_operations')->count())->toBe(1)
        ->and(Schema::hasColumn('tenancy_test_records', 'tenant_id'))->toBeFalse();
})->with(['duplicate', 'conflict', 'unknown_resource', 'oversized_id', 'unknown_metadata', 'oversized_metadata', 'unknown_tenant']);

it('rejects unmapped roots and invalid canonical parents before activation', function (): void {
    adoptionAdapter();
    DB::table('tenancy_test_records')->insert(['id' => 'a', 'name' => 'a', 'parent_id' => 'missing']);
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['tests'], [], adoptionOperation());
    expect(fn () => $coordinator->backfill($plan, 2, adoptionOperation()))->toThrow(TenantBoundaryViolation::class)
        ->and($coordinator->verify($plan)->passed())->toBeFalse();
    expect(fn () => $coordinator->activate($plan, adoptionOperation()))->toThrow(TenantSchemaNotReady::class);
});

it('rejects invalid parents after all mapped rows have been backfilled', function (): void {
    adoptionAdapter();
    $tenant = adoptionTenant();
    DB::table('tenancy_test_records')->insert(['id' => 'a', 'name' => 'a', 'parent_id' => 'missing']);
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['tests'], [new TenantAssignment('tests.records', 'a', $tenant)], adoptionOperation());
    $coordinator->backfill($plan, 2, adoptionOperation());
    expect($coordinator->verify($plan)->errors)->toBe(['tests:parent_invalid:a']);
    expect(fn () => $coordinator->activate($plan, adoptionOperation()))->toThrow(TenantSchemaNotReady::class);
    DB::table('tenancy_test_records')->where('id', 'a')->update(['parent_id' => null]);
    $coordinator->activate($coordinator->resume($plan->id), adoptionOperation());
    expect(DB::table('nvl_tenancy_installation_state')->value('state'))->toBe('active');
});

it('supports bounded ordered assignment reads and nested package-validated metadata', function (): void {
    adoptionAdapter();
    $tenant = adoptionTenant();
    $metadata = ['destination' => ['id' => '22222222-2222-4222-8222-222222222222']];
    $plan = app(TenantAdoptionCoordinator::class)->prepare(['tests'], [new TenantAssignment('tests.records', 'b', $tenant, $metadata), new TenantAssignment('tests.records', 'a', $tenant)], adoptionOperation());
    $mappings = app(TenantAdoptionMappings::class);
    expect($mappings->assignments($plan, 'tests.records', null, 1)[0]->recordId)->toBe('a')
        ->and($mappings->assignments($plan, 'tests.records', 'a', 1)[0]->recordId)->toBe('b')
        ->and($mappings->metadataFor($plan, 'tests.records', 'b'))->toBe($metadata)
        ->and($mappings->tenantFor($plan, 'tests.records', 'b')->value)->toBe($tenant->value);
});

it('rejects invalid batch limits and stuck package progress', function (): void {
    app(TenantAdoptionRegistry::class)->register('csv', EmptyAdoptionAdapter::class);
    $empty = new EmptyAdoptionAdapter;
    $empty->stuck = true;
    app()->instance($empty::class, $empty);
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['csv'], [], adoptionOperation());
    foreach ([0, 10001] as $limit) {
        expect(fn () => $coordinator->backfill($plan, $limit, adoptionOperation()))->toThrow(TenantConfigurationInvalid::class);
    }
    expect(fn () => $coordinator->backfill($plan, 2, adoptionOperation()))->toThrow(TenantBoundaryViolation::class);
});

it('requires explicit new adoption to move an active graph to prepared while preserving history', function (): void {
    adoptionAdapter();
    $coordinator = app(TenantAdoptionCoordinator::class);
    $first = $coordinator->prepare(['tests'], [], adoptionOperation());
    $coordinator->backfill($first, 2, adoptionOperation());
    $coordinator->activate($first, adoptionOperation());
    app(TenantInstallationState::class)->assertUsable('tests.records');
    $second = $coordinator->prepare(['tests'], [], adoptionOperation());
    expect($second->id)->not->toBe($first->id)
        ->and(DB::table('nvl_tenancy_adoption_runs')->count())->toBe(2)
        ->and(DB::table('nvl_tenancy_adoption_runs')->where('id', $first->id)->value('status'))->toBe('active')
        ->and(DB::table('nvl_tenancy_installation_state')->value('state'))->toBe('prepared');
    expect(fn () => app(TenantInstallationState::class)->assertUsable('tests.records'))->toThrow(TenantSchemaNotReady::class);
    expect(fn () => $coordinator->prepare(['tests'], [], adoptionOperation()))->toThrow(TenantSchemaNotReady::class);
    expect(fn () => $coordinator->resume($first->id))->toThrow(TenantSchemaNotReady::class);
});

it('denies caller-owned transactions before audit or DDL and blocks ordinary boundary writes during adoption', function (): void {
    adoptionAdapter();
    DB::beginTransaction();
    try {
        expect(fn () => app(TenantAdoptionCoordinator::class)->prepare(['tests'], [], adoptionOperation()))->toThrow(TenantBoundaryViolation::class);
    } finally {
        DB::rollBack();
    }
    expect(DB::table('nvl_tenancy_operations')->count())->toBe(0);
    app(TenantAdoptionCoordinator::class)->prepare(['tests'], [], adoptionOperation());
    expect(fn () => app(TenantBoundary::class)->query(OwnedRecord::query(), 'tests.records'))->toThrow(TenancyException::class);
});

it('reports doctor formats read-only and applies strict warning exit semantics', function (): void {
    app(TenantAdoptionRegistry::class)->register('csv', EmptyAdoptionAdapter::class);
    app(TenantAdoptionCoordinator::class)->prepare(['csv'], [], adoptionOperation());
    config(['tenancy.enabled' => false]);
    $before = DB::table('nvl_tenancy_operations')->count();
    foreach ([['--json' => true], ['--format' => 'json']] as $options) {
        expect(Artisan::call('nvl:tenancy:doctor', $options))->toBe(0);
        $report = json_decode(Artisan::output(), true);
        expect($report['healthy'])->toBeTrue();
    }
    expect(Artisan::call('nvl:tenancy:doctor', ['--strict' => true, '--format' => 'text']))->toBe(1)
        ->and(Artisan::output())->toContain('WARNING')
        ->and(DB::table('nvl_tenancy_operations')->count())->toBe($before);
});

it('does not treat CLI actor options as authorization', function (): void {
    adoptionAdapter();
    app()->bind(PlatformAccess::class, DenyPlatformAccess::class);
    $this->artisan('nvl:tenancy:adopt', ['phase' => 'prepare', '--packages' => 'tests', '--actor-type' => 'root', '--actor-id' => 'admin', '--purpose' => 'approved'])->assertFailed();
    expect(DB::table('nvl_tenancy_adoption_runs')->count())->toBe(0);
});

it('serializes independent processes through native DDL and releases the storage lock', function (): void {
    $driver = getenv('F5_LOCK_DRIVER') ?: 'sqlite';
    $path = tempnam(sys_get_temp_dir(), 'nvl-f5-lock-');
    $configuration = match ($driver) {
        'pgsql' => ['driver' => 'pgsql', 'host' => getenv('F5_LOCK_SOCKET') ?: '/tmp', 'port' => (int) (getenv('F5_LOCK_PORT') ?: 5432), 'database' => 'nvl_tenancy_test', 'username' => 'nvl_tenancy_test', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer'],
        'mysql', 'mariadb' => ['driver' => $driver, 'unix_socket' => getenv('F5_LOCK_SOCKET') ?: '/tmp/mysql.sock', 'database' => 'nvl_tenancy_test', 'username' => 'nvl_tenancy_test', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true],
        default => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true],
    };
    config(['database.connections.f5_lock' => $configuration]);
    $connection = DB::connection('f5_lock');
    $table = 'nvl_f5_lock_'.bin2hex(random_bytes(6));
    $process = static fn (): ProcessWorker => new ProcessWorker([PHP_BINARY, __DIR__.'/../Fixtures/adoption-lock-worker.php', json_encode($configuration, JSON_THROW_ON_ERROR), $table]);
    $lock = new TenantAdoptionLock;
    try {
        $lock->during($connection, function () use ($connection, $table, $process): void {
            $connection->getSchemaBuilder()->create($table, static function (Blueprint $blueprint): void {
                $blueprint->string('value');
            });
            $contender = $process();
            $contender->mustRun();
            expect($contender->getOutput())->toBe('blocked')
                ->and($connection->table($table)->count())->toBe(0);
        });
        $successor = $process();
        $successor->mustRun();
        expect($successor->getOutput())->toBe('acquired')
            ->and($connection->table($table)->count())->toBe(1);
    } finally {
        $connection->getSchemaBuilder()->dropIfExists($table);
        DB::purge('f5_lock');
        @unlink($path);
        @unlink($path.'.nvl-adoption.lock');
    }
});

it('fences session replacement and restores original connection lifecycle hooks', function (): void {
    $connection = DB::connection();
    $lock = new TenantAdoptionLock;
    $pdo = $connection->getPdo();
    expect(fn () => $lock->during($connection, fn () => $connection->reconnect()))->toThrow(TenantBoundaryViolation::class, 'Reconnection is forbidden');
    expect(fn () => $lock->during($connection, fn () => (new TenantAdoptionLock)->during($connection, static fn () => null)))->toThrow(TenantBoundaryViolation::class);
    try {
        expect(fn () => $lock->during($connection, function () use ($connection): void {
            $connection->setPdo(new PDO('sqlite::memory:'));
            $connection->statement('CREATE TABLE forbidden (id INTEGER)');
        }))->toThrow(TenantBoundaryViolation::class, 'session changed');
    } finally {
        $connection->setPdo($pdo);
    }
    expect($lock->during($connection, fn () => $connection->selectOne('SELECT 1 AS value')->value))->toBe(1);
});

it('rejects unknown packages and connection aliases that do not share the Laravel connection instance', function (): void {
    adoptionAdapter();
    $coordinator = app(TenantAdoptionCoordinator::class);
    foreach ([[], ['unknown']] as $packages) {
        expect(fn () => $coordinator->prepare($packages, [], adoptionOperation()))->toThrow(TenantConfigurationInvalid::class);
    }
    config(['database.connections.other' => config('database.connections.sqlite'), 'tenancy.connection' => 'other']);
    expect(fn () => app(TenantAdoptionGraph::class)->resolve(['tests']))->toThrow(TenantConfigurationInvalid::class, 'canonical core connection');
});

it('rechecks actual schema and tenant status before activating a previously verified run', function (string $change): void {
    adoptionAdapter();
    $tenant = adoptionTenant();
    DB::table('tenancy_test_records')->insert(['id' => 'a', 'name' => 'a']);
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['tests'], [new TenantAssignment('tests.records', 'a', $tenant)], adoptionOperation());
    $coordinator->backfill($plan, 2, adoptionOperation());
    expect($coordinator->verify($plan)->passed())->toBeTrue();
    if ($change === 'schema') {
        Schema::table('tenancy_test_records', static function (Blueprint $table): void {
            $table->dropColumn('tenant_id');
        });
    } else {
        app(TenantDirectory::class)->tenants[$tenant->value] = new TenantDescriptor($tenant, TenantStatus::Suspended);
    }
    expect(fn () => $coordinator->activate($plan, adoptionOperation()))->toThrow(TenancyException::class)
        ->and(DB::table('nvl_tenancy_installation_state')->value('state'))->toBe('prepared');
})->with(['schema', 'tenant']);

it('does not use a new adoption run to transfer an existing tenant-owned record', function (): void {
    adoptionAdapter();
    $tenant = adoptionTenant();
    $other = adoptionTenant('2');
    DB::table('tenancy_test_records')->insert(['id' => 'a', 'name' => 'a']);
    $coordinator = app(TenantAdoptionCoordinator::class);
    $first = $coordinator->prepare(['tests'], [new TenantAssignment('tests.records', 'a', $tenant)], adoptionOperation());
    $coordinator->backfill($first, 2, adoptionOperation());
    $coordinator->activate($first, adoptionOperation());
    $second = $coordinator->prepare(['tests'], [new TenantAssignment('tests.records', 'a', $other)], adoptionOperation());
    expect(fn () => $coordinator->backfill($second, 2, adoptionOperation()))->toThrow(TenantBoundaryViolation::class, 'cannot transfer')
        ->and(DB::table('tenancy_test_records')->value('tenant_id'))->toBe($tenant->value)
        ->and(DB::table('nvl_tenancy_adoption_mappings')->where('run_id', $first->id)->value('tenant_id'))->toBe($tenant->value);
});

it('runs JSONL CLI phases using persisted immutable input', function (): void {
    adoptionAdapter();
    $tenant = adoptionTenant();
    DB::table('tenancy_test_records')->insert(['id' => 'a', 'name' => 'a']);
    $file = tempnam(sys_get_temp_dir(), 'nvl-f5-mapping-');
    file_put_contents($file, json_encode(['resource' => 'tests.records', 'record_id' => 'a', 'tenant_id' => $tenant->value])."\n");
    $actor = ['--actor-type' => 'operator', '--actor-id' => 'operator-1', '--purpose' => 'reviewed adoption'];
    try {
        expect(Artisan::call('nvl:tenancy:adopt', ['phase' => 'prepare', '--packages' => 'tests', '--mapping' => $file, ...$actor]))->toBe(0);
        $id = trim(Artisan::output());
        foreach (['backfill', 'verify', 'activate'] as $phase) {
            expect(Artisan::call('nvl:tenancy:adopt', ['phase' => $phase, '--run' => $id, ...$actor]))->toBe(0);
        }
        expect(Artisan::call('nvl:tenancy:adopt', ['phase' => 'backfill', '--run' => $id, '--mapping' => $file, ...$actor]))->toBe(1);
    } finally {
        unlink($file);
    }
});

it('keeps manifest-only configuration identity immutable on resumption', function (): void {
    app(TenantAdoptionRegistry::class)->register('csv', EmptyAdoptionAdapter::class);
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['csv'], [], adoptionOperation());
    config(['tenancy.profile' => 'platform']);
    expect(fn () => $coordinator->resume($plan->id))->toThrow(TenantConfigurationInvalid::class);
});

it('does not publish active markers when structural input changes inside an adapter callback', function (): void {
    $adapter = adoptionAdapter();
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['tests'], [], adoptionOperation());
    $coordinator->backfill($plan, 2, adoptionOperation());
    $adapter->afterActivate = static fn () => config(['tenancy.profile' => 'platform']);
    expect(fn () => $coordinator->activate($plan, adoptionOperation()))->toThrow(TenantConfigurationInvalid::class)
        ->and(DB::table('nvl_tenancy_installation_state')->value('state'))->toBe('prepared');
});

it('revokes adapter invocation when actual maintenance ends before its checkpoint', function (): void {
    $adapter = adoptionAdapter();
    $adapter->afterPrepare = static fn () => app(MaintenanceMode::class)->deactivate();
    expect(fn () => app(TenantAdoptionCoordinator::class)->prepare(['tests'], [], adoptionOperation()))->toThrow(TenantBoundaryViolation::class)
        ->and(DB::table('nvl_tenancy_installation_state')->value('state'))->toBe('prepared');
});

it('fences queued and deferred work within adoption callbacks and restores host scheduling after failure', function (string $entry): void {
    $adapter = adoptionAdapter();
    MaintenanceProbeJob::$executions = 0;
    config(['queue.connections.deferred' => ['driver' => 'deferred'], 'queue.connections.background' => ['driver' => 'background']]);
    Process::fake();
    $queue = Queue::connection(in_array($entry, ['deferred', 'background'], true) ? $entry : 'sync');
    $dispatcher = app(DispatcherContract::class);
    $callbacks = app(DeferredCallbackCollection::class);
    $flag = new ReflectionProperty(NativeDispatcher::class, 'allowsDispatchingAfterResponses');
    $before = $flag->getValue($dispatcher);
    $adapter->afterPrepare = function () use ($entry, $queue, $dispatcher): void {
        match ($entry) {
            'after_response' => $dispatcher->dispatchAfterResponse(new MaintenanceProbeJob),
            'helper' => defer(static fn () => throw new RuntimeException('escaped deferred callback')),
            default => $queue->push(new MaintenanceProbeJob),
        };
    };
    try {
        expect(fn () => app(TenantAdoptionCoordinator::class)->prepare(['tests'], [], adoptionOperation()))->toThrow(TenantBoundaryViolation::class)
            ->and($callbacks)->toHaveCount(0)
            ->and($flag->getValue($dispatcher))->toBe($before);
        $this->app->terminate();
        expect(MaintenanceProbeJob::$executions)->toBe(0);
        Process::assertNothingRan();
    } finally {
        while (count($callbacks) > 0) {
            $callbacks->forget($callbacks->first()->name);
        }
    }
})->with(['sync', 'after_response', 'helper', 'deferred', 'background']);

it('normalizes canonical parent and family dependencies in adapter order', function (): void {
    adoptionAdapter();
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('children.records', 'children', InheritedRecord::class, TenantResourceKind::Inherited, 'tests.records', 'parent'));
    app(TenantResourceRegistry::class)->requireCompatible('children', 'tests');
    $adapter = new EmptyAdoptionAdapter;
    $adapter->owned = ['children.records'];
    app()->instance(EmptyAdoptionAdapter::class, $adapter);
    app(TenantAdoptionRegistry::class)->register('children', EmptyAdoptionAdapter::class);
    $graph = app(TenantAdoptionGraph::class)->resolve(['children']);
    expect($graph['packages'])->toBe(['children', 'tests'])
        ->and(array_keys($graph['adapters']))->toBe(['tests', 'children'])
        ->and($graph['resources'])->toBe(['children.records', 'tests.records']);
});

it('rejects metadata on an adapter without the optional package validator', function (): void {
    $adapter = new EmptyAdoptionAdapter;
    $adapter->owned = ['tests.records'];
    app()->instance(EmptyAdoptionAdapter::class, $adapter);
    app(TenantAdoptionRegistry::class)->register('tests', EmptyAdoptionAdapter::class);
    expect(fn () => app(TenantAdoptionCoordinator::class)->prepare(['tests'], [new TenantAssignment('tests.records', 'a', adoptionTenant(), ['field' => 'value'])], adoptionOperation()))->toThrow(TenantConfigurationInvalid::class, 'does not accept metadata')
        ->and(DB::table('nvl_tenancy_installation_state')->count())->toBe(0);
});

it('restores adoption scope after callback failure without granting a tenant maintenance lease', function (): void {
    $adapter = adoptionAdapter();
    $adapter->afterPrepare = static function (): never {
        expect(app(TenantAdoptionScope::class)->active())->toBeTrue()
            ->and(app(TenantMaintenanceLease::class)->active())->toBeFalse();
        throw new RuntimeException('callback failed');
    };
    expect(fn () => app(TenantAdoptionCoordinator::class)->prepare(['tests'], [], adoptionOperation()))->toThrow(RuntimeException::class, 'callback failed')
        ->and(app(TenantAdoptionScope::class)->active())->toBeFalse();
    MaintenanceProbeJob::$executions = 0;
    Bus::dispatch(new MaintenanceProbeJob);
    expect(MaintenanceProbeJob::$executions)->toBe(1);
});

it('uses the locked primary session for reads and restores host replica routing afterward', function (): void {
    $connection = DB::connection();
    $connection->statement('CREATE TABLE lock_session_probe (value TEXT)');
    $connection->table('lock_session_probe')->insert(['value' => 'primary']);
    $replica = new PDO('sqlite::memory:');
    $replica->exec('CREATE TABLE lock_session_probe (value TEXT)');
    $replica->exec("INSERT INTO lock_session_probe VALUES ('replica')");
    $connection->setReadPdo($replica);
    expect($connection->table('lock_session_probe')->value('value'))->toBe('replica');
    try {
        expect((new TenantAdoptionLock)->during($connection, fn () => $connection->table('lock_session_probe')->value('value')))->toBe('primary');
        expect($connection->table('lock_session_probe')->value('value'))->toBe('replica');
    } finally {
        $connection->setReadPdo($connection->getPdo());
    }
});

it('rolls back leaked adapter transactions before after-commit work can escape the scope', function (): void {
    $adapter = adoptionAdapter();
    MaintenanceProbeJob::$executions = 0;
    $queue = Queue::connection('sync');
    config(['database.connections.unrelated' => config('database.connections.sqlite')]);
    $adapter->afterPrepare = static function () use ($queue): void {
        DB::connection('unrelated')->beginTransaction();
        $queue->push((new MaintenanceProbeJob)->afterCommit());
    };
    expect(fn () => app(TenantAdoptionCoordinator::class)->prepare(['tests'], [], adoptionOperation()))->toThrow(TenantBoundaryViolation::class)
        ->and(DB::connection('unrelated')->transactionLevel())->toBe(0)
        ->and(app(TenantAdoptionScope::class)->active())->toBeFalse();
    DB::transaction(static fn () => null);
    expect(MaintenanceProbeJob::$executions)->toBe(0);
});

it('rejects an effective adapter implementation change when resuming identical resource declarations', function (): void {
    app(TenantAdoptionRegistry::class)->register('csv', EmptyAdoptionAdapter::class);
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['csv'], [], adoptionOperation());
    /** Provides an alternate effective class with identical declared resources. */
    $replacement = new class implements TenantAdoptionAdapter
    {
        /** @return list<string> */
        public function resources(): array
        {
            return [];
        }

        /** Preserve empty fixture preparation. */
        public function prepare(TenantAdoptionPlan $plan): void {}

        /** Return completed empty progress. */
        public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
        {
            return new TenantBackfillResult(null, 0);
        }

        /** Return empty readiness. */
        public function verify(TenantAdoptionPlan $plan): TenantVerification
        {
            return new TenantVerification([]);
        }

        /** Preserve empty fixture activation. */
        public function activate(TenantAdoptionPlan $plan): void {}
    };
    app()->instance(EmptyAdoptionAdapter::class, $replacement);
    expect(fn () => $coordinator->resume($plan->id))->toThrow(TenantConfigurationInvalid::class);
});

it('rejects final verification changes before publishing active markers and preserves prior history', function (string $change): void {
    $adapter = adoptionAdapter();
    $tenant = adoptionTenant();
    DB::table('tenancy_test_records')->insert(['id' => 'a', 'name' => 'a']);
    $mapping = [new TenantAssignment('tests.records', 'a', $tenant)];
    $coordinator = app(TenantAdoptionCoordinator::class);
    $prior = $coordinator->prepare(['tests'], $mapping, adoptionOperation());
    $coordinator->backfill($prior, 2, adoptionOperation());
    $coordinator->activate($prior, adoptionOperation());
    $history = DB::table('nvl_tenancy_adoption_runs')->where('id', $prior->id)->first();
    $plan = $coordinator->prepare(['tests'], $mapping, adoptionOperation());
    $coordinator->backfill($plan, 2, adoptionOperation());
    $calls = 0;
    $connection = DB::connection();
    $pdo = $connection->getPdo();
    $adapter->afterVerify = function () use (&$calls, $change, $plan, $connection): void {
        if (++$calls !== 2) {
            return;
        }
        match ($change) {
            'profile' => config(['tenancy.profile' => 'platform']),
            'input' => DB::table('nvl_tenancy_adoption_mappings')->where('run_id', $plan->id)->update(['record_id' => 'changed']),
            'maintenance' => app(MaintenanceMode::class)->deactivate(),
            'session' => $connection->setPdo(new PDO('sqlite::memory:')),
        };
    };
    try {
        expect(fn () => $coordinator->activate($plan, adoptionOperation()))->toThrow(TenancyException::class);
    } finally {
        $connection->setPdo($pdo);
    }
    expect($calls)->toBe(2)
        ->and(DB::table('nvl_tenancy_installation_state')->value('state'))->toBe('prepared')
        ->and(DB::table('nvl_tenancy_adoption_runs')->where('id', $prior->id)->first())->toEqual($history)
        ->and(DB::table('nvl_tenancy_adoption_mappings')->where('run_id', $prior->id)->value('record_id'))->toBe('a')
        ->and(app(TenantAdoptionScope::class)->active())->toBeFalse();
})->with(['profile', 'input', 'maintenance', 'session']);

it('revalidates standalone verification callbacks while leaving run checkpoints and audits unchanged', function (): void {
    $adapter = adoptionAdapter();
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['tests'], [], adoptionOperation());
    $coordinator->backfill($plan, 2, adoptionOperation());
    $before = DB::table('nvl_tenancy_adoption_runs')->first();
    $audits = DB::table('nvl_tenancy_operations')->count();
    $adapter->afterVerify = static fn () => config(['tenancy.profile' => 'platform']);
    expect(fn () => $coordinator->verify($plan))->toThrow(TenantConfigurationInvalid::class)
        ->and(DB::table('nvl_tenancy_adoption_runs')->first())->toEqual($before)
        ->and(DB::table('nvl_tenancy_operations')->count())->toBe($audits);
});

it('fences metadata validator publication during ingestion and read-only resumption', function (string $phase, string $entry): void {
    $adapter = adoptionAdapter();
    $tenant = adoptionTenant();
    $coordinator = app(TenantAdoptionCoordinator::class);
    $mapping = [new TenantAssignment('tests.records', 'a', $tenant)];
    $plan = $phase === 'resume' ? $coordinator->prepare(['tests'], $mapping, adoptionOperation()) : null;
    $before = DB::table('nvl_tenancy_adoption_runs')->get()->all();
    $dispatcher = app(DispatcherContract::class);
    $flag = new ReflectionProperty(NativeDispatcher::class, 'allowsDispatchingAfterResponses');
    $previous = $flag->getValue($dispatcher);
    $callbacks = app(DeferredCallbackCollection::class);
    $queue = Queue::connection('sync');
    MaintenanceProbeJob::$executions = 0;
    $depths = [];
    $adapter->onValidate = function () use ($entry, $dispatcher, $queue, &$depths): void {
        $depths[] = DB::connection()->transactionLevel();
        match ($entry) {
            'defer' => defer(static fn () => MaintenanceProbeJob::$executions++),
            'after_response' => $dispatcher->dispatchAfterResponse(new MaintenanceProbeJob),
            'sync' => $queue->push(new MaintenanceProbeJob),
            'after_commit' => $queue->push((new MaintenanceProbeJob)->afterCommit()),
        };
    };
    try {
        expect(fn () => $phase === 'prepare'
            ? $coordinator->prepare(['tests'], $mapping, adoptionOperation())
            : $coordinator->resume($plan->id))->toThrow(TenantBoundaryViolation::class);
        expect($depths)->not->toBeEmpty()
            ->and($depths[0])->toBe($phase === 'prepare' ? 1 : 0)
            ->and($callbacks)->toHaveCount(0)
            ->and(app(TenantAdoptionScope::class)->active())->toBeFalse()
            ->and($flag->getValue($dispatcher))->toBe($previous)
            ->and(DB::connection()->transactionLevel())->toBe(0);
        if ($phase === 'resume') {
            expect(DB::table('nvl_tenancy_adoption_runs')->get()->all())->toEqual($before);
        }
        DB::transaction(static fn () => null);
        $this->app->terminate();
        expect(MaintenanceProbeJob::$executions)->toBe(0);
        Queue::push(new MaintenanceProbeJob);
        expect(MaintenanceProbeJob::$executions)->toBe(1);
    } finally {
        while (count($callbacks) > 0) {
            $callbacks->forget($callbacks->first()->name);
        }
    }
})->with(['prepare', 'resume'])->with(['defer', 'after_response', 'sync', 'after_commit']);

it('rejects read-only adoption entry inside host transactions without rolling back host state', function (string $entry): void {
    $adapter = adoptionAdapter();
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['tests'], [new TenantAssignment('tests.records', 'a', adoptionTenant())], adoptionOperation());
    $calls = 0;
    $adapter->onValidate = static function () use (&$calls): void {
        $calls++;
    };
    config(['database.connections.host' => config('database.connections.sqlite')]);
    $host = DB::connection('host');
    $host->statement('CREATE TABLE host_state (value TEXT)');
    $host->beginTransaction();
    $host->table('host_state')->insert(['value' => 'preserve']);
    try {
        expect(fn () => $entry === 'resume' ? $coordinator->resume($plan->id) : $coordinator->verify($plan))->toThrow(TenantBoundaryViolation::class)
            ->and($calls)->toBe(0)
            ->and($host->transactionLevel())->toBe(1)
            ->and($host->table('host_state')->value('value'))->toBe('preserve');
    } finally {
        $host->rollBack();
    }
})->with(['resume', 'verify']);

it('invalidates primed probes when fenced ingestion commit callbacks fail after prepared markers persist', function (string $initial): void {
    $adapter = adoptionAdapter();
    $coordinator = app(TenantAdoptionCoordinator::class);
    $tenant = adoptionTenant();
    $mapping = [new TenantAssignment('tests.records', 'a', $tenant)];
    if ($initial === 'active') {
        $first = $coordinator->prepare(['tests'], $mapping, adoptionOperation());
        $coordinator->backfill($first, 2, adoptionOperation());
        $coordinator->activate($first, adoptionOperation());
    } else {
        config(['tenancy.enabled' => false]);
    }
    $installation = app(TenantInstallationState::class);
    $installation->assertUsable('tests.records');
    config(['tenancy.enabled' => true]);
    $queue = Queue::connection('sync');
    $adapter->onValidate = static fn () => $queue->push((new MaintenanceProbeJob)->afterCommit());
    expect(fn () => $coordinator->prepare(['tests'], $mapping, adoptionOperation()))->toThrow(TenantBoundaryViolation::class)
        ->and(DB::table('nvl_tenancy_installation_state')->value('state'))->toBe('prepared');
    if ($initial === 'legacy') {
        config(['tenancy.enabled' => false]);
    }
    expect(fn () => $installation->assertUsable('tests.records'))->toThrow(TenantSchemaNotReady::class);
})->with(['active', 'legacy']);

it('allows read-only verification without maintenance authorization or persisted state changes', function (): void {
    adoptionAdapter();
    $coordinator = app(TenantAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['tests'], [], adoptionOperation());
    $coordinator->backfill($plan, 2, adoptionOperation());
    $before = DB::table('nvl_tenancy_adoption_runs')->first();
    $audits = DB::table('nvl_tenancy_operations')->count();
    app(MaintenanceMode::class)->deactivate();
    app()->bind(PlatformAccess::class, DenyPlatformAccess::class);
    expect($coordinator->verify($plan)->passed())->toBeTrue()
        ->and(DB::table('nvl_tenancy_adoption_runs')->first())->toEqual($before)
        ->and(DB::table('nvl_tenancy_operations')->count())->toBe($audits);
});

it('unwinds only leaked metadata transaction levels before the coordinator rolls back its own transaction', function (): void {
    $adapter = adoptionAdapter();
    $levels = [];
    Event::listen(TransactionRolledBack::class, static function (TransactionRolledBack $event) use (&$levels): void {
        $levels[] = $event->connection->transactionLevel();
    });
    $adapter->onValidate = static function (): never {
        expect(DB::connection()->transactionLevel())->toBe(1)
            ->and(app(TenantAdoptionScope::class)->active())->toBeTrue();
        DB::beginTransaction();
        throw new RuntimeException('original metadata failure');
    };
    expect(fn () => app(TenantAdoptionCoordinator::class)->prepare(['tests'], [new TenantAssignment('tests.records', 'a', adoptionTenant())], adoptionOperation()))->toThrow(RuntimeException::class, 'original metadata failure')
        ->and($levels)->toBe([1, 0])
        ->and(DB::table('nvl_tenancy_adoption_runs')->count())->toBe(0)
        ->and(DB::table('nvl_tenancy_operations')->count())->toBe(1)
        ->and(app(TenantAdoptionScope::class)->active())->toBeFalse();
});
