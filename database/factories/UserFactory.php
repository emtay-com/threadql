<?php declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserLevel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * The name of the factory's corresponding model.
     */
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $username = $this->faker->unique()->userName();

        return [
            'name' => $username,
            'email' => "{$username}@tenant.local",
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password', ['rounds' => 12]),
            'tenant_id' => Tenant::factory(),
            'username' => $username,
            'level' => UserLevel::TENANT->value,
        ];
    }

    /**
     * Indicate that the user belongs to a specific tenant.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Indicate that the user has master level permissions.
     */
    public function master(): static
    {
        return $this->state(fn (array $attributes) => [
            'level' => UserLevel::MASTER->value,
        ]);
    }
}
