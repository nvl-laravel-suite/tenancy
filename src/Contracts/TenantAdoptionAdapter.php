<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Owns idempotent package schema and canonical data adoption under coordinator maintenance. */
interface TenantAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array;

    /** Prepare and validate the package's nullable ownership schema idempotently. */
    public function prepare(TenantAdoptionPlan $plan): void;

    /** Process at most the supplied limit using stable primary-key progress. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult;

    /**
     * Inspect actual storage and canonical parents without mutating records.
     *
     * @phpstan-impure
     */
    public function verify(TenantAdoptionPlan $plan): TenantVerification;

    /** Apply and validate final constraints idempotently before any active marker is published. */
    public function activate(TenantAdoptionPlan $plan): void;
}
