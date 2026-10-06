<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Queue\CallQueuedHandler;
use Nvl\Support\Tenancy\Exceptions\TenancyException;
use Nvl\Support\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Definitions\Tables\TenancyTables;
use Nvl\Tenancy\Queue\TenantCallQueuedHandler;
use Nvl\Tenancy\Queue\TenantDatabaseBatchRepository;
use PDOException;

/** Inspects deployment readiness without running adoption or mutating schema/data. */
final class TenancyDoctor
{
    /** Initialize the package-owned inspection dependencies. */
    public function __construct(
        private Repository $configuration,
        private EffectiveTenantConnection $connections,
        private TenantResourceRegistry $resources,
        private TenantOwnershipConfiguration $ownership,
        private TenantInstallationState $installation,
        private CallQueuedHandler $queueHandler,
        private BatchRepository $batches,
    ) {}

    /**
     * Inspect package readiness without rendering a command or changing state.
     *
     * @return array{configuration: array<string, mixed>|null, checks: list<array{key: string, passed: bool, severity: string, message: string}>}
     */
    public function inspect(): array
    {
        $configuration = $this->configuration;
        $connections = $this->connections;
        $resources = $this->resources;
        $ownership = $this->ownership;
        $installation = $this->installation;

        $boundariesRequired = $configuration->get('nvl-tenancy.enabled') === true;
        $checks = [];
        $deployment = null;
        try {
            $deployment = $ownership->inspect();
            $checks[] = ['key' => 'tenancy.feature', 'passed' => true, 'severity' => 'info', 'message' => $deployment['enabled'] ? 'Tenant feature is enabled.' : 'Tenant feature is disabled.'];
            $checks[] = ['key' => 'tenancy.connection', 'passed' => true, 'severity' => 'info', 'message' => 'Effective core connection: '.mb_strimwidth($deployment['connection'], 0, 160, '...').'.'];
            $checks[] = ['key' => 'tenancy.configuration', 'passed' => $deployment['compatible'], 'severity' => 'error', 'message' => $deployment['compatible'] ? 'Runtime ownership configuration is compatible.' : 'Loaded runtime packages require tenancy integration: '.implode(', ', $deployment['incompatible_families']).'.'];
            $enabled = $configuration->get('nvl-tenancy.enabled') === true;
            $schema = $connections->core()->getSchemaBuilder();
            $installed = $schema->hasTable(TenancyTables::get(TenancyTables::AdoptionRuns)) && $schema->hasTable(TenancyTables::get(TenancyTables::InstallationState)) && $schema->hasTable(TenancyTables::get(TenancyTables::Operations)) && $schema->hasTable(TenancyTables::get(TenancyTables::AdoptionMappings));
            $installed = $installed && ($configuration->get('nvl-tenancy.directory.driver') === 'host' || $schema->hasTable(TenancyTables::get(TenancyTables::Tenants)));
            $deployment['schema'] = $installed ? 'installed' : 'missing';
            $checks[] = ['key' => 'tenancy.core', 'passed' => ! $enabled || $installed, 'severity' => 'error', 'message' => $installed ? 'Core adoption storage is installed.' : 'Core adoption storage is not installed.'];
            foreach ($resources->all() as $key => $resource) {
                $checks[] = ['key' => $key.'.ownership', 'passed' => true, 'severity' => 'info', 'message' => 'Effective resource ownership: '.$ownership->mode($resource).'.'];
                try {
                    $installation->assertUsable($key);
                    $checks[] = ['key' => $key, 'passed' => true, 'severity' => 'error', 'message' => 'Resource installation is compatible.'];
                } catch (TenancyException) {
                    $boundariesRequired = true;
                    $checks[] = ['key' => $key, 'passed' => false, 'severity' => 'error', 'message' => 'Resource requires reviewed adoption or recovery.'];
                }
            }
            if ($installed) {
                $interrupted = $connections->core()->table(TenancyTables::get(TenancyTables::AdoptionRuns))->where('status', '!=', 'active')->exists();
                $checks[] = ['key' => 'tenancy.runs', 'passed' => ! $interrupted, 'severity' => 'warning', 'message' => $interrupted ? 'An adoption run requires resumption.' : 'No interrupted adoption run exists.'];
            }
        } catch (QueryException|PDOException) {
            if ($deployment !== null) {
                $deployment['schema'] = 'unavailable';
            }
            $checks[] = ['key' => 'tenancy.storage', 'passed' => false, 'severity' => 'error', 'message' => 'Storage could not be inspected; check the effective connection.'];
        } catch (TenancyException) {
            $checks[] = ['key' => 'tenancy.configuration', 'passed' => false, 'severity' => 'error', 'message' => 'Ownership configuration is invalid.'];
        }

        $queueCompatible = TenantCallQueuedHandler::compatible($this->queueHandler);
        $checks[] = [
            'key' => 'tenancy.queue_handler',
            'passed' => ! $boundariesRequired || $queueCompatible,
            'severity' => $boundariesRequired ? 'error' : 'info',
            'message' => ! $boundariesRequired
                ? 'Tenancy is disabled with legacy storage; the host queue handler is retained.'
                : ($queueCompatible
                    ? 'The queue handler implements TenantQueueHandler for inert validate admission and pre-restoration boundaries in call and failed.'
                    : 'The host queue handler must implement Core TenantQueueHandler: validate admits inert metadata and ownership before call or failed restores commands.'),
        ];
        $batchCompatible = TenantDatabaseBatchRepository::compatible($this->batches);
        $checks[] = [
            'key' => 'tenancy.batch_repository',
            'passed' => ! $boundariesRequired || $batchCompatible,
            'severity' => $boundariesRequired ? 'error' : 'info',
            'message' => ! $boundariesRequired
                ? 'Tenancy is disabled with legacy storage; the host batch repository is retained.'
                : ($batchCompatible
                    ? 'Native database batches include tenant callback validation.'
                    : 'Tenant batches require a compatible native database batch repository.'),
        ];

        return ['configuration' => $deployment, 'checks' => $checks];
    }
}
