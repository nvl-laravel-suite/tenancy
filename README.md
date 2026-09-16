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
context, the default migration switch loads no schema, no global middleware is
attached, and unadopted package queries remain unchanged while `tenancy.enabled` is
`false`. An adopted resource always checks its persisted marker, including when
the feature is disabled. Queue guards are registered without capturing an application or tenant
and act only during a recovery lease.

## Requirements and installation

```bash
composer require nvl/laravel-suite:^2.0
php artisan vendor:publish --tag=tenancy-config
php artisan vendor:publish --tag=tenancy-migrations
php artisan vendor:publish --tag=tenancy-skills
```

Laravel auto-discovers `Nvl\Tenancy\Providers\TenancyServiceProvider`. The
module requires `nvl/support` and `nvl/data` from the Suite 2.x line.

## Configuration

The shipped `config/tenancy.php` selects the first-release shared-database
strategy and keeps activation and optional migrations disabled. Configuration
contains deployment-level scalars, literal arrays, and adapter class strings;
closures and current tenant or actor values are rejected.

The `application` profile defaults registered mutable resource families to
`tenant`. Registered families may explicitly choose `tenant` or `platform` when
their code-owned capabilities support that mode. Unknown families, contradictory
parent modes, and incompatible declared family dependencies fail after provider
registration completes. Family overrides apply to mutable roots; fixed platform
vocabulary in the same family stays platform-owned and does not create a mutable
ownership conflict. A family consisting only of fixed platform definitions cannot
be reclassified as tenant-owned. Sharing supports only `none` and `copy` for Media, Metafields, and
Templates, and does not create a shared mutable resource mode.

Host adapters may implement `TenantDirectory`, `TenantMembershipAccess`,
`PlatformAccess`, `TenantHttpResolver`, or `TenantSiteResolver`. Choose either a
configured class string or a host container binding for one contract. Supplying
both is allowed for matching, inspectable class bindings. Genuinely conflicting
or opaque simultaneous bindings are rejected. Validation never constructs the
adapter. Missing membership and platform adapters deny access; the package
directory fallback fails with `TenantSchemaNotReady` until its optional store
exists. Selecting a host directory requires a host adapter.

Core storage is a separate opt-in migration set. Set
`tenancy.migrations.enabled=true` to register it with Laravel migrations, or
publish `tenancy-migrations` when the application owns the migration copy. The
set runs on `tenancy.connection` and creates the tenant directory, installation
state, privileged-operation audit, adoption runs, and reviewed adoption mappings.
Feature activation never registers this path by itself. A package-owned effective
directory receives tenant foreign keys; an effective host directory keeps its
application tenant identifiers as verified references.

## Disabled compatibility

Resolve `Nvl\Tenancy\Contracts\TenantContext` to inspect the immutable current
snapshot. Disabled installations return `TenantContextMode::Disabled` and need
no Tenancy tables. Ordinary tenant execution and tenant maintenance reject
disabled configuration. Explicitly authorized, durably audited platform work can
provision package directory entries before tenant activation. Enabling the feature
flag alone is not a resource-adoption or isolation path.

`ProvisionTenantAction::execute(string, PlatformOperation)` creates an active
package-directory tenant with a server-generated UUID after authorization and a
durable audit. Names are trimmed and must contain 1–255 characters.
`ChangeTenantStatusAction::execute(TenantId, TenantStatus, PlatformOperation)`
locks the canonical row before changing its lifecycle state. Suspended and
deleted tenants stop ordinary admission on the next directory check. Both actions
reject an effective host directory because host storage writes remain application
owned.

## Scoped execution and admission

`TenantRunner::run(TenantId, Closure)` is a trusted application boundary. It
checks the current directory status and enters an active tenant for the callback.
`TenantRunner::platform(PlatformOperation, Closure)` requires explicit host
`PlatformAccess` authorization and a durable SQL audit before the callback. The
operation's actor and purpose fields must each contain 1–255 characters.
Privileged entry fails when the audit store is absent or its connection already
has an open transaction. Audit writes precede callback-owned transactions.

The `TenantContext` interface remains read-only. Native runners require the
native scoped implementation and fail clearly for incompatible host context
overrides. The internal concrete state, maintenance lease, and queue guard are
not consumer APIs. Retained runners resolve context and host adapters for each
current worker scope; registries keep class names, never requests or actors.

Register integration classes through
`TenantContextParticipants::register(TenantContextParticipant::class)`. Each
participant's `enter(TenantContextSnapshot)` returns its restoration closure.
An `enter` failure must undo that participant's own partial state. Entered
participants unwind in reverse order. Cleanup failure is reported, preserves an
original operation exception, and revokes the scope until Laravel starts a new
application scope.

