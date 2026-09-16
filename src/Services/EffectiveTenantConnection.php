<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;

/** Normalizes effective Laravel connection identity and participating transactions. */
final readonly class EffectiveTenantConnection
{
    /** Create the connection boundary using deployment configuration. */
    public function __construct(private DatabaseManager $database, private Repository $configuration) {}

    /** Resolve a nullable default alias to its effective Laravel connection name. */
    public function name(?string $connection): string
    {
        return $this->database->connection($connection)->getName()
            ?? throw new TenantConfigurationInvalid('The effective Laravel connection must have a name.');
    }

    /**
     * Require all participating writes to share the exact Laravel connection object.
     *
     * @param  list<string|null>  $connections
     */
    public function assertCompatible(array $connections): void
    {
        $first = null;
        foreach ($connections as $name) {
            $connection = $this->database->connection($name);
            if ($first !== null && $connection !== $first) {
                throw new TenantConfigurationInvalid('Participating writes require the same Laravel connection instance.');
            }
            $first = $connection;
        }
    }

    /** Return the configured core storage connection. */
    public function core(): Connection
    {
        $name = $this->configuration->get('tenancy.connection');
        if ($name !== null && ! is_string($name)) {
            throw new TenantConfigurationInvalid('tenancy.connection must be null or a connection name.');
        }

        return $this->database->connection($name);
    }

    /**
     * Include core, default, and every connection already participating in this application.
     *
     * @internal
     *
     * @return array<int, Connection>
     */
    public function participating(): array
    {
        $this->core();
        $this->database->connection();
        $connections = [];
        foreach ($this->database->getConnections() as $connection) {
            $connections[spl_object_id($connection)] = $connection;
        }

        return $connections;
    }
}
