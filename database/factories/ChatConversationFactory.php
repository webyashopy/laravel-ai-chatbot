<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Webyashopy\Chatbot\Models\ChatConversation;

/**
 * @extends Factory<ChatConversation>
 */
class ChatConversationFactory extends Factory
{
    /** @var class-string<ChatConversation> */
    protected $model = ChatConversation::class;

    /**
     * `user_id` je ZÁMĚRNĚ closure, ne `User::factory()` napřímo.
     *
     * Definici factory Laravel vyhodnotí vždy, i když volající `user_id`
     * přebije stavem (`->create(['user_id' => $id])`). Eager volání
     * `config('chatbot.user_model')::factory()` by proto vyžadovalo, aby
     * host User model factory MĚL — což balíček nesmí předpokládat
     * (testovací stub ji nemá). Closure se vyhodnotí až tehdy, když
     * `user_id` nikdo nedodal.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn (): Model => $this->hostUser(),
            'title' => fake()->sentence(4),
            'model' => config('chatbot.default_model'),
        ];
    }

    /**
     * Vytvoří uživatele host aplikace přes jeho vlastní factory.
     */
    private function hostUser(): Model
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('chatbot.user_model');

        /** @var Model $user */
        $user = $userModel::factory()->create();

        return $user;
    }
}