Every resolved Laravel database connection participates in context lifecycle
checks, including connections first opened by the callback. A transaction blocks
a different tenant or mode; balanced reentry to the same tenant is allowed.
Callback-opened transaction levels must be closed before return. Leaked levels
are rolled back and invalidate the scope; closing a caller-owned level reports
corruption. A caller's already committed transaction cannot be undone. Write
compatibility separately compares actual Laravel connection objects: matching
DSNs under different names do not establish shared transactions.

Add `RequireTenantMembership` or `ResolvePublicTenant` explicitly to host routes.
The provider places admission before `SubstituteBindings`, after authentication
for membership routes, and does not change central identity routes. Host
`TenantHttpResolver` implementations select candidates and must reconcile all
supported route/header selectors and verified host mappings; selection never
grants membership. No universal header or domain convention is assumed.

Public `TenantSiteResolver` implementations must return a verified
`TenantSiteContext`. A verified serving alias may differ from its canonical
origin. The middleware stores this immutable value on the current request;
resolving it requires agreement with the active tenant. Unknown, suspended and
deleted public tenants receive the same not-found response. Browser `Origin`
and `Referer` values do not establish this mapping.

## Registered resource ownership

Packages register immutable `TenantResourceDefinition` values through
`TenantResourceRegistry::register()`. A key identifies one concrete model and
code-owned root, inherited, or fixed-platform policy. Identical registrations
are idempotent; conflicting keys/models and declared parent cycles fail.

`TenantBoundary::query()` verifies installation state and wraps existing caller
conditions before adding qualified ownership predicates. Tenant mode selects the
current tenant; platform mode selects only nullable platform partitions with
`ownership_key=platform`. Mixed tenant rows require
`ownership_key=tenant:<uuid>`, using their canonical tenant UUID so portable unique
indexes retain a distinct partition for each tenant.
Tenant-only roots deny platform access. Fixed platform vocabulary requires its
own package reader. The underlying SQL builder must use the registered canonical
connection and table, including during disabled compatibility checks; aliased
root tables and unions are rejected because a single predicate cannot safely
cover them. Soft-delete and other Eloquent scopes remain in effect.

`assertRecord()` fetches only persisted ownership facts using the registered
model's validated connection. Dirty tenant IDs, keys, foreign keys, and retained
relations cannot supply ownership evidence. Inherited predicates and record checks
follow the canonical parent and reject unknown owners, cycles, or incompatible
connections. Polymorphic effective modes derive recursively from all allowlisted
parents; conflicting modes, missing parents and cycles fail configuration
validation. This check does not lock business content: package writers must
reload and lock records under the tenant predicate before mutation, and validate
again immediately before external side effects.

`attributes()` generates root ownership fields; children derive fields from their
canonical parent. `key()` returns the legacy identity only for disabled,
unadopted resources. Otherwise it hashes the JSON tuple of effective connection,
resource key, context mode, tenant ID, and caller identity under `nvl:tenant:`.
Every enabled boundary operation rechecks current directory status. Only the
exact synchronous maintenance lease admits suspended/deleted tenants.

### Internal integration and adoption seams

These are package infrastructure, not consumer bypass APIs:

- `TenantResourceRegistry::requireCompatible(family, dependency)` declares a
  family dependency whose mutable ownership modes must match; fixed vocabulary
  is excluded from mode-split checks. Core imports no domain
  package to infer these edges.
- `registerParentResolver(resource, resolverClass)` registers a class implementing
  `TenantParentResolver::types(): array<string, class-string<Model>>`. Its map
  comes from the owning package's allowlist and maps persisted morph aliases to
  concrete models. It must be deterministic and free of tenant/request state.
  Every allowed model also needs a canonical resource registration. Missing,
  unknown, or disallowed types deny access before persisted morph types can
  instantiate classes. Merely registering a model globally never allowlists it.
- `TenantOwnershipConfiguration::fingerprint(resource)` is SHA-256 over canonical
  JSON format version 1: strategy/profile, effective core connection, configured
  directory driver/adapter and effective adapter class, then the sorted resource
  ownership closure. Each definition includes family/model/kind, parent/relation,
  catalog/mixed flags, effective mode, table/connection, fixed ownership columns
  and the mixed discriminator format (`platform|tenant:<uuid>`),
  parent resolver/type map, and sorted declared family dependencies. Parent and
  dependency definitions are recursively included. Unrelated resources are not.
  `hash(list<string> resources)` hashes the sorted selected resource-to-fingerprint
  map for an adoption run. Markers store the individual fingerprint. Neither hash
  includes feature/migration flags, current scope/status/actor, access/resolver
  configuration, or sharing policy.
