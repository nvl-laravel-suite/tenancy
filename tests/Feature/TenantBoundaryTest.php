<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Tenancy\Exceptions\TenantInactive;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\Services\TenantMaintenanceRunner;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\Tests\Fixtures\AllowedParentResolver;
use Nvl\Tenancy\Tests\Fixtures\F4InstallationFixture;
use Nvl\Tenancy\Tests\Fixtures\InheritedRecord;
use Nvl\Tenancy\Tests\Fixtures\InMemoryMaintenanceMode;
use Nvl\Tenancy\Tests\Fixtures\OwnedRecord;
use Nvl\Tenancy\Tests\Fixtures\PolymorphicRecord;
use Nvl\Tenancy\Tests\Fixtures\ProbeRestoredModel;
use Nvl\Tenancy\Tests\Fixtures\TestPlatformAccess;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

beforeEach(function (): void {
    Schema::create('tenancy_test_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id')->nullable();
        $table->string('ownership_key')->nullable();
        $table->string('name');
        $table->uuid('parent_id')->nullable();
        $table->softDeletes();
    });
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', OwnedRecord::class));
});

it('checks canonical ownership even after a caller dirties the owner and primary key', function (): void {
    $tenants = F4InstallationFixture::install();
    $a = OwnedRecord::create(['tenant_id' => $tenants[0]->value, 'name' => 'a']);
    $b = OwnedRecord::create(['tenant_id' => $tenants[1]->value, 'name' => 'b']);
    app(TenantRunner::class)->run($tenants[0], function () use ($a, $b): void {
        app(TenantBoundary::class)->assertRecord($a, 'tests.records');
        expect(fn () => app(TenantBoundary::class)->assertRecord($b, 'tests.records'))->toThrow(TenantBoundaryViolation::class);
        $b->tenant_id = $a->tenant_id;
        $b->id = $a->id;
        expect(fn () => app(TenantBoundary::class)->assertRecord($b, 'tests.records'))->toThrow(TenantBoundaryViolation::class);
    });
});

it('groups every caller OR branch while preserving bindings and soft deletes', function (): void {
    $tenants = F4InstallationFixture::install();
    OwnedRecord::create(['tenant_id' => $tenants[1]->value, 'name' => 'match-first']);
    OwnedRecord::create(['tenant_id' => $tenants[0]->value, 'name' => 'match-second']);
    OwnedRecord::create(['tenant_id' => $tenants[0]->value, 'name' => 'match-first'])->delete();
    app(TenantRunner::class)->run($tenants[0], function (): void {
        $query = OwnedRecord::where('name', 'match-first')->orWhere('name', 'match-second');
        expect(app(TenantBoundary::class)->query($query, 'tests.records')->pluck('name')->all())->toBe(['match-second']);
    });
});

it('admits registered model subclasses only when they retain canonical storage', function (): void {
    $tenants = F4InstallationFixture::install();
    $record = ProbeRestoredModel::create(['tenant_id' => $tenants[0]->value, 'name' => 'specialized']);

    app(TenantRunner::class)->run($tenants[0], function () use ($record): void {
        app(TenantBoundary::class)->assertRecord($record, 'tests.records');

        expect(app(TenantBoundary::class)->query(ProbeRestoredModel::query(), 'tests.records')->sole()->id)
            ->toBe($record->id);
    });
});

it('denies missing context unknown resources and conflicting registrations', function (): void {
    F4InstallationFixture::install();
    expect(fn () => app(TenantBoundary::class)->query(OwnedRecord::query(), 'tests.records'))->toThrow(TenantContextMissing::class)
        ->and(fn () => app(TenantResourceRegistry::class)->get('missing'))->toThrow(TenantConfigurationInvalid::class)
        ->and(fn () => app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'other', OwnedRecord::class)))->toThrow(TenantConfigurationInvalid::class);
});

