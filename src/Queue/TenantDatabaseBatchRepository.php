<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Queue;

use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Container\Container;
use Illuminate\Database\PostgresConnection;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\TenantQueueCarrier;
use Nvl\Tenancy\Services\TenantQueueCommand;
use Nvl\Tenancy\Services\TenantQueuePayload;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;
use ReflectionProperty;

/** Preserves native batch algorithms while validating callback ownership before deserialization. @internal */
class TenantDatabaseBatchRepository extends DatabaseBatchRepository
{
    /** Adapt only the exact native implementation, retaining its effective storage and factory. */
    public static function fromNative(DatabaseBatchRepository $repository): self
    {
        $factory = (new ReflectionProperty(DatabaseBatchRepository::class, 'factory'))->getValue($repository);
        $table = (new ReflectionProperty(DatabaseBatchRepository::class, 'table'))->getValue($repository);
        if (! $factory instanceof BatchFactory || ! is_string($table)) {
            throw new TenantConfigurationInvalid('The native batch repository configuration is incompatible.');
        }

        return new self($factory, $repository->getConnection(), $table);
    }

    /** Recognize a host implementation that preserves the guarded native batch methods. */
    public static function compatible(mixed $repository): bool
    {
        return $repository instanceof self;
    }

    /** Validate the complete producer batch before persisting callbacks or publishing work. */
    public function store(PendingBatch $batch): Batch
    {
        $app = Container::getInstance();
        if ($app->make('config')->get('tenancy.enabled') === true) {
            $expected = $app->make(TenantQueuePayload::class)->encode(TenantJobEnvelope::capture($app->make(TenantContext::class)));
            if (($batch->options['nvl_tenancy'] ?? null) !== $expected || $expected['mode'] !== 'tenant') {
                throw new TenantBoundaryViolation('Tenant batches must be captured and dispatched in their producer scope.');
            }
            foreach ($batch->jobs->flatten() as $job) {
                if (! is_object($job) || $app->make(TenantQueuePayload::class)->encode($app->make(TenantQueueCarrier::class)->envelope($job)) !== $expected) {
                    throw new TenantBoundaryViolation('A batch cannot mix captured tenant contexts.');
                }
            }
        }

        return parent::store($batch);
    }

    /**
     * Validate raw options before Laravel instantiates any serialized callback objects.
     *
     * @param  mixed  $serialized
     */
    protected function unserialize($serialized): mixed
    {
        $app = Container::getInstance();
        if (! is_string($serialized)) {
            throw new TenantBoundaryViolation('Native batch options must be serialized bytes.');
        }
        $raw = $serialized;
        if ($this->connection instanceof PostgresConnection && ! str_contains($raw, ':') && ! str_contains($raw, ';')) {
            $raw = base64_decode($raw, true);
            if ($raw === false) {
                throw new TenantBoundaryViolation('Native PostgreSQL batch options are malformed.');
            }
        }
        $app->make(TenantQueueCommand::class)->batchEnvelope($raw);

        return parent::unserialize($serialized);
    }
}
