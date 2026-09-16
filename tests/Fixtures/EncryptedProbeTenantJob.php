<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;

/** Exercises Laravel's native encrypted object payload representation. */
final class EncryptedProbeTenantJob extends ProbeTenantJob implements ShouldBeEncrypted {}
