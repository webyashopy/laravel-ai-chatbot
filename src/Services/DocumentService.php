<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Webyashopy\Chatbot\Exceptions\DocumentTooLargeException;
use Webyashopy\Chatbot\Exceptions\UnknownDocumentSchemaException;
use Webyashopy\Chatbot\Exceptions\UnsupportedDocumentException;
use Webyashopy\Chatbot\Models\ChatDocument;
use Webyashopy\Chatbot\Models\DocumentExtraction;
use Webyashopy\Chatbot\Support\ExtractionResult;
use Webyashopy\Chatbot\Support\PdfInspector;

/**
 * Vstupní bod digitalizace dokumentů — ulož soubor, extrahuj z něj data,
 * zapiš výsledek.
 *
 * Typické použití v hostovi (předvyplnění formuláře z nahrané faktury):
 *
 *     $result = Documents::digitize($request->file('soubor'), 'faktura', $request->user());
 *
 *     return back()->with('predvyplneno', $result->data());
 *
 * Mapování extrahovaných dat na doménové modely dělá HOST — balíček doménu
 * nezná (ADR-019). {@see ExtractionResult::data()} je obyčejné asociativní
 * pole podle `jsonSchema()` daného schématu.
 */
class DocumentService
{
    /**
     * Přípony podle ověřeného MIME typu. Přípona z nahrávky se ZÁMĚRNĚ
     * nepoužívá — pochází od klienta a dá se podvrhnout.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly DocumentExtractor $extractor,
        private readonly DocumentSchemaRegistry $schemas,
        private readonly PdfInspector $pdf = new PdfInspector,
    ) {}

    /**
     * Uloží soubor, ověří ho a založí {@see ChatDocument}.
     *
     * Stejný soubor (shodný SHA-256) od téhož uživatele se neukládá znovu —
     * vrátí se existující záznam, takže i jeho hotové extrakce.
     *
     * @param  UploadedFile|string  $file  Nahrávka, nebo absolutní cesta k souboru.
     * @param  mixed  $user  Uživatel hosta (typ `mixed` — balíček User model neimportuje).
     *
     * @throws UnsupportedDocumentException Nepodporovaný nebo nečitelný typ.
     * @throws DocumentTooLargeException Přes limit velikosti nebo počtu stran.
     */
    public function store(UploadedFile|string $file, mixed $user = null): ChatDocument
    {
        $path = $file instanceof UploadedFile ? (string) $file->getRealPath() : $file;

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw UnsupportedDocumentException::unreadable($path);
        }

        $contents = (string) file_get_contents($path);
        $mimeType = $this->detectMimeType($path);

        $this->guardMimeType($mimeType);
        $this->guardSize(strlen($contents));

        $pages = $this->resolvePages($contents, $mimeType);

        $sha256 = hash('sha256', $contents);
        $userId = $this->userId($user);

        $existing = ChatDocument::query()
            ->where('user_id', $userId)
            ->where('sha256', $sha256)
            ->first();

        if ($existing !== null && Storage::disk($existing->disk)->exists($existing->path)) {
            return $existing;
        }

        $disk = (string) config('chatbot.documents.disk', 'local');
        $directory = trim((string) config('chatbot.documents.path', 'chatbot/documents'), '/');
        $storedPath = $directory.'/'.$sha256.'.'.self::EXTENSIONS[$mimeType];

        Storage::disk($disk)->put($storedPath, $contents);

