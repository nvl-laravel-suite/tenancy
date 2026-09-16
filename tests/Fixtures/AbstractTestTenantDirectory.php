<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Nvl\Tenancy\Contracts\TenantDirectory;

/**
 * Non-instantiable directory implementation used for structural validation.
 */
abstract class AbstractTestTenantDirectory implements TenantDirectory {}
