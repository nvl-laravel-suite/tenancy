<?php

declare(strict_types=1);

use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Enums\TenantContextMode;

it('registers standalone tenancy type discovery while preserving disabled compatibility', function (): void {
    expect(array_column(app(TypeScriptSourceRegistry::class)->descriptors(), 'package'))
        ->toContain('nvl/core', 'nvl/tenancy');
    expect(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Disabled);
});
