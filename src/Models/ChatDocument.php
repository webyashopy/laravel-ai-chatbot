<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Nahraný dokument k digitalizaci (PDF nebo obrázek).
 *
 * Samotný soubor leží na disku z `chatbot.documents.disk` — v DB je jen
 * metadata a `sha256`, podle kterého se stejný soubor neukládá dvakrát
 * ({@see \Webyashopy\Chatbot\Services\DocumentService::store()}).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property int|null $pages
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChatDocument extends Model
{
    protected $fillable = [
        'user_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'sha256',
        'pages',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'pages' => 'integer',
        ];
    }

    /**
     * Vlastník dokumentu — model uživatele dodává HOST přes
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
     * @return HasMany<DocumentExtraction, $this>
     */
    public function extractions(): HasMany
    {
        return $this->hasMany(DocumentExtraction::class, 'document_id');
    }

    /**
     * Binární obsah souboru z disku.
     *
     * @throws \RuntimeException Soubor na disku chybí (smazaný ručně,
     *                           přepnutý disk, neproběhlá synchronizace).
     */
    public function contents(): string
    {
        $contents = Storage::disk($this->disk)->get($this->path);

        if ($contents === null) {
            throw new \RuntimeException(
                "Soubor dokumentu [{$this->path}] na disku [{$this->disk}] neexistuje."
            );
        }

        return $contents;
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
