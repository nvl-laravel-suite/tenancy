<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Enums;

use Nvl\Support\Tenancy\Enums\TenancyResponseCode;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Enums\TenancyResponseCode; retained for one major release. */
LegacyNeutralAlias::register(TenancyResponseCode::class, 'Nvl\\Tenancy\\Enums\\TenancyResponseCode');
