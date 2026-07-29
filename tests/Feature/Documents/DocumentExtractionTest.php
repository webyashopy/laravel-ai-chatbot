<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webyashopy\Chatbot\Chatbot;
use Webyashopy\Chatbot\Exceptions\ExtractionFailedException;
use Webyashopy\Chatbot\Exceptions\UnknownDocumentSchemaException;
use Webyashopy\Chatbot\Models\AiUsageLog;
use Webyashopy\Chatbot\Models\DocumentExtraction;
use Webyashopy\Chatbot\Services\DocumentService;
use Webyashopy\Chatbot\Support\Purpose;
use Webyashopy\Chatbot\Tests\Stubs\Documents\FakturaStubSchema;
use Webyashopy\Chatbot\Tests\Support\PdfFixture;
use Webyashopy\Chatbot\Tests\Stubs\User;

/**
 * Testy extrakce dat z dokumentu ({@see DocumentService::extract()}) —
 * tvar requestu, dekódování strukturované odpovědi, znovupoužití
 * uloženého výsledku a chování při chybě.
 *
 * Anthropic API je nahrazeno přes `Http::fake` — žádné reálné volání.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Chatbot::flush();
    Chatbot::registerDocumentSchema(FakturaStubSchema::class);

    config([
        'chatbot.user_model' => User::class,
        'chatbot.api.key' => 'env-server-key',
        'chatbot.documents.disk' => 'local',
        'chatbot.documents.model' => 'claude-sonnet-5',
        // Discovery vypnutá — schéma je registrované explicitně, sken adresáře
        // hosta by v Testbench prostředí stejně nic nenašel.
        'chatbot.documents.schemas.discover_paths' => [],
    ]);
});

afterEach(function () {
    Chatbot::flush();
});

/**
 * Odpověď API se strukturovaným výstupem — JSON je v textovém bloku.
 *
 * @param  array<string, mixed>  $data
 */
function fakeExtractionResponse(array $data, string $stopReason = 'end_turn'): void
{
    Http::fake([
        '*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($data)]],
            'usage' => ['input_tokens' => 2000, 'output_tokens' => 300],
            'stop_reason' => $stopReason,
        ], 200),
    ]);
}

function storeFakturaPdf(mixed $user = null): Webyashopy\Chatbot\Models\ChatDocument
{
    return app(DocumentService::class)->store(
        UploadedFile::fake()->createWithContent('faktura.pdf', PdfFixture::withPages(2)),
        $user,
    );
}

it('extrahuje data a uloží výsledek', function () {
    fakeExtractionResponse(['cislo' => 'FV2026001', 'castka_bez_dph' => 12500.0, 'polozky' => []]);

    $result = app(DocumentService::class)->extract(storeFakturaPdf(), 'faktura');

    expect($result->get('cislo'))->toBe('FV2026001')
        ->and($result->model())->toBe('claude-sonnet-5')
        ->and($result->wasCached())->toBeFalse()
        ->and($result->cost())->toBeGreaterThan(0.0);

    $extraction = DocumentExtraction::sole();

    expect($extraction->status)->toBe(DocumentExtraction::STATUS_SUCCESS)
        ->and($extraction->schema)->toBe('faktura')
        ->and($extraction->data['cislo'])->toBe('FV2026001')
        ->and($extraction->input_tokens)->toBe(2000);
});

it('aplikuje na data transform() schématu', function () {
    fakeExtractionResponse(['cislo' => 'FV1', 'castka_bez_dph' => 100.0, 'polozky' => []]);

    $result = app(DocumentService::class)->extract(storeFakturaPdf(), 'faktura');

    // `castka_s_dph` model nikdy nevrátil — dopočítal ji postprocessing schématu.
    expect($result->get('castka_s_dph'))->toBe(121.0);
});

it('pošle PDF jako document blok a vynutí JSON schéma', function () {
    fakeExtractionResponse(['cislo' => 'FV1', 'castka_bez_dph' => 1.0, 'polozky' => []]);

    app(DocumentService::class)->extract(storeFakturaPdf(), 'faktura');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();
        $content = $body['messages'][0]['content'];

        expect($body['model'])->toBe('claude-sonnet-5');

        // Pořadí bloků: dokument nejdřív, pokyn nakonec.
        expect($content[0]['type'])->toBe('document')
            ->and($content[0]['source']['type'])->toBe('base64')
            ->and($content[0]['source']['media_type'])->toBe('application/pdf')
            ->and($content[0]['title'])->toBe('faktura.pdf')
            ->and($content[1]['type'])->toBe('text');

        $schema = $body['output_config']['format']['schema'];

        expect($body['output_config']['format']['type'])->toBe('json_schema')
            // Doplněno normalizací — schéma stubu ani jedno neuvádí.
            ->and($schema['additionalProperties'])->toBeFalse()
            ->and($schema['required'])->toBe(['cislo', 'castka_bez_dph', 'polozky'])
            // Normalizace musí projít i do vnořeného objektu v `items`.
            ->and($schema['properties']['polozky']['items']['additionalProperties'])->toBeFalse()
            ->and($schema['properties']['polozky']['items']['required'])->toBe(['nazev']);

        return true;
    });
});

