<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Container\Container;
use Nvl\Tenancy\Contracts\TenantAdoptionMetadataValidator;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Definitions\Tables\TenancyTables;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantId;

/**
 * Persists and validates immutable adoption inputs and bounded phase checkpoints.
 *
 * @internal
 *
 * @phpstan-import-type Graph from TenantAdoptionGraph
 *
 * @phpstan-type Checkpoint array{prepared: bool, cursor: string|null, done: bool, activated: bool}
 * @phpstan-type Checkpoints array{manifest: string, adapters: array<string, Checkpoint>}
 * @phpstan-type Run array{plan: TenantAdoptionPlan, status: string, packages: list<string>, checkpoints: Checkpoints}
 */
final readonly class TenantAdoptionStore
{
    /** Resolve canonical storage and the current host directory. */
    public function __construct(private EffectiveTenantConnection $connections, private Container $container, private TenantAdoptionScope $scope) {}

    /** @return Run */
    public function load(string $id): array
    {
        $row = $this->connections->core()->table(TenancyTables::AdoptionRuns)->where('id', $id)->first();
        if ($row === null || ! is_string($row->mapping_hash) || ! is_string($row->configuration_hash)
            || ! is_string($row->packages) || ! is_string($row->checkpoints) || ! is_string($row->status)) {
            throw new TenantConfigurationInvalid('Unknown or invalid persisted adoption run.');
        }
        $packages = json_decode($row->packages, true, 32, JSON_THROW_ON_ERROR);
        $checkpoints = json_decode($row->checkpoints, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($packages) || ! array_is_list($packages) || $packages === [] || count(array_filter($packages, 'is_string')) !== count($packages)
            || ! is_array($checkpoints) || ! isset($checkpoints['manifest'], $checkpoints['adapters']) || ! is_string($checkpoints['manifest']) || ! is_array($checkpoints['adapters'])) {
            throw new TenantConfigurationInvalid('Invalid adoption input or checkpoint shape.');
        }
        foreach ($checkpoints['adapters'] as $package => $checkpoint) {
            if (! is_string($package) || ! is_array($checkpoint) || ! is_bool($checkpoint['prepared'] ?? null)
                || ! array_key_exists('cursor', $checkpoint) || (! is_string($checkpoint['cursor']) && $checkpoint['cursor'] !== null)
                || ! is_bool($checkpoint['done'] ?? null) || ! is_bool($checkpoint['activated'] ?? null)) {
                throw new TenantConfigurationInvalid('Invalid adoption adapter checkpoint.');
            }
        }

        /** @var list<string> $packages */
        /** @var Checkpoints $checkpoints */
        return ['plan' => new TenantAdoptionPlan($id, $this->connections->name($this->connections->core()->getName()), $row->mapping_hash, $row->configuration_hash), 'status' => $row->status, 'packages' => $packages, 'checkpoints' => $checkpoints];
    }

    /**
     * Stream reviewed rows into indexed storage inside the caller's preparation transaction.
     *
     * @param  iterable<TenantAssignment>  $assignments
     * @param  Graph  $graph
     */
    public function ingest(string $runId, iterable $assignments, array $graph): string
    {
        foreach ($assignments as $assignment) {
            $metadata = $this->validateAssignment($runId, $assignment, $graph);
            $query = $this->connections->core()->table(TenancyTables::AdoptionMappings);
            if ((clone $query)->where('run_id', $runId)->where('resource', $assignment->resource)->where('record_id', $assignment->recordId)->exists()) {
                throw new TenantConfigurationInvalid('Duplicate or conflicting record assignment.');
            }
            $query->insert(['run_id' => $runId, 'resource' => $assignment->resource, 'record_id' => $assignment->recordId, 'tenant_id' => $assignment->tenantId->value, 'metadata' => $metadata]);
        }

        return $this->mappingHash($runId, $graph);
    }

    /**
     * Recompute sorted immutable mapping bytes and revalidate current tenant/metadata admission.
     *
     * @param  Graph  $graph
     */
    public function mappingHash(string $runId, array $graph): string
    {
        $connection = $this->connections->core();
        $ordering = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => 'BINARY resource, BINARY record_id',
            'pgsql' => 'resource COLLATE "C", record_id COLLATE "C"',
            default => 'resource COLLATE BINARY, record_id COLLATE BINARY',
        };
        $hash = hash_init('sha256');
        foreach ($connection->table(TenancyTables::AdoptionMappings)->where('run_id', $runId)->orderByRaw($ordering)->cursor() as $row) {
            if (! is_string($row->resource) || ! is_string($row->record_id) || ! is_string($row->tenant_id) || ! is_string($row->metadata)) {
                throw new TenantConfigurationInvalid('Invalid persisted adoption mapping.');
            }
            $metadata = json_decode($row->metadata, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($metadata)) {
                throw new TenantConfigurationInvalid('Invalid persisted adoption metadata.');
            }
            /** @var array<string, mixed> $metadata */
            $assignment = new TenantAssignment($row->resource, $row->record_id, new TenantId($row->tenant_id), $metadata);
            $canonical = $this->validateAssignment($runId, $assignment, $graph);
            hash_update($hash, json_encode([$row->resource, $row->record_id, $assignment->tenantId->value, $canonical], JSON_THROW_ON_ERROR)."\n");
        }

        return hash_final($hash);
    }

    /**
     * Persist one resumable phase boundary.
     *
     * @param  Checkpoints  $checkpoints
     */
    public function checkpoint(string $id, string $status, array $checkpoints): void
    {
        $this->connections->core()->table(TenancyTables::AdoptionRuns)->where('id', $id)->update([
            'status' => $status, 'checkpoints' => json_encode($checkpoints, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
    }

    /**
     * Validate physical bounds, tenant status, and the owning package's optional schema.
     *
     * @param  Graph  $graph
     */
    private function validateAssignment(string $runId, TenantAssignment $assignment, array $graph): string
    {
        foreach ([$assignment->resource, $assignment->recordId] as $value) {
            if (trim($value) === '' || mb_strlen($value) > 191 || str_contains($value, "\0")) {
                throw new TenantConfigurationInvalid('Mapping identifiers require 1 to 191 characters.');
            }
        }
        $owner = $graph['owners'][$assignment->resource] ?? throw new TenantConfigurationInvalid('A mapping names a resource outside the selected graph.');
        $descriptor = $this->container->make(TenantDirectory::class)->find($assignment->tenantId);
        if ($descriptor->id->value !== $assignment->tenantId->value || $descriptor->status !== TenantStatus::Active) {
            throw new TenantBoundaryViolation('A mapping requires an existing active canonical tenant.');
        }
        $nodes = 0;
        $canonical = json_encode($this->canonical($assignment->metadata, 0, $nodes), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($canonical) > 16384) {
            throw new TenantConfigurationInvalid('Assignment metadata exceeds 16384 bytes.');
        }
        $adapter = $graph['adapters'][$owner];
        if ($adapter instanceof TenantAdoptionMetadataValidator) {
            $this->scope->during($runId, $adapter::class, 'metadata', fn () => $adapter->validateAssignment($assignment));
        } elseif ($assignment->metadata !== []) {
            throw new TenantConfigurationInvalid('This adoption adapter does not accept metadata.');
        }

        return $canonical;
    }

    /** Canonicalize bounded JSON values without coercing objects or credentials. */
    private function canonical(mixed $value, int $depth, int &$nodes): mixed
    {
        if (++$nodes > 2048 || $depth > 16) {
            throw new TenantConfigurationInvalid('Assignment metadata is too complex.');
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $this->canonical($item, $depth + 1, $nodes);
            }

            return $value;
        }
        if ($value === null || is_string($value) || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            return $value;
        }
        throw new TenantConfigurationInvalid('Assignment metadata must contain JSON values only.');
    }
}
