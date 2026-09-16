# NVL Tenancy — API and usage

[← NVL Laravel Suite](../../../README.md)

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/laravel-suite:^2.0` |
| Module identifier | `nvl/tenancy` |
| PHP namespace | `Nvl\Tenancy` |
| Service provider | `Nvl\Tenancy\Providers\TenancyServiceProvider` |
| Configuration | `config/tenancy.php` |

## Purpose

`nvl/tenancy` provides the deployment configuration, immutable context values,
adapter contracts, and fail-closed error vocabulary used by tenant-aware NVL
packages on Laravel 13 and PHP 8.4.

The package is inert by default. Its provider registers a scoped disabled
context, loads no migrations, and changes no routes, middleware, queues, or
package queries while `tenancy.enabled` is `false`.

## Requirements and installation

```bash
composer require nvl/laravel-suite:^2.0
php artisan vendor:publish --tag=tenancy-config
php artisan vendor:publish --tag=tenancy-skills
```

Laravel auto-discovers `Nvl\Tenancy\Providers\TenancyServiceProvider`. The
module requires `nvl/support` and `nvl/data` from the Suite 2.x line.

## Configuration

The shipped `config/tenancy.php` selects the first-release shared-database
strategy and keeps activation and optional migrations disabled. Configuration
contains deployment-level scalars, literal arrays, and adapter class strings;
closures and current tenant or actor values are rejected.

The `application` profile currently has no integrated resource families.
Resource overrides therefore fail until later package integration registers the
family. Sharing supports only `none` and `copy` for Media, Metafields, and
Templates, and does not create a shared mutable resource mode.

Host adapters may implement `TenantDirectory`, `TenantMembershipAccess`,
`PlatformAccess`, `TenantHttpResolver`, or `TenantSiteResolver`. Choose either a
configured class string or a host container binding for one contract. Supplying
both is a configuration error, and validation never resolves the adapter.

## Disabled compatibility

Resolve `Nvl\Tenancy\Contracts\TenantContext` to inspect the immutable current
snapshot. Disabled installations return `TenantContextMode::Disabled` and need
no Tenancy tables. Enabling the flag before the later schema, runner, adoption,
and package integration milestones is not an activation path.

## Security

Tenant IDs are canonical UUIDs. A missing tenant scope fails closed through a
typed exception. Platform work, directory admission, schema adoption, resource
predicates, middleware, and queue restoration are intentionally outside this
foundation milestone and must not be inferred from provider registration.

## Development/verification

Package checks use the local Suite dependency graph and run Pest serially:

```bash
composer test
composer analyse
composer format
composer validate:distribution
```

The foundation tests cover disabled installation, immutable context values,
typed missing-context failures, and configuration rejection without database or
adapter resolution. See [UPGRADING.md](UPGRADING.md), [SECURITY.md](SECURITY.md),
[CONTRIBUTING.md](CONTRIBUTING.md), and [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).
