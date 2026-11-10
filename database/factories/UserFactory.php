<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'firstName' => fake()->name(),
            'lastName' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    // TT-3.1e/SCRUM-278: dob defaults to null here, and User::isAdult() -- `$this->age &&
    // $this->age >= 18` -- returns false for a null dob (age defaults to 0), so a bare
    // User::factory()->create() is, perhaps surprisingly, a MINOR by this platform's own
    // definition. Any test creating a user who must legitimately pass an isAdult()-gated check
    // (e.g. EnsureVideoIsAvailableForSessionAction's interim minor-block) needs this explicit
    // state rather than relying on the implicit default.
    public function adult(): static
    {
        return $this->state(fn (array $attributes) => [
            'dob' => now()->subYears(30)->toDateString(),
        ]);
    }
}
