<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\Services\TenantOwnershipConfiguration;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Tests\Fixtures\AllowedParentResolver;
use Nvl\Tenancy\Tests\Fixtures\ArrayTenantDirectory;
use Nvl\Tenancy\Tests\Fixtures\F4InstallationFixture;
use Nvl\Tenancy\Tests\Fixtures\InheritedRecord;
use Nvl\Tenancy\Tests\Fixtures\LateResourceProvider;
use Nvl\Tenancy\Tests\Fixtures\OwnedRecord;
use Nvl\Tenancy\Tests\Fixtures\ParentTypesResolver;
use Nvl\Tenancy\Tests\Fixtures\PolymorphicRecord;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

beforeEach(function (): void {
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', OwnedRecord::class));
});

it('rejects enabled resources with no adopted schema', function (): void {
    config()->set('tenancy.enabled', true);
    expect(fn () => app(TenantBoundary::class)->key('tests.records', 'key'))->toThrow(TenantSchemaNotReady::class);
});

it('rejects prepared and incompatible installation markers', function (string $state, ?string $hash): void {
    F4InstallationFixture::install($state, $hash);
    expect(fn () => app(TenantBoundary::class)->key('tests.records', 'key'))->toThrow(TenantSchemaNotReady::class);
})->with([['prepared', null], ['active', str_repeat('0', 64)]]);

it('rejects disabling the feature after resource adoption', function (): void {
    F4InstallationFixture::install();
    config()->set('tenancy.enabled', false);
    expect(fn () => app(TenantBoundary::class)->key('tests.records', 'key'))->toThrow(TenantSchemaNotReady::class);
});

it('bounds absent marker probes within the connection generation', function (): void {
    DB::enableQueryLog();
    for ($i = 0; $i < 5; $i++) {
        expect(app(TenantBoundary::class)->key('tests.records', 'key'))->toBe('key');
    }
    expect(DB::getQueryLog())->toHaveCount(1);
    app(TenantInstallationState::class)->invalidate();
    app(TenantBoundary::class)->key('tests.records', 'key');
    expect(DB::getQueryLog())->toHaveCount(2);
});

it('propagates a real connection failure instead of caching legacy compatibility', function (): void {
    config()->set('database.connections.sqlite.database', '/tmp/nonexistent-f4-'.Str::uuid().'.sqlite');
    DB::purge('sqlite');
    expect(fn () => app(TenantBoundary::class)->key('tests.records', 'legacy'))->toThrow(QueryException::class);
    config()->set('database.connections.sqlite.database', ':memory:');
    DB::purge('sqlite');
    expect(app(TenantBoundary::class)->key('tests.records', 'legacy'))->toBe('legacy');
});

it('reprobes after connection replacement and after worker scope reset', function (): void {
    app(TenantBoundary::class)->key('tests.records', 'legacy');
    DB::purge('sqlite');
    DB::enableQueryLog();
    app(TenantBoundary::class)->key('tests.records', 'legacy');
    expect(DB::getQueryLog())->toHaveCount(1);
    app()->forgetScopedInstances();
    app(TenantBoundary::class)->key('tests.records', 'legacy');
    expect(DB::getQueryLog())->toHaveCount(2);
});

it('bounds active marker probes independently from tenant status queries', function (): void {
    F4InstallationFixture::install();
    DB::enableQueryLog();
    for ($i = 0; $i < 5; $i++) {
        app(TenantInstallationState::class)->assertUsable('tests.records');
    }
    expect(DB::getQueryLog())->toHaveCount(2);
});

it('keeps resource markers effective after reboot even when core configuration points at an empty store', function (bool $enabled): void {
    $file = tempnam(sys_get_temp_dir(), 'f4-adopted-');
    try {
        config()->set('database.connections.sqlite.database', $file);
        DB::purge('sqlite');
        DB::connection()->getSchemaBuilder()->create('tenancy_test_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->softDeletes();
        });
        $tenants = F4InstallationFixture::install();
        OwnedRecord::create(['tenant_id' => $tenants[0]->value, 'name' => 'private']);
        $this->refreshApplication();
        config()->set([
            'database.connections.sqlite.database' => $file,
            'database.connections.empty_core' => [...config('database.connections.sqlite'), 'database' => ':memory:'],
            'tenancy.connection' => 'empty_core',
            'tenancy.enabled' => $enabled,
        ]);
        app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', OwnedRecord::class));
        expect(fn () => app(TenantBoundary::class)->query(OwnedRecord::query(), 'tests.records')->get())->toThrow(TenantSchemaNotReady::class);
    } finally {
        DB::purge('sqlite');
        unlink($file);
    }
})->with([false, true]);

