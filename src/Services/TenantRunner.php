<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantContextParticipant;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantInactive;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantId;
use Throwable;

/** Executes trusted application work in a restored, transaction-aware tenant scope. */
final readonly class TenantRunner
{
    /** Keep only the container so retained runners resolve each current worker scope. */
    public function __construct(private Container $container) {}

    /**
     * Admit an active tenant and restore its scope after synchronous work.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(TenantId $tenant, Closure $operation): mixed
    {
        if ($this->container->make(Repository::class)->get('tenancy.enabled') !== true) {
            throw new TenantConfigurationInvalid('Tenant execution requires tenancy.enabled.');
        }
        $this->context();
        $descriptor = $this->container->make(TenantDirectory::class)->find($tenant);
        if ($descriptor->id->value !== $tenant->value) {
            throw new TenantBoundaryViolation('The directory returned a different tenant.');
        }
        $lease = $this->container->make(TenantMaintenanceLease::class);
        if ($lease->active() && ! $lease->admits($tenant)) {
            throw new TenantBoundaryViolation('Maintenance work is limited to its leased tenant.');
        }
        if ($descriptor->status !== TenantStatus::Active && ! $lease->admits($tenant)) {
            throw new TenantInactive;
        }

        return $this->execute(new TenantContextSnapshot(TenantContextMode::Tenant, $tenant), $operation);
    }

    /**
     * Authorize and durably audit privileged work, including pre-activation provisioning.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function platform(PlatformOperation $operation, Closure $callback): mixed
    {
        $this->context();
        if ($this->container->make(TenantMaintenanceLease::class)->active()) {
            throw new TenantBoundaryViolation('Maintenance work cannot enter platform context.');
        }
        $this->container->make(PlatformAccess::class)->authorize($operation);
        $this->container->make(TenantOperationRecorder::class)->record($operation);

        return $this->execute(new TenantContextSnapshot(TenantContextMode::Platform), $callback);
    }

    /** Resolve the exact context exposed to consumers and reject incompatible overrides. */
    private function context(): ScopedTenantContext
    {
        return $this->nativeContext($this->container->make(TenantContext::class));
    }

    /** Require explicit participation in the native mutable state implementation. */
    private function nativeContext(TenantContext $context): ScopedTenantContext
    {
        if (! $context instanceof ScopedTenantContext) {
            throw new TenantConfigurationInvalid('The native runner requires its native ScopedTenantContext binding.');
        }
        if ($context->invalid()) {
            throw new TenantBoundaryViolation('The tenant scope was invalidated by failed cleanup.');
        }

        return $context;
    }

    /**
     * Apply one context transition and unwind every successfully entered participant.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function execute(TenantContextSnapshot $next, Closure $callback): mixed
    {
        $context = $this->context();
        $previous = $context->snapshot();
        $connections = $this->container->make(EffectiveTenantConnection::class);
        $levels = [];
        foreach ($connections->participating() as $id => $connection) {
            $levels[$id] = $connection->transactionLevel();
            if ($levels[$id] > 0 && ($previous->mode !== $next->mode || $previous->tenantId?->value !== $next->tenantId?->value)) {
                throw new TenantBoundaryViolation('Tenant context cannot change inside a participating transaction.');
            }
        }
        $restorers = [];
        $failure = null;
        $cleanupFailures = [];
        $context->replace($next);
        try {
            foreach ($this->container->make(TenantContextParticipants::class)->all() as $participant) {
                $restorers[] = $this->enterParticipant($this->container->make($participant), $next);
            }

            return $callback();
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            foreach (array_reverse($restorers) as $restore) {
                try {
                    $restore();
                } catch (Throwable $exception) {
                    $this->cleanupFailure($context, $exception, $failure, $cleanupFailures);
                }
            }
            foreach ($connections->participating() as $id => $connection) {
                $initial = $levels[$id] ?? 0;
                if ($connection->transactionLevel() !== $initial) {
                    $exception = new TenantBoundaryViolation('Tenant callback changed its caller transaction balance.');
                    try {
                        if ($connection->transactionLevel() > $initial) {
                            $connection->rollBack($initial);
                        }
                    } catch (Throwable $rollbackFailure) {
                        $this->cleanupFailure($context, $rollbackFailure, $failure, $cleanupFailures);
                    }
                    $this->cleanupFailure($context, $exception, $failure, $cleanupFailures);
                }
            }
            if (! $context->invalid()) {
                $context->replace($previous);
            }
            try {
                foreach ($cleanupFailures as $cleanupFailure) {
                    $this->container->make(ExceptionHandler::class)->report($cleanupFailure);
                }
            } finally {
                if ($failure !== null) {
                    throw $failure;
                }
            }
        }
    }

    /**
     * Enter a resolved participant through its declared contract.
     *
     * @return Closure(): void
     */
    private function enterParticipant(mixed $participant, TenantContextSnapshot $next): Closure
    {
        if (! $participant instanceof TenantContextParticipant) {
            throw new TenantConfigurationInvalid('A registered participant binding must implement TenantContextParticipant.');
        }

        return $participant->enter($next);
    }

    /**
     * Collect cleanup corruption for reporting after restoration and preserve the original failure.
     *
     * @param  list<Throwable>  $cleanupFailures
     *
     * @param-out Throwable $failure
     */
    private function cleanupFailure(ScopedTenantContext $context, Throwable $exception, ?Throwable &$failure, array &$cleanupFailures): void
    {
        $context->invalidate();
        $failure ??= $exception;
        $cleanupFailures[] = $exception;
    }
}
