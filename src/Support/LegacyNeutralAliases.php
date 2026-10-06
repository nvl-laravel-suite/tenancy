<?php

declare(strict_types=1);
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantContextParticipant;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Contracts\TenantHttpResolver;
use Nvl\Support\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Support\Tenancy\Contracts\TenantParentResolver;
use Nvl\Support\Tenancy\Contracts\TenantQueuedJob;
use Nvl\Support\Tenancy\Enums\TenancyResponseCode;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Enums\TenantResourceKind;
use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Support\Tenancy\Exceptions\TenancyException;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Support\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Support\Tenancy\Exceptions\TenantInactive;
use Nvl\Support\Tenancy\Exceptions\TenantNotFound;
use Nvl\Support\Tenancy\Exceptions\TenantSchemaNotReady;
use Nvl\Support\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Support\Tenancy\Services\TenantContextParticipants;
use Nvl\Support\Tenancy\Services\TenantExtensionGuard;
use Nvl\Support\Tenancy\Services\TenantQueuePayload;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;
use Nvl\Support\Tenancy\ValueObjects\TenantResourceDefinition;
use Nvl\Support\Tenancy\ValueObjects\TenantSiteContext;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** Load deprecated neutral aliases before legacy catch clauses and serialized data are interpreted. */
foreach ([
    TenantContextParticipant::class => 'Nvl\\Tenancy\\Contracts\\TenantContextParticipant',
    TenantContext::class => 'Nvl\\Tenancy\\Contracts\\TenantContext',
    TenantHttpResolver::class => 'Nvl\\Tenancy\\Contracts\\TenantHttpResolver',
    TenantDirectory::class => 'Nvl\\Tenancy\\Contracts\\TenantDirectory',
    TenantParentResolver::class => 'Nvl\\Tenancy\\Contracts\\TenantParentResolver',
    TenantMembershipAccess::class => 'Nvl\\Tenancy\\Contracts\\TenantMembershipAccess',
    TenantQueuedJob::class => 'Nvl\\Tenancy\\Contracts\\TenantQueuedJob',
    TenantResourceKind::class => 'Nvl\\Tenancy\\Enums\\TenantResourceKind',
    TenancyResponseCode::class => 'Nvl\\Tenancy\\Enums\\TenancyResponseCode',
    TenantContextMode::class => 'Nvl\\Tenancy\\Enums\\TenantContextMode',
    TenantStatus::class => 'Nvl\\Tenancy\\Enums\\TenantStatus',
    TenantSchemaNotReady::class => 'Nvl\\Tenancy\\Exceptions\\TenantSchemaNotReady',
    TenancyException::class => 'Nvl\\Tenancy\\Exceptions\\TenancyException',
    TenantContextMissing::class => 'Nvl\\Tenancy\\Exceptions\\TenantContextMissing',
    TenantConfigurationInvalid::class => 'Nvl\\Tenancy\\Exceptions\\TenantConfigurationInvalid',
    TenantBoundaryViolation::class => 'Nvl\\Tenancy\\Exceptions\\TenantBoundaryViolation',
    TenantNotFound::class => 'Nvl\\Tenancy\\Exceptions\\TenantNotFound',
    TenantInactive::class => 'Nvl\\Tenancy\\Exceptions\\TenantInactive',
    EffectiveTenantConnection::class => 'Nvl\\Tenancy\\Services\\EffectiveTenantConnection',
    TenantContextParticipants::class => 'Nvl\\Tenancy\\Services\\TenantContextParticipants',
    TenantExtensionGuard::class => 'Nvl\\Tenancy\\Services\\TenantExtensionGuard',
    TenantQueuePayload::class => 'Nvl\\Tenancy\\Services\\TenantQueuePayload',
    TenantResourceRegistry::class => 'Nvl\\Tenancy\\Services\\TenantResourceRegistry',
    TenantSiteContext::class => 'Nvl\\Tenancy\\ValueObjects\\TenantSiteContext',
    TenantDescriptor::class => 'Nvl\\Tenancy\\ValueObjects\\TenantDescriptor',
    PlatformOperation::class => 'Nvl\\Tenancy\\ValueObjects\\PlatformOperation',
    TenantResourceDefinition::class => 'Nvl\\Tenancy\\ValueObjects\\TenantResourceDefinition',
    TenantContextSnapshot::class => 'Nvl\\Tenancy\\ValueObjects\\TenantContextSnapshot',
    TenantJobEnvelope::class => 'Nvl\\Tenancy\\ValueObjects\\TenantJobEnvelope',
    TenantId::class => 'Nvl\\Tenancy\\ValueObjects\\TenantId',
] as $canonical => $legacy) {
    LegacyNeutralAlias::register($canonical, $legacy);
}