it('obrázek pošle jako image blok, ne jako dokument', function () {
    fakeExtractionResponse(['cislo' => 'FV1', 'castka_bez_dph' => 1.0, 'polozky' => []]);

    $document = app(DocumentService::class)->store(UploadedFile::fake()->image('doklad.png', 30, 30));

    app(DocumentService::class)->extract($document, 'faktura');

    Http::assertSent(function (Request $request): bool {
        $content = $request->data()['messages'][0]['content'];

        expect($content[0]['type'])->toBe('image')
            ->and($content[0]['source']['media_type'])->toBe('image/png');

        return true;
    });
});

it('loguje volání s účelem document', function () {
    fakeExtractionResponse(['cislo' => 'FV1', 'castka_bez_dph' => 1.0, 'polozky' => []]);

    app(DocumentService::class)->extract(storeFakturaPdf(), 'faktura');

    expect(AiUsageLog::sole()->purpose)->toBe(Purpose::DOCUMENT);
});

it('podruhé vrátí uložený výsledek bez volání API', function () {
    fakeExtractionResponse(['cislo' => 'FV2026001', 'castka_bez_dph' => 500.0, 'polozky' => []]);

    $document = storeFakturaPdf();
    $service = app(DocumentService::class);

    $service->extract($document, 'faktura');
    $druhy = $service->extract($document, 'faktura');

    expect($druhy->wasCached())->toBeTrue()
        ->and($druhy->get('cislo'))->toBe('FV2026001');

    Http::assertSentCount(1);
    $this->assertDatabaseCount('document_extractions', 1);
});

it('s force volá API znovu', function () {
    fakeExtractionResponse(['cislo' => 'FV1', 'castka_bez_dph' => 1.0, 'polozky' => []]);

    $document = storeFakturaPdf();
    $service = app(DocumentService::class);

    $service->extract($document, 'faktura');
    $znovu = $service->extract($document, 'faktura', force: true);

    expect($znovu->wasCached())->toBeFalse();

    Http::assertSentCount(2);
    $this->assertDatabaseCount('document_extractions', 2);
});

it('neplatný JSON v odpovědi ohlásí a zapíše neúspěch', function () {
    Http::fake([
        '*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Zde jsou data: {neplatny']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'stop_reason' => 'end_turn',
        ], 200),
    ]);

    expect(fn () => app(DocumentService::class)->extract(storeFakturaPdf(), 'faktura'))
        ->toThrow(ExtractionFailedException::class);

    $extraction = DocumentExtraction::sole();

    expect($extraction->status)->toBe(DocumentExtraction::STATUS_FAILED)
        ->and($extraction->data)->toBeNull()
        ->and($extraction->error)->not->toBeEmpty();
});

it('useknutou odpověď rozpozná podle stop_reason', function () {
    fakeExtractionResponse(['cislo' => 'FV1'], stopReason: 'max_tokens');

    expect(fn () => app(DocumentService::class)->extract(storeFakturaPdf(), 'faktura'))
        ->toThrow(ExtractionFailedException::class, 'max_tokens');
});

it('odmítnutí modelem rozpozná podle stop_reason', function () {
    fakeExtractionResponse(['cislo' => 'FV1'], stopReason: 'refusal');

    expect(fn () => app(DocumentService::class)->extract(storeFakturaPdf(), 'faktura'))
        ->toThrow(ExtractionFailedException::class, 'odmítl');
});

it('neznámé schéma ohlásí bez volání API', function () {
    Http::fake();

    expect(fn () => app(DocumentService::class)->extract(storeFakturaPdf(), 'neexistuje'))
        ->toThrow(UnknownDocumentSchemaException::class);

    Http::assertNothingSent();
});

it('vypnutá feature digitalizaci zastaví', function () {
    Http::fake();
    config(['chatbot.features.documents' => false]);

    expect(fn () => app(DocumentService::class)->extract(storeFakturaPdf(), 'faktura'))
        ->toThrow(UnknownDocumentSchemaException::class);

    Http::assertNothingSent();
});

it('digitize spojí uložení a extrakci do jednoho kroku', function () {
    fakeExtractionResponse(['cislo' => 'FV2026009', 'castka_bez_dph' => 999.0, 'polozky' => []]);

    $user = User::create(['name' => 'Tester', 'email' => 'digitize@example.test']);

    $result = app(DocumentService::class)->digitize(
        UploadedFile::fake()->createWithContent('faktura.pdf', PdfFixture::withPages(1)),
        'faktura',
        $user,
    );

    expect($result->get('cislo'))->toBe('FV2026009')
        ->and($result->extractionId())->not->toBeNull();

    $this->assertDatabaseCount('chat_documents', 1);
    $this->assertDatabaseHas('document_extractions', [
        'schema' => 'faktura',
        'user_id' => $user->id,
        'status' => DocumentExtraction::STATUS_SUCCESS,
    ]);
});
