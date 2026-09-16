<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Exceptions;

use Illuminate\Http\Response;
use Nvl\Tenancy\Enums\TenancyResponseCode;

/**
 * Reports tenant work attempted without an active tenant scope.
 */
final class TenantContextMissing extends TenancyException
{
    /**
     * Create a missing-context failure.
     */
    public function __construct(string $message = 'Tenant context is not resolved.')
    {
        parent::__construct(
            message: $message,
            responseCode: TenancyResponseCode::TenantContextMissing,
            suggestedStatus: Response::HTTP_CONFLICT,
        );
    }
}
