<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Bus\BatchRepository;
use Illuminate\Container\Container;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Queue;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Support\Tenancy\Services\TenantQueuePayload;
use Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;
use Nvl\Tenancy\Queue\TenantCallQueuedHandler;
use Nvl\Tenancy\Queue\TenantDatabaseBatchRepository;

/**
 * Registers the synchronous recovery guard without capturing an application in static hooks.
 *
 * @internal This framework seam is never instantiated as a queue driver.
 */
abstract class TenantMaintenanceQueueGuard extends Queue
{
    /** Install exactly one named guard while preserving host payload callbacks. */
    public static function register(): void
    {
        $callback = [self::class, 'payload'];
        if (! in_array($callback, self::$createPayloadCallbacks, true)) {
            self::createPayloadUsing($callback);
        }
    }

    /** Remove this package's callback when a new application starts with Tenancy disabled. */
    public static function unregister(): void
    {
        self::$createPayloadCallbacks = array_values(array_filter(
            self::$createPayloadCallbacks,
            static fn (mixed $callback): bool => $callback !== [self::class, 'payload'],
        ));
    }

    /**
     * Deny privileged publication and append only the explicitly captured scalar tenant envelope.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function payload(?string $connection = null, ?string $queue = null, array $payload = []): array
    {
        $app = Container::getInstance();
        if ($app->make('config')->get('nvl-tenancy.enabled') !== true) {
            return [];
        }
        if ($app->bound(TenantMaintenanceLease::class)) {
            $app->make(TenantMaintenanceLease::class)->assertQueueAllowed();
        }

        if ($app->bound(TenantAdoptionScope::class)) {
            $app->make(TenantAdoptionScope::class)->assertQueueAllowed();
        }

        if (! $app->bound(TenantContext::class)) {
            return [];
        }
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $command = $data['command'] ?? null;
        if (! is_object($command)) {
            throw new TenantBoundaryViolation('String jobs and custom queue handlers require an explicit tenant adapter.');
        }
        if ($app->make(TenantGlobalJobRegistry::class)->allows($command::class)) {
            $envelope = new TenantJobEnvelope(new TenantContextSnapshot(TenantContextMode::Unresolved));
        } else {
            $envelope = $app->make(TenantQueueCarrier::class)->envelope($command);
            if ($envelope->context->mode !== TenantContextMode::Tenant) {
                throw new TenantBoundaryViolation('Tenant commands must capture an admitted tenant before scheduling.');
            }
        }
        if (! TenantCallQueuedHandler::compatible($app->make(CallQueuedHandler::class))) {
            throw new TenantConfigurationInvalid('The host queue handler must implement TenantQueueHandler and preserve inert validation and both restoration boundaries.');
        }

        if (is_string($data['batchId'] ?? null)) {
            $repository = $app->make(BatchRepository::class);
            if (! TenantDatabaseBatchRepository::compatible($repository)) {
                throw new TenantConfigurationInvalid('Tenant batches require a compatible native database batch repository.');
            }
            $batch = $repository->find($data['batchId']);
            if ($batch === null || ($batch->options['nvl_tenancy'] ?? null) !== $app->make(TenantQueuePayload::class)->encode($envelope)) {
                throw new TenantBoundaryViolation('Queued work does not match its persisted batch context.');
            }
        }

        return ['data' => array_merge($data, ['nvl_tenancy' => $app->make(TenantQueuePayload::class)->encode($envelope)])];
    }
}
