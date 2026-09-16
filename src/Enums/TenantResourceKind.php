<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Enums;

/** Declares the code-owned source of a resource's ownership. */
enum TenantResourceKind: string
{
    case Root = 'root';
    case Inherited = 'inherited';
    case Platform = 'platform';
}
