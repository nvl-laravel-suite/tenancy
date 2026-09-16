<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Contracts\Foundation\MaintenanceMode;

/** Provides isolated in-memory maintenance state for Tenancy feature tests. */
final class InMemoryMaintenanceMode implements MaintenanceMode
{
    /** @var array<string, mixed>|null */
    private ?array $payload = [];

    /** @param array<string, mixed> $payload */
    public function activate(array $payload): void
    {
        $this->payload = $payload;
    }

    /** Deactivate maintenance mode. */
    public function deactivate(): void
    {
        $this->payload = null;
    }

    /** Report whether maintenance mode is active. */
    public function active(): bool
    {
        return $this->payload !== null;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->payload ?? [];
    }
}
