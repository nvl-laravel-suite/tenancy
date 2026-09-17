# Security Policy

Security fixes are provided for the current `2.x` line on PHP 8.4 and Laravel
13.

Report vulnerabilities privately through the repository host's
security-advisory feature. Include the configured strategy and profile, context
mode, adapter source, and the stable tenancy response code. Do not include
credentials, tenant data, SQL, tokens, or unredacted configuration secrets.

Provider registration is not tenant isolation. Keep the feature disabled until
the complete schema, context runner, resource boundaries, adoption, and worker
restoration milestones are installed and verified.

Registered boundaries validate canonical persisted ownership and current status.
Callers must reload and lock business records under the predicate before mutation;
loaded relationships and dirty attributes are not authorization evidence. Existing
adoption markers remain mandatory when a feature is disabled or the configured
core store changes. Marker cache invalidation is internal adoption infrastructure,
and all other worker processes must restart at cutover.

Adoption requires authenticated host platform authorization and actual application
maintenance, with durable audits before mutations. CLI actor fields confer no
authority. Immutable mapping/configuration fingerprints are checked on resumption
and before phase checkpoints. Prepared graphs remain blocked through partial DDL;
only a fully verified graph becomes active. Adapters preserve existing ownership
and reject unsupported re-adoption; this protocol is not a tenant-transfer API.
No adoption callback may publish queue, after-response, deferred or background work.
Drain and restart other processes at cutover; local lock/probe state cannot replace
that operational boundary. Local SQLite file locks require a local filesystem.

<!-- tenancy-program-p2 -->
The configurable-tenancy implementation is present; its consolidated verification matrix remains pending and no release-readiness claim is made.
