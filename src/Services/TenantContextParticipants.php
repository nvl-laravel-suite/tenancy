<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Nvl\Tenancy\Contracts\TenantContextParticipant;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use ReflectionClass;

/** Registers immutable participant class names without retaining scoped instances. */
final class TenantContextParticipants
{
    /** @var list<class-string<TenantContextParticipant>> */
    private array $participants = [];

    /**
     * Register an integration once in deterministic entry order.
     *
     * @param  class-string<TenantContextParticipant>  $participant
     */
    public function register(string $participant): void
    {
        $reflection = new ReflectionClass($participant);
        if (! $reflection->implementsInterface(TenantContextParticipant::class) || ! $reflection->isInstantiable()) {
            throw new TenantConfigurationInvalid('Context participants must implement TenantContextParticipant.');
        }
        if (! in_array($participant, $this->participants, true)) {
            $this->participants[] = $participant;
        }
    }

    /**
     * Return registered integration classes for resolution in the current scope.
     *
     * @internal
     *
     * @return list<class-string<TenantContextParticipant>>
     */
    public function all(): array
    {
        return $this->participants;
    }
}
