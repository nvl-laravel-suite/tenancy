# Upgrading NVL Tenancy

## Unreleased foundation

The foundation is disabled by default and introduces no migration or data
adoption requirement.

1. Publish and review `tenancy.php` as a minimal deployment overlay.
2. Keep `tenancy.enabled` and `tenancy.migrations.enabled` false until the
   complete runtime, schema, and every participating package integration are
   installed and their adoption procedure has been reviewed.
3. Replace closures with class-string adapters before caching configuration.
4. Configure either a class string or a host binding for each adapter contract.
5. Do not assign existing records to a default tenant; later adoption tooling
   requires explicit reviewed mappings.
