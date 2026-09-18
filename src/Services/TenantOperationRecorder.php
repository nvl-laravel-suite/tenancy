<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Support\Str;
use Nvl\Tenancy\Definitions\Tables\TenancyTables;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\ValueObjects\PlatformOperation;

/** Persists bounded operation facts before privileged callbacks can perform work. */
final readonly class TenantOperationRecorder
{
    /** Create the core audit adapter. */
    public function __construct(private EffectiveTenantConnection $connections) {}

    /** Record an authorized operation outside any caller-owned audit transaction. */
    public function record(PlatformOperation $operation): void
    {
        foreach ([$operation->purpose, $operation->actorType, $operation->actorId] as $value) {
            if (trim($value) === '' || mb_strlen($value) > 255) {
                throw new TenantConfigurationInvalid('Operation actor and purpose must contain 1 to 255 characters.');
            }
        }
        $connection = $this->connections->core();
        if ($connection->transactionLevel() !== 0) {
            throw new TenantBoundaryViolation('Operation audit cannot be recorded inside an existing transaction.');
        }
        if (! $connection->getSchemaBuilder()->hasTable(TenancyTables::Operations)) {
            throw new TenantSchemaNotReady('The tenant operation audit store is not installed.');
        }
        $connection->table(TenancyTables::Operations)->insert([
            'id' => (string) Str::uuid(),
            'actor_type' => $operation->actorType,
            'actor_id' => $operation->actorId,
            'purpose' => $operation->purpose,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
