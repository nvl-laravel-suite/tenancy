<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Contracts\TenantHttpResolver;
use Nvl\Support\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Support\Tenancy\Services\TenantSiteAttributes;
use Nvl\Support\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Support\Tenancy\ValueObjects\TenantSiteContext;
use Nvl\Tenancy\Contracts\TenantSiteResolver;
use Nvl\Tenancy\Http\Middleware\RequireTenantMembership;
use Nvl\Tenancy\Http\Middleware\ResolvePublicTenant;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\Tests\Fixtures\ArrayTenantDirectory;
use Nvl\Tenancy\Tests\Fixtures\StrictTenantHttpResolver;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    config()->set('tenancy.enabled', true);
    $this->a = new TenantId('10000000-0000-4000-8000-000000000001');
    $this->b = new TenantId('10000000-0000-4000-8000-000000000002');
    $this->directory = new ArrayTenantDirectory([
        $this->a->value => new TenantDescriptor($this->a, TenantStatus::Active),
        $this->b->value => new TenantDescriptor($this->b, TenantStatus::Active),
    ]);
    $this->admissions = new ArrayObject;
    $this->bindings = new ArrayObject;
    app()->instance(TenantDirectory::class, $this->directory);
    app()->instance(TenantHttpResolver::class, new StrictTenantHttpResolver);
    app()->instance(TenantMembershipAccess::class, new class($this->admissions) implements TenantMembershipAccess
    {
        public function __construct(private ArrayObject $admissions) {}

        public function assertMember(Authenticatable $actor, TenantId $tenant): void
        {
            $this->admissions[] = $tenant->value;
            if ($actor->getAuthIdentifier() !== $tenant->value) {
                throw new TenantBoundaryViolation;
            }
        }
    });
    Route::bind('record', function (string $value): string {
        $tenant = app(TenantContext::class)->requireTenant()->value;
        $this->bindings[] = $tenant;

        return $tenant.':'.$value;
    });
    Route::get('/member/{tenant}/{record}', fn (string $tenant, string $record) => ['record' => $record])
        ->middleware([SubstituteBindings::class, RequireTenantMembership::class, 'auth']);
    Route::get('/optional/{tenant}/{record}', fn (string $tenant, string $record) => ['record' => $record])
        ->middleware([SubstituteBindings::class, RequireTenantMembership::class]);
    Route::get('/public/{record}', fn (string $record) => [
        'record' => $record, 'site' => app(TenantSiteContext::class)->site, 'origin' => app(TenantSiteContext::class)->canonicalOrigin,
    ])->middleware([SubstituteBindings::class, ResolvePublicTenant::class]);
    Route::get('/central', fn () => ['mode' => app(TenantContext::class)->snapshot()->mode->value]);
});

