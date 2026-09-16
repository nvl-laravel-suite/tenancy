<?php

declare(strict_types=1);

use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;

it('registers standalone tenancy type discovery while preserving disabled compatibility', function (): void {
    expect(array_column(app(TypeScriptSourceRegistry::class)->descriptors(), 'package'))
        ->toContain('nvl/data', 'nvl/tenancy');
    expect(app(TenantContext::class)->snapshot()->mode)->toBe(TenantContextMode::Disabled);
});