        return ChatDocument::create([
            'user_id' => $userId,
            'disk' => $disk,
            'path' => $storedPath,
            'original_name' => $file instanceof UploadedFile
                ? $file->getClientOriginalName()
                : basename($path),
            'mime_type' => $mimeType,
            'size_bytes' => strlen($contents),
            'sha256' => $sha256,
            'pages' => $pages,
        ]);
    }

    /**
     * Extrahuje z dokumentu data podle schématu.
     *
     * Bez `$force` se nejdřív hledá poslední ÚSPĚŠNÁ extrakce téhož dokumentu
     * týmž schématem — opakované otevření formuláře nad stejnou fakturou tak
     * nestojí nic navíc. `$force: true` si vynutí nové volání (např. po opravě
     * schématu).
     *
     * @param  array<string, mixed>  $options  `model` (override), `max_tokens`.
     *
     * @throws UnknownDocumentSchemaException Schéma není registrované.
     * @throws \Webyashopy\Chatbot\Exceptions\ExtractionFailedException Odpověď nejde použít.
     * @throws \RuntimeException Chyba API, rate limit, chybějící klíč.
     */
    public function extract(
        ChatDocument $document,
        string $schema,
        mixed $user = null,
        bool $force = false,
        array $options = [],
    ): ExtractionResult {
        $definition = $this->schemas->get($schema);

        if ($definition === null) {
            throw UnknownDocumentSchemaException::named($schema, $this->schemas->names());
        }

        if (! $force) {
            $cached = $this->latestSuccessful($document, $schema);

            if ($cached !== null) {
                return new ExtractionResult(
                    data: (array) ($cached->data ?? []),
                    schema: $schema,
                    model: $cached->model,
                    usage: [],
                    cost: $cached->cost,
                    cached: true,
                    extractionId: $cached->id,
                );
            }
        }

        $userId = $this->userId($user);

        try {
            $result = $this->extractor->extract(
                contents: $document->contents(),
                mimeType: $document->mime_type,
                schema: $definition,
                options: [
                    'user' => $user,
                    'model' => $options['model'] ?? null,
                    'max_tokens' => $options['max_tokens'] ?? null,
                    'title' => $document->original_name,
                ],
            );
        } catch (Throwable $e) {
            $this->recordFailure($document, $schema, $userId, $options, $e);

            throw $e;
        }

        $usage = $result->usage();

        $extraction = DocumentExtraction::create([
            'document_id' => $document->id,
            'user_id' => $userId,
            'schema' => $schema,
            'data' => $result->data(),
            'model' => $result->model(),
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'cost' => $result->cost(),
            'status' => DocumentExtraction::STATUS_SUCCESS,
        ]);

        return new ExtractionResult(
            data: $result->data(),
            schema: $schema,
            model: $result->model(),
            usage: $usage,
            cost: $result->cost(),
            cached: false,
            extractionId: $extraction->id,
        );
    }

    /**
     * Zkratka „nahraj a rovnou vytěž" — {@see store()} + {@see extract()}.
     */
    public function digitize(
        UploadedFile|string $file,
        string $schema,
        mixed $user = null,
        bool $force = false,
        array $options = [],
    ): ExtractionResult {
        return $this->extract($this->store($file, $user), $schema, $user, $force, $options);
    }

    /**
     * Smaže dokument i jeho soubor na disku (extrakce padnou s ním přes FK
     * cascade). Pro retenční úlohy hosta.
     */
    public function delete(ChatDocument $document): void
    {
        Storage::disk($document->disk)->delete($document->path);

        $document->delete();
    }

    /**
     * Registr schémat — pro select ve formuláři hosta
     * ({@see DocumentSchemaRegistry::options()}).
     */
    public function schemas(): DocumentSchemaRegistry
    {
        return $this->schemas;
    }

    /**
     * Poslední úspěšná extrakce dvojice (dokument, schéma).
     */
    private function latestSuccessful(ChatDocument $document, string $schema): ?DocumentExtraction
    {
        return DocumentExtraction::query()
            ->where('document_id', $document->id)
            ->where('schema', $schema)
            ->successful()
            ->latest('id')
            ->first();
    }

    /**
     * Zapíše neúspěšný pokus. Volání API proběhlo a zaplatilo se, takže po
     * něm musí zůstat stopa — bez ní by se hledalo, za co přišla faktura
     * od Anthropic.
     *
     * Selhání zápisu NESMÍ přebít původní výjimku (ta nese příčinu, kterou
     * host řeší) — proto vlastní try/catch a jen log.
     *
     * @param  array<string, mixed>  $options
     */
    private function recordFailure(
        ChatDocument $document,
        string $schema,
        int|string|null $userId,
        array $options,
        Throwable $e,
    ): void {
        try {
            DocumentExtraction::create([
                'document_id' => $document->id,
                'user_id' => $userId,
                'schema' => $schema,
                'data' => null,
                'model' => (string) ($options['model'] ?? config('chatbot.documents.model', 'claude-sonnet-5')),
                'status' => DocumentExtraction::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $writeError) {
            Log::error('Chatbot: neúspěšnou extrakci se nepodařilo zapsat.', [
                'document_id' => $document->id,
                'schema' => $schema,
                'exception' => $writeError->getMessage(),
            ]);
        }
    }

    /**
     * MIME typ z OBSAHU souboru (finfo), ne z přípony ani z hlavičky od
     * klienta — přejmenovaný `skript.php` na `faktura.pdf` musí propadnout.
     */
    private function detectMimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw UnsupportedDocumentException::unreadable($path);
        }

        $mimeType = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mimeType === false ? 'application/octet-stream' : $mimeType;
    }

    /**
     * @throws UnsupportedDocumentException
     */
    private function guardMimeType(string $mimeType): void
    {
        $allowed = array_values(array_filter(
            (array) config('chatbot.documents.allowed_mime', array_keys(self::EXTENSIONS)),
            static fn (mixed $value): bool => is_string($value),
        ));

        // Druhá podmínka: typ povolený v configu, ale bez známé přípony by
        // shodil ukládání na undefined indexu — raději srozumitelná výjimka.
        if (! in_array($mimeType, $allowed, true) || ! isset(self::EXTENSIONS[$mimeType])) {
            throw UnsupportedDocumentException::mimeType($mimeType, $allowed);
        }
    }

    /**
     * @throws DocumentTooLargeException
     */
    private function guardSize(int $bytes): void
    {
        $maxBytes = (int) config('chatbot.documents.max_size_mb', 20) * 1_048_576;

        if ($bytes > $maxBytes) {
            throw DocumentTooLargeException::bytes($bytes, $maxBytes);
        }
    }

    /**
     * Počet stran u PDF (u obrázků `null`) + kontrola limitu.
     *
     * Když počet stran nejde zjistit (komprimované object streamy), limit
     * se NEVYNUCUJE a zůstane jen limit velikosti souboru. Odmítat takové
     * PDF by znamenalo odmítat legitimní dokumenty kvůli heuristice.
     *
     * @throws UnsupportedDocumentException
     * @throws DocumentTooLargeException
     */
    private function resolvePages(string $contents, string $mimeType): ?int
    {
        if ($mimeType !== 'application/pdf') {
            return null;
        }

        if (! $this->pdf->isPdf($contents)) {
            throw UnsupportedDocumentException::corruptedPdf();
        }

        $pages = $this->pdf->pageCount($contents);

        if ($pages === null) {
            Log::info('Chatbot: počet stran PDF nelze zjistit, limit stran se nevynucuje.');

            return null;
        }

        $maxPages = (int) config('chatbot.documents.max_pages', 200);

        if ($pages > $maxPages) {
            throw DocumentTooLargeException::pages($pages, $maxPages);
        }

        return $pages;
    }

    /**
     * Identifikátor uživatele bez vazby na host User model (`mixed`) —
     * stejná logika jako v {@see AiService}.
     */
    private function userId(mixed $user): int|string|null
    {
        if ($user === null) {
            return null;
        }

        if ($user instanceof Model) {
            /** @var int|string|null $key */
            $key = $user->getKey();

            return $key;
        }

        return $user->id ?? null;
    }
}
