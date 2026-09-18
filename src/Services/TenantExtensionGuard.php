<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/** Validates host extension contracts and their enabled tenant capability. */
final readonly class TenantExtensionGuard
{
    /** Create the extension boundary from deployment configuration. */
    public function __construct(private Repository $configuration) {}

    /**
     * Require the base extension contract and, when enabled, its tenant-safe contract.
     *
     * @template TExtension of object
     *
     * @param  class-string<TExtension>  $baseContract
     * @param  class-string  $tenantContract
     * @return class-string<TExtension>
     */
    public function assertCompatible(
        object|string $extension,
        string $baseContract,
        string $tenantContract,
        string $label,
    ): string {
        $class = is_object($extension) ? $extension::class : $extension;

        if (! is_a($class, $baseContract, true)) {
            throw new InvalidArgumentException(
                "{$label} [{$class}] must implement [{$baseContract}].",
            );
        }

        if ($this->configuration->get('tenancy.enabled') === true
            && ! is_a($class, $tenantContract, true)) {
            throw new InvalidArgumentException(
                "{$label} [{$class}] is not tenant compatible.",
            );
        }

        return $class;
    }
}
