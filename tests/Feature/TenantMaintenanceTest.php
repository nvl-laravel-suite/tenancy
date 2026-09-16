<?php

declare(strict_types=1);

use Illuminate\Bus\Dispatcher as NativeDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;
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
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Services\DenyPlatformAccess;
use Nvl\Tenancy\Services\TenantContextParticipants;
use Nvl\Tenancy\Services\TenantMaintenanceLease;
use Nvl\Tenancy\Services\TenantMaintenanceQueueGuard;
use Nvl\Tenancy\Services\TenantMaintenanceRunner;
use Nvl\Tenancy\Services\TenantOperationRecorder;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\Tests\Fixtures\ArrayTenantDirectory;
use Nvl\Tenancy\Tests\Fixtures\InMemoryMaintenanceMode;
use Nvl\Tenancy\Tests\Fixtures\MaintenanceProbeJob;
use Nvl\Tenancy\Tests\Fixtures\TestContextParticipant;
use Nvl\Tenancy\Tests\Fixtures\TestPlatformAccess;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;

use function Illuminate\Support\defer;

function installTenancyCoreSchemaForMaintenance(): void
{
    config()->set('tenancy.migrations.enabled', true);
    (new TenancyServiceProvider(app()))->boot();
    Artisan::call('migrate', ['--force' => true]);
}

beforeEach(function (): void {
    config()->set('tenancy.enabled', true);
    $this->tenant = new TenantId('10000000-0000-4000-8000-000000000001');
    $this->operation = new PlatformOperation('recovery', 'user', 'operator');
    $this->directory = new ArrayTenantDirectory([$this->tenant->value => new TenantDescriptor($this->tenant, TenantStatus::Suspended)]);
    app()->instance(TenantDirectory::class, $this->directory);
    app()->instance(PlatformAccess::class, new TestPlatformAccess);
    app()->instance(MaintenanceMode::class, new InMemoryMaintenanceMode);
    MaintenanceProbeJob::$executions = 0;
    Queue::connection('sync');
});

it('requires audit storage before granting recovery', function (): void {
    expect(fn () => app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, fn () => test()->fail('entered')))
        ->toThrow(TenantSchemaNotReady::class);
    expect(app(TenantMaintenanceLease::class)->active())->toBeFalse();
});

it('admits inactive tenants only within a synchronous audited recovery lease', function (TenantStatus $status): void {
    installTenancyCoreSchemaForMaintenance();
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
    installTenancyCoreSchemaForMaintenance();
    expect(fn () => app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, fn () => throw new RuntimeException('recovery')))
        ->toThrow(RuntimeException::class, 'recovery');
    expect(app(TenantMaintenanceLease::class)->active())->toBeFalse()
        ->and(DB::table('nvl_tenancy_operations')->count())->toBe(1)
        ->and(fn () => app(TenantRunner::class)->run($this->tenant, fn () => test()->fail('entered')))->toThrow(TenantInactive::class);
});

it('requires feature activation maintenance mode authorization and known identity', function (string $denial, string $exception): void {
    installTenancyCoreSchemaForMaintenance();
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
    installTenancyCoreSchemaForMaintenance();
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
    installTenancyCoreSchemaForMaintenance();
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
    installTenancyCoreSchemaForMaintenance();
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
    installTenancyCoreSchemaForMaintenance();
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
    installTenancyCoreSchemaForMaintenance();
    $other = new TenantId('10000000-0000-4000-8000-000000000002');
    $this->directory->tenants[$other->value] = new TenantDescriptor($other, TenantStatus::Active);
    app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, function () use ($other): void {
        expect(fn () => app(TenantRunner::class)->run($other, fn () => test()->fail('entered')))->toThrow(TenantBoundaryViolation::class)
            ->and(fn () => app(TenantRunner::class)->platform($this->operation, fn () => test()->fail('entered')))->toThrow(TenantBoundaryViolation::class);
    });
});

it('does not admit a new privileged operation inside the audit transaction', function (): void {
    installTenancyCoreSchemaForMaintenance();
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
    installTenancyCoreSchemaForMaintenance();
    config()->set('tenancy.enabled', false);
    expect(app(TenantRunner::class)->platform($this->operation, fn () => app(TenantContext::class)->snapshot()->mode))
        ->toBe(TenantContextMode::Platform);
    expect(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Disabled)
        ->and(DB::table('nvl_tenancy_operations')->count())->toBe(1);
});

