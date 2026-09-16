<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Container\Container;
use Illuminate\Queue\Queue;

/**
 * Registers the synchronous recovery guard without capturing an application in static hooks.
 *
 * @internal This framework seam is never instantiated as a queue driver.
 */
abstract class TenantMaintenanceQueueGuard extends Queue
{
    /** Install exactly one named guard while preserving host payload callbacks. */
    public static function register(): void
    {
        $callback = [self::class, 'payload'];
        if (! in_array($callback, self::$createPayloadCallbacks, true)) {
            self::createPayloadUsing($callback);
        }
    }

    /**
     * Deny payload creation under the current scope without adding privilege metadata.
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $app = Container::getInstance();
        if ($app->bound(TenantMaintenanceLease::class)) {
            $app->make(TenantMaintenanceLease::class)->assertQueueAllowed();
        }

        return [];
    }
}
