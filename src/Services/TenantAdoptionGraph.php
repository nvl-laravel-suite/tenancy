<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;

/**
 * Resolves package adapter ownership and dependency ordering on the canonical connection.
 *
 * @internal
 *
 * @phpstan-type Graph array{packages: list<string>, resources: list<string>, adapters: array<string, TenantAdoptionAdapter>, owners: array<string, string>, manifest: string}
 */
final readonly class TenantAdoptionGraph
{
    /** Resolve current-scope adapters only for the duration of an operation. */
    public function __construct(private Container $container, private TenantAdoptionRegistry $adoptions, private TenantResourceRegistry $resources, private TenantOwnershipConfiguration $ownership, private EffectiveTenantConnection $connections, private Repository $configuration) {}

    /**
     * Resolve and topologically order the complete selected graph.
     *
     * @param  list<string>  $packages
     * @return Graph
     */
    public function resolve(array $packages): array
    {
        if ($packages === []) {
            throw new TenantConfigurationInvalid('Select at least one registered adoption package.');
        }
        $this->ownership->validate();
        $adapters = [];
        $owners = [];
        $families = [];
        $registered = $this->adoptions->all();
        foreach ($registered as $package => $class) {
            $adapter = $this->container->make($class);
            if (! $adapter instanceof TenantAdoptionAdapter) {
                throw new TenantConfigurationInvalid('An adoption binding must implement TenantAdoptionAdapter.');
            }
            $adapters[$package] = $adapter;
            foreach ($adapter->resources() as $key) {
                if (isset($owners[$key])) {
                    throw new TenantConfigurationInvalid('A resource must have exactly one adoption owner.');
                }
                $definition = $this->resources->get($key);
                if (mb_strlen($key) > 191) {
                    throw new TenantConfigurationInvalid('Resource identifiers cannot exceed 191 characters.');
                }
                $owners[$key] = $package;
                $families[$definition->family][] = $key;
            }
        }
        $ordered = [];
        $visiting = [];
        $visit = function (string $package) use (&$visit, &$ordered, &$visiting, $adapters, $owners, $families): void {
            if (isset($ordered[$package])) {
                return;
            }
            if (! isset($adapters[$package]) || isset($visiting[$package])) {
                throw new TenantConfigurationInvalid('Unknown adoption adapter or cyclic package dependency.');
            }
            $visiting[$package] = true;
            $dependencies = [];
            foreach ($adapters[$package]->resources() as $key) {
                $definition = $this->resources->get($key);
                $required = [];
                foreach ($this->resources->dependencies()[$definition->family] ?? [] as $family) {
                    $required = [...$required, ...($families[$family] ?? throw new TenantConfigurationInvalid('A dependency has no registered adoption adapter.'))];
                }
                if ($definition->parentResource !== null) {
                    $required[] = $definition->parentResource;
                } elseif ($definition->kind === TenantResourceKind::Inherited) {
                    foreach ($this->ownership->parentTypes($key) as $class) {
                        $required[] = $this->resources->forModel(new $class)->key;
                    }
                }
                foreach ($required as $resource) {
                    $owner = $owners[$resource] ?? throw new TenantConfigurationInvalid('A canonical parent has no registered adoption adapter.');
                    if ($owner !== $package) {
                        $dependencies[$owner] = true;
                    }
                }
            }
            ksort($dependencies);
            foreach (array_keys($dependencies) as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$package]);
            $ordered[$package] = $adapters[$package];
        };
        $packages = array_values(array_unique($packages));
        sort($packages);
        foreach ($packages as $package) {
            $visit($package);
        }
        $keys = [];
        $manifest = [];
        foreach ($ordered as $package => $adapter) {
            $owned = $adapter->resources();
            sort($owned);
            $manifest[$package] = ['class' => $registered[$package], 'effective_class' => $adapter::class, 'resources' => $owned];
            foreach ($owned as $key) {
                $definition = $this->resources->get($key);
                $model = new $definition->model;
                if ($model->getConnection() !== $this->connections->core()) {
                    throw new TenantConfigurationInvalid('Adoption resources must use the canonical core connection instance.');
                }
                $keys[] = $key;
            }
        }
        sort($keys);
        $packages = array_keys($ordered);
        sort($packages);

        return [
            'packages' => $packages,
            'resources' => $keys,
            'adapters' => $ordered,
            'owners' => array_intersect_key($owners, array_flip($keys)),
            'manifest' => hash('sha256', json_encode([
                'adapters' => $manifest,
                'strategy' => $this->configuration->get('tenancy.strategy'),
                'profile' => $this->configuration->get('tenancy.profile'),
                'connection' => $this->connections->core()->getName(),
                'directory' => [
                    'driver' => $this->configuration->get('tenancy.directory.driver'),
                    'adapter' => $this->configuration->get('tenancy.directory.adapter'),
                    'effective_adapter' => $this->container->make(TenantDirectory::class)::class,
                ],
            ], JSON_THROW_ON_ERROR)),
        ];
    }
}
