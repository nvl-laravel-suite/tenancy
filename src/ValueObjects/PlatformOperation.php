<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\ValueObjects\PlatformOperation; retained for one major release. */
LegacyNeutralAlias::register(PlatformOperation::class, 'Nvl\\Tenancy\\ValueObjects\\PlatformOperation');
