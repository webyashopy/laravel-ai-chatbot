<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webyashopy\Chatbot\Exceptions\DocumentTooLargeException;
use Webyashopy\Chatbot\Exceptions\UnsupportedDocumentException;
use Webyashopy\Chatbot\Models\ChatDocument;
use Webyashopy\Chatbot\Services\DocumentService;
use Webyashopy\Chatbot\Tests\Support\PdfFixture;
use Webyashopy\Chatbot\Tests\Stubs\User;

/**
 * Testy ukládání dokumentů ({@see DocumentService::store()}) — validace
 * typu z obsahu, limity a deduplikace podle SHA-256.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    config([
        'chatbot.user_model' => User::class,
        'chatbot.documents.disk' => 'local',
        'chatbot.documents.path' => 'chatbot/documents',
    ]);
});

it('uloží PDF, zjistí počet stran a spočítá hash', function () {
    $contents = PdfFixture::withPages(3);
    $file = UploadedFile::fake()->createWithContent('faktura.pdf', $contents);

    $document = app(DocumentService::class)->store($file);

    expect($document->mime_type)->toBe('application/pdf')
        ->and($document->pages)->toBe(3)
        ->and($document->original_name)->toBe('faktura.pdf')
        ->and($document->size_bytes)->toBe(strlen($contents))
        ->and($document->sha256)->toBe(hash('sha256', $contents));

    Storage::disk('local')->assertExists($document->path);
});

it('u obrázku nechá počet stran prázdný', function () {
    $file = UploadedFile::fake()->image('doklad.png', 40, 40);

    $document = app(DocumentService::class)->store($file);

    expect($document->mime_type)->toBe('image/png')
        ->and($document->pages)->toBeNull();
});

it('nevynucuje limit stran u PDF, kde počet stran nejde zjistit', function () {
    config(['chatbot.documents.max_pages' => 1]);

    $file = UploadedFile::fake()->createWithContent('sken.pdf', PdfFixture::withUnknownPageCount());

    $document = app(DocumentService::class)->store($file);

    expect($document->pages)->toBeNull();
});

it('stejný soubor od téhož uživatele neuloží dvakrát', function () {
    config(['chatbot.user_model' => User::class]);

    $user = User::create(['name' => 'Tester', 'email' => 'dedup@example.test']);
    $contents = PdfFixture::withPages(1);

    $service = app(DocumentService::class);

    $first = $service->store(UploadedFile::fake()->createWithContent('a.pdf', $contents), $user);
    $second = $service->store(UploadedFile::fake()->createWithContent('b.pdf', $contents), $user);

    expect($second->id)->toBe($first->id);

    $this->assertDatabaseCount('chat_documents', 1);
});

it('týž soubor od jiného uživatele uloží zvlášť', function () {
    $contents = PdfFixture::withPages(1);
    $service = app(DocumentService::class);

    $prvni = User::create(['name' => 'A', 'email' => 'a@example.test']);
    $druhy = User::create(['name' => 'B', 'email' => 'b@example.test']);

    $service->store(UploadedFile::fake()->createWithContent('x.pdf', $contents), $prvni);
    $service->store(UploadedFile::fake()->createWithContent('x.pdf', $contents), $druhy);

    $this->assertDatabaseCount('chat_documents', 2);
});

it('odmítne soubor, jehož obsah neodpovídá povolenému typu', function () {
    // Přípona `.pdf`, ale obsah je prostý text — finfo pozná rozdíl.
    $file = UploadedFile::fake()->createWithContent('podvrh.pdf', 'tohle je jen text');

    expect(fn () => app(DocumentService::class)->store($file))
        ->toThrow(UnsupportedDocumentException::class);

    $this->assertDatabaseCount('chat_documents', 0);
});

it('odmítne soubor přes limit velikosti', function () {
    config(['chatbot.documents.max_size_mb' => 1]);

    $contents = PdfFixture::withPages(1).str_repeat('x', 1_100_000);
    $file = UploadedFile::fake()->createWithContent('velka.pdf', $contents);

    expect(fn () => app(DocumentService::class)->store($file))
        ->toThrow(DocumentTooLargeException::class);
});

it('odmítne PDF přes limit stran', function () {
    config(['chatbot.documents.max_pages' => 5]);

    $file = UploadedFile::fake()->createWithContent('kniha.pdf', PdfFixture::withPages(12));

    expect(fn () => app(DocumentService::class)->store($file))
        ->toThrow(DocumentTooLargeException::class, '12 stran');
});

it('smaže dokument i jeho soubor na disku', function () {
    $file = UploadedFile::fake()->createWithContent('faktura.pdf', PdfFixture::withPages(1));

    $service = app(DocumentService::class);
    $document = $service->store($file);
    $path = $document->path;

    $service->delete($document);

    Storage::disk('local')->assertMissing($path);
    expect(ChatDocument::count())->toBe(0);
});
