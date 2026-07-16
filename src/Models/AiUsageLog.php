<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Log AI/API dotazu (ADR-015) — append-only, jeden řádek na každé volání
 * Anthropic API. Zdroj nákladovosti po uživatelích.
 *
 * `purpose` je volný STRING, ne enum (ADR-019 §3) — balíček nezná doménové
 * účely hosta (JNS posílá 'ocr'), definuje jen {@see \Webyashopy\Chatbot\Support\Purpose}.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $model
 * @property string $purpose
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $cost
 * @property string $key_source
 * @property bool $success
 * @property string|null $error
 * @property int|null $conversation_id
 * @property array<string, mixed>|null $context
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiUsageLog extends Model
{
    protected $table = 'ai_usage_logs';

    protected $fillable = [
        'user_id',
        'model',
        'purpose',
        'input_tokens',
        'output_tokens',
        'cost',
        'key_source',
        'success',
        'error',
        'conversation_id',
        'context',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => 'string',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'decimal:4',
            'success' => 'boolean',
            'context' => 'array',
        ];
    }

    /**
     * Uživatel, který volání vyvolal.
     *
     * User model balíček neimportuje — čte ho z `config('chatbot.user_model')`
     * (host si ho může přepsat env `CHATBOT_USER_MODEL`).
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = (string) config('chatbot.user_model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'user_id');
    }

    /**
     * Append-only garance: log nákladů se nesmí zpětně upravovat ani mazat.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Log AI nákladů je append-only a nelze jej upravit.');
        });

        static::deleting(function (): void {
            throw new LogicException('Log AI nákladů je append-only a nelze jej smazat.');
        });
    }
}
