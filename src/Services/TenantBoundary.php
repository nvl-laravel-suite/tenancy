<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Grammar;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Tenancy\Exceptions\TenantInactive;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Enforces canonical ownership for registered records, queries, attributes and identities. */
final readonly class TenantBoundary
{
    /** Resolve current scoped dependencies on every entry, including retained worker services. */
    public function __construct(private Container $container) {}

    /**
     * Apply required ownership predicates around every existing caller condition.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function query(Builder $query, string $resource): Builder
    {
        $definition = $this->registry()->get($resource);
        $this->assertModel($query->getModel(), $definition);
        $this->assertQueryStorage($query);
        $snapshot = $this->admit($definition);
        if ($snapshot === null) {
            return $query;
        }
        $this->scopeQuery($query, $definition, $snapshot, []);

        return $query;
    }

    /** Verify persisted ownership facts without trusting dirty attributes or loaded relations. */
    public function assertRecord(Model $record, string $resource): void
    {
        $definition = $this->registry()->get($resource);
        $this->assertModel($record, $definition);
        $snapshot = $this->admit($definition);
        if ($snapshot !== null) {
            $this->assertCanonical($record, $definition, $snapshot, []);
        }
    }

    /**
     * Generate ownership fields for a new root; children must derive ownership from their canonical parent.
     *
     * @return array{tenant_id?: string|null, ownership_key?: string}
     */
    public function attributes(string $resource): array
    {
        $definition = $this->registry()->get($resource);
        $snapshot = $this->admit($definition);
        if ($snapshot === null) {
            return [];
        }
        if ($definition->kind !== TenantResourceKind::Root) {
            throw new TenantBoundaryViolation('Ownership attributes require a root resource.');
        }
        $attributes = ['tenant_id' => $snapshot->tenantId?->value];
        if ($definition->allowsPlatformCatalog || $definition->allowsPlatformRows) {
            $attributes['ownership_key'] = $this->ownershipKey($snapshot);
        }

        return $attributes;
    }

    /** Derive an isolated identity after verifying the resource and current context. */
    public function key(string $resource, string $identity): string
    {
        $definition = $this->registry()->get($resource);
        $snapshot = $this->admit($definition);
        if ($snapshot === null) {
            return $identity;
        }
        $model = new $definition->model;
        $connectionName = $this->container->make(EffectiveTenantConnection::class)->name($model->getConnectionName());

        return 'nvl:tenant:'.hash('sha256', json_encode([
            $connectionName, $resource, $snapshot->mode->value, $snapshot->tenantId?->value, $identity,
        ], JSON_THROW_ON_ERROR));
    }

    /** Validate composition and schema, then recheck directory status without caching admission. */
    private function admit(TenantResourceDefinition $resource): ?TenantContextSnapshot
    {
        if ($this->container->make(Repository::class)->get('tenancy.enabled') === true) {
            $this->ownership()->assertReady();
        }
        $this->container->make(TenantInstallationState::class)->assertUsable($resource->key);
        if ($this->container->make(Repository::class)->get('tenancy.enabled') !== true) {
            return null;
        }
        $snapshot = $this->snapshot($this->container->make(TenantContext::class));
        if (! in_array($snapshot->mode, [TenantContextMode::Tenant, TenantContextMode::Platform], true)) {
            throw new TenantContextMissing;
        }
        $this->assertMode($resource, $snapshot);
        if ($snapshot->tenantId !== null) {
            $tenant = $this->container->make(TenantDirectory::class)->find($snapshot->tenantId);
            if ($tenant->id->value !== $snapshot->tenantId->value) {
                throw new TenantBoundaryViolation('The directory returned a different tenant.');
            }
            $lease = $this->container->make(TenantMaintenanceLease::class);
            if ($lease->active() && ! $lease->admits($snapshot->tenantId)) {
                throw new TenantBoundaryViolation('The current tenant differs from the active maintenance lease.');
            }
            if ($tenant->status !== TenantStatus::Active && ! $lease->admits($snapshot->tenantId)) {
                throw new TenantInactive;
            }
        }

        return $snapshot;
    }

    /** Read a valid current scope through the public contract. */
    private function snapshot(TenantContext $context): TenantContextSnapshot
    {
        if ($context instanceof ScopedTenantContext && $context->invalid()) {
            throw new TenantBoundaryViolation('The tenant scope has been invalidated.');
        }

        return $context->snapshot();
    }

    /** Deny generic vocabulary access and platform-wide access to tenant roots. */
    private function assertMode(TenantResourceDefinition $resource, TenantContextSnapshot $snapshot): void
    {
        if ($resource->kind === TenantResourceKind::Platform) {
            throw new TenantBoundaryViolation('Platform vocabulary requires its package-owned explicit reader.');
        }
        $mode = $this->ownership()->mode($resource);
        if (($snapshot->mode === TenantContextMode::Tenant && $mode === 'platform')
            || ($snapshot->mode === TenantContextMode::Platform && $resource->kind === TenantResourceKind::Root
                && ! $resource->allowsPlatformCatalog && ! $resource->allowsPlatformRows)) {
            throw new TenantBoundaryViolation('The context cannot access this resource ownership mode.');
        }
    }

    /** Encode a portable mixed-table partition identity using the canonical tenant UUID. */
    private function ownershipKey(TenantContextSnapshot $snapshot): string
    {
        return $snapshot->tenantId === null ? 'platform' : 'tenant:'.$snapshot->tenantId->value;
    }

    /** Require the registered model lineage, canonical table and canonical Laravel connection instance. */
    private function assertModel(Model $model, TenantResourceDefinition $resource): void
    {
        $canonical = new $resource->model;
        if (! is_a($model, $resource->model) || $model->getTable() !== $canonical->getTable()
            || $model->getConnection() !== $canonical->getConnection()) {
            throw new TenantBoundaryViolation('The record does not match the registered resource storage.');
        }
    }

    /**
     * Validate actual SQL storage before installation admission or disabled compatibility.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    private function assertQueryStorage(Builder $query): void
    {
        $base = $query->getQuery();
        if ($base->getConnection() !== $query->getModel()->getConnection()) {
            throw new TenantBoundaryViolation('The SQL builder does not use the registered resource connection.');
        }
        $table = $query->getModel()->getTable();
        $from = $base->from;
        $isCanonicalStorage = $from === $table
            || (is_string($from) && preg_match('/^'.preg_quote($table, '/').'\s+as\s+laravel_reserved_\d+$/i', $from) === 1);
        if ($base->unions !== null || ! $isCanonicalStorage) {
            throw new TenantBoundaryViolation('Ownership queries require their canonical table without unions.');
        }
    }

    /**
     * Compose tenant predicates and canonical-parent subqueries without interpreting unknown ownership as global.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  list<string>  $visited
     */
    private function scopeQuery(Builder $query, TenantResourceDefinition $resource, TenantContextSnapshot $snapshot, array $visited): void
    {
        if (in_array($resource->key, $visited, true)) {
            throw new TenantBoundaryViolation('Canonical ownership contains a cycle.');
        }
        $visited[] = $resource->key;
        $this->assertMode($resource, $snapshot);
        $this->assertQueryStorage($query);
        $base = $query->getQuery();
        if ($base->wheres !== []) {
            $nested = $base->forNestedWhere();
            $nested->wheres = $base->wheres;
            $nested->setBindings($base->getRawBindings()['where'], 'where');
            $base->wheres = [];
            $base->setBindings([], 'where');
            $base->addNestedWhereQuery($nested);
        }
        $query->where($query->getModel()->qualifyColumn('tenant_id'), $snapshot->tenantId?->value);
        if ($resource->allowsPlatformCatalog || $resource->allowsPlatformRows) {
            $query->where($query->getModel()->qualifyColumn('ownership_key'), $this->ownershipKey($snapshot));
        }
        if ($resource->kind === TenantResourceKind::Inherited) {
            $relation = $this->parentRelation($resource);
            $parents = $relation instanceof MorphTo ? $this->ownership()->parentTypes($resource->key) : ['' => $relation->getRelated()::class];
            $query->where(function (Builder $group) use ($parents, $relation, $resource, $snapshot, $visited): void {
                $group->whereRaw('1 = 0');
                foreach ($parents as $type => $class) {
                    $parent = new $class;
                    $definition = $this->parentDefinition($parent, $resource);
                    $this->container->make(TenantInstallationState::class)->assertUsable($definition->key);
                    $parentQuery = $parent->newQuery();
                    $this->scopeQuery($parentQuery, $definition, $snapshot, $visited);
                    $group->orWhere(function (Builder $branch) use ($relation, $parentQuery, $type): void {
                        if ($relation instanceof MorphTo) {
                            $branch->where($branch->qualifyColumn($relation->getMorphType()), $type);
                            $foreignKey = $branch->qualifyColumn($relation->getForeignKeyName());
                            $ownerKey = $parentQuery->qualifyColumn($relation->getOwnerKeyName() ?: $parentQuery->getModel()->getKeyName());
                            $branch->whereIn(
                                $this->textIdentity($branch, $foreignKey),
                                $parentQuery->select($this->textIdentity($parentQuery, $ownerKey)),
                            );

                            return;
                        }
                        $branch->whereIn($branch->qualifyColumn($relation->getForeignKeyName()), $parentQuery->select($parentQuery->qualifyColumn($relation->getOwnerKeyName() ?: $parentQuery->getModel()->getKeyName())));
                    });
                }
            });
        }
    }

    /**
     * Cast polymorphic identities to their portable persisted string representation.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function textIdentity(Builder $query, string $column): Expression
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);
        $type = in_array(
            $query->getModel()->getConnection()->getDriverName(),
            ['mysql', 'mariadb'],
            true,
        ) ? 'CHAR' : 'TEXT';

        return new readonly class($wrapped, $type) implements Expression
        {
            public function __construct(
                private string $column,
                private string $type,
            ) {}

            public function getValue(Grammar $grammar): string
            {
                return "CAST({$this->column} AS {$this->type})";
            }
        };
    }

    /**
     * Fetch only canonical ownership facts and recursively verify the persisted parent identity.
     *
     * @param  list<string>  $visited
     */
    private function assertCanonical(Model $record, TenantResourceDefinition $resource, TenantContextSnapshot $snapshot, array $visited): void
    {
        $this->assertModel($record, $resource);
        $this->assertMode($resource, $snapshot);
        $key = $record->getRawOriginal($record->getKeyName());
        $identity = $resource->key.':'.(is_string($key) || is_int($key) ? $key : '');
        if (! $record->exists || $key === null || in_array($identity, $visited, true)) {
            throw new TenantBoundaryViolation;
        }
        $visited[] = $identity;
        $columns = [$record->getKeyName(), 'tenant_id'];
        if ($resource->allowsPlatformCatalog || $resource->allowsPlatformRows) {
            $columns[] = 'ownership_key';
        }
        $relation = $resource->kind === TenantResourceKind::Inherited ? $this->parentRelation($resource) : null;
        if ($relation !== null) {
            $columns[] = $relation->getForeignKeyName();
            if ($relation instanceof MorphTo) {
                $columns[] = $relation->getMorphType();
            }
        }
        $facts = $record->getConnection()->table($record->getTable())->where($record->getKeyName(), $key)->first(array_unique($columns));
        if ($facts === null || $facts->tenant_id !== $snapshot->tenantId?->value
            || (($resource->allowsPlatformCatalog || $resource->allowsPlatformRows)
                && $facts->ownership_key !== $this->ownershipKey($snapshot))) {
            throw new TenantBoundaryViolation;
        }
        if ($relation !== null) {
            $class = $relation->getRelated()::class;
            if ($relation instanceof MorphTo) {
                $type = $facts->{$relation->getMorphType()};
                $types = $this->ownership()->parentTypes($resource->key);
                if (! is_string($type) || ! isset($types[$type])) {
                    throw new TenantBoundaryViolation;
                }
                $class = $types[$type];
            }
            $parent = new $class;
            $definition = $this->parentDefinition($parent, $resource);
            $this->container->make(TenantInstallationState::class)->assertUsable($definition->key);
            $id = $parent->getConnection()->table($parent->getTable())->where($relation->getOwnerKeyName() ?: $parent->getKeyName(), $facts->{$relation->getForeignKeyName()})->value($parent->getKeyName());
            if ($id === null) {
                throw new TenantBoundaryViolation;
            }
            $canonical = $parent->newFromBuilder([$parent->getKeyName() => $id]);
            $this->assertCanonical($canonical, $definition, $snapshot, $visited);
        }
    }

    /**
     * Inspect relation metadata on a clean model before reading any caller or persisted morph attributes.
     *
     * @return BelongsTo<Model, Model>
     */
    private function parentRelation(TenantResourceDefinition $resource): BelongsTo
    {
        $model = new $resource->model;
        $relation = Relation::noConstraints(fn () => $model->{$resource->parentRelation}());
        if (! $relation instanceof BelongsTo) {
            throw new TenantConfigurationInvalid('Inherited ownership requires a canonical belongs-to relation.');
        }

        return $relation;
    }

    /** Validate the allowlisted parent's registration, declared policy and transaction connection. */
    private function parentDefinition(Model $parent, TenantResourceDefinition $child): TenantResourceDefinition
    {
        try {
            $definition = $this->registry()->forModel($parent);
        } catch (TenantConfigurationInvalid) {
            throw new TenantBoundaryViolation;
        }
        $childModel = new $child->model;
        if (($child->parentResource !== null && $child->parentResource !== $definition->key)
            || $parent->getConnection() !== $childModel->getConnection()) {
            throw new TenantBoundaryViolation;
        }

        return $definition;
    }

    /** Resolve the current package registry. */
    private function registry(): TenantResourceRegistry
    {
        return $this->container->make(TenantResourceRegistry::class);
    }

    /** Resolve current structural configuration without retaining mutable context. */
    private function ownership(): TenantOwnershipConfiguration
    {
        return $this->container->make(TenantOwnershipConfiguration::class);
    }
}
