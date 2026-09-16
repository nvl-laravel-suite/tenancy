<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Tenancy\ValueObjects\TenantAssignment;

/** Optionally validates exact package-owned assignment metadata before preparation. */
interface TenantAdoptionMetadataValidator
{
    /** Reject unknown fields, invalid destination references, and confidential payloads. */
    public function validateAssignment(TenantAssignment $assignment): void;
}
