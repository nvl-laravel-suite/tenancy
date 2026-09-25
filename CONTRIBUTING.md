# Contributing to NVL Tenancy

This public repository is a publication mirror of private source. Open an issue
here for a bug or proposal; include a reproduction and, if helpful, a patch.
Maintainers apply accepted changes in source and publish a mirror release.
Direct mirror pull requests do not update source. See the
[organization contribution guide](https://github.com/nvl-laravel-suite/.github/blob/main/CONTRIBUTING.md).

Keep all tenant domain contracts and runtime inside `Nvl\Tenancy`. Support must
remain free of tenant logic, and integrating packages must retain ownership of
their schema, queries, writes, and adoption adapters.

Use test-driven development and run package tests serially because Testbench
shares bootstrap and cache state. Verify the focused Pest suite, Pint, PHPStan at
maximum strictness, Composer validation, package-family validation, and public
contract checks.

Do not activate migrations, add package dependency edges, or claim package
tenant readiness before the corresponding phased milestone and proof suite.
