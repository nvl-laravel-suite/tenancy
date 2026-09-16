<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Closure;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Queue\TenantDatabaseBatchRepository;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/** Revalidates queued context against this worker's configuration and directory. */
final class TenantQueueContext
{
    /** Retain the application, never an old worker's scoped state. */
    public function __construct(private readonly Container $container) {}

    /** Capture one immutable owner for all native jobs and callbacks in a pending batch. */
    public function captureBatch(PendingBatch $batch): PendingBatch
    {
        $this->container->make(TenantMaintenanceLease::class)->assertQueueAllowed();
        $this->container->make(TenantAdoptionScope::class)->assertQueueAllowed();
        if (! TenantDatabaseBatchRepository::compatible($this->container->make(BatchRepository::class))) {
            throw new TenantConfigurationInvalid('Tenant batches require a compatible native database batch repository.');
        }
        $envelope = TenantJobEnvelope::capture($this->container->make(TenantContext::class));
        if ($envelope->context->mode !== TenantContextMode::Tenant) {
            throw new TenantBoundaryViolation('Tenant batches require an admitted producer tenant.');
        }
        $expected = $this->container->make(TenantQueuePayload::class)->encode($envelope);
        foreach ($batch->jobs->flatten() as $job) {
            if (! is_object($job) || $this->container->make(TenantQueuePayload::class)->encode($this->container->make(TenantQueueCarrier::class)->envelope($job)) !== $expected) {
                throw new TenantBoundaryViolation('A batch cannot mix captured tenant contexts.');
            }
        }

        return $batch->withOption('nvl_tenancy', $expected);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(TenantJobEnvelope $envelope, Closure $operation): mixed
    {
        $this->container->make(TenantMaintenanceLease::class)->assertQueueAllowed();
        $this->container->make(TenantAdoptionScope::class)->assertQueueAllowed();
        $this->container->make(TenantQueuePayload::class)->encode($envelope);
        $enabled = $this->container->make(Repository::class)->get('tenancy.enabled') === true;
        $mode = $envelope->context->mode;
        if (($mode === TenantContextMode::Disabled) === $enabled) {
            throw new TenantBoundaryViolation('Queued tenant mode is incompatible with this worker.');
        }
        if ($mode === TenantContextMode::Tenant) {
            return $this->container->make(TenantRunner::class)->run($envelope->context->tenantId ?? throw new TenantBoundaryViolation('Tenant jobs require a tenant ID.'), $operation);
        }
        if ($mode === TenantContextMode::Disabled) {
            foreach ($this->container->make(TenantResourceRegistry::class)->all() as $resource) {
                $this->container->make(TenantInstallationState::class)->assertUsable($resource->key);
            }
        }

        return $this->container->make(TenantRunner::class)->withoutTenant($envelope->context, $operation);
    }
}
