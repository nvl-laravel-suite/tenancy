<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantInactive;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\Services\DenyPlatformAccess;
use Nvl\Tenancy\Services\TenantMaintenanceLease;
use Nvl\Tenancy\Services\TenantMaintenanceQueueGuard;
use Nvl\Tenancy\Services\TenantMaintenanceRunner;
use Nvl\Tenancy\Services\TenantOperationRecorder;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\Tests\Fixtures\ArrayTenantDirectory;
use Nvl\Tenancy\Tests\Fixtures\MaintenanceProbeJob;
use Nvl\Tenancy\Tests\Fixtures\TemporaryOperationStore;
use Nvl\Tenancy\Tests\Fixtures\TestMaintenanceMode;
use Nvl\Tenancy\Tests\Fixtures\TestPlatformAccess;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;

beforeEach(function (): void {
    config()->set('tenancy.enabled', true);
    $this->tenant = new TenantId('10000000-0000-4000-8000-000000000001');
    $this->operation = new PlatformOperation('recovery', 'user', 'operator');
    $this->directory = new ArrayTenantDirectory([$this->tenant->value => new TenantDescriptor($this->tenant, TenantStatus::Suspended)]);
    app()->instance(TenantDirectory::class, $this->directory);
    app()->instance(PlatformAccess::class, new TestPlatformAccess);
    app()->instance(MaintenanceMode::class, new TestMaintenanceMode);
    MaintenanceProbeJob::$executions = 0;
    Queue::connection('sync');
});

it('requires audit storage before granting recovery', function (): void {
    expect(fn () => app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, fn () => test()->fail('entered')))
        ->toThrow(TenantSchemaNotReady::class);
    expect(app(TenantMaintenanceLease::class)->active())->toBeFalse();
});

it('admits inactive tenants only within a synchronous audited recovery lease', function (TenantStatus $status): void {
    TemporaryOperationStore::create();
    $this->directory->tenants[$this->tenant->value] = new TenantDescriptor($this->tenant, $status);
    $runner = app(TenantRunner::class);
    expect(fn () => $runner->run($this->tenant, fn () => test()->fail('entered')))->toThrow(TenantInactive::class);
    expect(app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, function (): string {
        expect(DB::table('nvl_tenancy_operations')->count())->toBe(1);

        return app(TenantContext::class)->requireTenant()->value;
    }))->toBe($this->tenant->value);
    expect(fn () => $runner->run($this->tenant, fn () => test()->fail('entered')))->toThrow(TenantInactive::class)
        ->and(app(TenantMaintenanceLease::class)->active())->toBeFalse()
        ->and(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
})->with([TenantStatus::Suspended, TenantStatus::Deleted]);

it('revokes recovery on exception while preserving its durable audit', function (): void {
    TemporaryOperationStore::create();
    expect(fn () => app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, fn () => throw new RuntimeException('recovery')))
        ->toThrow(RuntimeException::class, 'recovery');
    expect(app(TenantMaintenanceLease::class)->active())->toBeFalse()
        ->and(DB::table('nvl_tenancy_operations')->count())->toBe(1)
        ->and(fn () => app(TenantRunner::class)->run($this->tenant, fn () => test()->fail('entered')))->toThrow(TenantInactive::class);
});

it('requires feature activation maintenance mode authorization and known identity', function (string $denial, string $exception): void {
    TemporaryOperationStore::create();
    match ($denial) {
        'disabled' => config()->set('tenancy.enabled', false),
        'online' => app(MaintenanceMode::class)->deactivate(),
        'unauthorized' => app()->instance(PlatformAccess::class, new DenyPlatformAccess),
        'unknown' => $this->directory->tenants = [],
    };
    expect(fn () => app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, fn () => test()->fail('entered')))->toThrow($exception);
    expect(DB::table('nvl_tenancy_operations')->count())->toBe(0);
})->with([
    ['disabled', TenantConfigurationInvalid::class], ['online', TenantBoundaryViolation::class],
    ['unauthorized', TenantBoundaryViolation::class], ['unknown', TenantNotFound::class],
]);

it('rejects ordinary and after-commit Bus dispatch while recovery owns the scope', function (bool $afterCommit): void {
    TemporaryOperationStore::create();
    app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, function () use ($afterCommit): void {
        DB::transaction(function () use ($afterCommit): void {
            $job = new MaintenanceProbeJob;
            if ($afterCommit) {
                $job->afterCommit();
            }
            expect(fn () => Bus::dispatch($job))->toThrow(TenantBoundaryViolation::class, 'Queue dispatch');
            expect(fn () => MaintenanceProbeJob::dispatch())->toThrow(TenantBoundaryViolation::class, 'Queue dispatch');
        });
    });
    expect(MaintenanceProbeJob::$executions)->toBe(0);
    Bus::dispatch(new MaintenanceProbeJob);
    expect(MaintenanceProbeJob::$executions)->toBe(1);
})->with([true, false]);

