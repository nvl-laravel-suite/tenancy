<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Queue;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\CallQueuedHandler;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantGlobalJobRegistry;
use Nvl\Tenancy\Services\TenantQueueCarrier;
use Nvl\Tenancy\Services\TenantQueueCommand;
use Nvl\Tenancy\Services\TenantQueueContext;
use Nvl\Tenancy\Services\TenantQueuePayload;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/** Restores the boundary before Laravel deserializes either execution or failure commands. @internal */
class TenantCallQueuedHandler extends CallQueuedHandler
{
    /** Identify a handler that explicitly inherits both package entry boundaries. */
    public static function compatible(mixed $handler): bool
    {
        return $handler instanceof self;
    }

    /** @param array<string, mixed> $data */
    public function call(Job $job, array $data): void
    {
        $data = $this->legacyPayload($data);
        $this->withinPayload($data, fn () => parent::call($job, $data));
    }

    /** @param array<string, mixed> $data */
    public function failed(array $data, mixed $e, string $uuid, ?Job $job = null): void
    {
        $data = $this->legacyPayload($data);
        $this->withinPayload($data, fn () => parent::failed($data, $e, $uuid, $job));
    }

    /** Validate the carried command boundary before native middleware, handle or failed callbacks.
     * @param  array<string, mixed>  $data
     */
    protected function getCommand(array $data): mixed
    {
        if (! is_array($data['nvl_tenancy'] ?? null)) {
            throw new TenantBoundaryViolation('The queued command requires tenant envelope metadata.');
        }
        $command = parent::getCommand($data);
        $envelope = $this->container->make(TenantQueuePayload::class)->decode($data['nvl_tenancy']);
        if ($envelope->context->mode === TenantContextMode::Tenant
            && (! is_object($command)
                || $this->container->make(TenantQueuePayload::class)->encode($this->container->make(TenantQueueCarrier::class)->envelope($command)) !== $data['nvl_tenancy'])) {
            throw new TenantBoundaryViolation('The carried job envelope differs from the worker payload.');
        }

        if ($envelope->context->mode === TenantContextMode::Tenant) {
            $this->container->make(TenantQueueCommand::class)->validateRestored($command);
        }

        return $command;
    }

    /**
     * Admit old object payloads only on disabled workers; run() still checks every adoption marker.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function legacyPayload(array $data): array
    {
        if (! array_key_exists('nvl_tenancy', $data) && $this->container->make(Repository::class)->get('tenancy.enabled') !== true) {
            $data['nvl_tenancy'] = $this->container->make(TenantQueuePayload::class)->encode(new TenantJobEnvelope(new TenantContextSnapshot(TenantContextMode::Disabled)));
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function withinPayload(array $data, Closure $operation): mixed
    {
        if (! is_array($data['nvl_tenancy'] ?? null)) {
            throw new TenantBoundaryViolation('Queued work requires scalar tenant envelope metadata.');
        }
        $envelope = $this->container->make(TenantQueuePayload::class)->decode($data['nvl_tenancy']);
        if (! is_string($data['commandName'] ?? null) || ! is_string($data['command'] ?? null)) {
            throw new TenantBoundaryViolation('The queued command representation is invalid.');
        }
        if ($envelope->context->mode === TenantContextMode::Unresolved
            && ! $this->container->make(TenantGlobalJobRegistry::class)->allows($data['commandName'])) {
            throw new TenantBoundaryViolation('Unresolved queued work requires a specific global identity registration.');
        }

        return $this->container->make(TenantQueueContext::class)->run($envelope, function () use ($data, $envelope, $operation): mixed {
            $this->container->make(TenantQueueCommand::class)->validate($data, $envelope);

            return $operation();
        });
    }
}
