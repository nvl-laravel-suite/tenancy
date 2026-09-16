<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Closure;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;

/** Models a real manifest-only package with no tenant Eloquent resources. */
final class EmptyAdoptionAdapter implements TenantAdoptionAdapter
{
    /** @var Closure(): void|null */
    public ?Closure $onActivate = null;

    /** @var list<string> */
    public array $errors = [];

    public bool $stuck = false;

    /** @var list<string> */
    public array $owned = [];

    /** @return list<string> */
    public function resources(): array
    {
        return $this->owned;
    }

    /** Manifest preparation needs no table alteration. */
    public function prepare(TenantAdoptionPlan $plan): void {}

    /** Report manifest readiness without inventing record rows. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        return $this->stuck ? new TenantBackfillResult('stuck', 0) : new TenantBackfillResult(null, 0);
    }

    /** Return package-owned readiness failures. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        return new TenantVerification($this->errors);
    }

    /** Activate the package's manifest workflow. */
    public function activate(TenantAdoptionPlan $plan): void
    {
        ($this->onActivate ?? static function (): void {})();
    }
}
