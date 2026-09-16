<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Tenancy\Contracts\TenantParentResolver;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;
use ReflectionClass;

/** Stores immutable package definitions without retaining models or tenant state. */
final class TenantResourceRegistry
{
    /** @var array<string, TenantResourceDefinition> */
    private array $resources = [];

    /** @var array<string, class-string<TenantParentResolver>> */
    private array $parentResolvers = [];

    /** @var array<string, list<string>> */
    private array $dependencies = [];

    /**
     * Register a package-owned canonical morph allowlist.
     *
     * @internal
     *
     * @param  class-string<TenantParentResolver>  $resolver
     */
    public function registerParentResolver(string $resource, string $resolver): void
    {
        if (! (new ReflectionClass($resolver))->implementsInterface(TenantParentResolver::class) || ! (new ReflectionClass($resolver))->isInstantiable() || (isset($this->parentResolvers[$resource]) && $this->parentResolvers[$resource] !== $resolver)) {
            throw new TenantConfigurationInvalid('Invalid or conflicting canonical parent resolver.');
        }
        $this->parentResolvers[$resource] = $resolver;
    }

    /**
     * Return the required package-owned resolver class without constructing it.
     *
     * @internal
     *
     * @return class-string<TenantParentResolver>
     */
    public function parentResolver(string $resource): string
    {
        return $this->parentResolvers[$resource] ?? throw new TenantConfigurationInvalid('A polymorphic resource requires a package-owned parent allowlist.');
    }

    /**
     * Declare a code-owned family dependency that must share ownership mode.
     *
     * @internal
     */
    public function requireCompatible(string $family, string $dependency): void
    {
        $this->dependencies[$family] = array_values(array_unique([...($this->dependencies[$family] ?? []), $dependency]));
    }

    /**
     * Return declared family dependency edges.
     *
     * @internal
     *
     * @return array<string, list<string>>
     */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /** Register an identical definition idempotently and reject ambiguous ownership graphs. */
    public function register(TenantResourceDefinition $resource): void
    {
        foreach ($this->resources as $existing) {
            if ($existing->key === $resource->key || $existing->model === $resource->model) {
                if ($existing == $resource) {
                    return;
                }
                throw new TenantConfigurationInvalid('Conflicting resource key or model registration.');
            }
        }
        $resources = [...$this->resources, $resource->key => $resource];
        foreach ($resources as $definition) {
            $visited = [];
            while ($definition !== null) {
                if (isset($visited[$definition->key])) {
                    throw new TenantConfigurationInvalid('Resource ownership contains a cycle.');
                }
                $visited[$definition->key] = true;
                $definition = $definition->parentResource === null ? null : ($resources[$definition->parentResource] ?? null);
            }
        }
        $this->resources = $resources;
    }

    /** Resolve a registered package key or fail closed. */
    public function get(string $key): TenantResourceDefinition
    {
        return $this->resources[$key] ?? throw new TenantConfigurationInvalid("Unknown tenancy resource [{$key}].");
    }

    /** Resolve the exact registered concrete model without accepting subclasses implicitly. */
    public function forModel(Model $model): TenantResourceDefinition
    {
        foreach ($this->resources as $resource) {
            if ($model::class === $resource->model) {
                return $resource;
            }
        }
        throw new TenantConfigurationInvalid('The model has no registered tenant ownership policy.');
    }

    /**
     * Return registered immutable definitions.
     *
     * @return array<string, TenantResourceDefinition>
     */
    public function all(): array
    {
        return $this->resources;
    }
}
