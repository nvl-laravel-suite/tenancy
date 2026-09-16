<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use stdClass;
use WeakMap;

/**
 * Probes persisted adoption on each resource's actual storage connection once per generation.
 *
 * @internal
 */
final class TenantInstallationState
{
    /** @var WeakMap<Connection, array<string, stdClass>> */
    private WeakMap $markers;

    /** Create an application-owned compatibility cache without tenant context. */
    public function __construct(
        private readonly Repository $configuration,
        private readonly TenantResourceRegistry $registry,
        private readonly TenantOwnershipConfiguration $ownership,
        private readonly EffectiveTenantConnection $connections,
    ) {
        $this->markers = new WeakMap;
    }

    /** Deny prepared, missing enabled, incompatible, and disabled adopted resources. */
    public function assertUsable(string $resource): void
    {
        $definition = $this->registry->get($resource);
        $model = new $definition->model;
        $connection = $model->getConnection();
        if (! $this->markers->offsetExists($connection)) {
            $markers = [];
            if ($connection->getSchemaBuilder()->hasTable('nvl_tenancy_installation_state')) {
                foreach ($connection->table('nvl_tenancy_installation_state')->get() as $marker) {
                    if (! is_string($marker->resource)) {
                        throw new TenantSchemaNotReady('The installation marker has an invalid resource key.');
                    }
                    $markers[$marker->resource] = $marker;
                }
            }
            $this->markers[$connection] = $markers;
        }
        $marker = $this->markers[$connection][$resource] ?? null;
        $enabled = $this->configuration->get('tenancy.enabled') === true;
        if ($marker === null && ! $enabled) {
            return;
        }
        if ($marker === null || ! $enabled || $marker->state !== 'active' || ! in_array($marker->schema_version, [1, '1'], true)) {
            throw new TenantSchemaNotReady('The resource ownership schema is not active for this deployment.');
        }
        if ($connection !== $this->connections->core() || ! is_string($marker->configuration_hash)
            || ! hash_equals($this->ownership->fingerprint($resource), $marker->configuration_hash)) {
            throw new TenantSchemaNotReady('The persisted ownership configuration is incompatible with this deployment.');
        }
    }

    /** Clear local probe state after authorized schema changes; other workers must restart. */
    public function invalidate(): void
    {
        $this->markers = new WeakMap;
    }
}
