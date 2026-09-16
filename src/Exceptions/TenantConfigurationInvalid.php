<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Exceptions;

use Illuminate\Http\Response;
use Nvl\Tenancy\Enums\TenancyResponseCode;

/**
 * Reports invalid deployment-level tenancy configuration.
 */
final class TenantConfigurationInvalid extends TenancyException
{
    /**
     * Create a configuration failure with a bounded diagnostic message.
     */
    public function __construct(string $message = 'Tenancy configuration is invalid.')
    {
        parent::__construct(
            message: $message,
            responseCode: TenancyResponseCode::TenantConfigurationInvalid,
            suggestedStatus: Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }
}
