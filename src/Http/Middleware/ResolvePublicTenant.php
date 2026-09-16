<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Nvl\Tenancy\Contracts\TenantSiteResolver;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantInactive;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\TenantSiteContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Admits a host-verified public tenant and keeps its site context on the request. */
final readonly class ResolvePublicTenant
{
    /** Resolve public adapters only at the request boundary. */
    public function __construct(private Container $container, private TenantRunner $runner) {}

    /**
     * Enter the verified site tenant and restore request-local site state on exit.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->container->bound(TenantSiteResolver::class)) {
            throw new NotFoundHttpException('Tenant was not found.');
        }
        try {
            $site = $this->container->make(TenantSiteResolver::class)->resolve($request);

            return $this->runner->run($site->tenantId, function () use ($site, $request, $next): Response {
                $previous = $request->attributes->get(TenantSiteContext::class);
                $request->attributes->set(TenantSiteContext::class, $site);
                try {
                    return $next($request);
                } finally {
                    if ($previous === null) {
                        $request->attributes->remove(TenantSiteContext::class);
                    } else {
                        $request->attributes->set(TenantSiteContext::class, $previous);
                    }
                }
            });
        } catch (TenantBoundaryViolation|TenantInactive|TenantNotFound $exception) {
            throw new NotFoundHttpException('Tenant was not found.', $exception);
        }
    }
}
