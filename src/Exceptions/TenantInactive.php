<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Exceptions;

use Illuminate\Http\Response;
use Nvl\Tenancy\Enums\TenancyResponseCode;

/**
 * Reports tenant work denied because its directory entry is inactive.
 */
final class TenantInactive extends TenancyException
{
    /**
     * Create an inactive-tenant failure.
     */
    public function __construct(string $message = 'Tenant is not active.')
    {
        parent::__construct(
            message: $message,
            responseCode: TenancyResponseCode::TenantInactive,
            suggestedStatus: Response::HTTP_FORBIDDEN,
        );
    }
}
