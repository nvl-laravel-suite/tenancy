<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers fixture integration metadata after the Tenancy provider boots. */
final class LateResourceProvider extends ServiceProvider
{
    /** Register a real resource during the normal provider boot phase. */
    public function boot(): void
    {
        $this->app->make(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', OwnedRecord::class));
    }
}