it('rejects caller-selected connections even when their DSNs are identical', function (): void {
    $tenants = F4InstallationFixture::install();
    config()->set('database.connections.other', config('database.connections.sqlite'));
    app(TenantRunner::class)->run($tenants[0], function (): void {
        expect(fn () => app(TenantBoundary::class)->query(OwnedRecord::on('other'), 'tests.records'))->toThrow(TenantBoundaryViolation::class);
    });
});

it('rejects a retained tenant A model and loaded relation under tenant B', function (): void {
    $tenants = F4InstallationFixture::install();
    $parent = OwnedRecord::create(['tenant_id' => $tenants[0]->value, 'name' => 'parent']);
    $child = OwnedRecord::create(['tenant_id' => $tenants[0]->value, 'name' => 'child', 'parent_id' => $parent->id]);
    $child->load('parent');
    app(TenantRunner::class)->run($tenants[1], function () use ($child): void {
        expect(fn () => app(TenantBoundary::class)->assertRecord($child, 'tests.records'))->toThrow(TenantBoundaryViolation::class)
            ->and(fn () => app(TenantBoundary::class)->assertRecord($child->parent, 'tests.records'))->toThrow(TenantBoundaryViolation::class);
    });
});

it('rechecks status after runner entry and permits cleanup only inside the exact maintenance lease', function (TenantStatus $status): void {
    $tenants = F4InstallationFixture::install();
    app()->instance(PlatformAccess::class, new TestPlatformAccess);
    $maintenance = new InMemoryMaintenanceMode;
    $maintenance->activate([]);
    app()->instance(MaintenanceMode::class, $maintenance);
    app(TenantRunner::class)->run($tenants[0], function () use ($tenants, $status): void {
        app(TenantBoundary::class)->attributes('tests.records');
        DB::table('nvl_tenancy_tenants')->where('id', $tenants[0]->value)->update(['status' => $status->value]);
        expect(fn () => app(TenantBoundary::class)->attributes('tests.records'))->toThrow(TenantInactive::class);
    });
    app(TenantMaintenanceRunner::class)->run($tenants[0], new PlatformOperation('cleanup', 'user', 'operator'), function () use ($tenants): void {
        expect(app(TenantBoundary::class)->attributes('tests.records'))->toBe(['tenant_id' => $tenants[0]->value]);
    });
    expect(fn () => app(TenantRunner::class)->run($tenants[0], fn () => app(TenantBoundary::class)->attributes('tests.records')))->toThrow(TenantInactive::class);
})->with([TenantStatus::Suspended, TenantStatus::Deleted]);

it('keeps legacy identities and queries unchanged without directory storage', function (): void {
    OwnedRecord::create(['name' => 'legacy']);
    $query = OwnedRecord::query();
    expect(app(TenantBoundary::class)->key('tests.records', 'legacy-key'))->toBe('legacy-key')
        ->and(app(TenantBoundary::class)->attributes('tests.records'))->toBe([])
        ->and(app(TenantBoundary::class)->query($query, 'tests.records'))->toBe($query)
        ->and($query->count())->toBe(1);
});

it('scopes mixed rows with a null tenant and platform discriminator only in authorized platform context', function (): void {
    app()->instance(TenantResourceRegistry::class, new TenantResourceRegistry);
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', OwnedRecord::class, allowsPlatformCatalog: true));
    $tenants = F4InstallationFixture::install();
    app()->instance(PlatformAccess::class, new TestPlatformAccess);
    OwnedRecord::create(['tenant_id' => null, 'ownership_key' => 'platform', 'name' => 'catalog']);
    $invalid = OwnedRecord::create(['tenant_id' => null, 'ownership_key' => null, 'name' => 'ambiguous']);
    OwnedRecord::create(['tenant_id' => $tenants[0]->value, 'ownership_key' => 'tenant:'.$tenants[0]->value, 'name' => 'tenant']);
    OwnedRecord::create(['tenant_id' => $tenants[0]->value, 'ownership_key' => 'platform', 'name' => 'contradictory']);
    app(TenantRunner::class)->platform(new PlatformOperation('catalog', 'user', 'operator'), function () use ($invalid): void {
        expect(app(TenantBoundary::class)->query(OwnedRecord::query(), 'tests.records')->pluck('name')->all())->toBe(['catalog'])
            ->and(app(TenantBoundary::class)->attributes('tests.records'))->toBe(['tenant_id' => null, 'ownership_key' => 'platform'])
            ->and(fn () => app(TenantBoundary::class)->assertRecord($invalid, 'tests.records'))->toThrow(TenantBoundaryViolation::class);
    });
    app(TenantRunner::class)->run($tenants[0], function (): void {
        expect(app(TenantBoundary::class)->query(OwnedRecord::query(), 'tests.records')->pluck('name')->all())->toBe(['tenant']);
    });
});

