<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Jedna extrakce dat z dokumentu daným schématem.
 *
 * Jeden dokument může mít víc extrakcí (různá schémata, opakování po
 * opravě schématu). Poslední ÚSPĚŠNÁ extrakce dvojice (dokument, schéma)
 * se vrací bez nového volání API — viz
 * {@see \Webyashopy\Chatbot\Services\DocumentService::extract()}.
 *
 * Neúspěch se ukládá také (`status = 'failed'` + `error`) — jinak by po
 * chybě nezůstala stopa a hledalo by se, proč se za volání platilo.
 *
 * @property int $id
 * @property int $document_id
 * @property int|null $user_id
 * @property string $schema
 * @property array<string, mixed>|null $data
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property float|null $cost
 * @property string $status
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentExtraction extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'document_id',
        'user_id',
        'schema',
        'data',
        'model',
        'input_tokens',
        'output_tokens',
        'cost',
        'status',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'float',
        ];
    }

    /**
     * @return BelongsTo<ChatDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ChatDocument::class, 'document_id');
    }

    /**
     * Uživatel, který extrakci vyvolal — model dodává HOST přes
     * `config('chatbot.user_model')` (ADR-019).
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
     * @param  Builder<DocumentExtraction>  $query
     * @return Builder<DocumentExtraction>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }
}
