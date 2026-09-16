<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\SendQueuedMailable;

/** A host wrapper must never become a generic global job escape. */
class GlobalMailableWrapper extends SendQueuedMailable implements ShouldQueue {}
