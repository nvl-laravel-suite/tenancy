<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Tenancy\Exceptions\TenantInactive;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Services\ScopedTenantContext;
use Nvl\Tenancy\Services\TenancyConfiguration;
use Nvl\Tenancy\Services\TenantOwnershipConfiguration;
use Nvl\Tenancy\Tests\Fixtures\AbstractTestTenantDirectory;
use Nvl\Tenancy\Tests\Fixtures\ConflictingTestTenantDirectory;
use Nvl\Tenancy\Tests\Fixtures\TestTenantDirectory;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantId;

it('registers the library without activating tenancy or installing schema', function (): void {
    expect(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Disabled)
        ->and(config('tenancy.enabled'))->toBeFalse()
        ->and(Schema::hasTable('nvl_tenancy_tenants'))->toBeFalse();
});

it('canonicalizes and validates tenant identifiers', function (): void {
    expect(new TenantId(' 10000000-0000-4000-8000-0000000000AA ')->value)
        ->toBe('10000000-0000-4000-8000-0000000000aa')
        ->and(fn (): TenantId => new TenantId('not-a-uuid'))
        ->toThrow(InvalidArgumentException::class, 'Tenant identifiers must be valid UUIDs.');
});

it('rejects contradictory context snapshots', function (): void {
    $tenantId = new TenantId('10000000-0000-4000-8000-000000000001');

    expect(fn (): TenantContextSnapshot => new TenantContextSnapshot(TenantContextMode::Tenant))
        ->toThrow(InvalidArgumentException::class, 'Tenant mode requires a tenant identifier.')
        ->and(fn (): TenantContextSnapshot => new TenantContextSnapshot(TenantContextMode::Platform, $tenantId))
        ->toThrow(InvalidArgumentException::class, 'Only tenant mode may carry a tenant identifier.');
});

it('fails closed when tenant context is missing', function (): void {
    expect(fn (): TenantId => app(TenantContext::class)->requireTenant())
        ->toThrow(TenantContextMissing::class, 'Tenant context is not resolved.')
        ->and((new ScopedTenantContext(new Repository([
            'tenancy' => ['enabled' => true],
        ])))->snapshot()->mode)
        ->toBe(TenantContextMode::Unresolved);
});

it('ships the frozen inert configuration defaults', function (): void {
    expect(config('tenancy'))->toBe([
        'enabled' => false,
        'strategy' => 'shared-database',
        'connection' => null,
        'profile' => 'application',
        'directory' => [
            'driver' => 'package',
            'adapter' => null,
        ],
        'resolvers' => [
            'http' => null,
            'public_site' => null,
        ],
        'access' => [
            'membership' => null,
            'platform' => null,
        ],
        'resources' => [],
        'sharing' => [
            'media' => 'none',
            'metafields' => 'none',
            'templates' => 'none',
        ],
        'migrations' => ['enabled' => false],
    ]);
});

it('rejects invalid deployment configuration', function (string $path, mixed $value, string $message): void {
    config()->set($path, $value);

    expect(function (): void {
        app(TenancyConfiguration::class)->validate();
        app(TenantOwnershipConfiguration::class)->validate();
    })->toThrow(TenantConfigurationInvalid::class, $message);
})->with([
    'non-boolean enablement' => ['tenancy.enabled', 'false', 'tenancy.enabled must be a boolean.'],
    'unknown strategy' => ['tenancy.strategy', 'database-per-tenant', 'Unsupported tenancy strategy [database-per-tenant].'],
    'unknown profile' => ['tenancy.profile', 'custom', 'Unsupported tenancy profile [custom].'],
    'non-boolean migrations' => ['tenancy.migrations.enabled', 'true', 'tenancy.migrations.enabled must be a boolean.'],
    'unknown family' => ['tenancy.resources.media', 'tenant', 'Unknown tenancy resource family [media].'],
    'invalid sharing' => ['tenancy.sharing.media', 'shared', 'Unsupported tenancy sharing mode [shared] for [media].'],
    'cached closure' => ['tenancy.resolvers.http', static fn (): null => null, 'Tenancy configuration must not contain closures.'],
    'invalid adapter class' => ['tenancy.directory.adapter', stdClass::class, 'Configured adapter [stdClass] must implement [Nvl\\Tenancy\\Contracts\\TenantDirectory].'],
]);

it('accepts a valid explicit host directory class without resolving it', function (): void {
    config()->set([
        'tenancy.directory.driver' => 'host',
        'tenancy.directory.adapter' => TestTenantDirectory::class,
    ]);

    expect(app()->resolved(TestTenantDirectory::class))->toBeFalse()
        ->and(app(TenancyConfiguration::class)->validate())->toBeNull()
        ->and(app()->resolved(TestTenantDirectory::class))->toBeFalse();
});

it('allows repeat validation after the provider registers a configured adapter', function (): void {
    config()->set([
        'tenancy.directory.driver' => 'host',
        'tenancy.directory.adapter' => TestTenantDirectory::class,
    ]);

    (new TenancyServiceProvider(app()))->boot();

    expect(app()->bound(TenantDirectory::class))->toBeTrue()
        ->and(app(TenancyConfiguration::class)->validate())->toBeNull()
        ->and(app(TenancyConfiguration::class)->validate())->toBeNull()
        ->and(app()->resolved(TestTenantDirectory::class))->toBeFalse();
});

it('allows an identical explicit host binding without resolving it', function (): void {
    config()->set([
        'tenancy.directory.driver' => 'host',
        'tenancy.directory.adapter' => TestTenantDirectory::class,
    ]);
    app()->bind(TenantDirectory::class, TestTenantDirectory::class);

    expect(app(TenancyConfiguration::class)->validate())->toBeNull()
        ->and(app()->resolved(TestTenantDirectory::class))->toBeFalse();
});

it('rejects a genuinely conflicting host binding without constructing it', function (): void {
    config()->set([
        'tenancy.directory.driver' => 'host',
        'tenancy.directory.adapter' => TestTenantDirectory::class,
    ]);
    app()->bind(TenantDirectory::class, ConflictingTestTenantDirectory::class);

    expect(fn (): null => app(TenancyConfiguration::class)->validate())
        ->toThrow(
            TenantConfigurationInvalid::class,
            'tenancy.directory.adapter conflicts with an existing host binding for [Nvl\\Tenancy\\Contracts\\TenantDirectory].',
        )
        ->and(app()->resolved(ConflictingTestTenantDirectory::class))->toBeFalse();
});

it('rejects non-instantiable configured adapters', function (string $adapter): void {
    config()->set([
        'tenancy.directory.driver' => 'host',
        'tenancy.directory.adapter' => $adapter,
    ]);

    expect(fn (): null => app(TenancyConfiguration::class)->validate())
        ->toThrow(
            TenantConfigurationInvalid::class,
            "Configured adapter [{$adapter}] must be an instantiable class implementing [Nvl\\Tenancy\\Contracts\\TenantDirectory].",
        );
})->with([
    'interface' => TenantDirectory::class,
    'abstract class' => AbstractTestTenantDirectory::class,
]);

it('exposes stable machine-readable tenancy failure codes', function (string $exception, string $code): void {
    expect((new $exception)->responseCode())->toBe($code);
})->with([
    [TenantContextMissing::class, 'tenant_context_missing'],
    [TenantNotFound::class, 'tenant_not_found'],
    [TenantInactive::class, 'tenant_inactive'],
    [TenantBoundaryViolation::class, 'tenant_boundary_violation'],
    [TenantConfigurationInvalid::class, 'tenant_configuration_invalid'],
    [TenantSchemaNotReady::class, 'tenant_schema_not_ready'],
]);
