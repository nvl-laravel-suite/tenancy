<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Contracts\TenantParentResolver;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/**
 * Derives structural ownership modes and deterministic per-resource adoption fingerprints.
 *
 * @internal
 */
final readonly class TenantOwnershipConfiguration
{
    /**
     * Stateful runtime providers whose integration must explicitly register ownership and adoption.
     * These strings are compatibility metadata, not dependencies on optional domain packages.
     *
     * @var array<string, string>
     */
    private const array RUNTIME_PROVIDERS = [
        'activity' => 'Nvl\\Activity\\Providers\\ActivityServiceProvider',
        'auth' => 'Nvl\\Auth\\Providers\\AuthServiceProvider',
        'comments' => 'Nvl\\Comments\\Providers\\CommentsServiceProvider',
        'content' => 'Nvl\\Content\\Providers\\ContentServiceProvider',
        'csv' => 'Nvl\\Csv\\Providers\\CsvServiceProvider',
        'forms' => 'Nvl\\Forms\\Providers\\FormsServiceProvider',
        'mail-notifications' => 'Nvl\\MailNotifications\\Providers\\MailNotificationsServiceProvider',
        'media' => 'Nvl\\Media\\Providers\\MediaServiceProvider',
        'metafields' => 'Nvl\\Metafields\\Providers\\MetafieldsServiceProvider',
        'pages' => 'Nvl\\Pages\\Providers\\PagesServiceProvider',
        'seo' => 'Nvl\\Seo\\Providers\\SeoServiceProvider',
        'settings' => 'Nvl\\Settings\\Providers\\SettingsServiceProvider',
        'taxonomy' => 'Nvl\\Taxonomy\\Providers\\TaxonomyServiceProvider',
        'templates' => 'Nvl\\Templates\\Providers\\TemplatesServiceProvider',
        'translations' => 'Nvl\\Translations\\Providers\\TranslationsServiceProvider',
    ];

    /** Create a registry-aware ownership validator without retaining request state. */
    public function __construct(private Repository $configuration, private TenantResourceRegistry $registry, private EffectiveTenantConnection $connections, private Container $container) {}

    /** Validate complete package registrations after all provider boot methods have run. */
    public function validate(): void
    {
        $families = [];
        foreach ($this->registry->all() as $resource) {
            $families[$resource->family][] = $resource;
            $this->mode($resource);
        }
        $overrides = $this->configuration->get('tenancy.resources', []);
        if (! is_array($overrides)) {
            throw new TenantConfigurationInvalid('tenancy.resources must be an array.');
        }
        foreach ($overrides as $family => $mode) {
            if (! is_string($family) || ! isset($families[$family])) {
                throw new TenantConfigurationInvalid(sprintf('Unknown tenancy resource family [%s].', mb_strimwidth((string) $family, 0, 160, '...')));
            }
            if (! in_array($mode, ['tenant', 'platform'], true)) {
                throw new TenantConfigurationInvalid('Resource ownership modes must be tenant or platform.');
            }
            $mutable = array_filter($families[$family], static fn (TenantResourceDefinition $resource): bool => $resource->kind !== TenantResourceKind::Platform);
            if ($mutable === [] && $mode !== 'platform') {
                throw new TenantConfigurationInvalid('A fixed platform family cannot be reclassified.');
            }
            foreach ($mutable as $resource) {
                if ($resource->kind === TenantResourceKind::Root && $mode === 'platform' && ! $resource->allowsPlatformCatalog && ! $resource->allowsPlatformRows) {
                    throw new TenantConfigurationInvalid('The family does not support the requested ownership mode.');
                }
            }
        }
        foreach ($this->registry->dependencies() as $family => $dependencies) {
            foreach ($dependencies as $dependency) {
                if (! isset($families[$family], $families[$dependency])) {
                    throw new TenantConfigurationInvalid('An ownership dependency names an unregistered family.');
                }
                foreach ($families[$family] as $resource) {
                    foreach ($families[$dependency] as $required) {
                        if ($resource->kind === TenantResourceKind::Platform || $required->kind === TenantResourceKind::Platform) {
                            continue;
                        }
                        if ($this->mode($resource) !== $this->mode($required)) {
                            throw new TenantConfigurationInvalid('Dependent families must have compatible ownership modes.');
                        }
                    }
                }
            }
        }
    }

    /** Declare a code-owned dependency between mutable family ownership modes. */
    public function requireCompatible(string $family, string $dependency): void
    {
        $this->registry->requireCompatible($family, $dependency);
    }

    /**
     * List incompatible loaded runtime families without resolving adapters or probing storage.
     *
     * @return list<string>
     */
    public function incompatibleFamilies(): array
    {
        if (! $this->container instanceof Application) {
            return [];
        }
        $families = array_fill_keys(array_map(static fn (TenantResourceDefinition $resource): string => $resource->family, $this->registry->all()), true);
        $adapters = $this->container->make(TenantAdoptionRegistry::class)->all();
        $incompatible = [];
        foreach (self::RUNTIME_PROVIDERS as $family => $provider) {
            if ($this->container->providerIsLoaded($provider) && (($family !== 'csv' && ! isset($families[$family])) || ! isset($adapters[$family]))) {
                $incompatible[] = $family;
            }
        }

        return $incompatible;
    }

    /** Reject actual tenant activation while loaded runtime packages lack their integration. */
    public function assertReady(): void
    {
        $this->validate();
        if ($this->configuration->get('tenancy.enabled') === true && ($incompatible = $this->incompatibleFamilies()) !== []) {
            throw new TenantConfigurationInvalid('Loaded runtime packages require tenancy integration: '.implode(', ', $incompatible).'.');
        }
    }

    /**
     * Describe deployment configuration separately from lazy storage/adoption readiness.
     *
     * @return array{enabled: bool, profile: string, connection: string, compatible: bool, incompatible_families: list<string>, resources: array<string, array{family: string, mode: string, model: string, table: string, connection: string}>, schema: string}
     */
    public function inspect(): array
    {
        $this->validate();
        $resources = [];
        foreach ($this->registry->all() as $key => $resource) {
            $model = new $resource->model;
            $resources[$key] = [
                'family' => $resource->family, 'mode' => $this->mode($resource), 'model' => $resource->model,
                'table' => $model->getTable(), 'connection' => $this->connections->name($model->getConnectionName()),
            ];
        }
        $incompatible = $this->incompatibleFamilies();
        $enabled = $this->configuration->get('tenancy.enabled') === true;

        return [
            'enabled' => $enabled, 'profile' => 'application', 'connection' => $this->connections->core()->getName() ?? '',
            'compatible' => ! $enabled || $incompatible === [], 'incompatible_families' => $incompatible,
            'resources' => $resources, 'schema' => 'not-probed',
        ];
    }

    /** Return effective ownership, inheriting canonical parents and rejecting contradictory overrides. */
    public function mode(TenantResourceDefinition $resource): string
    {
        return $this->deriveMode($resource, []);
    }

    /**
     * Resolve validated package allowlists before any persisted morph class can be instantiated.
     *
     * @return array<string, class-string<Model>>
     */
    public function parentTypes(string $resource): array
    {
        $resolver = $this->registry->parentResolver($resource);
        $adapter = $this->container->make($resolver);
        if (! $adapter instanceof TenantParentResolver) {
            throw new TenantConfigurationInvalid('The parent resolver binding must implement TenantParentResolver.');
        }

        return $this->validateTypes($adapter->types());
    }

    /** Fingerprint one resource and its ownership dependency closure independently of unrelated packages. */
    public function fingerprint(string $resource): string
    {
        $this->validate();
        $definitions = [];
        $this->collect($this->registry->get($resource), $definitions);
        ksort($definitions);

        return hash('sha256', json_encode([
            'version' => 1,
            'strategy' => $this->configuration->get('tenancy.strategy'),
            'profile' => $this->configuration->get('tenancy.profile'),
            'connection' => $this->connections->core()->getName(),
            'directory' => [
                'driver' => $this->configuration->get('tenancy.directory.driver'),
                'adapter' => $this->configuration->get('tenancy.directory.adapter'),
                'effective_adapter' => $this->container->make(TenantDirectory::class)::class,
            ],
            'resources' => $definitions,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Hash the sorted selected resource-to-fingerprint map for an adoption run.
     *
     * @param  list<string>  $resources
     */
    public function hash(array $resources): string
    {
        $fingerprints = [];
        foreach ($resources as $resource) {
            $fingerprints[$resource] = $this->fingerprint($resource);
        }
        ksort($fingerprints);

        return hash('sha256', json_encode($fingerprints, JSON_THROW_ON_ERROR));
    }

    /**
     * Resolve the complete parent graph before applying a compatible child override.
     *
     * @param  array<string, true>  $visited
     */
    private function deriveMode(TenantResourceDefinition $resource, array $visited): string
    {
        if (isset($visited[$resource->key])) {
            throw new TenantConfigurationInvalid('Resource ownership contains a cycle.');
        }
        $visited[$resource->key] = true;
        $override = $this->configuration->get('tenancy.resources.'.$resource->family);
        if ($override !== null && ! in_array($override, ['tenant', 'platform'], true)) {
            throw new TenantConfigurationInvalid('Resource ownership modes must be tenant or platform.');
        }
        if ($resource->kind === TenantResourceKind::Platform) {
            return 'platform';
        }
        if ($resource->kind === TenantResourceKind::Root) {
            return $override ?? 'tenant';
        }
        $parents = [];
        if ($resource->parentResource !== null) {
            $parents[] = $this->registry->get($resource->parentResource);
        } else {
            foreach ($this->parentTypes($resource->key) as $parentClass) {
                $parents[] = $this->registry->forModel(new $parentClass);
            }
        }
        $mode = null;
        foreach ($parents as $parent) {
            $parentMode = $this->deriveMode($parent, $visited);
            if ($mode !== null && $mode !== $parentMode) {
                throw new TenantConfigurationInvalid('Polymorphic parents must have one consistent ownership mode.');
            }
            $mode = $parentMode;
        }
        if ($mode === null) {
            throw new TenantConfigurationInvalid('Inherited ownership requires at least one registered parent.');
        }
        if ($override !== null && $override !== $mode) {
            throw new TenantConfigurationInvalid('Inherited resources cannot override their parent ownership mode.');
        }

        return $mode;
    }

    /** Validate actual adapter output before constructing parent models.
     * @param  array<mixed>  $types
     * @return array<string, class-string<Model>>
     */
    private function validateTypes(array $types): array
    {
        foreach ($types as $type => $model) {
            if (! is_string($type) || $type === '' || ! is_string($model) || ! is_a($model, Model::class, true)) {
                throw new TenantConfigurationInvalid('Canonical parent allowlists require persisted types and concrete models.');
            }
            $this->registry->forModel(new $model);
        }
        ksort($types);

        return $types;
    }

    /**
     * Collect immutable structural ownership facts, terminating dependency cycles.
     *
     * @param  array<string, array<string, mixed>>  $definitions
     */
    private function collect(TenantResourceDefinition $resource, array &$definitions): void
    {
        if (isset($definitions[$resource->key])) {
            return;
        }
        $model = new $resource->model;
        $types = $resource->kind === TenantResourceKind::Inherited && $resource->parentResource === null ? $this->parentTypes($resource->key) : [];
        $dependencies = $this->registry->dependencies()[$resource->family] ?? [];
        sort($dependencies);
        $definitions[$resource->key] = [
            'family' => $resource->family, 'model' => $resource->model, 'kind' => $resource->kind->value,
            'parent' => $resource->parentResource, 'relation' => $resource->parentRelation,
            'catalog' => $resource->allowsPlatformCatalog, 'platform_rows' => $resource->allowsPlatformRows,
            'mode' => $this->mode($resource), 'table' => $model->getTable(),
            'connection' => $this->connections->name($model->getConnectionName()),
            'columns' => ['tenant_id', ...($resource->allowsPlatformCatalog || $resource->allowsPlatformRows ? ['ownership_key'] : [])],
            ...($resource->allowsPlatformCatalog || $resource->allowsPlatformRows ? ['ownership_key_format' => 'platform|tenant:<uuid>'] : []),
            'parent_resolver' => $types === [] ? null : $this->registry->parentResolver($resource->key),
            'parent_types' => $types, 'dependencies' => $dependencies,
        ];
        if ($resource->parentResource !== null) {
            $this->collect($this->registry->get($resource->parentResource), $definitions);
        }
        foreach ($types as $parentModel) {
            $this->collect($this->registry->forModel(new $parentModel), $definitions);
        }
        foreach ($this->registry->all() as $dependency) {
            if (in_array($dependency->family, $dependencies, true)) {
                $this->collect($dependency, $definitions);
            }
        }
    }
}
