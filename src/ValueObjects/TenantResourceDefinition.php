<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

use Illuminate\Database\Eloquent\Model;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use ReflectionClass;

/** Immutable package-owned resource classification and canonical parent policy. */
final readonly class TenantResourceDefinition
{
    /**
     * Declare one concrete resource and its ownership capabilities.
     *
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $key,
        public string $family,
        public string $model,
        public TenantResourceKind $kind = TenantResourceKind::Root,
        public ?string $parentResource = null,
        public ?string $parentRelation = null,
        public bool $allowsPlatformCatalog = false,
        public bool $allowsPlatformRows = false,
    ) {
        if ($key === '' || strlen($key) > 191 || trim($key) !== $key || $family === '' || trim($family) !== $family
            || ! (new ReflectionClass($model))->isSubclassOf(Model::class) || ! (new ReflectionClass($model))->isInstantiable()) {
            throw new TenantConfigurationInvalid('A resource requires a bounded key, family and concrete Eloquent model.');
        }
        if ($kind === TenantResourceKind::Inherited) {
            if ($parentRelation === null || $parentRelation === '' || ! method_exists($model, $parentRelation)
                || $parentResource === '' || $this->usesOwnershipKey()) {
                throw new TenantConfigurationInvalid('Inherited resources require a canonical parent relation and cannot declare independent platform ownership.');
            }
        } elseif ($parentResource !== null || $parentRelation !== null) {
            throw new TenantConfigurationInvalid('Only inherited resources may declare a parent.');
        }
        if ($kind === TenantResourceKind::Platform && $this->usesOwnershipKey()) {
            throw new TenantConfigurationInvalid('Fixed platform resources cannot declare mixed ownership.');
        }
    }

    /** Determine whether the resource stores the mixed platform-or-tenant discriminator. */
    public function usesOwnershipKey(): bool
    {
        return $this->allowsPlatformCatalog || $this->allowsPlatformRows;
    }
}
