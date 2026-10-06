# Upgrading NVL Tenancy

## Tenancy foundation distribution

Standalone `nvl/tenancy:^5.0` requires Support/Data and the declared PHP extensions
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

1. Publish and review `nvl-tenancy.php` as a minimal deployment overlay.
2. Keep `nvl-tenancy.enabled=false` until every participating package integration is
   installed and its adoption procedure has been reviewed.
3. Choose core-schema ownership explicitly. Set
   `nvl-tenancy.migrations.enabled=true` for package-managed migrations, or publish
   `nvl-tenancy-migrations` for an application-owned copy. Both paths target
   `nvl-tenancy.connection`; feature enablement alone never registers migrations.
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
php artisan nvl:schema:upgrade --package=tenancy --claim-legacy --migration-owner=vendor --dry-run --format=json
php artisan nvl:schema:upgrade --package=tenancy --claim-legacy --migration-owner=vendor --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Declare each published path and canonical identity explicitly in `nvl-core.migrations.published`; retimestamped history also needs an exact `legacy` mapping. Use `--migration-owner=vendor` after manually archiving declared copies outside loaded paths, or `--migration-owner=published` after manually replacing executable copies with current migration code and disabling vendor loading. The plan verifies ownership and preserves batches; checksums do not automatically claim files. Modified host copies remain host-owned. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, run `nvl:schema:preflight` with the same selected paths and connection, then migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.


## Queue envelope and retained handler cutover

### Compose retained queue handlers explicitly

Enabled Tenancy preserves an existing host `CallQueuedHandler` binding. Adapt it
to Core's `TenantQueueHandler` contract: `validate()` must admit captured metadata
and the inert command/model graph without restoring user objects. Both `call()`
and `failed()` must revalidate before command restoration. Extending the supplied
`TenantCallQueuedHandler` and preserving all three methods supplies this adapter.
Core invokes `validate()` at `JobProcessing`, before native execution or terminal
failure handling. Carried-envelope mismatches, wrong-owner model identifiers and
other admission failures enter raw quarantine, so native retry cannot restore
the rejected command. Admitted commands that fail in `handle()` keep native
failure handling. Ordinary host payloads without NVL metadata retain their handling.
Tenancy Doctor requires these integrations when the runtime is enabled or a
declared resource has adopted storage; disabled legacy storage passes with
retained host handlers and batch repositories.

### Retry quarantined NVL jobs through raw transport

Rejected NVL envelopes appear in the host's native failed-job store with
`NVL queue envelope rejected:` in their boundary exception. Laravel's native
`queue:retry` restores the command before it requeues it. Installed Laravel 13
dispatches `JobRetryRequested` before that restoration, so Core rejects identified
quarantine records at this event for native ID, `all`, queue and range selections.
These records must use the raw retry command. Ordinary failed
host jobs retain native retry behavior.

Configure persistent native failed-job storage for this inspection and retry
path. If that storage is disabled or unavailable, the rejected job is still
deleted to prevent native failure callbacks, and a storage error is raised;
there is no durable quarantine record for that attempt.

After repairing the runtime or envelope boundary, use
`php artisan nvl:queue:retry <failed-id...>` for these records. It requeues the
original raw body and lets the worker validate it again; it preserves captured
`retryUntil` and payload attempts. The native failed-job ID is forgotten only
after the transport confirms the push. An expired deadline still expires.
Sync queues and transports with command-dependent options, including native
SQS `getQueueableOptions()`, require an explicit raw retry integration. Custom
retry commands must apply Core's `TenantQueueQuarantine::beforeNativeRetry()`
raw-record preflight before any restoration. Commands omitting Laravel's event
require that explicit integration. Recheck event ordering when upgrading Laravel.
