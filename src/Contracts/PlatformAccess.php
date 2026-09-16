<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Tenancy\ValueObjects\PlatformOperation;

/**
 * Authorizes one explicit privileged platform operation.
 */
interface PlatformAccess
{
    /**
     * Authorize a privileged platform operation.
     */
    public function authorize(PlatformOperation $operation): void;
}
