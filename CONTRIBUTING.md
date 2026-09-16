# Contributing to NVL Tenancy

Keep all tenant domain contracts and runtime inside `Nvl\Tenancy`. Support must
remain free of tenant logic, and integrating packages must retain ownership of
their schema, queries, writes, and adoption adapters.

Use test-driven development and run package tests serially because Testbench
shares bootstrap and cache state. Verify the focused Pest suite, Pint, PHPStan at
maximum strictness, Composer validation, package-family validation, and public
contract checks.

Do not activate migrations, add package dependency edges, or claim package
tenant readiness before the corresponding phased milestone and proof suite.
