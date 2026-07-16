<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory stub User modelu — stojí v ní za to jen unikátní e-mail
 * (sloupec má unique index).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** @var class-string<User> */
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
