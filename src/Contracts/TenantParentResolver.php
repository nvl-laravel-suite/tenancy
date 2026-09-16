<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Supplies the owning package's allowlisted persisted morph identities.
 *
 * @internal
 */
interface TenantParentResolver
{
    /**
     * Return explicitly allowed persisted type values and concrete model classes.
     *
     * @return array<string, class-string<Model>>
     */
    public function types(): array;
}
