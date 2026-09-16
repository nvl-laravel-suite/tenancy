<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\PackageTenantDirectory;

/** Creates the opt-in Tenancy directory, audit, installation, and adoption stores. */
return new class extends Migration
{
    /** Return the configured core connection for migration transaction ownership. */
    public function getConnection(): ?string
    {
        $connection = config('tenancy.connection');
        if ($connection !== null && ! is_string($connection)) {
            throw new TenantConfigurationInvalid('tenancy.connection must be null or a connection name.');
        }

        return $connection;
    }

    /** Create the complete core schema on the configured Tenancy connection. */
    public function up(): void
    {
        $schema = $this->schema();

        $schema->create('nvl_tenancy_tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('status', 32);
            $table->timestamps();

            $table->index('name', 'nvl_tenancy_tenants_name_idx');
            $table->index('status', 'nvl_tenancy_tenants_status_idx');
        });

        $schema->create('nvl_tenancy_adoption_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 32);
            $table->char('mapping_hash', 64);
            $table->char('configuration_hash', 64);
            $table->json('packages');
            $table->json('checkpoints');
            $table->timestamps();

            $table->index(['status', 'updated_at'], 'nvl_tenancy_adoption_runs_status_idx');
            $table->index('mapping_hash', 'nvl_tenancy_adoption_runs_mapping_idx');
        });

        $schema->create('nvl_tenancy_installation_state', function (Blueprint $table): void {
            $table->string('resource', 191)->primary();
            $table->unsignedInteger('schema_version');
            $table->string('state', 32);
            $table->char('configuration_hash', 64);
            $table->uuid('run_id');
            $table->timestamps();

            $table->index('state', 'nvl_tenancy_installation_state_state_idx');
            $table->index('run_id', 'nvl_tenancy_installation_state_run_idx');
            $table->foreign('run_id', 'nvl_tenancy_installation_state_run_fk')
                ->references('id')
                ->on('nvl_tenancy_adoption_runs')
                ->restrictOnDelete();
        });

        $schema->create('nvl_tenancy_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('actor_type', 255);
            $table->string('actor_id', 255);
            $table->string('purpose', 255);
            $table->timestamps();

            $table->index(['actor_type', 'actor_id'], 'nvl_tenancy_operations_actor_idx');
            $table->index('created_at', 'nvl_tenancy_operations_created_idx');
        });

        $packageOwnsDirectory = app(TenantDirectory::class) instanceof PackageTenantDirectory;

        $schema->create('nvl_tenancy_adoption_mappings', function (Blueprint $table) use ($packageOwnsDirectory): void {
            $table->uuid('run_id');
            $table->string('resource', 191);
            $table->string('record_id', 191);
            $table->uuid('tenant_id');
            $table->json('metadata');

            $table->unique(
                ['run_id', 'resource', 'record_id'],
                'nvl_tenancy_adoption_mappings_record_unique',
            );
            $table->index(
                ['tenant_id', 'resource'],
                'nvl_tenancy_adoption_mappings_tenant_idx',
            );
            $table->foreign('run_id', 'nvl_tenancy_adoption_mappings_run_fk')
                ->references('id')
                ->on('nvl_tenancy_adoption_runs')
                ->cascadeOnDelete();

            if ($packageOwnsDirectory) {
                $table->foreign('tenant_id', 'nvl_tenancy_adoption_mappings_tenant_fk')
                    ->references('id')
                    ->on('nvl_tenancy_tenants')
                    ->restrictOnDelete();
            }
        });
    }

    /** Remove the complete core schema from the configured Tenancy connection. */
    public function down(): void
    {
        $schema = $this->schema();
        $schema->dropIfExists('nvl_tenancy_adoption_mappings');
        $schema->dropIfExists('nvl_tenancy_operations');
        $schema->dropIfExists('nvl_tenancy_installation_state');
        $schema->dropIfExists('nvl_tenancy_adoption_runs');
        $schema->dropIfExists('nvl_tenancy_tenants');
    }

    /** Resolve the schema builder for the configured core connection. */
    private function schema(): Builder
    {
        return Schema::connection($this->getConnection());
    }
};
