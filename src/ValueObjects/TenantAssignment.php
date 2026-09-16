<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

/** Carries one explicit reviewed record owner and package-validated metadata. */
final readonly class TenantAssignment
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $resource,
        public string $recordId,
        public TenantId $tenantId,
        public array $metadata = [],
    ) {}
}
