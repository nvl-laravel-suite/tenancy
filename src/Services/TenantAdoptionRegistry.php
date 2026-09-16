<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use ReflectionClass;

/** Retains package-owned adoption adapter class names without scoped runtime state. */
final class TenantAdoptionRegistry
{
    /** @var array<string, class-string<TenantAdoptionAdapter>> */
    private array $adapters = [];

    /** @param class-string<TenantAdoptionAdapter> $adapter */
    public function register(string $package, string $adapter): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,190}$/D', $package) !== 1 || ! class_exists($adapter)
            || ! (new ReflectionClass($adapter))->implementsInterface(TenantAdoptionAdapter::class) || ! (new ReflectionClass($adapter))->isInstantiable()
            || (isset($this->adapters[$package]) && $this->adapters[$package] !== $adapter)) {
            throw new TenantConfigurationInvalid('Invalid or conflicting adoption adapter registration.');
        }
        $this->adapters[$package] = $adapter;
    }

    /**
     * Return immutable registrations for internal graph resolution.
     *
     * @internal
     *
     * @return array<string, class-string<TenantAdoptionAdapter>>
     */
    public function all(): array
    {
        return $this->adapters;
    }
}
