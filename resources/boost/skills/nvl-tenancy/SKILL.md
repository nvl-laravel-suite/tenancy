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
  five-table core schema only when `tenancy.migrations.enabled=true`; publishing
  the `tenancy-migrations` tag is the explicit application-owned alternative.
- Route core schema and package tenant writes through `tenancy.connection`.
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
- Use `TenantBoundary` for queries, records, generated root fields and identity
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
