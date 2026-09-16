<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantId;
use stdClass;

/** Reads explicit immutable reviewed assignments without ordinary tenant scopes. */
final readonly class TenantAdoptionMappings
{
    /** Resolve the canonical persisted mapping store. */
    public function __construct(private EffectiveTenantConnection $connections) {}

    /** Resolve one explicit root owner or reject an unmapped record. */
    public function tenantFor(TenantAdoptionPlan $plan, string $resource, string $recordId): TenantId
    {
        return $this->assignment($plan, $resource, $recordId)->tenantId;
    }

    /**
     * Return a bounded stable record-ID batch from the reviewed mapping.
     *
     * @return list<TenantAssignment>
     */
    public function assignments(TenantAdoptionPlan $plan, string $resource, ?string $afterRecordId, int $limit): array
    {
        $this->assertPlan($plan);
        if ($limit < 1 || $limit > 10000) {
            throw new TenantConfigurationInvalid('Assignment limits must be between 1 and 10000.');
        }
        $query = $this->connections->core()->table('nvl_tenancy_adoption_mappings')->where('run_id', $plan->id)->where('resource', $resource)->orderBy('record_id');
        if ($afterRecordId !== null) {
            $query->where('record_id', '>', $afterRecordId);
        }

        return array_values($query->limit($limit)->get()->map(fn (stdClass $row): TenantAssignment => $this->decode($row))->all());
    }

    /** @return array<string, mixed> */
    public function metadataFor(TenantAdoptionPlan $plan, string $resource, string $recordId): array
    {
        return $this->assignment($plan, $resource, $recordId)->metadata;
    }

    /** Load the complete assignment without accepting a forged plan reference. */
    private function assignment(TenantAdoptionPlan $plan, string $resource, string $recordId): TenantAssignment
    {
        $this->assertPlan($plan);
        $row = $this->connections->core()->table('nvl_tenancy_adoption_mappings')->where('run_id', $plan->id)->where('resource', $resource)->where('record_id', $recordId)->first();
        if ($row === null) {
            throw new TenantBoundaryViolation('The record has no reviewed tenant assignment.');
        }

        return $this->decode($row);
    }

    /** Validate immutable plan identity before exposing its mapping. */
    private function assertPlan(TenantAdoptionPlan $plan): void
    {
        $connection = $this->connections->core();
        $run = $connection->table('nvl_tenancy_adoption_runs')->where('id', $plan->id)->first();
        if ($plan->connection !== $connection->getName() || $run === null || $run->mapping_hash !== $plan->mappingHash || $run->configuration_hash !== $plan->configurationHash) {
            throw new TenantBoundaryViolation('The adoption plan does not match persisted input.');
        }
    }

    /** Decode typed assignments from the owned storage shape. */
    private function decode(stdClass $row): TenantAssignment
    {
        if (! is_string($row->resource) || ! is_string($row->record_id) || ! is_string($row->tenant_id) || ! is_string($row->metadata)) {
            throw new TenantConfigurationInvalid('Invalid persisted assignment.');
        }
        $metadata = json_decode($row->metadata, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($metadata)) {
            throw new TenantConfigurationInvalid('Assignment metadata must be an object.');
        }

        /** @var array<string, mixed> $metadata */
        return new TenantAssignment($row->resource, $row->record_id, new TenantId($row->tenant_id), $metadata);
    }
}
