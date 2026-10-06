---
name: nvl-tenancy
description: Implement, integrate, test, diagnose, or review nvl/tenancy context, configuration, admission, isolation, adoption, lifecycle, and worker boundaries in Laravel 13.
---

# NVL Tenancy

Keep tenant domain types and runtime inside `Nvl\Tenancy`. Support remains free
of tenancy logic, while every integrating package owns its storage, predicates,
writes, grants, adoption adapter, and lifecycle.

## Preserve the foundation boundary

- Keep the default provider state inert: `tenancy.enabled=false` does not itself
  register schema, routes, middleware, resource adoption, or query changes.
- Keep migration registration independent from feature activation. Register the
  five-table core schema only when `nvl-tenancy.migrations.enabled=true`; publishing
  the `nvl-tenancy-migrations` tag is the explicit application-owned alternative.
- Route core schema and package tenant writes through `nvl-tenancy.connection`.
  Provisioning and status mutation require the effective `PackageTenantDirectory`;
  host directory writes remain host owned and tenant foreign keys are omitted.
- Authorize and durably record bounded platform-operation facts before starting
  privileged callback transactions or writing tenant rows.
- Treat context as scoped state. Public package callers receive only the
  read-only `TenantContext` contract.
- Validate deployment configuration and adapter class strings without resolving
  request-scoped adapters or opening a database connection.
- Reject missing context in enabled mode. Tenant, platform, public-site, and
  central identity operations remain explicit separate boundaries.
- Never infer tenant ownership from request data or assign legacy rows to a
  default tenant.

## Resource integrations

- Register concrete models and code-owned parent policies before boot completion.
  Declare ownership dependencies with the internal `requireCompatible` seam.
- Use `Nvl\Support\Tenancy\Contracts\TenantBoundary` for queries, records, generated root fields and identity
  keys. Reload and lock under the predicate before writes; recheck status before
  external effects. Never authorize through dirty fields or loaded relations.
- Generate mixed `ownership_key` values as `platform` or `tenant:<canonical UUID>`
  so natural uniqueness remains tenant-specific. Tenant-only tables omit this field.
- Map persisted polymorphic types through the package allowlist using the internal
  `TenantParentResolver` adapter before registry lookup or class construction.
- Store `TenantOwnershipConfiguration::fingerprint(resource)` in each marker and
  `hash(selectedResources)` in the adoption run. Keep unrelated resources out of
  each fingerprint. See README's internal integration and adoption seams.
- Preserve lazy marker checks on the resource's actual connection even when
  disabled. Propagate database failures. Only the adoption coordinator invalidates
  local probes; cutover also requires worker restart. Do not cache tenant status.
- Manual run/marker fixtures are confined to F4 guard tests. Later integrations
  use their real adoption adapters through the coordinator.

## Verify

Run focused package tests serially, then Pint, package PHPStan, package-family
validation, root configuration/module tests, Composer validation, and public
contract checks. Prove disabled compatibility without loading Tenancy migrations,
then prove the separately enabled core migration on the configured connection.

## Explicit adoption protocol

Use `TenantAdoptionCoordinator` and registered real package adapters for all
installation transitions. Do not fabricate active markers in downstream fixtures.
Use direct Testbench `Illuminate\Foundation\Testing\DatabaseMigrations`, not an outer transaction. Stream reviewed
JSONL assignments; validate nonempty metadata through the optional
`TenantAdoptionMetadataValidator` interface before preparation. Adapters own exact
metadata fields, canonical SQL/DDL, parent validation and idempotent constraints;
zero-resource adapters remain supported.

Each mutation needs actual maintenance, fresh host authorization and durable audit.
Actor CLI fields and plan UUIDs are not authorization. Resume immutable inputs; never
overwrite mapping hashes or supersede interrupted prepared runs. Verified active
graphs may enter a new explicitly reviewed structural adoption that preserves prior
history; adapters must reject tenant transfers and unsupported structural changes.
`verify` and Doctor are read-only; activation rechecks actual storage. Keep database
bootstrap overrides disabled in the maintenance command environment until activation,
then restore validated configuration, rebuild caches and restart drained processes.

Internal callback scopes identify run/adapter/phase but grant no ordinary boundary
access or tenant recovery lease. Queue, after-response, deferred and background
publication must remain fenced. Native connection locks span DDL/checkpoints; no
reconnect or session swap is allowed. SQLite file locks require local storage.