it('rejects empty and overlong audit facts before privileged work', function (string $purpose): void {
    installTenancyCoreSchemaForMaintenance();
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
        installTenancyCoreSchemaForMaintenance();
        app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, function (): void {
            expect(fn () => Queue::push(new MaintenanceProbeJob))->toThrow(TenantBoundaryViolation::class);
        });
        Queue::push(new MaintenanceProbeJob);
        expect($hostCalls)->toHaveCount(2)->and(MaintenanceProbeJob::$executions)->toBe(1);
    } finally {
        $callbacks->setValue(null, $original);
    }
});

it('fences after-response dispatch and restores the exact host deferral behavior', function (bool $deferred, string $entry): void {
    installTenancyCoreSchemaForMaintenance();
    $dispatcher = app(DispatcherContract::class);
    $deferred ? $dispatcher->withDispatchingAfterResponses() : $dispatcher->withoutDispatchingAfterResponses();
    $flag = new ReflectionProperty(NativeDispatcher::class, 'allowsDispatchingAfterResponses');
    $failure = null;
    try {
        app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, function () use ($dispatcher, $entry): void {
            if ($entry === 'dispatchable') {
                MaintenanceProbeJob::dispatch()->afterResponse();
            } else {
                $dispatcher->dispatchAfterResponse(new MaintenanceProbeJob);
            }
        });
    } catch (Throwable $exception) {
        $failure = $exception;
    }
    $this->app->terminate();
    expect(MaintenanceProbeJob::$executions)->toBe(0)
        ->and($failure)->toBeInstanceOf(TenantBoundaryViolation::class)
        ->and($flag->getValue($dispatcher))->toBe($deferred)
        ->and(app(DispatcherContract::class))->toBe($dispatcher)
        ->and(app(TenantMaintenanceLease::class)->active())->toBeFalse();

    $dispatcher->dispatchAfterResponse(new MaintenanceProbeJob);
    expect(MaintenanceProbeJob::$executions)->toBe($deferred ? 0 : 1);
    $this->app->terminate();
    expect(MaintenanceProbeJob::$executions)->toBe(1);
})->with([true, false])->with(['dispatchable', 'retained']);

it('preserves the after-response fence through rejected nested recovery and callback failure', function (bool $deferred): void {
    installTenancyCoreSchemaForMaintenance();
    $dispatcher = app(DispatcherContract::class);
    $deferred ? $dispatcher->withDispatchingAfterResponses() : $dispatcher->withoutDispatchingAfterResponses();
    $flag = new ReflectionProperty(NativeDispatcher::class, 'allowsDispatchingAfterResponses');
    $runner = app(TenantMaintenanceRunner::class);
    expect(fn () => $runner->run($this->tenant, $this->operation, function () use ($runner, $dispatcher, $flag): void {
        expect($flag->getValue($dispatcher))->toBeFalse();
        expect(fn () => $runner->run($this->tenant, $this->operation, fn () => test()->fail('nested callback')))
            ->toThrow(TenantBoundaryViolation::class, 'already active');
        expect($flag->getValue($dispatcher))->toBeFalse();
        expect(fn () => $dispatcher->dispatchAfterResponse(new MaintenanceProbeJob))
            ->toThrow(TenantBoundaryViolation::class, 'Queue dispatch');
        throw new RuntimeException('original maintenance failure');
    }))->toThrow(RuntimeException::class, 'original maintenance failure');
    expect($flag->getValue($dispatcher))->toBe($deferred)
        ->and(app(TenantMaintenanceLease::class)->active())->toBeFalse();
    $this->app->terminate();
    expect(MaintenanceProbeJob::$executions)->toBe(0);
})->with([true, false]);

it('rejects incompatible host dispatchers before entering maintenance work', function (): void {
    installTenancyCoreSchemaForMaintenance();
    $dispatcher = Mockery::mock(DispatcherContract::class);
    app()->instance(DispatcherContract::class, $dispatcher);
    expect(fn () => app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, fn () => test()->fail('entered')))
        ->toThrow(TenantConfigurationInvalid::class, 'dispatcher');
    expect(app(DispatcherContract::class))->toBe($dispatcher)
        ->and(app(TenantMaintenanceLease::class)->active())->toBeFalse();
});

