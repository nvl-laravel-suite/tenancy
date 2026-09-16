<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

use Nvl\Tenancy\Contracts\TenantContext;

/** Immutable context captured before queued execution crosses a process boundary. */
final readonly class TenantJobEnvelope
{
    /** Preserve the producer context without serializing models or credentials. */
    public function __construct(public TenantContextSnapshot $context, public int $version = 1) {}

    /** Snapshot the currently admitted context. */
    public static function capture(TenantContext $context): self
    {
        return new self($context->snapshot());
    }
}
