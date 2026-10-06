<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Providers;

use Closure;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\Request;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Providers\TenantServiceProvider;
use Nvl\Support\Tenancy\Contracts\TenantBoundary as BoundaryContract;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Contracts\TenantHttpResolver;
use Nvl\Support\Tenancy\Contracts\TenantInstallationState as InstallationStateContract;
use Nvl\Support\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Support\Tenancy\Contracts\TenantOwnershipConfiguration as OwnershipConfigurationContract;
use Nvl\Support\Tenancy\Contracts\TenantQueueContext as QueueContextContract;
use Nvl\Support\Tenancy\Contracts\TenantRunner as RunnerContract;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Support\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Support\Tenancy\Services\DisabledTenantContext;
use Nvl\Support\Tenancy\Services\DisabledTenantDirectory;
use Nvl\Support\Tenancy\Services\DisabledTenantMembershipAccess;
use Nvl\Support\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Support\Tenancy\Services\PersistedTenantStorage;
use Nvl\Support\Tenancy\Services\TenantContextParticipants;
use Nvl\Support\Tenancy\Services\TenantQueuePayload;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Tenancy\Services\TenantSiteAttributes;
use Nvl\Support\Tenancy\ValueObjects\TenantSiteContext;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Tenancy\Console\Commands\TenancyAdoptCommand;
use Nvl\Tenancy\Console\Commands\TenancyDoctorCommand;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantSiteResolver;
use Nvl\Tenancy\Http\Middleware\RequireTenantMembership;
use Nvl\Tenancy\Http\Middleware\ResolvePublicTenant;
use Nvl\Tenancy\Queue\TenantCallQueuedHandler;
use Nvl\Tenancy\Queue\TenantDatabaseBatchRepository;
use Nvl\Tenancy\Services\DenyPlatformAccess;
use Nvl\Tenancy\Services\DenyTenantMembershipAccess;
use Nvl\Tenancy\Services\PackageTenantDirectory;
use Nvl\Tenancy\Services\ScopedTenantContext;
use Nvl\Tenancy\Services\TenancyConfiguration;
use Nvl\Tenancy\Services\TenancyDoctor;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantAdoptionScope;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantGlobalJobRegistry;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\Services\TenantMaintenanceLease;
use Nvl\Tenancy\Services\TenantMaintenanceQueueGuard;
use Nvl\Tenancy\Services\TenantOwnershipConfiguration;
use Nvl\Tenancy\Services\TenantQueueContext;
use Nvl\Tenancy\Services\TenantRunner;
use ReflectionFunction;

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
        PackageDoctorContributor::register($this->app, 'nvl/tenancy', fn (): array => $this->app->make(TenancyDoctor::class)->inspect()['checks']);

        $this->mergePackageConfiguration(__DIR__.'/../../config/tenancy.php', 'tenancy');
        $this->app->instance('nvl.tenancy.runtime', true);
        $this->app->singleton(TenancyConfiguration::class);
        $this->app->register(TenantServiceProvider::class);
        $this->app->singleton(TenantAdoptionRegistry::class);
        $this->app->scoped(TenantInstallationState::class);
        $this->app->scopedIf(ScopedTenantContext::class);
        $this->app->extend(TenantContext::class, static fn (TenantContext $context, Container $app): TenantContext => $context instanceof DisabledTenantContext ? $app->make(ScopedTenantContext::class) : $context);
        foreach ([
            BoundaryContract::class => TenantBoundary::class,
            RunnerContract::class => TenantRunner::class,
            QueueContextContract::class => TenantQueueContext::class,
            InstallationStateContract::class => TenantInstallationState::class,
            OwnershipConfigurationContract::class => TenantOwnershipConfiguration::class,
        ] as $contract => $runtime) {
            $this->app->extend($contract, static fn (object $service, Container $app): object => str_starts_with($service::class, 'Nvl\\Support\\Tenancy\\Services\\Disabled') || $service instanceof PersistedTenantStorage ? $app->make($runtime) : $service);
        }
        foreach ([TenantContext::class, TenantDirectory::class, TenantMembershipAccess::class, TenantHttpResolver::class, TenantResourceRegistry::class, TenantContextParticipants::class, TenantQueuePayload::class, EffectiveTenantConnection::class] as $neutral) {
            $legacy = str_replace('Nvl\\Support\\Tenancy\\', 'Nvl\\Tenancy\\', $neutral);
            $this->app->beforeResolving($neutral, static function (string $abstract, array $parameters, Container $app) use ($neutral, $legacy): void {
                $binding = self::binding($app, $legacy);
                if ($binding === null) {
                    return;
                }
                $variables = (new ReflectionFunction($binding['concrete']))->getStaticVariables();
                if (($variables['neutral'] ?? null) === $neutral) {
                    return;
                }
                $current = self::binding($app, $neutral);
                if ($current !== null && $current['concrete'] === $binding['concrete']) {
                    return;
                }
                $currentVariables = $current === null ? [] : (new ReflectionFunction($current['concrete']))->getStaticVariables();
                $currentClass = $currentVariables['concrete'] ?? null;
                if ($current !== null && $currentClass !== $neutral
                    && (! is_string($currentClass) || ! str_starts_with($currentClass, 'Nvl\\Support\\Tenancy\\Services\\Disabled'))) {
                    return;
                }
                $app->bind($neutral, static fn (Container $app): object => self::resolveObject($app, $legacy));
            });
            if ($this->app->bound($legacy)) {
                $binding = self::binding($this->app, $legacy);
                if ($binding !== null) {
                    $variables = (new ReflectionFunction($binding['concrete']))->getStaticVariables();
                    if (($variables['neutral'] ?? null) === $neutral) {
                        continue;
                    }
                    $this->app->bind($neutral, static fn (Container $app): object => self::resolveObject($app, $legacy));
                } else {
                    $this->app->bind($neutral, static fn (Container $app): object => self::resolveObject($app, $legacy));
                }

                continue;
            }
            $this->app->rebinding($legacy, static function (Container $app, object $service) use ($neutral, $legacy): void {
                $app->bind($neutral, static fn (Container $app): object => self::resolveObject($app, $legacy));
            });
            $this->app->bind($legacy, static fn (Container $app): object => $app->make($neutral));
        }
        $this->app->scopedIf(TenantMaintenanceLease::class);
        $this->app->scopedIf(TenantAdoptionScope::class);

        $this->app->extend(TenantDirectory::class, static function (TenantDirectory $directory, Container $app): TenantDirectory {
            if (! $directory instanceof DisabledTenantDirectory) {
                return $directory;
            }
            $adapter = $app->make('config')->get('tenancy.directory.adapter');
            if (is_string($adapter)) {
                $configured = $app->make($adapter);
                if (! $configured instanceof TenantDirectory) {
                    throw new TenantConfigurationInvalid('The directory adapter must implement TenantDirectory.');
                }

                return $configured;
            }
            if ($app->make('config')->get('tenancy.directory.driver') !== 'package') {
                throw new TenantConfigurationInvalid('A host tenant directory adapter must be bound.');
            }

            return $app->make(PackageTenantDirectory::class);
        });
        $this->app->extend(TenantMembershipAccess::class, static function (TenantMembershipAccess $membership, Container $app): TenantMembershipAccess {
            if (! $membership instanceof DisabledTenantMembershipAccess) {
                return $membership;
            }
            $adapter = $app->make('config')->get('tenancy.access.membership');
            $configured = $app->make(is_string($adapter) ? $adapter : DenyTenantMembershipAccess::class);
            if (! $configured instanceof TenantMembershipAccess) {
                throw new TenantConfigurationInvalid('The membership adapter must implement TenantMembershipAccess.');
            }

            return $configured;
        });
        $this->registerFallbackAdapters();
        $this->app->bindIf(TenantSiteContext::class, static function (Container $app): TenantSiteContext {
            $site = TenantSiteAttributes::read($app->make(Request::class));
            if (! $site instanceof TenantSiteContext) {
                throw new TenantContextMissing('No verified public site is active for this request.');
            }
            if ($app->make(TenantContext::class)->requireTenant()->value !== $site->tenantId->value) {
                throw new TenantBoundaryViolation('The verified public site differs from the active tenant.');
            }

            return $site;
        });
        $this->app->bind('Nvl\\Tenancy\\ValueObjects\\TenantSiteContext', static fn (Container $app): TenantSiteContext => $app->make(TenantSiteContext::class));
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

    /** Resolve a legacy object binding without accepting primitive replacements. */
    private static function resolveObject(Container $app, string $abstract): object
    {
        $service = $app->make($abstract);
        if (! is_object($service)) {
            throw new TenantConfigurationInvalid('Tenant bindings must resolve service objects.');
        }

        return $service;
    }

    /**
     * Read native binding metadata without constructing host adapters.
     *
     * @return array{concrete: Closure, shared: bool}|null
     */
    private static function binding(ContainerContract $app, string $abstract): ?array
    {
        if (! $app instanceof Container) {
            throw new TenantConfigurationInvalid('Tenant adapters require the native Laravel container.');
        }
        $binding = $app->getBindings()[$abstract] ?? null;
        if (! is_array($binding) || ! ($binding['concrete'] ?? null) instanceof Closure || ! is_bool($binding['shared'] ?? null)) {
            return null;
        }

        return ['concrete' => $binding['concrete'], 'shared' => $binding['shared']];
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
