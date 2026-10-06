<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Database\Factories\TenantFactory;
use Nvl\Tenancy\Definitions\Tables\TenancyTables;

/**
 * Represents one canonical package-owned tenant directory entry.
 *
 * @property string $id Canonical tenant UUID.
 * @property string $name Operator-managed tenant name.
 * @property TenantStatus $status Current tenant lifecycle state.
 * @property Carbon|null $created_at Creation time.
 * @property Carbon|null $updated_at Last update time.
 *
 * @api
 *
 * @nvl-consumer-read id
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUuids;

    public const string TABLE = TenancyTables::Tenants;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'status',
    ];

    protected $table = self::TABLE;

    /** Use the deployment's explicit core storage connection. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('tenancy') ?? parent::getConnectionName());
    }

    /** @return array<string, class-string<TenantStatus>> */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
        ];
    }

    /** Resolve the configured package storage table. */
    public function getTable(): string
    {
        return TenancyTables::get(TenancyTables::Tenants);
    }

    /**
     * Return the package's runtime fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