it('denies platform mode access to tenant-only roots and generic access to fixed platform vocabulary', function (): void {
    F4InstallationFixture::install();
    app()->instance(PlatformAccess::class, new TestPlatformAccess);
    app(TenantRunner::class)->platform(new PlatformOperation('catalog', 'user', 'operator'), function (): void {
        expect(fn () => app(TenantBoundary::class)->query(OwnedRecord::query(), 'tests.records'))->toThrow(TenantBoundaryViolation::class);
    });
    app()->instance(TenantResourceRegistry::class, new TenantResourceRegistry);
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', OwnedRecord::class, TenantResourceKind::Platform));
    app(TenantRunner::class)->platform(new PlatformOperation('catalog', 'user', 'operator'), function (): void {
        expect(fn () => app(TenantBoundary::class)->query(OwnedRecord::query(), 'tests.records'))->toThrow(TenantBoundaryViolation::class);
    });
});

it('scopes identity keys by effective connection resource context and tenant', function (): void {
    $tenants = F4InstallationFixture::install();
    $a = app(TenantRunner::class)->run($tenants[0], fn () => app(TenantBoundary::class)->key('tests.records', 'same'));
    $b = app(TenantRunner::class)->run($tenants[1], fn () => app(TenantBoundary::class)->key('tests.records', 'same'));
    expect($a)->toBe('nvl:tenant:'.hash('sha256', '["sqlite","tests.records","tenant","10000000-0000-4000-8000-000000000001","same"]'))
        ->and($b)->not->toBe($a);
});

it('rejects ownership cycles at registration without retaining the failed definition', function (): void {
    $registry = new TenantResourceRegistry;
    $registry->register(new TenantResourceDefinition('tests.parent', 'tests', OwnedRecord::class, TenantResourceKind::Inherited, 'tests.child', 'parent'));
    expect(fn () => $registry->register(new TenantResourceDefinition('tests.child', 'tests', InheritedRecord::class, TenantResourceKind::Inherited, 'tests.parent', 'parent')))
        ->toThrow(TenantConfigurationInvalid::class)
        ->and($registry->all())->toHaveCount(1);
});

it('derives inherited ownership from the canonical parent and rejects forged child ownership', function (): void {
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.children', 'tests', InheritedRecord::class, TenantResourceKind::Inherited, 'tests.records', 'parent'));
    Schema::create('tenancy_test_children', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id');
        $table->uuid('parent_id');
        $table->string('name');
        $table->softDeletes();
    });
    $tenants = F4InstallationFixture::install();
    F4InstallationFixture::mark('tests.children');
    $a = OwnedRecord::create(['tenant_id' => $tenants[0]->value, 'name' => 'a']);
    $b = OwnedRecord::create(['tenant_id' => $tenants[1]->value, 'name' => 'b']);
    $good = InheritedRecord::create(['tenant_id' => $tenants[0]->value, 'parent_id' => $a->id, 'name' => 'good']);
    $forged = InheritedRecord::create(['tenant_id' => $tenants[0]->value, 'parent_id' => $b->id, 'name' => 'forged']);
    $forged->parent_id = $a->id;
    $forged->setRelation('parent', $a);
    app(TenantRunner::class)->run($tenants[0], function () use ($good, $forged): void {
        app(TenantBoundary::class)->assertRecord($good, 'tests.children');
        expect(fn () => app(TenantBoundary::class)->assertRecord($forged, 'tests.children'))->toThrow(TenantBoundaryViolation::class)
            ->and(app(TenantBoundary::class)->query(InheritedRecord::query(), 'tests.children')->pluck('name')->all())->toBe(['good'])
            ->and(fn () => app(TenantBoundary::class)->attributes('tests.children'))->toThrow(TenantBoundaryViolation::class);
    });
});

