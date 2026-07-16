<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Webyashopy\Chatbot\Database\Factories\ChatConversationFactory;

/**
 * Konverzace chatbota (ADR-016) — privátní, vlastníkem je vždy uživatel,
 * který ji založil (ownership hlídá {@see \Webyashopy\Chatbot\Policies\ChatConversationPolicy}).
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $title
 * @property string $model
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseFactory(ChatConversationFactory::class)]
class ChatConversation extends Model
{
    /** @use HasFactory<ChatConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'model',
    ];

    /**
     * Vlastník konverzace — model uživatele dodává HOST přes
     * `config('chatbot.user_model')`. Balíček host User model nikdy
     * neimportuje (ADR-019), proto se třída relace čte z configu.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('chatbot.user_model');

        return $this->belongsTo($userModel);
    }

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }
}