it('restores the host response deferral setting after successful maintenance', function (bool $deferred): void {
    installTenancyCoreSchemaForMaintenance();
    $dispatcher = app(DispatcherContract::class);
    $deferred ? $dispatcher->withDispatchingAfterResponses() : $dispatcher->withoutDispatchingAfterResponses();
    $flag = new ReflectionProperty(NativeDispatcher::class, 'allowsDispatchingAfterResponses');
    $result = app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, function () use ($dispatcher, $flag): string {
        expect($flag->getValue($dispatcher))->toBeFalse();

        return 'completed';
    });
    expect($result)->toBe('completed')
        ->and($flag->getValue($dispatcher))->toBe($deferred)
        ->and(app(TenantMaintenanceLease::class)->active())->toBeFalse();
})->with([true, false]);

it('restores the response fence when cleanup reporting throws without replacing the work error', function (bool $deferred): void {
    installTenancyCoreSchemaForMaintenance();
    $dispatcher = app(DispatcherContract::class);
    $deferred ? $dispatcher->withDispatchingAfterResponses() : $dispatcher->withoutDispatchingAfterResponses();
    $flag = new ReflectionProperty(NativeDispatcher::class, 'allowsDispatchingAfterResponses');
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->once()->andReturnUsing(function () use ($dispatcher, $flag): never {
        expect($flag->getValue($dispatcher))->toBeFalse()
            ->and(app(TenantMaintenanceLease::class)->active())->toBeTrue()
            ->and(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
        expect(fn () => $dispatcher->dispatchAfterResponse(new MaintenanceProbeJob))
            ->toThrow(TenantBoundaryViolation::class, 'Queue dispatch');
        throw new RuntimeException('reporting failed');
    });
    app()->instance(ExceptionHandler::class, $handler);
    app()->instance(TestContextParticipant::class, new TestContextParticipant(fn () => fn () => throw new RuntimeException('cleanup')));
    app(TenantContextParticipants::class)->register(TestContextParticipant::class);
    expect(fn () => app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, fn () => throw new RuntimeException('original work')))
        ->toThrow(RuntimeException::class, 'original work');
    expect($flag->getValue($dispatcher))->toBe($deferred)
        ->and(app(TenantMaintenanceLease::class)->active())->toBeFalse();
    $this->app->terminate();
    expect(MaintenanceProbeJob::$executions)->toBe(0);
})->with([true, false]);

it('rejects native deferred scheduling before appending to a retained host collection', function (string $entry): void {
    installTenancyCoreSchemaForMaintenance();
    config()->set('queue.connections.deferred', ['driver' => 'deferred']);
    config()->set('queue.connections.background', ['driver' => 'background']);
    Process::fake();
    $driver = Queue::connection($entry === 'background' ? 'background' : 'deferred');
    $callbacks = new DeferredCallbackCollection;
    app()->instance(DeferredCallbackCollection::class, $callbacks);
    $hostCalls = new ArrayObject;
    defer(function () use ($hostCalls): void {
        $hostCalls[] = 'existing';
    }, 'host-existing');
    expect(app(DeferredCallbackCollection::class))->toBe($callbacks);
    $failure = null;
    try {
        try {
            app(TenantMaintenanceRunner::class)->run($this->tenant, $this->operation, function () use ($driver, $entry): void {
                if ($entry === 'helper') {
                    defer(fn () => test()->fail('maintenance deferred callback escaped'));
                } else {
                    $driver->push(new MaintenanceProbeJob);
                }
            });
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        expect($callbacks)->toHaveCount(1)
            ->and($failure)->toBeInstanceOf(TenantBoundaryViolation::class)
            ->and(app(DeferredCallbackCollection::class))->toBe($callbacks);
        $callbacks->invoke();
        expect((array) $hostCalls)->toBe(['existing'])
            ->and(MaintenanceProbeJob::$executions)->toBe(0);
        Process::assertNothingRan();

        if ($entry === 'helper') {
            defer(function () use ($hostCalls): void {
                $hostCalls[] = 'after';
            });
        } else {
            $driver->push(new MaintenanceProbeJob);
        }
        expect($callbacks)->toHaveCount(1);
        $callbacks->invoke();
        if ($entry === 'background') {
            Process::assertRan(fn (PendingProcess $process): bool => str_contains($process->command, 'invoke-serialized-closure'));
        } elseif ($entry === 'deferred') {
            expect(MaintenanceProbeJob::$executions)->toBe(1);
        } else {
            expect((array) $hostCalls)->toBe(['existing', 'after']);
        }
    } finally {
        while (count($callbacks) > 0) {
            $callbacks->forget($callbacks->first()->name);
        }
    }
})->with(['deferred', 'background', 'helper']);
