<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvl\Support\Tenancy\Contracts\TenantBoundary as BoundaryContract;
use Nvl\Support\Tenancy\Contracts\TenantContext as ContextContract;
use Nvl\Support\Tenancy\Contracts\TenantDirectory as DirectoryContract;
use Nvl\Support\Tenancy\Contracts\TenantQueueContext as QueueContract;
use Nvl\Support\Tenancy\Contracts\TenantRunner as RunnerContract;
use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Support\Tenancy\ValueObjects\TenantSiteContext;
use Nvl\Tenancy\Services\ScopedTenantContext;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantQueueContext;
use Nvl\Tenancy\Services\TenantRunner;

it('selects the enforcing services through the neutral Core contracts', function (): void {
    expect(app(BoundaryContract::class))->toBeInstanceOf(TenantBoundary::class)
        ->and(app(ContextContract::class))->toBeInstanceOf(ScopedTenantContext::class)
        ->and(app(QueueContract::class))->toBeInstanceOf(TenantQueueContext::class)
        ->and(app(RunnerContract::class))->toBeInstanceOf(TenantRunner::class);
});

it('loads deprecated aliases idempotently for legacy catches and immutable serialized identifiers', function (): void {
    require __DIR__.'/../../src/Support/LegacyNeutralAliases.php';
    require __DIR__.'/../../src/Support/LegacyNeutralAliases.php';
    $legacy = 'Nvl\\Tenancy\\ValueObjects\\TenantId';
    $id = new TenantId('10000000-0000-4000-8000-000000000001');
    expect($id)->toBeInstanceOf($legacy);
    $serialized = 'O:'.strlen($legacy).':"'.$legacy.'":1:{s:5:"value";s:36:"'.$id->value.'";}';
    expect(unserialize($serialized, ['allowed_classes' => [$legacy, TenantId::class]]))->toBeInstanceOf(TenantId::class);
    try {
        throw new TenantBoundaryViolation;
    } catch (Nvl\Tenancy\Exceptions\TenantBoundaryViolation $exception) {
        expect($exception)->toBeInstanceOf(TenantBoundaryViolation::class);
    }
});

it('shares late legacy scoped adapters with neutral consumers in either resolution order', function (bool $neutralFirst): void {
    $legacy = 'Nvl\\Tenancy\\Contracts\\TenantDirectory';
    app()->scoped($legacy, static fn (): DirectoryContract => new class implements DirectoryContract
    {
        public TenantStatus $status = TenantStatus::Active;

        public function find(TenantId $tenant): TenantDescriptor
        {
            return new TenantDescriptor($tenant, $this->status);
        }
    });
    $first = app($neutralFirst ? DirectoryContract::class : $legacy);
    $second = app($neutralFirst ? $legacy : DirectoryContract::class);

    expect($first)->toBe($second);
    $first->status = TenantStatus::Suspended;
    expect($second->find(new TenantId('10000000-0000-4000-8000-000000000001'))->status)->toBe(TenantStatus::Suspended);

    app()->forgetScopedInstances();
    $fresh = app(DirectoryContract::class);
    expect($fresh)->not->toBe($first)
        ->and($fresh)->toBe(app($legacy))
        ->and($fresh->find(new TenantId('10000000-0000-4000-8000-000000000001'))->status)->toBe(TenantStatus::Active);
})->with([true, false]);

it('resolves a verified site stored under the deprecated request attribute key', function (): void {
    $tenant = new TenantId('10000000-0000-4000-8000-000000000001');
    $site = new TenantSiteContext($tenant, 'site', 'https://site.test');
    $context = Mockery::mock(ContextContract::class);
    $context->shouldReceive('requireTenant')->andReturn($tenant);
    app()->instance(ContextContract::class, $context);
    $request = Request::create('/');
    $request->attributes->set('Nvl\\Tenancy\\ValueObjects\\TenantSiteContext', $site);
    app()->instance(Request::class, $request);

    expect(app(TenantSiteContext::class))->toBe($site);
});
