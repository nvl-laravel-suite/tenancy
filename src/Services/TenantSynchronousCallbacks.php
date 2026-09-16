<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Closure;
use Illuminate\Bus\Dispatcher as NativeDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use ReflectionProperty;

/** Preserves native host response scheduling while synchronous privileged callbacks are fenced. @internal */
final readonly class TenantSynchronousCallbacks
{
    /**
     * Fence native response dispatch so the active lease rejects it before it can be deferred.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(DispatcherContract $dispatcher, Closure $callback): mixed
    {
        if (! $dispatcher instanceof NativeDispatcher || $dispatcher::class !== NativeDispatcher::class) {
            throw new TenantConfigurationInvalid('Tenant maintenance requires the native Laravel bus dispatcher for response dispatch fencing.');
        }
        $flag = new ReflectionProperty(NativeDispatcher::class, 'allowsDispatchingAfterResponses');
        $previous = $flag->getValue($dispatcher);
        if (! is_bool($previous)) {
            throw new TenantConfigurationInvalid('The native bus dispatcher response deferral setting must be boolean.');
        }
        $dispatcher->withoutDispatchingAfterResponses();
        try {
            return $callback();
        } finally {
            if ($previous) {
                $dispatcher->withDispatchingAfterResponses();
            } else {
                $dispatcher->withoutDispatchingAfterResponses();
            }
        }
    }
}
