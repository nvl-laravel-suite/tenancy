<?php

declare(strict_types=1);

use Nvl\Tenancy\Services\TenantExtensionGuard;

it('accepts a base extension while tenancy is disabled', function (): void {
    $extension = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'legacy';
        }
    };

    app(TenantExtensionGuard::class)->assertCompatible(
        extension: $extension,
        baseContract: Stringable::class,
        tenantContract: JsonSerializable::class,
        label: 'Probe extension',
    );

    expect(true)->toBeTrue();
});

it('requires the tenant capability only while tenancy is enabled', function (): void {
    config()->set('tenancy.enabled', true);

    $extension = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'legacy';
        }
    };

    expect(fn () => app(TenantExtensionGuard::class)->assertCompatible(
        extension: $extension,
        baseContract: Stringable::class,
        tenantContract: JsonSerializable::class,
        label: 'Probe extension',
    ))->toThrow(InvalidArgumentException::class, 'Probe extension ['.$extension::class.'] is not tenant compatible.');
});

it('always rejects extensions outside their base contract', function (): void {
    expect(fn () => app(TenantExtensionGuard::class)->assertCompatible(
        extension: stdClass::class,
        baseContract: Stringable::class,
        tenantContract: JsonSerializable::class,
        label: 'Probe extension',
    ))->toThrow(InvalidArgumentException::class, 'must implement ['.Stringable::class.']');
});
