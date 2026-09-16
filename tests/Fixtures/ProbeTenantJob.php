<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Tenancy\ValueObjects\TenantJobEnvelope;
use RuntimeException;
use Throwable;

/** Records context at the first user-code deserialization instruction and each lifecycle stage. */
class ProbeTenantJob implements ShouldQueue, TenantQueuedJob
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels { __unserialize as private restoreProperties; }

    /** @var list<array{stage: string, tenant: ?string, label: string}> */
    public static array $observations = [];

    public int $tries = 1;

    public readonly TenantJobEnvelope $envelope;

    public function __construct(public string $label = 'probe', public bool $throws = false, public ?ProbeRestoredModel $record = null)
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
        $this->label = is_string($values['label'] ?? null) ? $values['label'] : 'probe';
        $this->recordStage('unserialize');
        $this->restoreProperties($values);
    }

    public function handle(): void
    {
        $this->recordStage('handle');
        if ($this->label === 'retry-A' && $this->attempts() === 1) {
            $this->release();

            return;
        }
        if ($this->throws) {
            throw new RuntimeException('Queue probe failed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->recordStage('failed');
    }

    private function recordStage(string $stage): void
    {
        $observation = ['stage' => $stage, 'tenant' => app(TenantContext::class)->snapshot()->tenantId?->value, 'label' => $this->label];
        self::$observations[] = $observation;
        if (config('tenancy_queue_probe.persist') === true) {
            DB::table('queue_probes')->insert($observation);
        }
    }
}
