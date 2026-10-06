<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Support\Tenancy\Exceptions\TenantNotFound;
use Nvl\Support\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Support\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Support\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\Definitions\Tables\TenancyTables;

/** Reads the package-owned canonical directory without requiring production models. */
final readonly class PackageTenantDirectory implements TenantDirectory
{
    /** Create the package directory adapter. */
    public function __construct(private EffectiveTenantConnection $connections) {}

    /** Find an existing canonical tenant after verifying the optional core store. */
    public function find(TenantId $tenant): TenantDescriptor
    {
        $connection = $this->connections->core();
        if (! $connection->getSchemaBuilder()->hasTable(TenancyTables::get(TenancyTables::Tenants))) {
            throw new TenantSchemaNotReady('The tenant directory store is not installed.');
        }
        $row = $connection->table(TenancyTables::get(TenancyTables::Tenants))->where('id', $tenant->value)->first(['id', 'status']);
        if ($row === null) {
            throw new TenantNotFound;
        }
        if (! is_string($row->id) || ! is_string($row->status) || TenantStatus::tryFrom($row->status) === null) {
            throw new TenantSchemaNotReady('The tenant directory entry has an invalid stored shape.');
        }

        return new TenantDescriptor(new TenantId($row->id), TenantStatus::from($row->status));
    }
}
