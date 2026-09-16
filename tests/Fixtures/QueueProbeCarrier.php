<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Queue\SerializesModels;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;

/** Explicit scalar capture shared by native wrapper test carriers. */
trait QueueProbeCarrier
{
    use SerializesModels { __unserialize as private restoreCarrier; }

    public readonly TenantJobEnvelope $envelope;

    public function __construct()
    {
        $this->envelope = TenantJobEnvelope::capture(app(TenantContext::class));
    }

    public function tenantJobEnvelope(): TenantJobEnvelope
    {
        return $this->envelope;
    }

    /** @param array<string, mixed> $values */
    public function __unserialize(array $values): void
    {
        self::observe('wrapper-unserialize');
        $this->restoreCarrier($values);
    }

    public static function observe(string $stage): void
    {
        ProbeTenantJob::$observations[] = ['stage' => $stage, 'tenant' => app(TenantContext::class)->snapshot()->tenantId?->value, 'label' => static::class];
    }
}