it('rejects changed ownership configuration in a newly booted application', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'f4-config-');
    try {
        config()->set('database.connections.sqlite.database', $file);
        DB::purge('sqlite');
        F4InstallationFixture::install();
        $this->refreshApplication();
        config()->set([
            'database.connections.sqlite.database' => $file,
            'tenancy.enabled' => true,
            'tenancy.directory.driver' => 'host',
            'tenancy.directory.adapter' => ArrayTenantDirectory::class,
        ]);
        app()->instance(TenantDirectory::class, new ArrayTenantDirectory([]));
        app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', OwnedRecord::class));
        expect(fn () => app(TenantBoundary::class)->key('tests.records', 'key'))->toThrow(TenantSchemaNotReady::class);
    } finally {
        DB::purge('sqlite');
        unlink($file);
    }
});

it('does not invalidate adopted roots when an unrelated package registers later', function (): void {
    F4InstallationFixture::install();
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('other.records', 'other', InheritedRecord::class));
    expect(app(TenantInstallationState::class)->assertUsable('tests.records'))->toBeNull();
});

it('rejects unsupported platform and family dependency modes', function (): void {
    config()->set('tenancy.resources.tests', 'platform');
    expect(fn () => app(TenantOwnershipConfiguration::class)->validate())->toThrow(TenantConfigurationInvalid::class);
    config()->set('tenancy.resources.tests', 'tenant');
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('other.records', 'other', InheritedRecord::class, allowsPlatformRows: true));
    config()->set('tenancy.resources.other', 'platform');
    app(TenantResourceRegistry::class)->requireCompatible('tests', 'other');
    expect(fn () => app(TenantOwnershipConfiguration::class)->validate())->toThrow(TenantConfigurationInvalid::class);
});

it('accepts cached family configuration registered by a later provider in the normal boot cycle', function (): void {
    $original = app();
    $cache = tempnam(sys_get_temp_dir(), 'f4-cache-');
    $configuration = config()->all();
    $configuration['tenancy']['resources'] = ['tests' => 'tenant'];
    file_put_contents($cache, '<?php return '.var_export($configuration, true).';');
    try {
        $fresh = new Application(base_path());
        $fresh->instance('config', new Repository(require $cache));
        $fresh->instance('files', new Filesystem);
        $fresh->register(DatabaseServiceProvider::class);
        $fresh->register(TenancyServiceProvider::class);
        $fresh->register(LateResourceProvider::class);
        $fresh->boot();
        expect($fresh->make(TenantOwnershipConfiguration::class)->validate())->toBeNull()
            ->and($fresh->make(TenantResourceRegistry::class)->get('tests.records')->family)->toBe('tests');
    } finally {
        Container::setInstance($original);
        unlink($cache);
    }
});

it('rejects incomplete parent and polymorphic allowlist registrations before adoption', function (): void {
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.orphan', 'children', InheritedRecord::class, TenantResourceKind::Inherited, 'missing', 'parent'));
    expect(fn () => app(TenantOwnershipConfiguration::class)->fingerprint('tests.orphan'))->toThrow(TenantConfigurationInvalid::class);
    $registry = new TenantResourceRegistry;
    $registry->register(new TenantResourceDefinition('tests.poly', 'children', PolymorphicRecord::class, TenantResourceKind::Inherited, parentRelation: 'owner'));
    app()->instance(TenantResourceRegistry::class, $registry);
    expect(fn () => app(TenantOwnershipConfiguration::class)->fingerprint('tests.poly'))->toThrow(TenantConfigurationInvalid::class);
});

it('preserves disabled unadopted models on their own connection', function (): void {
    config()->set([
        'database.connections.empty_core' => config('database.connections.sqlite'),
        'tenancy.connection' => 'empty_core',
    ]);
    expect(app(TenantBoundary::class)->key('tests.records', 'legacy'))->toBe('legacy');
});

it('rejects changing the effective directory binding with otherwise identical configuration', function (): void {
    F4InstallationFixture::install();
    app()->instance(TenantDirectory::class, new ArrayTenantDirectory([]));
    expect(fn () => app(TenantInstallationState::class)->assertUsable('tests.records'))->toThrow(TenantSchemaNotReady::class);
});

it('rejects missing or unsupported version markers after schema installation', function (bool $remove): void {
    F4InstallationFixture::install();
    if ($remove) {
        DB::table('nvl_tenancy_installation_state')->delete();
    } else {
        DB::table('nvl_tenancy_installation_state')->update(['schema_version' => 2]);
    }
    app(TenantInstallationState::class)->invalidate();
    expect(fn () => app(TenantInstallationState::class)->assertUsable('tests.records'))->toThrow(TenantSchemaNotReady::class);
})->with([true, false]);

