<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

/** Reports bounded adapter progress; a null cursor declares completion. */
final readonly class TenantBackfillResult
{
    /** Report the next opaque stable cursor and count of processed records. */
    public function __construct(public ?string $nextCursor, public int $processed) {}
}
