<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\Services\TenantOwnershipConfiguration;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Creates real isolated F4 installation markers before the coordinator exists. */
final class F4InstallationFixture
{
    /**
     * Seed real run/marker rows only for F4's isolated installation-state contract.
     *
     * @return list<TenantId>
     */
    public static function install(string $state = 'active', ?string $hash = null): array
    {
        config()->set(['tenancy.enabled' => true, 'tenancy.migrations.enabled' => true]);
        (new TenancyServiceProvider(app()))->boot();
        Artisan::call('migrate', ['--force' => true]);
        $tenants = [new TenantId('10000000-0000-4000-8000-000000000001'), new TenantId('10000000-0000-4000-8000-000000000002')];
        foreach ($tenants as $tenant) {
            DB::table('nvl_tenancy_tenants')->insert(['id' => $tenant->value, 'name' => 'Fixture tenant', 'status' => 'active']);
        }
        $runId = (string) Str::uuid();
        $hash ??= app(TenantOwnershipConfiguration::class)->fingerprint('tests.records');
        DB::table('nvl_tenancy_adoption_runs')->insert([
            'id' => $runId, 'status' => $state, 'mapping_hash' => hash('sha256', 'f4-fixture'),
            'configuration_hash' => app(TenantOwnershipConfiguration::class)->hash(['tests.records']), 'packages' => '["tests"]', 'checkpoints' => '{}',
        ]);
        DB::table('nvl_tenancy_installation_state')->insert([
            'resource' => 'tests.records', 'schema_version' => 1, 'state' => $state,
            'configuration_hash' => $hash, 'run_id' => $runId,
        ]);
        app(TenantInstallationState::class)->invalidate();

        return $tenants;
    }

    /** Add another real marker in the existing isolated F4 run. */
    public static function mark(string $resource): void
    {
        DB::table('nvl_tenancy_installation_state')->insert([
            'resource' => $resource, 'schema_version' => 1, 'state' => 'active',
            'configuration_hash' => app(TenantOwnershipConfiguration::class)->fingerprint($resource),
            'run_id' => DB::table('nvl_tenancy_adoption_runs')->value('id'),
        ]);
        app(TenantInstallationState::class)->invalidate();
    }
}