it('authenticates and admits membership before route binding despite declaration order', function (): void {
    $this->actingAs(new GenericUser(['id' => $this->a->value]));
    $this->getJson('/member/'.$this->a->value.'/one')->assertOk()->assertJsonPath('record', $this->a->value.':one');
    expect((array) $this->admissions)->toBe([$this->a->value])
        ->and((array) $this->bindings)->toBe([$this->a->value])
        ->and(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
});

it('denies unauthorized membership before binding', function (): void {
    $this->actingAs(new GenericUser(['id' => $this->b->value]));
    $this->getJson('/member/'.$this->a->value.'/one')->assertNotFound();
    expect((array) $this->bindings)->toBe([]);
});

it('denies missing actors even when host omitted authentication middleware', function (): void {
    $this->getJson('/optional/'.$this->a->value.'/one')->assertNotFound();
    expect((array) $this->admissions)->toBe([])->and((array) $this->bindings)->toBe([]);
});

it('propagates conflicting host selections before membership or binding', function (string $selector): void {
    $this->actingAs(new GenericUser(['id' => $this->a->value]));
    if ($selector === 'domain') {
        app()->instance(TenantHttpResolver::class, new StrictTenantHttpResolver(['localhost' => $this->b->value]));
    }
    $headers = $selector === 'header' ? ['X-Test-Tenant' => $this->b->value] : [];
    $this->getJson('/member/'.$this->a->value.'/one', $headers)->assertNotFound();
    expect((array) $this->admissions)->toBe([])->and((array) $this->bindings)->toBe([]);
})->with(['header', 'domain']);

it('denies selected middleware without host resolvers and leaves central routes alone', function (): void {
    app()->offsetUnset(TenantHttpResolver::class);
    $this->actingAs(new GenericUser(['id' => $this->a->value]));
    $this->getJson('/member/'.$this->a->value.'/one')->assertNotFound();
    $this->getJson('/public/one')->assertNotFound();
    $this->getJson('/central')->assertOk()->assertJsonPath('mode', 'unresolved');
    expect((array) $this->bindings)->toBe([]);
});

it('uses only the verified public site and permits a host-verified serving alias', function (): void {
    app()->instance(TenantSiteResolver::class, new class($this->a) implements TenantSiteResolver
    {
        public function __construct(private TenantId $tenant) {}

        public function resolve(Request $request): TenantSiteContext
        {
            if ($request->getHost() !== 'localhost') {
                throw new TenantBoundaryViolation;
            }

            return new TenantSiteContext($this->tenant, 'storefront', 'https://canonical.example');
        }
    });
    $this->getJson('/public/one', ['Origin' => 'https://attacker.example', 'X-Test-Tenant' => $this->b->value])
        ->assertOk()->assertJsonPath('record', $this->a->value.':one')
        ->assertJsonPath('site', 'storefront')->assertJsonPath('origin', 'https://canonical.example');
    expect(fn () => app(TenantSiteContext::class))->toThrow(TenantContextMissing::class);
});

it('returns the same public result for suspended deleted and unknown tenants', function (string $status): void {
    if ($status === 'unknown') {
        unset($this->directory->tenants[$this->a->value]);
    } else {
        $this->directory->tenants[$this->a->value] = new TenantDescriptor($this->a, TenantStatus::from($status));
    }
    app()->instance(TenantSiteResolver::class, new class($this->a) implements TenantSiteResolver
    {
        public function __construct(private TenantId $tenant) {}

        public function resolve(Request $request): TenantSiteContext
        {
            return new TenantSiteContext($this->tenant, 'site', 'https://example.test');
        }
    });
    $this->getJson('/public/one')->assertNotFound()->assertJsonPath('message', 'Tenant was not found.');
    expect((array) $this->bindings)->toBe([]);
})->with(['suspended', 'deleted', 'unknown']);

it('never reuses public site objects across requests and rejects nested tenant disagreement', function (): void {
    $sites = new ArrayObject([
        new TenantSiteContext($this->a, 'first', 'https://first.example'),
        new TenantSiteContext($this->b, 'second', 'https://second.example'),
    ]);
    app()->instance(TenantSiteResolver::class, new class($sites) implements TenantSiteResolver
    {
        private int $offset = 0;

        public function __construct(private ArrayObject $sites) {}

        public function resolve(Request $request): TenantSiteContext
        {
            return $this->sites[$this->offset++];
        }
    });
    Route::get('/nested-site', function (): array {
        expect(fn () => app(TenantRunner::class)->run($this->b, fn () => app(TenantSiteContext::class)))
            ->toThrow(TenantBoundaryViolation::class);

        return ['site' => app(TenantSiteContext::class)->site];
    })->middleware(ResolvePublicTenant::class);
    $this->getJson('/nested-site')->assertOk()->assertJsonPath('site', 'first');
    $this->getJson('/public/two')->assertOk()->assertJsonPath('site', 'second')->assertJsonPath('record', $this->b->value.':two');
});

it('restores both public site attribute keys after successful and failed request scopes', function (bool $fail): void {
    $site = new TenantSiteContext($this->a, 'current', 'https://current.example');
    app()->instance(TenantSiteResolver::class, new class($site) implements TenantSiteResolver
    {
        public function __construct(private TenantSiteContext $site) {}

        public function resolve(Request $request): TenantSiteContext
        {
            return $this->site;
        }
    });
    $request = Request::create('https://current.example');
    $original = [TenantSiteContext::class => null, TenantSiteAttributes::LegacyKey => 'legacy-before'];
    $request->attributes->replace($original);
    $invoke = static fn (): Response => app(ResolvePublicTenant::class)->handle($request, static function (Request $request) use ($site, $fail): Response {
        expect($request->attributes->get(TenantSiteContext::class))->toBe($site)
            ->and($request->attributes->get(TenantSiteAttributes::LegacyKey))->toBe($site);
        if ($fail) {
            throw new RuntimeException('Fixture callback failed.');
        }

        return new Response('ok');
    });

    if ($fail) {
        expect($invoke)->toThrow(RuntimeException::class, 'Fixture callback failed.');
    } else {
        expect($invoke()->getContent())->toBe('ok');
    }
    expect($request->attributes->all())->toBe($original)
        ->and(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Unresolved);
})->with([false, true]);
