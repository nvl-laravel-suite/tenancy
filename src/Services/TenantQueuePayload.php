<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use InvalidArgumentException;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/** Converts the versioned queue boundary to scalar wire metadata. @internal */
final class TenantQueuePayload
{
    /** @return array{version: int, mode: string, tenant_id: ?string} */
    public function encode(TenantJobEnvelope $envelope): array
    {
        $data = ['version' => $envelope->version, 'mode' => $envelope->context->mode->value, 'tenant_id' => $envelope->context->tenantId?->value];
        $this->decode($data);

        return $data;
    }

    /** @param array<array-key, mixed> $data */
    public function decode(array $data): TenantJobEnvelope
    {
        if (count($data) !== 3 || ($data['version'] ?? null) !== 1 || ! is_string($data['mode'] ?? null)
            || ! array_key_exists('tenant_id', $data) || (! is_string($data['tenant_id']) && $data['tenant_id'] !== null)) {
            throw new TenantBoundaryViolation('The queue tenant envelope is invalid or unsupported.');
        }
        $mode = TenantContextMode::tryFrom($data['mode']);
        if ($mode === null || $mode === TenantContextMode::Platform) {
            throw new TenantBoundaryViolation('Platform privilege cannot be serialized into queued work.');
        }
        try {
            return new TenantJobEnvelope(new TenantContextSnapshot($mode, $data['tenant_id'] === null ? null : new TenantId($data['tenant_id'])));
        } catch (InvalidArgumentException) {
            throw new TenantBoundaryViolation('The queue tenant envelope contains an invalid context.');
        }
    }
}
