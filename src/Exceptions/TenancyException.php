<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Exceptions;

use Nvl\Support\Tenancy\Exceptions\TenancyException;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Exceptions\TenancyException; retained for one major release. */
LegacyNeutralAlias::register(TenancyException::class, 'Nvl\\Tenancy\\Exceptions\\TenancyException');
