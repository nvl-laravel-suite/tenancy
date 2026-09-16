<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Closure;
use Illuminate\Bus\Dispatcher as NativeDispatcher;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;
use ReflectionProperty;

/** Runs authorized synchronous recovery for one existing tenant during maintenance. */
final readonly class TenantMaintenanceRunner
{
    /** Resolve maintenance dependencies from the current scope at every entry. */
    public function __construct(private Container $container) {}

    /**
     * Authorize, audit, lease, and restore one tenant recovery operation.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(TenantId $tenant, PlatformOperation $operation, Closure $callback): mixed
    {
        if ($this->container->make(Repository::class)->get('tenancy.enabled') !== true) {
            throw new TenantConfigurationInvalid('Tenant maintenance requires tenancy.enabled.');
        }
        if (! $this->container->make(MaintenanceMode::class)->active()) {
            throw new TenantBoundaryViolation('Tenant recovery requires application maintenance mode.');
        }
        foreach ($this->container->make(EffectiveTenantConnection::class)->participating() as $connection) {
            if ($connection->transactionLevel() > 0) {
                throw new TenantBoundaryViolation('Tenant maintenance cannot enter inside a participating transaction.');
            }
        }
        $this->container->make(PlatformAccess::class)->authorize($operation);
        $descriptor = $this->container->make(TenantDirectory::class)->find($tenant);
        if ($descriptor->id->value !== $tenant->value) {
            throw new TenantBoundaryViolation('The directory returned a different tenant.');
        }
        $this->container->make(TenantOperationRecorder::class)->record($operation);

        return $this->withoutResponseDeferral(
            $this->container->make(DispatcherContract::class),
            fn (): mixed => $this->container->make(TenantMaintenanceLease::class)->during(
                $tenant,
                fn (): mixed => $this->container->make(TenantRunner::class)->run($tenant, $callback),
            ),
        );
    }

    /**
     * Fence native response dispatch so the active lease rejects it before it can be deferred.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withoutResponseDeferral(DispatcherContract $dispatcher, Closure $callback): mixed
    {
        if (! $dispatcher instanceof NativeDispatcher || $dispatcher::class !== NativeDispatcher::class) {
            throw new TenantConfigurationInvalid('Tenant maintenance requires the native Laravel bus dispatcher for response dispatch fencing.');
        }
        $flag = new ReflectionProperty(NativeDispatcher::class, 'allowsDispatchingAfterResponses');
        $previous = $flag->getValue($dispatcher);
        if (! is_bool($previous)) {
            throw new TenantConfigurationInvalid('The native bus dispatcher response deferral setting must be boolean.');
        }
        $dispatcher->withoutDispatchingAfterResponses();
        try {
            return $callback();
        } finally {
            if ($previous) {
                $dispatcher->withDispatchingAfterResponses();
            } else {
                $dispatcher->withoutDispatchingAfterResponses();
            }
        }
    }
}
