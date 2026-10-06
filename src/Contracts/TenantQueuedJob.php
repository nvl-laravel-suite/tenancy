<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Support\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Contracts\TenantQueuedJob; retained for one major release. */
LegacyNeutralAlias::register(TenantQueuedJob::class, 'Nvl\\Tenancy\\Contracts\\TenantQueuedJob');
