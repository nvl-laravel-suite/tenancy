<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

/** Provides a distinct model registration for inherited ownership scenarios. */
final class InheritedRecord extends OwnedRecord
{
    protected $table = 'tenancy_test_children';
}
