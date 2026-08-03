<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Webyashopy\Chatbot\Database\Factories\ChatMessageFactory;
use Webyashopy\Chatbot\Enums\ChatRole;

/**
 * Zpráva v konverzaci chatbota (ADR-016) — append-only historie.
 *
 * `action` drží návrh zápisu z write nástroje
 * (`{ kind, payload, summary, status: pending|confirmed|cancelled, result_id? }`),
 * `steps` průběh tool-use smyčky (`{ tool, input, summary }`). Tvar obou je
 * smluvní — viz `contracts/api/chatbot-tools.md`.
 *
 * @property int $id
 * @property int $conversation_id
 * @property ChatRole $role
 * @property string $content
 * @property string|null $model
 * @property int|null $ai_usage_log_id
 * @property array<string, mixed>|null $action
 * @property array<int, array<string, mixed>>|null $steps
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(ChatMessageFactory::class)]
class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'model',
        'ai_usage_log_id',
        'action',
        'steps',
    ];

    /**
     * `content`/`action`/`steps` můžou nést zdravotní/PII data hosta
     * (GDPR čl. 9) — s `chatbot.encrypt_messages` (TASK-AIBOT-01g, default
     * `false`) se ukládají šifrovaně (`encrypted` / `encrypted:array`,
     * precedent {@see UserAiSettings} `api_key => encrypted`). Vyžaduje
     * migraci `action`/`steps` z `json` na `text` (viz
     * `database/migrations/*_change_chat_messages_action_steps_to_text.php`)
     * — `encrypted:array` ukládá base64 ciphertext string, který PostgreSQL
     * `json` sloupec odmítne.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        $encrypt = (bool) config('chatbot.encrypt_messages', false);

        return [
            'role' => ChatRole::class,
            'content' => $encrypt ? 'encrypted' : 'string',
            'action' => $encrypt ? 'encrypted:array' : 'array',
            'steps' => $encrypt ? 'encrypted:array' : 'array',
        ];
    }

    /**
     * @return BelongsTo<ChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<AiUsageLog, $this>
     */
    public function aiUsageLog(): BelongsTo
    {
        return $this->belongsTo(AiUsageLog::class, 'ai_usage_log_id');
    }
}
