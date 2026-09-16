<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Exceptions;

use Illuminate\Http\Response;
use Nvl\Tenancy\Enums\TenancyResponseCode;

/**
 * Reports a tenant identifier absent from the configured directory.
 */
final class TenantNotFound extends TenancyException
{
    /**
     * Create an unknown-tenant failure.
     */
    public function __construct(string $message = 'Tenant was not found.')
    {
        parent::__construct(
            message: $message,
            responseCode: TenancyResponseCode::TenantNotFound,
            suggestedStatus: Response::HTTP_NOT_FOUND,
        );
    }
}
