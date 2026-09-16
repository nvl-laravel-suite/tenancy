---
name: backend-tenancy
description: Implement, integrate, test, diagnose, or review nvl/tenancy context, configuration, admission, isolation, adoption, lifecycle, and worker boundaries in Laravel 13.
---

# Backend Tenancy

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

## Verify

Run focused package tests serially, then Pint, package PHPStan, package-family
validation, root configuration/module tests, Composer validation, and public
contract checks. Prove disabled compatibility without loading Tenancy migrations,
then prove the separately enabled core migration on the configured connection.