- `TenantInstallationState::assertUsable(resource)` probes the resource's actual
  storage connection, never a newly configured empty core store. It performs one
  schema probe and, when present, one marker-set read per connection object per
  scoped worker generation. Database errors propagate. Enabled missing, prepared,
  incompatible, or disabled-but-adopted resources fail closed. `invalidate()` is
  for the authorized adoption coordinator after schema changes; other processes
  must be drained/restarted. Tenant status is never cached with these markers.

F4 tests alone seed real adoption-run and marker rows to verify this guard before
the coordinator exists. Downstream integrations must use the actual coordinator
and their package adoption adapters.

## Synchronous tenant recovery

`TenantMaintenanceRunner::run(TenantId, PlatformOperation, Closure)` admits one
existing active, suspended or deleted tenant only while actual Laravel maintenance
mode is active. It requires authorization, durable audit, and no open transaction
on any resolved connection before granting a tenant-specific internal lease.
The lease is revoked in `finally`, cannot switch tenants or enter platform mode,
and does not remove resource ownership predicates.

Normal Bus/Dispatchable queue dispatch is rejected before publication. Direct
queue payload creation is also guarded. A retained sync queue with `afterCommit`
may defer that rejection until commit attempts payload creation while the lease
is active. Callback-leaked transactions are rolled back before leaving the lease,
so their deferred callbacks cannot survive recovery. No lease is added to job
metadata. Native Bus `afterResponse` deferral is temporarily disabled during the
lease so Dispatchable calls and retained dispatchers reach the active queue guard
immediately. The exact previous host deferral setting is restored on every exit.
Maintenance requires the native Laravel dispatcher; custom dispatcher types fail
before callback entry because their deferral behavior cannot be fenced safely.
Retained native deferred/background queues and generic `defer()` scheduling are
also rejected before a callback is appended. Existing host deferred callbacks and
their collection binding remain intact; unused configured drivers do not block
maintenance. Host queue managers, dispatcher instances, and payload callbacks are
preserved.

The opt-in core migration now supplies the real SQL directory and audit stores
used by these boundaries. It does not adopt any domain package or make its queries
tenant-safe; each integration still requires its own later resource schema,
predicates, writes, adoption adapter, diagnostics, and acceptance tests.

## Explicit resumable adoption

Each participating package registers its `TenantAdoptionAdapter` class with
`TenantAdoptionRegistry::register(package, adapter)`. The coordinator resolves
canonical parent/family dependency closure, checks that every participating model
uses the **same Laravel Connection object** as the core store, and orders adapter
work before any schema transition. An adapter may legitimately return no resources
(for example a manifest-only integration); it still has immutable run input and
persisted phase checkpoints. Registration does not make any downstream package
tenant-safe by itself.

### Reviewed mapping input

The command streams JSONL. Every line has `resource`, string `record_id`, canonical
UUID `tenant_id`, and optional `metadata`:

```json
{"resource":"example.records","record_id":"record-123","tenant_id":"11111111-1111-4111-8111-111111111111","metadata":{}}
```

Resource and record IDs are 1–191 characters. Duplicate or conflicting assignments
fail. Mapping tenants must exist and be active in the effective directory.
Metadata is canonical bounded JSON (16 KiB, depth 16, 2048 values); it participates
in the immutable mapping hash. Adapters accepting nonempty metadata also implement
`TenantAdoptionMetadataValidator::validateAssignment(TenantAssignment): void` and
reject unknown fields, credentials, row payloads and invalid package references.
An adapter without this optional interface accepts only empty metadata. Nested
reviewed destination IDs are allowed; core does not assume every mapped/destination
record already exists. The owning package validates those semantics before prepare.

Adapters read owners using `TenantAdoptionMappings::tenantFor(plan, resource,
recordId)`, metadata using `metadataFor(...)`, and stable mapping batches using
`assignments(plan, resource, afterRecordId, limit)`. Unmapped existing roots fail;
packages may derive children from validated canonical parents. Both mapping-read
and backfill limits are 1–10000. Each callback returns an advancing opaque cursor
or `null` for completion; processed counts cannot exceed the requested limit.

### Operator procedure

1. Take a consistent backup of the affected database, package-owned split ledgers,
   and external assets. Rehearse restoring it before the maintenance window.
2. Drain HTTP traffic, queue workers and scheduled jobs, then enable actual Laravel
   maintenance mode. Install the opt-in core schema explicitly. Use a host
   `PlatformAccess` adapter that authenticates and authorizes the CLI operator;
   `--actor-type`, `--actor-id` and `--purpose` identify the audit, not a privilege.
3. Before schemas become prepared, use a validated maintenance bootstrap with
   `SETTINGS_CONFIG_OVERRIDES=false` (`settings.overrides.enabled=false`) so Settings
   cannot derive bootstrap config by reading blocked resources. Build that config
   cache before preparing, or use an isolated uncached maintenance environment;
   changing an environment variable does not replace an already cached true value.
   Keep ordinary request/job guards enabled.
