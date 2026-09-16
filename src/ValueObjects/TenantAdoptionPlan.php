<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

/** Identifies the immutable input and connection of one persisted adoption. */
final readonly class TenantAdoptionPlan
{
    /** Create a persisted adoption reference; this value grants no privileges. */
    public function __construct(
        public string $id,
        public string $connection,
        public string $mappingHash,
        public string $configurationHash,
    ) {}
}
