<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Tenancy\Contracts\TenantParentResolver;

/** Supplies explicit test-only parent graphs for recursive ownership validation. */
final readonly class ParentTypesResolver implements TenantParentResolver
{
    /**
     * Keep only package-allowlisted class names in the fixture adapter.
     *
     * @param  array<string, class-string<Model>>  $parents
     */
    public function __construct(private array $parents) {}

    /**
     * Return the configured persisted type map.
     *
     * @return array<string, class-string<Model>>
     */
    public function types(): array
    {
        return $this->parents;
    }
}
