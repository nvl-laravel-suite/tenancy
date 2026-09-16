<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/** Adds the test package's nullable ownership column using its own idempotent migration. */
final class PrepareRecordOwnership extends Migration
{
    /** Use the plan's canonical connection without changing global configuration. */
    public function __construct(private readonly Connection $database) {}

    /** Add ownership before the package backfill. */
    public function up(): void
    {
        $schema = $this->database->getSchemaBuilder();
        if (! $schema->hasColumn('tenancy_test_records', 'tenant_id')) {
            $schema->table('tenancy_test_records', static function (Blueprint $table): void {
                $table->uuid('tenant_id')->nullable();
            });
        }
    }
}