## Configuration and composition readiness

Use `TenantOwnershipConfiguration::requireCompatible(family, dependency)` for
code-owned mutable dependency rules. Keep fixed vocabulary platform-owned and
children bound to canonical parents. Resolver config is null or one class string;
never create a resolver-list API or client-defined resource-family registry.

Keep provider selection, feature enablement, metadata compatibility, and actual
schema/adoption state separate. Incomplete loaded stateful integrations must be
reported by Doctor after successful Unresolved boot and rejected by
`TenantOwnershipConfiguration::assertReady()` before tenant entry, maintenance,
boundary use or adoption activation. Platform bootstrap never grants tenant
Settings access. Do not add a bypass list or fabricate readiness registrations.
CSV is adoption-only and may expose zero resources; Translatable is owner-driven
without its own production schema/adopter. Neutral foundational libraries remain
outside resource ownership. None of these classifications proves an unimplemented
package integration safe.

Use the dedicated engine-aware schema/adoption case for PostgreSQL, MySQL 8.4 and
MariaDB (actual mariadb driver) proof. Preserve intentional SQLite-only fixtures.
Package-owned optional migrations and published consumer copies are exclusive:
leave migrations.enabled false for the consumer-owned copy and never run both.

## Queued execution and callbacks

Tenant commands implement `TenantQueuedJob::tenantJobEnvelope()` and capture
`TenantJobEnvelope::capture(TenantContext)` before PendingDispatch uniqueness,
response deferral, or after-commit scheduling. Package jobs carry scalar IDs and
recheck persisted ownership. Never adopt ambient context at payload creation.
Build unique/overlap identities from the captured tenant.

Use `TenantQueueContext::captureBatch(PendingBatch)` and dispatch in the captured
producer scope. All jobs and callbacks in a batch share one tenant. Mixed batches,
uncaptured IDs, incompatible repositories, and unrelated-scope batch response
publication fail closed. Keep native signed callback checks and validate nested
serialized closure captures before any callback/model restoration.

Exact native mail, notification, and listener wrappers read capture from their
mailable, notification, or event carriers. Prefer scalar/on-demand recipients;
registered model identifiers have no serialized relations/custom collections.
Generic wrappers never become global identity jobs. Global registrations name
specific scalar-only application jobs and execute in Unresolved context.

Keep both CallQueuedHandler entry boundaries and native database batch option
validation. Preserve compatible host bindings; diagnose incompatible adapters.
Retained host handlers must implement Core's TenantQueueHandler contract and
provide validate admission without restoring user objects, then revalidate before
restoring commands in both call and failed. Extending TenantCallQueuedHandler and
preserving all three methods supplies that adapter. Core checks the actual payload
handler and guarded call method, then invokes validate before native execution or
terminal failure handling. Inert carried-envelope and model-owner rejection enters
raw quarantine, so native retry cannot restore rejected work. Admitted handle
failures retain native failure handling. Host bindings remain unchanged.
Require guarded handler and batch integrations for enabled or adopted storage;
disabled legacy storage retains native host readiness.
Missing old payload metadata is tolerated only with disabled tenancy and every
participating resource unadopted. Present malformed metadata and enabled-worker
Disabled envelopes always fail. Prove call, failed, native worker retries/exhaustion,
wrapper restoration, signed callback captures, and retained-service cleanup.

## Standalone distribution and utility composition

- Install `nvl/tenancy:^2.0` with its declared Support/Data dependencies; NVL Auth
  is not required. Explicitly require Filterable for hosts that use its filters.
- Keep the minimal archive proof free of Suite/Auth/Filterable autoloading. Use a
  fresh Composer loader and process, package discovery, config cache and Doctor.
- Source diagnostics include `nvl/tenancy` even when the feature is disabled.
  Source registration creates no public ownership mutation DTO.
- Keep client mutation DTOs free of tenant and ownership keys. Preserve the caller
  predicate when composing custom OR and relation filters; the owning package
  still supplies the canonical tenant boundary.
- Concrete scoped context, queue internals, leases and adoption stores are internal.
  Use the documented contracts and authorized entry points. Foundation distribution
  is not evidence that later domain tenancy integrations have shipped.

## Configurable-tenancy release discipline

