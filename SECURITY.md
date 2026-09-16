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
