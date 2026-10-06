<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Support;

/** Loads one deprecated neutral alias without declaring the same symbol twice. @internal */
final class LegacyNeutralAlias
{
    /** @param class-string $canonical */
    public static function register(string $canonical, string $legacy): void
    {
        if (! class_exists($legacy, false) && ! interface_exists($legacy, false) && ! enum_exists($legacy, false)) {
            class_alias($canonical, $legacy);
        }
    }
}
