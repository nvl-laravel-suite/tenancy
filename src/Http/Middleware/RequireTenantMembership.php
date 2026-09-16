<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Http\Request;
use Nvl\Tenancy\Contracts\TenantHttpResolver;
use Nvl\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantInactive;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Services\TenantRunner;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Admits an authenticated tenant member before Laravel substitutes route bindings. */
final readonly class RequireTenantMembership
{
    /** Resolve request-specific adapters only while handling their request. */
    public function __construct(private Container $container, private TenantRunner $runner, private Factory $auth) {}

    /**
     * Select a candidate, authorize membership, then enter its active tenant scope.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (! $this->container->bound(TenantHttpResolver::class)) {
                throw new TenantBoundaryViolation;
            }
            $tenant = $this->container->make(TenantHttpResolver::class)->resolve($request);
            $actor = $this->auth->guard()->user();
            if ($actor === null) {
                throw new TenantBoundaryViolation;
            }
            $this->container->make(TenantMembershipAccess::class)->assertMember($actor, $tenant);

            return $this->runner->run($tenant, fn (): Response => $next($request));
        } catch (TenantBoundaryViolation|TenantInactive|TenantNotFound $exception) {
            throw new NotFoundHttpException('Tenant was not found.', $exception);
        }
    }
}
