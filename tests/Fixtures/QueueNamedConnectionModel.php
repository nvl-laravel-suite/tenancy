<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

/** A canonical model whose explicit storage differs from a worker's default. */
class QueueNamedConnectionModel extends ProbeRestoredModel
{
    protected $connection = 'queue_canonical';
}
