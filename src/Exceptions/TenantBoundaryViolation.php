<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Exceptions;

use Illuminate\Http\Response;
use Nvl\Tenancy\Enums\TenancyResponseCode;

/**
 * Reports a record or operation outside the active ownership boundary.
 */
final class TenantBoundaryViolation extends TenancyException
{
    /**
     * Create an ownership-boundary failure.
     */
    public function __construct(string $message = 'Tenant resource is outside the active context.')
    {
        parent::__construct(
            message: $message,
            responseCode: TenancyResponseCode::TenantBoundaryViolation,
            suggestedStatus: Response::HTTP_NOT_FOUND,
        );
    }
}
