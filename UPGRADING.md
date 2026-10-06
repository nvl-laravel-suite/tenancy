# Upgrading NVL Tenancy

## Tenancy foundation distribution

Standalone `nvl/tenancy:^2.0` requires Support/Data and the declared PHP extensions
and Symfony runtime components; it requires no NVL Auth. Tenancy and optional core
migrations remain disabled by default. Its provider now registers a TypeScript
source, so source diagnostics include `nvl/tenancy` even with tenancy disabled.
Hosts using Filterable must require it explicitly. Mutation DTOs must omit
`tenantId`, `tenant_id`, `ownershipKey` and `ownership_key`; canonical ownership is
assigned by the owning runtime boundary. This foundation release does not imply
that domain packages have completed their tenancy adoption.


## Unreleased foundation

The foundation is disabled by default and introduces no migration or data
adoption requirement.

1. Publish and review `tenancy.php` as a minimal deployment overlay.
2. Keep `tenancy.enabled=false` until every participating package integration is
   installed and its adoption procedure has been reviewed.
3. Choose core-schema ownership explicitly. Set
   `tenancy.migrations.enabled=true` for package-managed migrations, or publish
   `tenancy-migrations` for an application-owned copy. Both paths target
   `tenancy.connection`; feature enablement alone never registers migrations.
4. Replace closures with class-string adapters before caching configuration.
5. Configure either a class string or a host binding for each adapter contract.
   Package provisioning and status actions are supported only when the effective
   directory is the package adapter. Host directory writes remain host owned.
6. Do not assign existing records to a default tenant; later adoption tooling
   requires explicit reviewed mappings.

Installing the five core tables does not adopt any domain package and does not
make downstream package queries tenant-safe. Keep runtime activation off until
the remaining foundation and package-specific migrations, boundaries, Doctor
checks, and acceptance tests are complete.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.


### Core tenancy contract migration

Replace imports for neutral tenant contracts, identifiers, snapshots, resource definitions, enums, and queue envelopes with `Nvl\Support\Tenancy`. Replace runtime service injection with the matching Core contract, for example `Nvl\Support\Tenancy\Contracts\TenantBoundary`. The enforcing services remain in `Nvl\Tenancy\Services` and implement those contracts. Public neutral classes from the old namespace have deprecated aliases for one major release; new serialized envelopes use the Core namespace. Drain queued jobs and batches before deploying the namespace transition and restart retained workers.

Neutral packages no longer require the tenancy runtime in production. Keep `nvl/tenancy` installed and register its provider whenever tenant enforcement or adopted data is used. Removing or disabling the runtime does not remove adoption markers or turn adopted resources into legacy storage. Resource metadata is registered even without the runtime, so validation and persisted adoption checks remain active. Adoption probes are cached only within the current application scope, keyed by the actual Laravel connection; authorized schema changes must invalidate installation state, and retained workers must restart.

This change does not rewrite tenant-owned rows or installation markers. Existing enabled deployments retain the runtime directory, boundary, queue and adoption behavior.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=tenancy --claim-legacy --dry-run --format=json
php artisan nvl:schema:upgrade --package=tenancy --claim-legacy --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Unmodified published files, including changed timestamps, map by verified checksum to the exact vendor migration identity and current package migration implementation. Modified host copies remain host-owned. Disable vendor loading when retaining a published owner; duplicate ownership fails before migration. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.