it('applies an explicit tenant family mode to mutable roots while fixed vocabulary stays platform', function (): void {
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.vocabulary', 'tests', InheritedRecord::class, TenantResourceKind::Platform));
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('other.records', 'other', PolymorphicRecord::class));
    app(TenantResourceRegistry::class)->requireCompatible('other', 'tests');
    config()->set('tenancy.resources.tests', 'tenant');
    expect(app(TenantOwnershipConfiguration::class)->validate())->toBeNull()
        ->and(app(TenantOwnershipConfiguration::class)->mode(app(TenantResourceRegistry::class)->get('tests.records')))->toBe('tenant')
        ->and(app(TenantOwnershipConfiguration::class)->mode(app(TenantResourceRegistry::class)->get('tests.vocabulary')))->toBe('platform');
});

it('rejects attempting to reclassify a family containing only fixed platform vocabulary', function (): void {
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('vocabulary.records', 'vocabulary', InheritedRecord::class, TenantResourceKind::Platform));
    config()->set('tenancy.resources.vocabulary', 'tenant');
    expect(fn () => app(TenantOwnershipConfiguration::class)->validate())->toThrow(TenantConfigurationInvalid::class);
});

it('rejects a polymorphic child family override that contradicts its allowlisted parent', function (): void {
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('children.records', 'children', PolymorphicRecord::class, TenantResourceKind::Inherited, parentRelation: 'owner'));
    app(TenantResourceRegistry::class)->registerParentResolver('children.records', AllowedParentResolver::class);
    config()->set('tenancy.resources.children', 'platform');
    expect(fn () => app(TenantOwnershipConfiguration::class)->validate())->toThrow(TenantConfigurationInvalid::class);
});

it('derives polymorphic child ownership recursively without a redundant child override', function (string $mode): void {
    $registry = new TenantResourceRegistry;
    app()->instance(TenantResourceRegistry::class, $registry);
    $registry->register(new TenantResourceDefinition('tests.records', 'tests', OwnedRecord::class, allowsPlatformRows: true));
    $registry->register(new TenantResourceDefinition('parents.records', 'parents', InheritedRecord::class, TenantResourceKind::Inherited, 'tests.records', 'parent'));
    $registry->register(new TenantResourceDefinition('children.records', 'children', PolymorphicRecord::class, TenantResourceKind::Inherited, parentRelation: 'owner'));
    $registry->registerParentResolver('children.records', ParentTypesResolver::class);
    app()->instance(ParentTypesResolver::class, new ParentTypesResolver(['parent' => InheritedRecord::class]));
    $registry->requireCompatible('children', 'tests');
    config()->set('tenancy.resources.tests', $mode);
    expect(config('tenancy.resources.children'))->toBeNull()
        ->and(app(TenantOwnershipConfiguration::class)->mode($registry->get('children.records')))->toBe($mode)
        ->and(app(TenantOwnershipConfiguration::class)->validate())->toBeNull();
})->with(['tenant', 'platform']);

it('rejects contradictory polymorphic parent modes before a child override can select one', function (?string $override): void {
    $registry = app(TenantResourceRegistry::class);
    $registry->register(new TenantResourceDefinition('platform.records', 'platform_roots', InheritedRecord::class, allowsPlatformRows: true));
    $registry->register(new TenantResourceDefinition('children.records', 'children', PolymorphicRecord::class, TenantResourceKind::Inherited, parentRelation: 'owner'));
    $registry->registerParentResolver('children.records', ParentTypesResolver::class);
    app()->instance(ParentTypesResolver::class, new ParentTypesResolver(['tenant' => OwnedRecord::class, 'platform' => InheritedRecord::class]));
    config()->set('tenancy.resources.platform_roots', 'platform');
    if ($override !== null) {
        config()->set('tenancy.resources.children', $override);
    }
    expect(fn () => app(TenantOwnershipConfiguration::class)->mode($registry->get('children.records')))->toThrow(TenantConfigurationInvalid::class);
})->with([null, 'tenant', 'platform']);

it('rejects recursive polymorphic ownership cycles with a configuration error', function (): void {
    $registry = app(TenantResourceRegistry::class);
    $registry->register(new TenantResourceDefinition('children.records', 'children', PolymorphicRecord::class, TenantResourceKind::Inherited, parentRelation: 'owner'));
    $registry->registerParentResolver('children.records', ParentTypesResolver::class);
    app()->instance(ParentTypesResolver::class, new ParentTypesResolver(['self' => PolymorphicRecord::class]));
    expect(fn () => app(TenantOwnershipConfiguration::class)->mode($registry->get('children.records')))->toThrow(TenantConfigurationInvalid::class);
});
