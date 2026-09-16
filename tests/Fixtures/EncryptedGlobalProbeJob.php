<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use RuntimeException;

/** An explicitly registered scalar global identity job also supports native encryption. */
final class EncryptedGlobalProbeJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public static int $executions = 0;

    public function handle(): void
    {
        if (app(TenantContext::class)->snapshot()->mode !== TenantContextMode::Unresolved) {
            throw new RuntimeException('Global identity scope was not isolated.');
        }
        self::$executions++;
    }
}
