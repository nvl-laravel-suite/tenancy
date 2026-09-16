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
attached, and package queries remain unchanged while `tenancy.enabled` is
`false`. Queue guards are registered without capturing an application or tenant
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

The `application` profile currently has no integrated resource families.
Resource overrides therefore fail until later package integration registers the
family. Sharing supports only `none` and `copy` for Media, Metafields, and
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

## Security

Tenant IDs are canonical UUIDs. A missing tenant scope fails closed through a
typed exception. Schema adoption, resource predicates, and normal queue context
propagation/restoration remain future foundation work and must not be inferred
from provider registration or the synchronous recovery guard.

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
