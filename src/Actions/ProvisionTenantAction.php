<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Actions;

use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Models\Tenant;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Services\PackageTenantDirectory;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Provisions one canonical package-owned tenant through an audited platform operation. */
final readonly class ProvisionTenantAction
{
    /** Create the tenant provisioning use case. */
    public function __construct(
        private TenantRunner $runner,
        private TenantDirectory $directory,
        private EffectiveTenantConnection $connections,
    ) {}

    /**
     * Provision an active tenant with a server-generated identifier.
     *
     * @throws TenantConfigurationInvalid When the name or effective directory is incompatible
     */
    public function execute(string $name, PlatformOperation $operation): TenantDescriptor
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new TenantConfigurationInvalid('Tenant names must contain 1 to 255 characters.');
        }

        return $this->runner->platform($operation, function () use ($name): TenantDescriptor {
            $this->assertPackageDirectory();
            $connection = $this->connections->core();

            return $connection->transaction(function () use ($connection, $name): TenantDescriptor {
                $tenant = new Tenant;
                $tenant->setConnection($connection->getName());
                $tenant->fill([
                    'name' => $name,
                    'status' => TenantStatus::Active,
                ]);
                $tenant->save();

                return new TenantDescriptor(new TenantId($tenant->id), TenantStatus::Active);
            });
        });
    }

    /** Require the effective directory binding to own the package tenant table. */
    private function assertPackageDirectory(): void
    {
        if (! $this->directory instanceof PackageTenantDirectory) {
            throw new TenantConfigurationInvalid('Tenant writes require the effective package-owned tenant directory.');
        }
    }
}
