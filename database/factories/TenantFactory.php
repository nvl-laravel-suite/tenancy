<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Tenancy\Models\Tenant;

/**
 * Builds Tenant fixture rows and their declared package parents.
 *
 * @extends Factory<Tenant>
 *
 * @api
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<Tenant>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'status' => 'active',
        ];
    }
}
