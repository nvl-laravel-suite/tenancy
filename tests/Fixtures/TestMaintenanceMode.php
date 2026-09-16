<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Contracts\Foundation\MaintenanceMode;

/** Provides disposable maintenance state without touching the developer application. */
final class TestMaintenanceMode implements MaintenanceMode
{
    /** Create an isolated maintenance-mode flag. */
    public function __construct(public bool $enabled = true) {}

    /**
     * Activate this test-only maintenance flag.
     *
     * @param  array<string, mixed>  $payload
     */
    public function activate(array $payload): void
    {
        $this->enabled = true;
    }

    /** Deactivate this test-only maintenance flag. */
    public function deactivate(): void
    {
        $this->enabled = false;
    }

    /** Return whether this fixture is in maintenance mode. */
    public function active(): bool
    {
        return $this->enabled;
    }

    /**
     * Return the empty test-only maintenance metadata.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [];
    }
}
