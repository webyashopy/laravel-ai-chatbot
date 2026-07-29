<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Facades;

use Illuminate\Support\Facades\Facade;
use Webyashopy\Chatbot\Services\DocumentService;

/**
 * Fasáda nad {@see DocumentService} — zkratka pro čitelnější volání v hostovi:
 *
 *     use Webyashopy\Chatbot\Facades\Documents;
 *
 *     $result = Documents::digitize($request->file('soubor'), 'faktura', $request->user());
 *
 * ZÁMĚRNĚ bez globálního aliasu v `composer.json` — jméno `Documents` je
 * v kořenovém namespace hosta příliš obecné a mohlo by kolidovat s jeho
 * vlastní třídou. Importuje se plným namespace, nebo se použije
 * `app(DocumentService::class)`.
 *
 * @method static \Webyashopy\Chatbot\Models\ChatDocument store(\Illuminate\Http\UploadedFile|string $file, mixed $user = null)
 * @method static \Webyashopy\Chatbot\Support\ExtractionResult extract(\Webyashopy\Chatbot\Models\ChatDocument $document, string $schema, mixed $user = null, bool $force = false, array $options = [])
 * @method static \Webyashopy\Chatbot\Support\ExtractionResult digitize(\Illuminate\Http\UploadedFile|string $file, string $schema, mixed $user = null, bool $force = false, array $options = [])
 * @method static void delete(\Webyashopy\Chatbot\Models\ChatDocument $document)
 * @method static \Webyashopy\Chatbot\Services\DocumentSchemaRegistry schemas()
 *
 * @see DocumentService
 */
class Documents extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DocumentService::class;
    }
}
