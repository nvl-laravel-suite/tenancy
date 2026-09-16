---
name: nvl-tenancy
description: Implement, integrate, test, diagnose, or review nvl/tenancy context, configuration, admission, isolation, adoption, lifecycle, and worker boundaries in Laravel 13.
---

# NVL Tenancy

Keep tenant domain types and runtime inside `Nvl\Tenancy`. Support remains free
of tenancy logic, while every integrating package owns its storage, predicates,
writes, grants, adoption adapter, and lifecycle.

## Preserve the foundation boundary

- Keep the provider inert when `tenancy.enabled` is false: no schema, routes,
  middleware, resource adoption, or query changes.
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
contract checks. Prove disabled compatibility without loading Tenancy migrations.