it('rejects direct sync queue publication including commit-time payload creation', function (bool $afterCommit): void {
    TemporaryOperationStore::create();
    app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, function () use ($afterCommit): void {
        expect(fn () => DB::transaction(function () use ($afterCommit): void {
            $job = new MaintenanceProbeJob;
            if ($afterCommit) {
                $job->afterCommit();
            }
            Queue::push($job);
        }))->toThrow(TenantBoundaryViolation::class, 'Queue dispatch');
    });
    expect(MaintenanceProbeJob::$executions)->toBe(0);
})->with([true, false]);

it('rolls back deferred sync jobs with leaked callback transactions', function (): void {
    TemporaryOperationStore::create();
    Exceptions::fake();
    expect(fn () => app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, function (): void {
        config()->set('database.connections.unrelated', config('database.connections.sqlite'));
        DB::connection('unrelated')->beginTransaction();
        Queue::push((new MaintenanceProbeJob)->afterCommit());
    }))->toThrow(TenantBoundaryViolation::class, 'transaction balance');
    expect(DB::connection('unrelated')->transactionLevel())->toBe(0)
        ->and(MaintenanceProbeJob::$executions)->toBe(0);
    DB::transaction(fn () => null);
    expect(MaintenanceProbeJob::$executions)->toBe(0);
});

it('rejects recovery entry with a preexisting unrelated transaction even in the same tenant', function (): void {
    TemporaryOperationStore::create();
    $this->directory->tenants[$this->tenant->value] = new TenantDescriptor($this->tenant, TenantStatus::Active);
    config()->set('database.connections.unrelated', config('database.connections.sqlite'));
    app(TenantRunner::class)->run($this->tenant, function (): void {
        DB::connection('unrelated')->beginTransaction();
        try {
            expect(fn () => app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, fn () => test()->fail('entered')))
                ->toThrow(TenantBoundaryViolation::class);
        } finally {
            DB::connection('unrelated')->rollBack();
        }
    });
    expect(DB::table('nvl_tenancy_operations')->count())->toBe(0);
});

it('forbids changing tenants or entering platform mode inside a recovery lease', function (): void {
    TemporaryOperationStore::create();
    $other = new TenantId('10000000-0000-4000-8000-000000000002');
    $this->directory->tenants[$other->value] = new TenantDescriptor($other, TenantStatus::Active);
    app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, function () use ($other): void {
        expect(fn () => app(TenantRunner::class)->run($other, fn () => test()->fail('entered')))->toThrow(TenantBoundaryViolation::class)
            ->and(fn () => app(TenantRunner::class)->platform($this->operation, fn () => test()->fail('entered')))->toThrow(TenantBoundaryViolation::class);
    });
});

it('does not admit a new privileged operation inside the audit transaction', function (): void {
    TemporaryOperationStore::create();
    DB::beginTransaction();
    try {
        expect(fn () => app(TenantRunner::class)->platform($this->operation, fn () => test()->fail('entered')))
            ->toThrow(TenantBoundaryViolation::class, 'audit');
    } finally {
        DB::rollBack();
    }
    expect(DB::table('nvl_tenancy_operations')->count())->toBe(0);
});

it('allows explicit audited platform provisioning while tenancy is disabled', function (): void {
    TemporaryOperationStore::create();
    config()->set('tenancy.enabled', false);
    expect(app(TenantRunner::class)->platform($this->operation, fn () => app(TenantContext::class)->snapshot()->mode))
        ->toBe(TenantContextMode::Platform);
    expect(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Disabled)
        ->and(DB::table('nvl_tenancy_operations')->count())->toBe(1);
});

it('rejects empty and overlong audit facts before privileged work', function (string $purpose): void {
    TemporaryOperationStore::create();
    expect(fn () => app(TenantOperationRecorder::class)->record(new PlatformOperation($purpose, 'user', '1')))
        ->toThrow(TenantConfigurationInvalid::class);
    expect(DB::table('nvl_tenancy_operations')->count())->toBe(0);
})->with(['', str_repeat('x', 256)]);

it('registers a single current-scope queue guard while preserving host callbacks after resets', function (): void {
    $callbacks = new ReflectionProperty(Illuminate\Queue\Queue::class, 'createPayloadCallbacks');
    $original = $callbacks->getValue();
    $hostCalls = new ArrayObject;
    try {
        Illuminate\Queue\Queue::createPayloadUsing(null);
        Illuminate\Queue\Queue::createPayloadUsing(function () use ($hostCalls): array {
            $hostCalls[] = 'called';

            return ['host_metadata' => true];
        });
        TenantMaintenanceQueueGuard::register();
        TenantMaintenanceQueueGuard::register();
        expect($callbacks->getValue())->toHaveCount(2);
        TemporaryOperationStore::create();
        app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, function (): void {
            expect(fn () => Queue::push(new MaintenanceProbeJob))->toThrow(TenantBoundaryViolation::class);
        });
        Queue::push(new MaintenanceProbeJob);
        expect($hostCalls)->toHaveCount(2)->and(MaintenanceProbeJob::$executions)->toBe(1);
    } finally {
        $callbacks->setValue(null, $original);
    }
});
