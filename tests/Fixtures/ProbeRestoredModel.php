<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Nvl\Tenancy\Contracts\TenantContext;

/** Canonical resource carried through Laravel's normally unscoped restoration path. */
class ProbeRestoredModel extends OwnedRecord
{
    /** @var list<string> */
    public static array $restored = [];

    protected static function booted(): void
    {
        static::retrieved(static function (self $record): void {
            self::$restored[] = $record->id;
            if (config('tenancy_queue_probe.persist') === true) {
                DB::table('queue_probes')->insert(['stage' => 'model-restored', 'tenant' => app(TenantContext::class)->snapshot()->tenantId?->value, 'label' => $record->name]);
            }
        });
    }
}
