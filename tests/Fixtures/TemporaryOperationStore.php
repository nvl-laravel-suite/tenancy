<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates only disposable F2 audit storage; F3 replaces this with the real core migration.
 */
final class TemporaryOperationStore
{
    /** Install the frozen operation table shape in the isolated test database. */
    public static function create(): void
    {
        Schema::create('nvl_tenancy_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('actor_type');
            $table->string('actor_id');
            $table->string('purpose');
            $table->timestamps();
        });
    }
}
