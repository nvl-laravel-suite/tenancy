<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Tenancy\Contracts\TenantParentResolver;

/** Restricts fixture polymorphic ownership independently of the global resource registry. */
final class AllowedParentResolver implements TenantParentResolver
{
    /**
     * Return the single package-allowed persisted alias.
     *
     * @return array<string, class-string<Model>>
     */
    public function types(): array
    {
        return ['record' => OwnedRecord::class];
    }
}