- Preserve disabled compatibility and package independence; tenant support never creates an undeclared Auth or Suite dependency.
- Use registered package-owned resources, adoption adapters, Actions, and lifecycle APIs. Never add a generic tenant delete-all path or raw cross-package cleanup.
- Treat mapping/configuration hashes, interruption checkpoints, conservation evidence, worker context, tenant-leading queries, and standalone consumption as release contracts.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine checks from loaded NVL providers. Retain the package Doctor command for its detailed report; both paths reuse the package-owned inspection service.

### Brownfield storage identities

Resolve all package tables through the table helper and canonical `nvl-tenancy.tables.*`, connections through `nvl-tenancy.connection` with Core/Laravel inheritance. Defaults use `nvl_tenancy_*`; migration filenames include that package slug. Never silently adopt a matching table or generic migration filename. Run shared `nvl:doctor --strict --format=json` and the explicit `nvl:schema:upgrade --package=tenancy --claim-legacy --dry-run --format=json` before upgrading owned legacy storage. Validate the complete plan and choose one migration owner. Preserve host records, constraint names and stored morph values. Deprecated config inputs last one major; canonical options take precedence.

## Canonical configuration ownership

- Read/write `nvl-tenancy` configuration and publish only canonical `nvl-<package>-<resource>` tags. Keep logical package/tenant resource identifiers unchanged.
- Generic config roots and unprefixed package environment names are foreign by default. For an upgrading NVL host only, select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` explicitly; both default off. Canonical presence wins, including false/null/empty values. Legacy inputs are read without writing back and are removed in major 6.
- Use canonical `NVL_<PACKAGE>_*` variables only in config evaluation, then rebuild configuration caches and restart workers after cutover. Shared Laravel environment variables retain their names. Consult Core's versioned `support/resources/global-names.json` for all renames.
- Old global aliases and legacy route families require separate explicit `global_aliases`/`legacy_routes` package selections. Preserve collisions and use Doctor diagnostics; never grant generic permissions automatically or claim signed-link compatibility without the same authorization/signature checks.

## Application workflow substitution

Both platform workflows gain focused contracts. Neutral Core contracts, effective host adapter identity, compatibility aliases, security, and inactive-by-default behavior are preserved.

The supported workflow injection names are `ChangeTenantStatusContract`, `ProvisionTenantContract`.

Inject the supported contract into host orchestration and bind a native interface
mock or host implementation before resolving that orchestration. Keep concrete
constructors and native workflow bodies intact; internal chains remain package-owned.
Use declared DTOs or unsaved model identity handles for orchestration fixtures.
Use real package workflows and Laravel framework fakes for persistence, tenant,
queue, file, and external-effect integration checks. A host substitute proves
only the host call and result. Keep public declarations tagged `@api` and
constructor/configuration/private helpers internal.

Consult the owning README's Testing your app section for native examples. Include
`vendor/nvl/core/support/consumer-audit.neon` in host PHPStan and declare explicit
`nvlConsumer.testPaths`; the Suite workbench is not consumer tooling.


## Consumer runtime and testing contracts

Start with the package README Quickstart and Testing your app sections. Use `nvl:install <package>` for loaded-package common config publication; it does not enable features, run schema or refresh caches. Preserve native host owner keys/morph maps and selected auth/tenancy defaults. Read full runtime defaults and publish advanced config only deliberately.

Inject the supported focused interfaces and preserve host bindings. Returned model handles do not permit package-table queries/writes outside documented capability/extension seams. Host tests may substitute contracts in Laravel's container, use shipped model factories (ordinary make may persist parents; withoutParents()->make is detached), and use Laravel effect fakes deliberately. Only Media/Stripe have dedicated provider/library fakes; do not invent a universal package fake. Settings InteractsWithSettings is definition-only. Host PHPStan may include vendor/nvl/core/support/consumer-audit.neon; no unpublished workbench command is a consumer requirement.

Read docs/events.md and the package README error table. Domain events use schemaVersion=1, model-free facts and actual source-connection commit callbacks; only six declared old Event suffix aliases remain for major 5. Migrate exact listeners/fakes and suffix wildcards, drain old queued payloads, rebuild event cache and restart workers. Delivery is not a durable outbox. The Core exception renderer is opt-in, JSON-only for respondable failures, with exactly message/code/context and host-selected locale. Do not expose diagnostics or reinterpret missing bindings as authorization denial.

Core package logging uses nvl/normal with CSV quiet by default, stable message keys and bounded context; incidents survive quiet. Do not mutate global logger context or log raw row/provider/content/credential payloads. Run only authorized project checks and report new acceptance as pending until actual output exists.
