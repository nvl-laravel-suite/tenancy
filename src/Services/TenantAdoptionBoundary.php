<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Database\Connection;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;

/** Enforces canonical connection, assignment, ownership, and progress rules during package adoption. */
final readonly class TenantAdoptionBoundary
{
    /** Create the package-adoption invariant boundary. */
    public function __construct(
        private EffectiveTenantConnection $connections,
        private TenantAdoptionMappings $mappings,
        private TenantResourceRegistry $resources,
    ) {}

    /** Resolve the registered resource's canonical adoption connection. */
    public function connection(TenantAdoptionPlan $plan, string $resource): Connection
    {
        $definition = $this->resources->get($resource);
        $model = new $definition->model;
        $connection = $model->getConnection();

        if ($connection !== $this->connections->core()
            || $connection->getName() !== $plan->connection) {
            throw new TenantBoundaryViolation(
                "Tenant adoption resource [{$resource}] does not use the plan's canonical connection.",
            );
        }

        return $connection;
    }

    /**
     * Read one stable bounded batch from the reviewed adoption mapping.
     *
     * @return list<TenantAssignment>
     */
    public function assignments(
        TenantAdoptionPlan $plan,
        string $resource,
        ?string $cursor,
        int $limit,
    ): array {
        $this->connection($plan, $resource);

        return $this->mappings->assignments($plan, $resource, $cursor, $limit);
    }

    /**
     * Encode canonical tenant ownership for one reviewed mutable assignment.
     *
     * @return array{tenant_id: string, ownership_key?: string}
     */
    public function ownership(TenantAssignment $assignment, string $resource): array
    {
        $definition = $this->resources->get($resource);

        if ($assignment->resource !== $resource || $definition->kind === TenantResourceKind::Platform) {
            throw new TenantBoundaryViolation(
                "Tenant assignment does not match mutable resource [{$resource}].",
            );
        }

        $attributes = ['tenant_id' => $assignment->tenantId->value];

        if ($definition->usesOwnershipKey()) {
            $attributes['ownership_key'] = 'tenant:'.$assignment->tenantId->value;
        }

        return $attributes;
    }

    /**
     * Build the standard stable-cursor result for a reviewed assignment batch.
     *
     * @param  list<TenantAssignment>  $assignments
     */
    public function result(array $assignments): TenantBackfillResult
    {
        if ($assignments === []) {
            return new TenantBackfillResult(null, 0);
        }

        return new TenantBackfillResult(
            $assignments[array_key_last($assignments)]->recordId,
            count($assignments),
        );
    }
}
