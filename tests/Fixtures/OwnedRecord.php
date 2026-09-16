<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Canonical database fixture for tenant ownership boundaries.
 *
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $ownership_key
 * @property string $name
 * @property string|null $parent_id
 * @property string|null $deleted_at
 */
class OwnedRecord extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tenancy_test_records';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * Return the canonical self-referencing parent.
     *
     * @return BelongsTo<OwnedRecord, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
