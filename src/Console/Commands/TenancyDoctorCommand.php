<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use Nvl\Tenancy\Exceptions\TenancyException;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\Services\TenantOwnershipConfiguration;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use PDOException;

/** Inspects deployment readiness without running adoption or mutating schema/data. */
final class TenancyDoctorCommand extends Command
{
    protected $signature = 'nvl:tenancy:doctor {--json : Output JSON} {--strict : Treat warnings as failures} {--format=text : Output format: text or json}';

    protected $description = 'Inspect tenant ownership configuration and adoption readiness';

    /** Report bounded configuration and schema readiness facts. */
    public function handle(Repository $configuration, EffectiveTenantConnection $connections, TenantResourceRegistry $resources, TenantOwnershipConfiguration $ownership, TenantInstallationState $installation): int
    {
        if (! in_array($this->option('format'), ['text', 'json'], true)) {
            $this->error('Output format must be text or json.');

            return self::FAILURE;
        }
        $checks = [];
        $deployment = null;
        try {
            $deployment = $ownership->inspect();
            $checks[] = ['key' => 'tenancy.feature', 'passed' => true, 'severity' => 'info', 'message' => $deployment['enabled'] ? 'Tenant feature is enabled.' : 'Tenant feature is disabled.'];
            $checks[] = ['key' => 'tenancy.connection', 'passed' => true, 'severity' => 'info', 'message' => 'Effective core connection: '.mb_strimwidth($deployment['connection'], 0, 160, '...').'.'];
            $checks[] = ['key' => 'tenancy.configuration', 'passed' => $deployment['compatible'], 'severity' => 'error', 'message' => $deployment['compatible'] ? 'Runtime ownership configuration is compatible.' : 'Loaded runtime packages require tenancy integration: '.implode(', ', $deployment['incompatible_families']).'.'];
            $enabled = $configuration->get('tenancy.enabled') === true;
            $schema = $connections->core()->getSchemaBuilder();
            $installed = $schema->hasTable('nvl_tenancy_adoption_runs') && $schema->hasTable('nvl_tenancy_installation_state') && $schema->hasTable('nvl_tenancy_operations') && $schema->hasTable('nvl_tenancy_adoption_mappings');
            $installed = $installed && ($configuration->get('tenancy.directory.driver') === 'host' || $schema->hasTable('nvl_tenancy_tenants'));
            $deployment['schema'] = $installed ? 'installed' : 'missing';
            $checks[] = ['key' => 'tenancy.core', 'passed' => ! $enabled || $installed, 'severity' => 'error', 'message' => $installed ? 'Core adoption storage is installed.' : 'Core adoption storage is not installed.'];
            foreach ($resources->all() as $key => $resource) {
                $checks[] = ['key' => $key.'.ownership', 'passed' => true, 'severity' => 'info', 'message' => 'Effective resource ownership: '.$ownership->mode($resource).'.'];
                try {
                    $installation->assertUsable($key);
                    $checks[] = ['key' => $key, 'passed' => true, 'severity' => 'error', 'message' => 'Resource installation is compatible.'];
                } catch (TenancyException) {
                    $checks[] = ['key' => $key, 'passed' => false, 'severity' => 'error', 'message' => 'Resource requires reviewed adoption or recovery.'];
                }
            }
            if ($installed) {
                $interrupted = $connections->core()->table('nvl_tenancy_adoption_runs')->where('status', '!=', 'active')->exists();
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
        $failed = collect($checks)->contains(fn (array $check): bool => ! $check['passed'] && ($this->option('strict') || $check['severity'] === 'error'));
        if ($this->option('json') || $this->option('format') === 'json') {
            $this->line(json_encode(['healthy' => ! $failed, 'configuration' => $deployment, 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $check) {
                $this->line(sprintf('[%s] %s: %s', $check['passed'] ? 'PASS' : strtoupper($check['severity']), $check['key'], $check['message']));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
