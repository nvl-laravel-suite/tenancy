<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Tenancy\Exceptions\TenantInactive;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Services\ScopedTenantContext;
use Nvl\Tenancy\Services\TenantContextParticipants;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\Tests\Fixtures\ArrayTenantDirectory;
use Nvl\Tenancy\Tests\Fixtures\SecondContextParticipant;
use Nvl\Tenancy\Tests\Fixtures\TestContextParticipant;
use Nvl\Tenancy\Tests\Fixtures\TestPlatformAccess;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;

beforeEach(function (): void {
    config()->set('tenancy.enabled', true);
    $this->a = new TenantId('10000000-0000-4000-8000-000000000001');
    $this->b = new TenantId('10000000-0000-4000-8000-000000000002');
    $this->directory = new ArrayTenantDirectory([
        $this->a->value => new TenantDescriptor($this->a, TenantStatus::Active),
        $this->b->value => new TenantDescriptor($this->b, TenantStatus::Active),
    ]);
    app()->instance(TenantDirectory::class, $this->directory);
});

it('restores context when nested work throws', function (): void {
    $runner = app(TenantRunner::class);
    $context = app(TenantContext::class);
    $runner->run($this->a, function () use ($runner, $context): void {
        expect(fn () => $runner->run($this->b, fn () => throw new RuntimeException('probe')))
            ->toThrow(RuntimeException::class, 'probe');
        expect($context->requireTenant()->value)->toBe($this->a->value);
    });
    expect($context->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
});

it('shares the exact context regardless of concrete resolution order', function (bool $concreteFirst): void {
    $first = app($concreteFirst ? ScopedTenantContext::class : TenantContext::class);
    $second = app($concreteFirst ? TenantContext::class : ScopedTenantContext::class);
    expect($first)->toBe($second);
    expect(app(TenantRunner::class)->run($this->a, fn () => $first->requireTenant()->value))->toBe($this->a->value);
})->with([true, false]);

it('rejects a host read-only context override at native runner entry', function (): void {
    $context = Mockery::mock(TenantContext::class);
    app()->instance(TenantContext::class, $context);
    expect(fn () => app(TenantRunner::class)->run($this->a, fn () => null))
        ->toThrow(TenantConfigurationInvalid::class, 'native runner');
});

it('reuses a retained runner across worker scopes without retaining context', function (): void {
    $runner = app(TenantRunner::class);
    $old = app(TenantContext::class);
    $runner->run($this->a, fn () => expect($old->requireTenant()->value)->toBe($this->a->value));
    app()->forgetScopedInstances();
    $next = app(TenantContext::class);
    expect($next)->not->toBe($old);
    $runner->run($this->b, fn () => expect($next->requireTenant()->value)->toBe($this->b->value));
    expect($old->snapshot()->mode)->toBe(TenantContextMode::Unresolved)
        ->and($next->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
});

it('denies inactive and unknown directory entries before work', function (): void {
    $this->directory->tenants[$this->a->value] = new TenantDescriptor($this->a, TenantStatus::Suspended);
    unset($this->directory->tenants[$this->b->value]);
    expect(fn () => app(TenantRunner::class)->run($this->a, fn () => test()->fail('entered')))->toThrow(TenantInactive::class)
        ->and(fn () => app(TenantRunner::class)->run($this->b, fn () => test()->fail('entered')))->toThrow(TenantNotFound::class);
});

it('rejects tenant entry when disabled', function (): void {
    config()->set('tenancy.enabled', false);
    expect(fn () => app(TenantRunner::class)->run($this->a, fn () => test()->fail('entered')))->toThrow(TenantConfigurationInvalid::class);
});

it('allows same tenant reentry but rejects a different tenant in a transaction', function (): void {
    $runner = app(TenantRunner::class);
    $runner->run($this->a, function () use ($runner): void {
        DB::beginTransaction();
        try {
            expect($runner->run($this->a, fn () => 42))->toBe(42);
            expect(fn () => $runner->run($this->b, fn () => test()->fail('entered')))->toThrow(TenantBoundaryViolation::class);
        } finally {
            DB::rollBack();
        }
    });
});

it('checks transactions on every participating resolved connection', function (): void {
    config()->set('database.connections.other', config('database.connections.sqlite'));
    $other = DB::connection('other');
    $other->beginTransaction();
    try {
        expect(fn () => app(TenantRunner::class)->run($this->a, fn () => test()->fail('entered')))->toThrow(TenantBoundaryViolation::class);
    } finally {
        $other->rollBack();
    }
});

it('normalizes default aliases and rejects distinct connections with identical settings', function (): void {
    $connections = app(EffectiveTenantConnection::class);
    expect($connections->name(null))->toBe('sqlite')
        ->and($connections->assertCompatible([null, 'sqlite']))->toBeNull();
    config()->set('database.connections.other', config('database.connections.sqlite'));
    expect(fn () => $connections->assertCompatible(['sqlite', 'other']))->toThrow(TenantConfigurationInvalid::class);
});

it('denies platform work by default including a system actor', function (): void {
    expect(fn () => app(TenantRunner::class)->platform(new PlatformOperation('test', 'system', 'test'), fn () => test()->fail('entered')))
        ->toThrow(TenantBoundaryViolation::class);
});

it('requires the durable audit store even for authorized platform work', function (): void {
    $access = new TestPlatformAccess;
    app()->instance(PlatformAccess::class, $access);
    expect(fn () => app(TenantRunner::class)->platform(new PlatformOperation('test', 'user', '1'), fn () => test()->fail('entered')))
        ->toThrow(TenantSchemaNotReady::class);
    expect($access->operations)->toHaveCount(1);
});

it('unwinds entered participants in reverse order after callback failure', function (): void {
    $events = [];
    foreach ([TestContextParticipant::class => 'first', SecondContextParticipant::class => 'second'] as $class => $name) {
        app()->instance($class, new $class(function () use (&$events, $name): Closure {
            $events[] = 'enter '.$name;

            return function () use (&$events, $name): void {
                $events[] = 'leave '.$name;
            };
        }));
        app(TenantContextParticipants::class)->register($class);
    }
    expect(fn () => app(TenantRunner::class)->run($this->a, fn () => throw new RuntimeException('work')))->toThrow(RuntimeException::class, 'work');
    expect($events)->toBe(['enter first', 'enter second', 'leave second', 'leave first']);
});

it('restores earlier participants when a later enter fails', function (): void {
    $events = [];
    app()->instance(TestContextParticipant::class, new TestContextParticipant(function () use (&$events): Closure {
        $events[] = 'entered';

        return function () use (&$events): void {
            $events[] = 'restored';
        };
    }));
    app()->instance(SecondContextParticipant::class, new SecondContextParticipant(fn () => throw new RuntimeException('enter failure')));
    app(TenantContextParticipants::class)->register(TestContextParticipant::class);
    app(TenantContextParticipants::class)->register(SecondContextParticipant::class);
    expect(fn () => app(TenantRunner::class)->run($this->a, fn () => test()->fail('entered')))->toThrow(RuntimeException::class, 'enter failure');
    expect($events)->toBe(['entered', 'restored'])
        ->and(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
});

it('reports failed cleanup preserves the original failure and revokes the scope', function (bool $workFails): void {
    Exceptions::fake();
    app()->instance(TestContextParticipant::class, new TestContextParticipant(fn () => fn () => throw new RuntimeException('cleanup')));
    app(TenantContextParticipants::class)->register(TestContextParticipant::class);
    $runner = app(TenantRunner::class);
    expect(fn () => $runner->run($this->a, fn () => $workFails ? throw new RuntimeException('work') : 42))
        ->toThrow(RuntimeException::class, $workFails ? 'work' : 'cleanup');
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'cleanup');
    expect(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved)
        ->and(fn () => $runner->run($this->b, fn () => test()->fail('entered')))->toThrow(TenantBoundaryViolation::class, 'invalidated');
})->with([true, false]);

it('rolls back leaked transactions including connections first resolved in the callback', function (bool $newConnection): void {
    Exceptions::fake();
    config()->set('database.connections.other', config('database.connections.sqlite'));
    $connectionName = $newConnection ? 'other' : 'sqlite';
    expect(fn () => app(TenantRunner::class)->run($this->a, function () use ($connectionName): void {
        DB::connection($connectionName)->beginTransaction();
    }))->toThrow(TenantBoundaryViolation::class, 'transaction balance');
    expect(DB::connection($connectionName)->transactionLevel())->toBe(0)
        ->and(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
    Exceptions::assertReported(TenantBoundaryViolation::class);
})->with([true, false]);

it('preserves callback failure when a transaction leaks', function (): void {
    Exceptions::fake();
    expect(fn () => app(TenantRunner::class)->run($this->a, function (): void {
        DB::beginTransaction();
        throw new RuntimeException('original');
    }))->toThrow(RuntimeException::class, 'original');
    expect(DB::transactionLevel())->toBe(0);
    Exceptions::assertReported(TenantBoundaryViolation::class);
});

it('invalidates a scope if nested work closes its caller transaction', function (): void {
    Exceptions::fake();
    $runner = app(TenantRunner::class);
    $runner->run($this->a, function () use ($runner): void {
        DB::beginTransaction();
        expect(fn () => $runner->run($this->a, fn () => DB::commit()))->toThrow(TenantBoundaryViolation::class, 'transaction balance');
        expect(fn () => app(TenantContext::class)->requireTenant())->toThrow(TenantContextMissing::class);
    });
    expect(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
});

it('keeps package directory fallback lazy and honors host bindings', function (): void {
    app()->offsetUnset(TenantDirectory::class);
    expect(fn () => app(TenantDirectory::class)->find($this->a))->toThrow(TenantSchemaNotReady::class);
    app()->instance(TenantDirectory::class, $this->directory);
    expect(app(TenantDirectory::class))->toBe($this->directory);
});

it('fails clearly for a missing host directory adapter', function (): void {
    app()->offsetUnset(TenantDirectory::class);
    config()->set('tenancy.directory.driver', 'host');
    expect(fn () => app(TenantRunner::class)->run($this->a, fn () => null))->toThrow(TenantConfigurationInvalid::class, 'host tenant directory');
});

it('finishes restoration and preserves the work error if cleanup reporting itself fails', function (): void {
    $restored = false;
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->once()->andThrow(new RuntimeException('reporting'));
    app()->instance(ExceptionHandler::class, $handler);
    app()->instance(TestContextParticipant::class, new TestContextParticipant(function () use (&$restored): Closure {
        return function () use (&$restored): void {
            $restored = true;
        };
    }));
    app()->instance(SecondContextParticipant::class, new SecondContextParticipant(fn () => fn () => throw new RuntimeException('cleanup')));
    app(TenantContextParticipants::class)->register(TestContextParticipant::class);
    app(TenantContextParticipants::class)->register(SecondContextParticipant::class);
    expect(fn () => app(TenantRunner::class)->run($this->a, fn () => throw new RuntimeException('work')))
        ->toThrow(RuntimeException::class, 'work');
    expect($restored)->toBeTrue()
        ->and(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
});
