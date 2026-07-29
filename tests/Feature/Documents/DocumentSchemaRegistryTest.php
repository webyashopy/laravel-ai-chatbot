<?php

declare(strict_types=1);

use Webyashopy\Chatbot\Chatbot;
use Webyashopy\Chatbot\Contracts\DocumentSchema;
use Webyashopy\Chatbot\Services\DocumentSchemaRegistry;
use Webyashopy\Chatbot\Tests\Stubs\Documents\FakturaStubSchema;
use Webyashopy\Chatbot\Tests\Support\HostFixture;

/**
 * Testy self-discovery {@see DocumentSchemaRegistry} — stejná mechanika
 * jako u registru nástrojů, včetně fixture adresáře zastupujícího HOST
 * aplikaci (Testbench žádný `App\` PSR-4 prefix nemá).
 */
beforeEach(function () {
    Chatbot::flush();
    config([
        'chatbot.features.documents' => true,
        'chatbot.documents.schemas.discover_paths' => [],
    ]);
});

afterEach(function () {
    Chatbot::flush();
    HostFixture::cleanup();
});

it('najde schéma v adresáři hosta skenem adresáře', function () {
    $host = HostFixture::make();
    $host->writeDocumentSchema('DodaciListSchema', 'dodaci_list');

    config(['chatbot.documents.schemas.discover_paths' => [$host->path]]);

    $registry = new DocumentSchemaRegistry;

    expect($registry->all())->toHaveCount(1)
        ->and($registry->get('dodaci_list'))->toBeInstanceOf(DocumentSchema::class)
        ->and($registry->names())->toBe(['dodaci_list']);
});

it('vezme i explicitně registrované schéma mimo prohledávané cesty', function () {
    Chatbot::registerDocumentSchema(FakturaStubSchema::class);

    $registry = new DocumentSchemaRegistry;

    expect($registry->has('faktura'))->toBeTrue()
        ->and($registry->options())->toBe(['faktura' => 'Faktura přijatá']);
});

it('opakovaná registrace téhož schématu je no-op', function () {
    Chatbot::registerDocumentSchema(FakturaStubSchema::class);
    Chatbot::registerDocumentSchema(FakturaStubSchema::class);

    expect(Chatbot::registeredDocumentSchemas())->toHaveCount(1)
        ->and((new DocumentSchemaRegistry)->all())->toHaveCount(1);
});

it('odmítne třídu, která kontrakt neimplementuje', function () {
    expect(fn () => Chatbot::registerDocumentSchema(stdClass::class))
        ->toThrow(InvalidArgumentException::class);
});

it('vypnutá feature vrátí prázdný registr', function () {
    Chatbot::registerDocumentSchema(FakturaStubSchema::class);

    config(['chatbot.features.documents' => false]);

    $registry = new DocumentSchemaRegistry;

    expect($registry->all())->toBe([])
        ->and($registry->get('faktura'))->toBeNull()
        ->and($registry->names())->toBe([]);
});

it('nesestavitelné schéma přeskočí místo pádu', function () {
    $host = HostFixture::make();
    $namespace = rtrim($host->namespace, '\\');

    // Konstruktor se skalárem bez defaultu — kontejner ho neumí sestavit.
    $host->writeRaw('RozbiteSchema.php', <<<PHP
        <?php

        namespace {$namespace};

        use Webyashopy\\Chatbot\\Support\\BaseDocumentSchema;

        class RozbiteSchema extends BaseDocumentSchema
        {
            public function __construct(private string \$neco) {}

            public function name(): string
            {
                return 'rozbite';
            }

            public function description(): string
            {
                return 'Nesestavitelné schéma.';
            }

            public function jsonSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }
        }
        PHP);

    config(['chatbot.documents.schemas.discover_paths' => [$host->path]]);

    expect((new DocumentSchemaRegistry)->all())->toBe([]);
});
