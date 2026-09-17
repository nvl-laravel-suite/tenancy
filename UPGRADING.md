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

<!-- tenancy-program-p2 -->
Configurable-tenancy implementation and adoption documentation are present. The final consolidated verification matrix is pending; do not treat this package as release-ready until that gate passes.
