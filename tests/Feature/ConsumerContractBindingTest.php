<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Support\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Tenancy\Actions\ChangeTenantStatusAction;
use Nvl\Tenancy\Actions\ProvisionTenantAction;
use Nvl\Tenancy\Contracts\ChangeTenantStatusContract;
use Nvl\Tenancy\Contracts\ProvisionTenantContract;
use Nvl\Tenancy\Providers\TenancyServiceProvider;

/** @return array<string, array{class-string, class-string}> */
function tenancyConsumerContracts(): array
{
    return [
        'provision' => [ProvisionTenantContract::class, ProvisionTenantAction::class],
        'status' => [ChangeTenantStatusContract::class, ChangeTenantStatusAction::class],
    ];
}

it('retains the complete native workflow signatures and documentation', function (string $contract, string $implementation): void {
    $declared = new ReflectionMethod($contract, 'execute');
    $native = new ReflectionMethod($implementation, 'execute');
    $reflection = new ReflectionClass($implementation);
    expect($reflection->implementsInterface($contract))->toBeTrue()
        ->and($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue()
        ->and((string) $declared->getReturnType())->toBe((string) $native->getReturnType())
        ->and($declared->getDocComment())->toBe($native->getDocComment())
        ->and((new ReflectionClass($contract))->getDocComment())->toContain('@api')
        ->and($declared->getNumberOfParameters())->toBe($native->getNumberOfParameters());
    foreach ($declared->getParameters() as $index => $parameter) {
        $concrete = $native->getParameters()[$index];
        expect([$parameter->getName(), (string) $parameter->getType()])->toBe([$concrete->getName(), (string) $concrete->getType()])
            ->and($parameter->isDefaultValueAvailable())->toBeFalse()
            ->and($parameter->isPassedByReference())->toBeFalse()
            ->and($parameter->isVariadic())->toBeFalse();
    }
})->with(tenancyConsumerContracts());

it('resolves transient native defaults and concrete actions without enabling tenancy', function (string $contract, string $implementation): void {
    expect(config('nvl-tenancy.enabled'))->toBeFalse()
        ->and(app($contract))->toBeInstanceOf($implementation)->not->toBe(app($contract))
        ->and(app($implementation))->toBeInstanceOf($implementation)
        ->and(config('nvl-tenancy.migrations.enabled'))->toBeFalse();
})->with(tenancyConsumerContracts());

it('preserves early host bindings in independent applications and late replacements', function (string $contract, string $implementation): void {
    $host = new Application(sys_get_temp_dir());
    $host->instance('config', new Repository(['nvl-tenancy' => ['enabled' => false]]));
    $host->instance('files', new Filesystem);
    $early = Mockery::mock($contract);
    $host->instance($contract, $early);
    (new TenancyServiceProvider($host))->register();
    expect($host->make($contract))->toBe($early);
    Container::setInstance($this->app);
    Facade::setFacadeApplication($this->app);
    $late = Mockery::mock($contract);
    app()->instance($contract, $late);
    (new TenancyServiceProvider($this->app))->register();
    expect(app($contract))->toBe($late)
        ->and(app($implementation))->toBeInstanceOf($implementation)
        ->and($host->make($contract))->toBe($early);
})->with(tenancyConsumerContracts());

it('injects and executes the selected host workflows through their contracts', function (): void {
    $tenant = new TenantId('11111111-1111-4111-8111-111111111111');
    $operation = new PlatformOperation('consumer contract', 'operator', 'fixture');
    $descriptor = new TenantDescriptor($tenant, TenantStatus::Active);
    $provision = Mockery::mock(ProvisionTenantContract::class);
    $provision->shouldReceive('execute')->once()->with('Consumer', $operation)->andReturn($descriptor);
    $change = Mockery::mock(ChangeTenantStatusContract::class);
    $change->shouldReceive('execute')->once()->with($tenant, TenantStatus::Suspended, $operation);
    app()->instance(ProvisionTenantContract::class, $provision);
    app()->instance(ChangeTenantStatusContract::class, $change);
    $result = app()->call(static function (ProvisionTenantContract $provision, ChangeTenantStatusContract $change) use ($tenant, $operation): TenantDescriptor {
        $result = $provision->execute('Consumer', $operation);
        $change->execute($tenant, TenantStatus::Suspended, $operation);

        return $result;
    });
    expect($result)->toBe($descriptor);
});