it('scopes a string polymorphic identity against UUID parents through the package allowlist', function (): void {
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.poly', 'tests', PolymorphicRecord::class, TenantResourceKind::Inherited, parentRelation: 'owner'));
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.disallowed', 'other', InheritedRecord::class));
    app(TenantResourceRegistry::class)->registerParentResolver('tests.poly', AllowedParentResolver::class);
    Schema::create('tenancy_test_polymorphic', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id');
        $table->string('owner_id');
        $table->string('owner_type');
        $table->string('name');
        $table->softDeletes();
    });
    $tenants = F4InstallationFixture::install();
    F4InstallationFixture::mark('tests.poly');
    $a = OwnedRecord::create(['tenant_id' => $tenants[0]->value, 'name' => 'a']);
    $b = OwnedRecord::create(['tenant_id' => $tenants[1]->value, 'name' => 'b']);
    $good = PolymorphicRecord::create(['tenant_id' => $tenants[0]->value, 'owner_id' => $a->id, 'owner_type' => 'record', 'name' => 'good']);
    $bad = [];
    foreach (['record', InheritedRecord::class, DateTime::class, 'unknown'] as $type) {
        $record = PolymorphicRecord::create(['tenant_id' => $tenants[0]->value, 'owner_id' => $b->id, 'owner_type' => $type, 'name' => 'bad']);
        $record->owner_id = $a->id;
        $record->owner_type = 'record';
        $record->setRelation('owner', $a);
        $bad[] = $record;
    }
    app(TenantRunner::class)->run($tenants[0], function () use ($good, $bad): void {
        app(TenantBoundary::class)->assertRecord($good, 'tests.poly');
        foreach ($bad as $record) {
            expect(fn () => app(TenantBoundary::class)->assertRecord($record, 'tests.poly'))->toThrow(TenantBoundaryViolation::class);
        }
        expect(app(TenantBoundary::class)->query(PolymorphicRecord::query(), 'tests.poly')->pluck('name')->all())->toBe(['good']);
    });
});

it('rejects an SQL builder swapped onto unadopted storage while retaining the canonical Eloquent model', function (bool $enabled): void {
    $tenants = $enabled ? F4InstallationFixture::install() : [new TenantId('10000000-0000-4000-8000-000000000001')];
    config()->set('database.connections.other', config('database.connections.sqlite'));
    $other = DB::connection('other');
    $other->getSchemaBuilder()->create('tenancy_test_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id');
        $table->string('name');
        $table->softDeletes();
    });
    $other->table('tenancy_test_records')->insert([
        'id' => '20000000-0000-4000-8000-000000000001',
        'tenant_id' => $tenants[0]->value,
        'name' => 'unadopted-other-connection',
    ]);
    $query = OwnedRecord::query()->setQuery($other->table('tenancy_test_records'));
    expect($query->getModel()->getConnection())->toBe(DB::connection())
        ->and($query->getQuery()->getConnection())->toBe($other)
        ->and($other->getSchemaBuilder()->hasTable('nvl_tenancy_installation_state'))->toBeFalse()
        ->and((clone $query)->pluck('name')->all())->toBe(['unadopted-other-connection']);
    $assertDenied = function () use ($query): void {
        expect(fn () => app(TenantBoundary::class)->query($query, 'tests.records')->pluck('name')->all())
            ->toThrow(TenantBoundaryViolation::class);
    };
    if ($enabled) {
        app(TenantRunner::class)->run($tenants[0], $assertDenied);
    } else {
        $assertDenied();
    }
})->with([true, false]);

