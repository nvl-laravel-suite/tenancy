<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Throwable;

/**
 * Fences one synchronous registered adoption callback without granting ordinary tenant access.
 *
 * @internal Only the adoption coordinator enters this scope; it grants no boundary bypass.
 */
final class TenantAdoptionScope
{
    /** @var array{run: string, adapter: class-string<TenantAdoptionAdapter>, phase: string}|null */
    private ?array $invocation = null;

    /** Resolve the current host dispatcher at each callback boundary. */
    public function __construct(private readonly Container $container, private readonly TenantSynchronousCallbacks $synchronous) {}

    /** Report whether the current application scope owns an adoption callback. */
    public function active(): bool
    {
        return $this->invocation !== null;
    }

    /** Reject publication before any queue payload or deferred callback is retained. */
    public function assertQueueAllowed(): void
    {
        if ($this->active()) {
            throw new TenantBoundaryViolation('Queue dispatch and deferred work are forbidden during adoption callbacks.');
        }
    }

    /**
     * Enter one immutable run/adapter/phase reference and restore host behavior in finally.
     *
     * @template T
     *
     * @param  class-string<TenantAdoptionAdapter>  $adapter
     * @param  Closure(): T  $callback
     * @return T
     */
    public function during(string $run, string $adapter, string $phase, Closure $callback): mixed
    {
        if ($this->active()) {
            throw new TenantBoundaryViolation('An adoption callback is already active.');
        }
        $this->invocation = compact('run', 'adapter', 'phase');
        try {
            return $this->synchronous->run($this->container->make(Dispatcher::class), fn () => $this->balanced($callback));
        } finally {
            $this->invocation = null;
        }
    }

    /**
     * Revoke escaped after-commit callbacks while the publication fence remains active.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function balanced(Closure $callback): mixed
    {
        $connections = $this->container->make(EffectiveTenantConnection::class);
        $initial = $connections->participating();
        foreach ($initial as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new TenantBoundaryViolation('Adoption callbacks require balanced participating transactions.');
            }
        }
        $failure = null;
        try {
            return $callback();
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            $cleanup = [];
            foreach ($initial + $connections->participating() as $connection) {
                if ($connection->transactionLevel() === 0) {
                    continue;
                }
                $failure ??= new TenantBoundaryViolation('An adoption callback changed its transaction balance.');
                try {
                    $connection->rollBack(0);
                } catch (Throwable $exception) {
                    $cleanup[] = $exception;
                }
            }
            try {
                foreach ($cleanup as $exception) {
                    $this->container->make(ExceptionHandler::class)->report($exception);
                }
            } finally {
                if ($failure !== null) {
                    throw $failure;
                }
            }
        }
    }
}
