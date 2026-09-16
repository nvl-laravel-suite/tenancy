<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Providers;

use Illuminate\Support\ServiceProvider;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Contracts\TenantHttpResolver;
use Nvl\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Tenancy\Contracts\TenantSiteResolver;
use Nvl\Tenancy\Services\ScopedTenantContext;
use Nvl\Tenancy\Services\TenancyConfiguration;

/**
 * Registers inert tenancy configuration and scoped context services.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;

    /**
     * Publish optional Tenancy configuration and operator guidance.
     */
    public function boot(): void
    {
        $configuration = $this->app->make(TenancyConfiguration::class);
        $configuration->validate();
        $this->registerConfiguredAdapters();

        $this->publishes([
            __DIR__.'/../../config/tenancy.php' => config_path('tenancy.php'),
        ], 'tenancy-config');

        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'tenancy-skills');
    }

    /**
     * Merge package defaults and register the read-only scoped context contract.
     */
    public function register(): void
    {
        $this->mergePackageConfiguration(__DIR__.'/../../config/tenancy.php', 'tenancy');
        $this->app->singleton(TenancyConfiguration::class);
        $this->app->scopedIf(TenantContext::class, ScopedTenantContext::class);
    }

    /**
     * Register validated explicit adapter classes without replacing host bindings.
     */
    private function registerConfiguredAdapters(): void
    {
        $adapters = [
            TenantDirectory::class => config('tenancy.directory.adapter'),
            TenantHttpResolver::class => config('tenancy.resolvers.http'),
            TenantSiteResolver::class => config('tenancy.resolvers.public_site'),
            TenantMembershipAccess::class => config('tenancy.access.membership'),
            PlatformAccess::class => config('tenancy.access.platform'),
        ];

        foreach ($adapters as $contract => $adapter) {
            if (is_string($adapter)) {
                $this->app->bindIf($contract, $adapter);
            }
        }
    }
}
