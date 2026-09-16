<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Nvl\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/** Fixed adapters for native commands and native delivery wrappers. @internal */
final class TenantQueueCarrier
{
    /** Read explicit producer capture without adopting the ambient serialization scope. */
    public function envelope(object $command): TenantJobEnvelope
    {
        if ($command instanceof TenantQueuedJob) {
            return $command->tenantJobEnvelope();
        }
        $carriers = match ($command::class) {
            SendQueuedMailable::class => [$command->mailable],
            SendQueuedNotifications::class => [$command->notification],
            CallQueuedListener::class => $this->listenerArguments($command->data),
            default => [],
        };
        $envelope = null;
        foreach ($carriers as $carrier) {
            if ($carrier instanceof TenantQueuedJob) {
                $next = $carrier->tenantJobEnvelope();
                if ($envelope !== null && (new TenantQueuePayload)->encode($envelope) !== (new TenantQueuePayload)->encode($next)) {
                    throw new TenantBoundaryViolation('Native wrapper event arguments have different captured tenants.');
                }
                $envelope = $next;
            } elseif (is_object($carrier)) {
                throw new TenantBoundaryViolation('Native wrapper objects require explicit tenant capture.');
            }
        }

        return $envelope ?? throw new TenantBoundaryViolation('Enabled tenant jobs must carry an explicitly captured TenantJobEnvelope.');
    }

    /** @return array<array-key, mixed> */
    private function listenerArguments(mixed $data): array
    {
        if (! is_array($data)) {
            throw new TenantBoundaryViolation('Native queued listeners require explicit captured event arguments.');
        }

        return $data;
    }

    /** Recognize only the exact installed native wrapper classes. */
    public function nativeWrapper(string $class): bool
    {
        return in_array($class, [SendQueuedMailable::class, SendQueuedNotifications::class, CallQueuedListener::class], true);
    }
}