4. Inspect the read-only report with `php artisan nvl:tenancy:doctor --json`.
   Suite-compatible `--format=text|json` and `--strict` are also supported. Missing
   enabled schema or incompatible resources are errors; interrupted runs are warnings
   that fail in strict mode. Doctor creates no runs, audits or schema.
5. Prepare the reviewed graph and retain the printed run UUID. For example:

   ```bash
   php artisan nvl:tenancy:adopt prepare --packages=example --mapping=/secure/reviewed.jsonl --actor-type=operator --actor-id=ops-1 --purpose="reviewed adoption"
   php artisan nvl:tenancy:adopt backfill --run=RUN_UUID --limit=500 --actor-type=operator --actor-id=ops-1 --purpose="reviewed adoption"
   php artisan nvl:tenancy:adopt verify --run=RUN_UUID
   php artisan nvl:tenancy:adopt activate --run=RUN_UUID --actor-type=operator --actor-id=ops-1 --purpose="reviewed adoption"
   ```

   Repeat backfill until it reports completion. Preparation captures all prepared
   markers before adapter DDL; partial work therefore remains blocked. Each mutating
   invocation has fresh authorization, maintenance checks and a durable audit outside
   callback-owned transactions. Do not wrap the coordinator in an outer transaction.
6. Activation reruns whole-graph verification, applies every adapter's idempotent
   final constraints, then commits the active run and all markers together. Restore
   the validated bootstrap configuration, rebuild configuration caches and restart
   drained processes before reopening traffic. Local probe invalidation cannot update
   another process's already loaded cache.

### Recovery and adapter obligations

`resume(runId)` reloads the original immutable packages, mapping and configuration.
`verify(plan)` is read-only and persists no authorization-free verification flag.
Activation always rechecks actual storage after its fresh authorization. An
interrupted prepare, batch or DDL phase retries idempotently; successful DDL may
already be committed even though no active marker exists. Adapters must revalidate
actual schema, use stable bounded primary-key batches, preserve existing canonical
owners, validate parents and tenant status, and install their final constraints.
This API is not a cross-tenant transfer facility.

Source-data repair consistent with the reviewed mapping may resume the same run.
Mappings and hashes cannot be rewritten. A prepared graph cannot be superseded;
wrong reviewed input requires the rehearsed pre-adoption backup recovery and a
new review. A fully active selected graph may enter a new explicitly authorized
adoption for supported structural evolution: new prepared markers replace its
active markers while prior run/mapping history remains intact. Package adapters
must reject unsupported mode changes and ownership transfers. Core never invents
splits, copies, memberships or owner flags.

A connection-wide PostgreSQL session advisory lock, MySQL/MariaDB `GET_LOCK`, or
local SQLite file lock serializes independent processes across audits, DDL and
checkpoints. Contention fails closed for retry; reconnect/session replacement is
forbidden until the phase ends. Reads use the locked primary session, with host
replica routing restored afterward. Unsupported engines and SQLite network filesystems
are unsupported; in-memory SQLite storage is inherently process-local. The lock
is not an ordinary-write fence: draining processes and maintaining the application
maintenance window are mandatory.

The internal adoption scope contains only the run, registered adapter and phase.
It grants no ordinary boundary access or tenant recovery lease. Synchronous
canonical package SQL/DDL is the adapter's authority; queue, after-response,
deferred and background publication is fenced, with host dispatch behavior restored
in `finally`. Leaked callback transactions are rolled back before the scope clears
so after-commit publication cannot escape. Tests use `TenancyDatabaseTestCase` directly from Testbench with
`DatabaseMigrations`, real core migrations and real adapters, never a wrapping
`RefreshDatabase` transaction or manually fabricated active markers.

## Security

Tenant IDs are canonical UUIDs. A missing tenant scope fails closed through a
typed exception. The adoption coordinator only executes explicitly registered package adapters.
Normal queue context propagation/restoration remains future foundation work and
must not be inferred from provider registration or synchronous adoption/recovery.

## Development/verification

Package checks use the local Suite dependency graph and run Pest serially:

```bash
composer test
composer analyse
composer format
composer validate:distribution
```

The foundation tests cover disabled and explicitly registered schema installation,
package-directory provisioning and lifecycle, immutable context values, typed
missing-context failures, lazy adapter validation, scoped execution, transaction
balance, participant restoration, HTTP admission, and recovery authorization,
audit, and queue boundaries. See [UPGRADING.md](UPGRADING.md), [SECURITY.md](SECURITY.md),
[CONTRIBUTING.md](CONTRIBUTING.md), and [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).
