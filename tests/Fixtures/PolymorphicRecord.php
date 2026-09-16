<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Represents a child with a package-allowlisted polymorphic owner.
 *
 * @property string $owner_type
 * @property string $owner_id
 */
final class PolymorphicRecord extends OwnedRecord
{
    protected $table = 'tenancy_test_polymorphic';

    /**
     * Return canonical morph metadata without constructing a persisted owner.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
