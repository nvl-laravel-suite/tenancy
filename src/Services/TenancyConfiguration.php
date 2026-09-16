<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Contracts\TenantHttpResolver;
use Nvl\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Tenancy\Contracts\TenantSiteResolver;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use ReflectionClass;
use ReflectionFunction;

/**
 * Validates deployment-level tenancy configuration without resolving adapters.
 */
final readonly class TenancyConfiguration
{
    /**
     * @var array<string, class-string>
     */
    private const array ADAPTER_CONTRACTS = [
        'tenancy.directory.adapter' => TenantDirectory::class,
        'tenancy.resolvers.http' => TenantHttpResolver::class,
        'tenancy.resolvers.public_site' => TenantSiteResolver::class,
        'tenancy.access.membership' => TenantMembershipAccess::class,
        'tenancy.access.platform' => PlatformAccess::class,
    ];

    /**
     * Create a tenancy configuration validator.
     */
    public function __construct(
        private Repository $configuration,
        private Container $container,
    ) {}

    /**
     * Validate the complete cached configuration structure and adapter contracts.
     *
     * @throws TenantConfigurationInvalid When any configured value is unsupported
     */
    public function validate(): void
    {
        $tenancy = $this->configuration->get('tenancy');

        if (! is_array($tenancy)) {
            throw new TenantConfigurationInvalid('tenancy must be an array.');
        }

        if ($this->containsClosure($tenancy)) {
            throw new TenantConfigurationInvalid('Tenancy configuration must not contain closures.');
        }

        $this->assertExactKeys($tenancy, [
            'enabled',
            'strategy',
            'connection',
            'profile',
            'directory',
            'resolvers',
            'access',
            'resources',
            'sharing',
            'migrations',
        ], 'tenancy');

        if (! is_bool($tenancy['enabled'])) {
            throw new TenantConfigurationInvalid('tenancy.enabled must be a boolean.');
        }

        if ($tenancy['strategy'] !== 'shared-database') {
            throw new TenantConfigurationInvalid(sprintf(
                'Unsupported tenancy strategy [%s].',
                $this->displayValue($tenancy['strategy']),
            ));
        }

        if ($tenancy['connection'] !== null
            && (! is_string($tenancy['connection']) || trim($tenancy['connection']) === '')) {
            throw new TenantConfigurationInvalid('tenancy.connection must be null or a non-empty string.');
        }

        if ($tenancy['profile'] !== 'application') {
            throw new TenantConfigurationInvalid(sprintf(
                'Unsupported tenancy profile [%s].',
                $this->displayValue($tenancy['profile']),
            ));
        }

        $this->validateDirectory($tenancy['directory']);
        $this->validateAdapterMap($tenancy['resolvers'], ['http', 'public_site'], 'tenancy.resolvers');
        $this->validateAdapterMap($tenancy['access'], ['membership', 'platform'], 'tenancy.access');
        $this->validateResources($tenancy['resources']);
        $this->validateSharing($tenancy['sharing']);
        $this->validateMigrations($tenancy['migrations']);
        $this->validateAdapterContracts();
    }

    /**
     * Validate the tenant directory driver structure.
     *
     * @throws TenantConfigurationInvalid When the directory configuration is invalid
     */
    private function validateDirectory(mixed $directory): void
    {
        if (! is_array($directory)) {
            throw new TenantConfigurationInvalid('tenancy.directory must be an array.');
        }

        $this->assertExactKeys($directory, ['driver', 'adapter'], 'tenancy.directory');

        if (! in_array($directory['driver'], ['package', 'host'], true)) {
            throw new TenantConfigurationInvalid(sprintf(
                'Unsupported tenancy directory driver [%s].',
                $this->displayValue($directory['driver']),
            ));
        }

        if ($directory['adapter'] !== null && ! is_string($directory['adapter'])) {
            throw new TenantConfigurationInvalid('tenancy.directory.adapter must be null or a class string.');
        }
    }

    /**
     * Validate a closed map of nullable adapter class strings.
     *
     * @param  list<string>  $keys
     *
     * @throws TenantConfigurationInvalid When the adapter map is invalid
     */
    private function validateAdapterMap(mixed $adapters, array $keys, string $path): void
    {
        if (! is_array($adapters)) {
            throw new TenantConfigurationInvalid("{$path} must be an array.");
        }

        $this->assertExactKeys($adapters, $keys, $path);

        foreach ($adapters as $key => $adapter) {
            if ($adapter !== null && ! is_string($adapter)) {
                throw new TenantConfigurationInvalid("{$path}.{$key} must be null or a class string.");
            }
        }
    }

    /**
     * Reject resource-family overrides until packages register integration metadata.
     *
     * @throws TenantConfigurationInvalid When an unregistered family is configured
     */
    private function validateResources(mixed $resources): void
    {
        if (! is_array($resources)) {
            throw new TenantConfigurationInvalid('tenancy.resources must be an array.');
        }

        foreach ($resources as $family => $mode) {
            if (! is_string($family)) {
                throw new TenantConfigurationInvalid('Tenancy resource family names must be strings.');
            }

            throw new TenantConfigurationInvalid("Unknown tenancy resource family [{$family}].");
        }
    }

    /**
     * Validate the closed sharing-mode map.
     *
     * @throws TenantConfigurationInvalid When a sharing mode is unsupported
     */
    private function validateSharing(mixed $sharing): void
    {
        if (! is_array($sharing)) {
            throw new TenantConfigurationInvalid('tenancy.sharing must be an array.');
        }

        $this->assertExactKeys($sharing, ['media', 'metafields', 'templates'], 'tenancy.sharing');

        foreach ($sharing as $family => $mode) {
            if (! in_array($mode, ['none', 'copy'], true)) {
                throw new TenantConfigurationInvalid(sprintf(
                    'Unsupported tenancy sharing mode [%s] for [%s].',
                    $this->displayValue($mode),
                    $family,
                ));
            }
        }
    }

    /**
     * Validate explicit migration registration configuration.
     *
     * @throws TenantConfigurationInvalid When migration enablement is not boolean
     */
    private function validateMigrations(mixed $migrations): void
    {
        if (! is_array($migrations)) {
            throw new TenantConfigurationInvalid('tenancy.migrations must be an array.');
        }

        $this->assertExactKeys($migrations, ['enabled'], 'tenancy.migrations');

        if (! is_bool($migrations['enabled'])) {
            throw new TenantConfigurationInvalid('tenancy.migrations.enabled must be a boolean.');
        }
    }

    /**
     * Validate class-string compatibility and configuration versus host binding ownership.
     *
     * @throws TenantConfigurationInvalid When an adapter is incompatible or contradictory
     */
    private function validateAdapterContracts(): void
    {
        foreach (self::ADAPTER_CONTRACTS as $path => $contract) {
            $adapter = $this->configuration->get($path);

            if ($adapter === null) {
                continue;
            }

            if (! is_string($adapter) || ! is_a($adapter, $contract, true)) {
                throw new TenantConfigurationInvalid(sprintf(
                    'Configured adapter [%s] must implement [%s].',
                    $this->displayValue($adapter),
                    $contract,
                ));
            }

            $reflection = new ReflectionClass($adapter);

            if (! $reflection->isInstantiable()) {
                throw new TenantConfigurationInvalid(sprintf(
                    'Configured adapter [%s] must be an instantiable class implementing [%s].',
                    $adapter,
                    $contract,
                ));
            }

            if ($this->hasConflictingBinding($contract, $adapter)) {
                throw new TenantConfigurationInvalid(
                    "{$path} conflicts with an existing host binding for [{$contract}].",
                );
            }
        }
    }

    /**
     * Determine whether an existing binding differs from the configured class.
     *
     * Inspect binding metadata without resolving or constructing the adapter.
     *
     * @param  class-string  $contract
     * @param  class-string  $adapter
     */
    private function hasConflictingBinding(string $contract, string $adapter): bool
    {
        if (! $this->container->bound($contract)) {
            return false;
        }

        $binding = $this->container->getBindings()[$contract] ?? null;

        if (! is_array($binding)) {
            return true;
        }

        $concrete = $binding['concrete'] ?? null;

        if (! $concrete instanceof Closure) {
            return true;
        }

        $reflection = new ReflectionFunction($concrete);
        $variables = $reflection->getStaticVariables();

        return $reflection->getClosureThis() !== $this->container
            || ($variables['abstract'] ?? null) !== $contract
            || ($variables['concrete'] ?? null) !== $adapter;
    }

    /**
     * Assert that a closed configuration map has exactly the expected keys.
     *
     * @param  array<mixed>  $values
     * @param  list<string>  $expected
     *
     * @throws TenantConfigurationInvalid When a required key is absent or unknown
     */
    private function assertExactKeys(array $values, array $expected, string $path): void
    {
        $actual = array_keys($values);
        $unknown = array_values(array_diff($actual, $expected));
        $missing = array_values(array_diff($expected, $actual));

        if ($unknown !== []) {
            throw new TenantConfigurationInvalid("Unknown configuration key [{$path}.{$unknown[0]}].");
        }

        if ($missing !== []) {
            throw new TenantConfigurationInvalid("Missing configuration key [{$path}.{$missing[0]}].");
        }
    }

    /**
     * Determine whether any cached configuration value contains a closure.
     */
    private function containsClosure(mixed $value): bool
    {
        if ($value instanceof Closure) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $nested) {
            if ($this->containsClosure($nested)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert an invalid scalar or object into a bounded diagnostic label.
     */
    private function displayValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_object($value)) {
            return $value::class;
        }

        return get_debug_type($value);
    }
}
