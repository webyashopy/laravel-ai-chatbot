<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-user AI nastavení (ADR-015) — 1:1 na uživatele, self-service v hostovi.
 *
 * `api_key` je `encrypted` cast — v DB uložen zašifrovaně, do FE se nikdy
 * neposílá plaintextem (host vrací jen `has_api_key: bool`).
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $api_key
 * @property string|null $preferred_model
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserAiSettings extends Model
{
    protected $table = 'user_ai_settings';

    protected $fillable = [
        'user_id',
        'api_key',
        'preferred_model',
    ];

    /**
     * `api_key` se nesmí nikdy dostat do serializace modelu (toArray/toJson) —
     * defense-in-depth proti úniku do Inertia props přes vztažené relace
     * (viz TASK-PT-006-fix-1: `HandleInertiaRequests::share()` serializuje
     * `auth.user` včetně načtených relací).
     *
     * @var array<int, string>
     */
    protected $hidden = ['api_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
        ];
    }

    /**
     * Vlastník nastavení. User model se čte z `config('chatbot.user_model')` —
     * balíček host model nikdy neimportuje.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = (string) config('chatbot.user_model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'user_id');
    }
}
