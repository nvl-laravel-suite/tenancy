<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Contracts\TenantAdoptionMetadataValidator;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenancyException;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\TenantAdoptionMappings;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Exercises the real public adoption protocol with stable canonical record batches and DDL. */
class RecordAdoptionAdapter implements TenantAdoptionAdapter, TenantAdoptionMetadataValidator
{
    /** @var Closure(): void|null */
    public ?Closure $afterPrepare = null;

    /** @var Closure(): void|null */
    public ?Closure $afterBackfill = null;

    /** @var Closure(): void|null */
    public ?Closure $afterActivate = null;

    /** @var Closure(): void|null */
    public ?Closure $afterVerify = null;

    /** @var Closure(): void|null */
    public ?Closure $onValidate = null;

    /** Create package-owned canonical storage access. */
    public function __construct(private DatabaseManager $database, private TenantAdoptionMappings $mappings, private TenantDirectory $directory) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['tests.records'];
    }

    /** Validate the exact fixture destination-ID metadata without assuming the destination exists. */
    public function validateAssignment(TenantAssignment $assignment): void
    {
        ($this->onValidate ?? static function (): void {})();
        if ($assignment->metadata === []) {
            return;
        }
        if (array_keys($assignment->metadata) !== ['destination'] || ! is_array($assignment->metadata['destination'])
            || array_keys($assignment->metadata['destination']) !== ['id'] || ! is_string($assignment->metadata['destination']['id'])) {
            throw new TenantConfigurationInvalid('Unknown fixture mapping metadata.');
        }
        new TenantId($assignment->metadata['destination']['id']);
    }

    /** Run the package's nullable ownership migration and recheck actual schema on retries. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $migration = new PrepareRecordOwnership($this->database->connection($plan->connection));
        $migration->up();
        ($this->afterPrepare ?? static function (): void {})();
    }

    /** Backfill stable canonical primary keys without allowing cross-tenant transfer. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $connection = $this->database->connection($plan->connection);
        $query = $connection->table('tenancy_test_records')->orderBy('id')->limit($limit);
        if ($cursor !== null) {
            $query->where('id', '>', $cursor);
        }
        $rows = $query->get();
        foreach ($rows as $row) {
            if (! is_string($row->id)) {
                throw new TenantConfigurationInvalid('Invalid fixture record identifier.');
            }
            $tenant = $this->mappings->tenantFor($plan, 'tests.records', $row->id);
            if ($row->tenant_id !== null && $row->tenant_id !== $tenant->value) {
                throw new TenantBoundaryViolation('Adoption cannot transfer existing tenant ownership.');
            }
            $connection->table('tenancy_test_records')->where('id', $row->id)->update(['tenant_id' => $tenant->value]);
        }
        ($this->afterBackfill ?? static function (): void {})();
        $last = $rows->last();
        $next = $last !== null && is_string($last->id) && $rows->count() === $limit ? $last->id : null;

        return new TenantBackfillResult($next, $rows->count());
    }

    /** Validate every physical row, including soft-deleted records and canonical parents. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $connection = $this->database->connection($plan->connection);
        if (! $connection->getSchemaBuilder()->hasColumn('tenancy_test_records', 'tenant_id')) {
            return new TenantVerification(['schema_missing']);
        }
        $errors = [];
        foreach ($connection->table('tenancy_test_records')->orderBy('id')->cursor() as $row) {
            if (! is_string($row->id) || ! is_string($row->tenant_id)) {
                $errors[] = 'owner_missing';

                continue;
            }
            try {
                $tenant = new TenantId($row->tenant_id);
                if ($this->directory->find($tenant)->status !== TenantStatus::Active || $this->mappings->tenantFor($plan, 'tests.records', $row->id)->value !== $tenant->value) {
                    $errors[] = 'owner_invalid:'.$row->id;
                }
            } catch (TenancyException) {
                $errors[] = 'owner_invalid:'.$row->id;
            }
            if ($row->parent_id !== null && $connection->table('tenancy_test_records')->where('id', $row->parent_id)->value('tenant_id') !== $row->tenant_id) {
                $errors[] = 'parent_invalid:'.$row->id;
            }
            if (count($errors) >= 100) {
                break;
            }
        }

        ($this->afterVerify ?? static function (): void {})();

        return new TenantVerification($errors);
    }

    /** Install actual final ownership constraints and a portable tenant index idempotently. */
    public function activate(TenantAdoptionPlan $plan): void
    {
        $schema = $this->database->connection($plan->connection)->getSchemaBuilder();
        foreach ($schema->getColumns('tenancy_test_records') as $column) {
            if ($column['name'] === 'tenant_id' && $column['nullable']) {
                $schema->table('tenancy_test_records', static function (Blueprint $table): void {
                    $table->uuid('tenant_id')->nullable(false)->change();
                });
            }
        }
        if (! $schema->hasIndex('tenancy_test_records', 'tests_records_tenant_idx')) {
            $schema->table('tenancy_test_records', static function (Blueprint $table): void {
                $table->index('tenant_id', 'tests_records_tenant_idx');
            });
        }
        ($this->afterActivate ?? static function (): void {})();
    }
}
