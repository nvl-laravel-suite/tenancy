<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Definitions\Tables\TenancyTables;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Reads the package-owned canonical directory without requiring production models. */
final readonly class PackageTenantDirectory implements TenantDirectory
{
    /** Create the package directory adapter. */
    public function __construct(private EffectiveTenantConnection $connections) {}

    /** Find an existing canonical tenant after verifying the optional core store. */
    public function find(TenantId $tenant): TenantDescriptor
    {
        $connection = $this->connections->core();
        if (! $connection->getSchemaBuilder()->hasTable(TenancyTables::Tenants)) {
            throw new TenantSchemaNotReady('The tenant directory store is not installed.');
        }
        $row = $connection->table(TenancyTables::Tenants)->where('id', $tenant->value)->first(['id', 'status']);
        if ($row === null) {
            throw new TenantNotFound;
        }
        if (! is_string($row->id) || ! is_string($row->status) || TenantStatus::tryFrom($row->status) === null) {
            throw new TenantSchemaNotReady('The tenant directory entry has an invalid stored shape.');
        }

        return new TenantDescriptor(new TenantId($row->id), TenantStatus::from($row->status));
    }
}
