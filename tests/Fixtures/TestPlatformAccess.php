<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\ValueObjects\PlatformOperation;

/** Explicitly authorizes and records privileged operations in isolated tests. */
final class TestPlatformAccess implements PlatformAccess
{
    /** @var list<PlatformOperation> */
    public array $operations = [];

    /** Record one explicitly allowed operation. */
    public function authorize(PlatformOperation $operation): void
    {
        $this->operations[] = $operation;
    }
}
