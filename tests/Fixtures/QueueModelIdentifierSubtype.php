<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Contracts\Database\ModelIdentifier;

/** Native restoration accepts even an empty identifier subtype. */
class QueueModelIdentifierSubtype extends ModelIdentifier {}
