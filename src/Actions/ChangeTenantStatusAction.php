<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Actions;

use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Models\Tenant;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Services\PackageTenantDirectory;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Changes one package-owned tenant lifecycle state under a canonical row lock. */
final readonly class ChangeTenantStatusAction
{
    /** Create the tenant status transition use case. */
    public function __construct(
        private TenantRunner $runner,
        private TenantDirectory $directory,
        private EffectiveTenantConnection $connections,
    ) {}

    /**
     * Persist one audited tenant lifecycle transition.
     *
     * @throws TenantConfigurationInvalid When the effective directory is not package-owned
     * @throws TenantNotFound When the tenant identifier is unknown
     */
    public function execute(TenantId $tenant, TenantStatus $status, PlatformOperation $operation): void
    {
        $this->runner->platform($operation, function () use ($tenant, $status): void {
            $this->assertPackageDirectory();
            $connection = $this->connections->core();

            $connection->transaction(function () use ($connection, $tenant, $status): void {
                $model = (new Tenant)
                    ->setConnection($connection->getName())
                    ->newQuery()
                    ->whereKey($tenant->value)
                    ->lockForUpdate()
                    ->first();

                if (! $model instanceof Tenant) {
                    throw new TenantNotFound;
                }

                $model->status = $status;
                $model->save();
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
