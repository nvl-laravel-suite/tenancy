<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Exceptions;

use Illuminate\Http\Response;
use Nvl\Tenancy\Enums\TenancyResponseCode;

/**
 * Reports an unavailable or incomplete tenant ownership schema.
 */
final class TenantSchemaNotReady extends TenancyException
{
    /**
     * Create a schema-readiness failure.
     */
    public function __construct(string $message = 'Tenant schema is not ready.')
    {
        parent::__construct(
            message: $message,
            responseCode: TenancyResponseCode::TenantSchemaNotReady,
            suggestedStatus: Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
