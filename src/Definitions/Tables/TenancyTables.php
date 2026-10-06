<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Definitions\Tables;

use Nvl\Support\Config\PackageStorage;

/** Canonical table names owned by the Tenancy package. */
final class TenancyTables
{
    public const string Tenants = 'nvl_tenancy_tenants';

    public const string AdoptionRuns = 'nvl_tenancy_adoption_runs';

    public const string InstallationState = 'nvl_tenancy_installation_state';

    public const string Operations = 'nvl_tenancy_operations';

    public const string AdoptionMappings = 'nvl_tenancy_adoption_mappings';

    /** Return one configured logical or historical package table. */
    public static function get(string $key): string
    {
        return PackageStorage::resolveTable('tenancy', $key);
    }

    private function __construct() {}
}