it('preserves a replacement SQL builder on the canonical unadopted connection', function (): void {
    OwnedRecord::create(['name' => 'legacy']);
    $query = OwnedRecord::query()->setQuery(DB::connection()->table('tenancy_test_records'));
    expect(app(TenantBoundary::class)->query($query, 'tests.records'))->toBe($query)
        ->and($query->pluck('name')->all())->toBe(['legacy']);
});

it('rejects alternate SQL sources and unions before disabled resource admission', function (string $shape): void {
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('private.records', 'private', InheritedRecord::class));
    Schema::create('tenancy_test_children', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id');
        $table->string('name');
        $table->softDeletes();
    });
    $tenants = F4InstallationFixture::install();
    F4InstallationFixture::mark('private.records');
    InheritedRecord::create(['tenant_id' => $tenants[0]->value, 'name' => 'adopted-private-row']);
    DB::table('nvl_tenancy_installation_state')->where('resource', 'tests.records')->delete();
    app(TenantInstallationState::class)->invalidate();
    config()->set('tenancy.enabled', false);
    $query = OwnedRecord::withoutGlobalScopes();
    if ($shape === 'union') {
        $query->select(['id', 'tenant_id', 'name'])->union(DB::table('tenancy_test_children')->select(['id', 'tenant_id', 'name']));
    } else {
        $source = $shape === 'alias' ? 'tenancy_test_children as tenancy_test_records' : 'tenancy_test_children';
        $query->setQuery(DB::table($source));
    }
    expect((clone $query)->get()->pluck('name')->all())->toBe(['adopted-private-row'])
        ->and(fn () => app(TenantBoundary::class)->query($query, 'tests.records')->get())
        ->toThrow(TenantBoundaryViolation::class);
})->with(['replacement', 'alias', 'union']);

it('preserves A and B natural uniqueness using tenant-specific mixed ownership keys', function (): void {
    app()->instance(TenantResourceRegistry::class, new TenantResourceRegistry);
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', OwnedRecord::class, allowsPlatformRows: true));
    Schema::table('tenancy_test_records', function (Blueprint $table): void {
        $table->unique(['ownership_key', 'name']);
    });
    $tenants = F4InstallationFixture::install();
    foreach ($tenants as $tenant) {
        app(TenantRunner::class)->run($tenant, function (): void {
            OwnedRecord::create([...app(TenantBoundary::class)->attributes('tests.records'), 'name' => 'same-business-key']);
        });
    }
    expect(OwnedRecord::orderBy('tenant_id')->pluck('ownership_key')->all())->toBe([
        'tenant:10000000-0000-4000-8000-000000000001',
        'tenant:10000000-0000-4000-8000-000000000002',
    ]);
    expect(fn () => app(TenantRunner::class)->run($tenants[0], fn () => OwnedRecord::create([
        ...app(TenantBoundary::class)->attributes('tests.records'), 'name' => 'same-business-key',
    ])))->toThrow(QueryException::class);
});

it('rejects forged persisted mixed discriminators even when the tenant column and dirty key match', function (string $forgery): void {
    app()->instance(TenantResourceRegistry::class, new TenantResourceRegistry);
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('tests.records', 'tests', OwnedRecord::class, allowsPlatformRows: true));
    $tenants = F4InstallationFixture::install();
    $key = $forgery === 'other-tenant' ? 'tenant:'.$tenants[1]->value : $forgery;
    $forged = OwnedRecord::create(['tenant_id' => $tenants[0]->value, 'ownership_key' => $key, 'name' => 'forged']);
    $valid = OwnedRecord::create(['tenant_id' => $tenants[0]->value, 'ownership_key' => 'tenant:'.$tenants[0]->value, 'name' => 'valid']);
    $forged->ownership_key = $valid->ownership_key;
    app(TenantRunner::class)->run($tenants[0], function () use ($forged): void {
        expect(fn () => app(TenantBoundary::class)->assertRecord($forged, 'tests.records'))->toThrow(TenantBoundaryViolation::class)
            ->and(app(TenantBoundary::class)->query(OwnedRecord::query(), 'tests.records')->pluck('name')->all())->toBe(['valid']);
    });
})->with(['tenant', 'other-tenant', 'platform']);
