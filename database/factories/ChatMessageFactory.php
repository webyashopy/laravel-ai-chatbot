<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webyashopy\Chatbot\Enums\ChatRole;
use Webyashopy\Chatbot\Models\ChatConversation;
use Webyashopy\Chatbot\Models\ChatMessage;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    /** @var class-string<ChatMessage> */
    protected $model = ChatMessage::class;

    /**
     * `conversation_id` je closure ze stejného důvodu jako `user_id`
     * v {@see ChatConversationFactory} — konverzace se založí jen tehdy,
     * když ji volající nedodal (jinak by každá zpráva zakládala uživatele).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => fn (): ChatConversation => ChatConversation::factory()->create(),
            'role' => ChatRole::User,
            'content' => fake()->sentence(10),
            'model' => null,
            'ai_usage_log_id' => null,
        ];
    }
}
