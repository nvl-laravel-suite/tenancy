<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;

/**
 * Represents one canonical package-owned tenant directory entry.
 *
 * @property string $id Canonical tenant UUID.
 * @property string $name Operator-managed tenant name.
 * @property TenantStatus $status Current tenant lifecycle state.
 * @property Carbon|null $created_at Creation time.
 * @property Carbon|null $updated_at Last update time.
 */
final class Tenant extends Model
{
    use HasUuids;

    public const string TABLE = 'nvl_tenancy_tenants';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'status',
    ];

    protected $table = self::TABLE;

    /** Use the deployment's explicit core storage connection. */
    public function getConnectionName(): ?string
    {
        $connection = config('tenancy.connection');
        if ($connection !== null && ! is_string($connection)) {
            throw new TenantConfigurationInvalid('tenancy.connection must be null or a connection name.');
        }

        return $connection ?? parent::getConnectionName();
    }

    /** @return array<string, class-string<TenantStatus>> */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
        ];
    }
}
