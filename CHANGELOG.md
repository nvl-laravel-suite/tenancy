# Changelog


All notable changes to `nvl/tenancy` are documented here.

## [Unreleased]

## [5.0.1] - 2026-10-08

### Changed

- Correct published-family verification and adoption guidance for local Dagger CI; runtime contracts are unchanged.


## [5.0.0] - 2026-10-08

### Changed

- Both platform workflows gain focused contracts. Neutral Core contracts, effective host adapter identity, compatibility aliases, security, and inactive-by-default behavior are preserved. Document contract substitution and truthful host fixtures in Testing your app.
- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Adopt lockstep major 5 with required and development NVL peer floors of `^5.0`.
- Keep disabled runtime hooks, host queue handlers and static callbacks untouched.
- Validate queue envelope metadata before deserialization; preserve native failed-job storage with safe raw retry.
- Review [UPGRADING.md](UPGRADING.md) before adopting the new names and infrastructure boundaries.

## [2.2.1] - 2026-09-26

### Documentation

- Clarify public support, contribution, and private security reporting paths.

## [2.2.0] - 2026-09-25

### Changed

- Prepare `nvl/tenancy` for independent Composer and Git publication; require `nvl/core` for shared Support and Data services.

## [2.0.1] - 2026-09-22

- Prove standalone Tenancy archives with only Support/Data, cached configuration,
  Doctor and disabled compatibility; explicitly provision Filterable composition,
  register Tenancy type sources, and correct existing dependency/test autoload metadata.

- Restore explicitly captured queued tenant context before command/failure deserialization, validate native representations and canonical model ownership, and unwind worker state.

### Added

- Separate runtime provider compatibility from feature enablement and persisted
  adoption readiness, while preserving Unresolved platform bootstrap.
- Enforce metadata readiness before tenant execution and adoption activation;
  add bounded configuration and ownership/dependency diagnostics.
- Verify real core schema/adoption and consumer-owned migrations on supported
  database engines; classify optional migrations explicitly in family quality gates.

- Added explicit resumable adoption with immutable streamed mappings, package-owned
  metadata validation, connection-wide process locks, durable phase audits, real
  adapter DDL/checkpoints and atomic active markers.
- Added read-only Tenancy Doctor and authorized adoption CLI commands, Suite
  diagnostics integration, and synchronous adoption publication fences.

- Added the inert-by-default Laravel 13 package, frozen configuration contract,
  scoped read-only context, canonical tenant IDs, adapter interfaces, and stable
  transport-neutral failure codes.
- Added package discovery, configuration and skill publication, standalone
  quality configuration, and disabled compatibility coverage without installing
  tenant schema.
- Added active tenant runners, reverse-order context participants, transaction
  lifecycle checks, real guarded SQL directory/audit adapters, and explicit HTTP
  membership/public-site admission before route binding.
- Added authorized synchronous maintenance leases with durable audit, cleanup
  revocation, current-scope queue guards, and real opt-in core-store tests.
- Added the separately enabled five-table core migration set, package-owned tenant
  provisioning and lifecycle actions, configured-connection persistence, and
  durable privileged-operation audit coverage without activating downstream
  package tenancy.
- Added immutable resource registration, canonical persisted ownership checks,
  grouped query predicates, package-allowlisted polymorphic parents, isolated
  identity keys, and current-status resource admission.
- Added per-resource ownership fingerprints and bounded persisted adoption probes
  that reject prepared/incompatible resources and configuration downgrade attempts.
