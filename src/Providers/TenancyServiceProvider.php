<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Providers;

use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\Request;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Tenancy\Console\Commands\TenancyAdoptCommand;
use Nvl\Tenancy\Console\Commands\TenancyDoctorCommand;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Contracts\TenantHttpResolver;
use Nvl\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Tenancy\Contracts\TenantSiteResolver;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Tenancy\Http\Middleware\RequireTenantMembership;
use Nvl\Tenancy\Http\Middleware\ResolvePublicTenant;
use Nvl\Tenancy\Queue\TenantCallQueuedHandler;
use Nvl\Tenancy\Queue\TenantDatabaseBatchRepository;
use Nvl\Tenancy\Services\DenyPlatformAccess;
use Nvl\Tenancy\Services\DenyTenantMembershipAccess;
use Nvl\Tenancy\Services\PackageTenantDirectory;
use Nvl\Tenancy\Services\ScopedTenantContext;
use Nvl\Tenancy\Services\TenancyConfiguration;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantAdoptionScope;
use Nvl\Tenancy\Services\TenantContextParticipants;
use Nvl\Tenancy\Services\TenantGlobalJobRegistry;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\Services\TenantMaintenanceLease;
use Nvl\Tenancy\Services\TenantMaintenanceQueueGuard;
use Nvl\Tenancy\Services\TenantOwnershipConfiguration;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;

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
        $this->app->make(TypeScriptSourceRegistry::class)->register(__DIR__.'/..', 'nvl/tenancy');
        $configuration = $this->app->make(TenancyConfiguration::class);
        $configuration->validate();
        $this->app->booted(fn () => $this->app->make(TenantOwnershipConfiguration::class)->validate());
        $this->registerConfiguredAdapters();
        $this->registerMigrations();
        $this->commands([TenancyAdoptCommand::class, TenancyDoctorCommand::class]);
        $this->callAfterResolving(Kernel::class, static function (Kernel $kernel): void {
            $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, RequireTenantMembership::class);
            $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, ResolvePublicTenant::class);
        });

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
        $this->app->singleton(TenantResourceRegistry::class);
        $this->app->singleton(TenantAdoptionRegistry::class);
        $this->app->scoped(TenantInstallationState::class);
        $this->app->scopedIf(ScopedTenantContext::class);
        $this->app->scopedIf(TenantContext::class, static fn (Container $app): ScopedTenantContext => $app->make(ScopedTenantContext::class));
        $this->app->scopedIf(TenantMaintenanceLease::class);
        $this->app->scopedIf(TenantAdoptionScope::class);
        $this->app->singleton(TenantContextParticipants::class);
        $this->registerFallbackAdapters();
        $this->app->bindIf(TenantSiteContext::class, static function (Container $app): TenantSiteContext {
            $site = $app->make(Request::class)->attributes->get(TenantSiteContext::class);
            if (! $site instanceof TenantSiteContext) {
                throw new TenantContextMissing('No verified public site is active for this request.');
            }
            if ($app->make(TenantContext::class)->requireTenant()->value !== $site->tenantId->value) {
                throw new TenantBoundaryViolation('The verified public site differs from the active tenant.');
            }

            return $site;
        });
        foreach ([QueueFactory::class, DeferredCallbackCollection::class] as $dispatchBoundary) {
            $this->app->beforeResolving($dispatchBoundary, static function (): void {
                Container::getInstance()->make(TenantMaintenanceLease::class)->assertQueueAllowed();
                Container::getInstance()->make(TenantAdoptionScope::class)->assertQueueAllowed();
            });
        }
        $this->app->singleton(TenantGlobalJobRegistry::class);
        $this->app->bindIf(CallQueuedHandler::class, TenantCallQueuedHandler::class);
        $this->app->extend(DatabaseBatchRepository::class, static function ($repository) {
            return is_object($repository) && $repository::class === DatabaseBatchRepository::class
                ? TenantDatabaseBatchRepository::fromNative($repository)
                : $repository;
        });
        TenantMaintenanceQueueGuard::register();
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

    /** Publish the core migration set and register it only when explicitly enabled. */
    private function registerMigrations(): void
    {
        $path = __DIR__.'/../../database/migrations/tenancy';

        $this->publishesMigrations([
            $path => database_path('migrations'),
        ], 'tenancy-migrations');

        if (config('tenancy.migrations.enabled') === true) {
            $this->loadMigrationsFrom($path);
        }
    }

    /** Register fallbacks only on resolution so host configuration remains lazily inspectable. */
    private function registerFallbackAdapters(): void
    {
        foreach ([
            TenantDirectory::class => PackageTenantDirectory::class,
            TenantMembershipAccess::class => DenyTenantMembershipAccess::class,
            PlatformAccess::class => DenyPlatformAccess::class,
        ] as $contract => $fallback) {
            $this->app->beforeResolving($contract, static function (string $abstract, array $parameters, Container $app) use ($contract, $fallback): void {
                if (! $app->bound($contract)) {
                    if ($contract === TenantDirectory::class && $app->make('config')->get('tenancy.directory.driver') !== 'package') {
                        throw new TenantConfigurationInvalid('A host tenant directory adapter must be bound.');
                    }
                    $app->bind($contract, $fallback);
                }
            });
        }
    }
}
