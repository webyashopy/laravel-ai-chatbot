<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Webyashopy\Chatbot\Chatbot;
use Webyashopy\Chatbot\Contracts\ChatTool;
use Webyashopy\Chatbot\Services\ChatToolRegistry;
use Webyashopy\Chatbot\Tests\Support\HostFixture;

/**
 * Testy self-discovery {@see ChatToolRegistry} (TASK-093, přeneseno z JNS
 * tests/Feature/Ai/ChatToolRegistryTest.php a rozšířeno o konfigurovatelné
 * cesty + explicitní registraci).
 *
 * Fixture nástroje se zapisují do dočasného adresáře, který zastupuje HOST
 * aplikaci (Testbench žádný `App\` PSR-4 prefix nemá) — viz {@see HostFixture}.
 */
beforeEach(function () {
    Chatbot::flush();
    config(['chatbot.chat.tools.enabled' => true]);
});

afterEach(function () {
    Chatbot::flush();
    HostFixture::cleanup();
});

it('najde nástroj v adresáři hosta skenem adresáře', function () {
    $host = HostFixture::make();
    $host->writeTool('FixtureEchoTool', 'fixture_echo');

    config(['chatbot.tools.discover_paths' => [$host->path]]);

    $registry = new ChatToolRegistry;

    expect($registry->all())->toHaveCount(1);

    $found = $registry->get('fixture_echo');

    expect($found)->toBeInstanceOf(ChatTool::class)
        ->and($found->handle([], (object) ['id' => 1]))->toBe(['status' => 'ok'])
        ->and($registry->definitions())->toBe([[
            'name' => 'fixture_echo',
            'description' => 'Testovací echo nástroj.',
            'input_schema' => ['type' => 'object'],
        ]])
        ->and($registry->get('cokoliv-co-urcite-neexistuje'))->toBeNull();
});

/*
 * Klíčová vlastnost DX (ADR-019 §6): přidání nástroje = JEDEN nový soubor.
 * Tento test nesahá na registry, config ani jiný sdílený soubor — jen přidá
 * druhou třídu do téhož adresáře a oba nástroje se objeví. Kdyby někdo
 * discovery nahradil ručním seznamem, test spadne.
 */
it('nový nástroj nevyžaduje editaci žádného sdíleného souboru', function () {
    $host = HostFixture::make();
    config(['chatbot.tools.discover_paths' => [$host->path]]);

    $host->writeTool('FirstTool', 'read_first');

    expect((new ChatToolRegistry)->all())->toHaveCount(1);

    // Druhý task přidá svůj nástroj — jiný soubor, žádná kolize v gitu.
    $host->writeTool('SecondTool', 'read_second');

    $names = array_map(fn (ChatTool $tool): string => $tool->name(), (new ChatToolRegistry)->all());

    expect($names)->toEqualCanonicalizing(['read_first', 'read_second']);
});

it('prohledá i podadresáře a víc nakonfigurovaných cest', function () {
    $first = HostFixture::make();
    $first->writeTool('Read/NestedTool', 'read_nested');

    $second = HostFixture::make();
    $second->writeTool('OtherPathTool', 'read_other');

    config(['chatbot.tools.discover_paths' => [$first->path, $second->path]]);

    $names = array_map(fn (ChatTool $tool): string => $tool->name(), (new ChatToolRegistry)->all());

    expect($names)->toEqualCanonicalizing(['read_nested', 'read_other']);
});

it('neskenuje vendor/ ani když je taková cesta v configu', function () {
    $vendor = base_path('vendor');

    // Úklid řeší afterEach (i při pádu testu). `vendor` mažeme celý jen tehdy,
    // když ho vytvořil tenhle test — jinak bychom smazali cizí obsah.
    $host = HostFixture::in($vendor.'/acme/package/Tools', File::isDirectory($vendor) ? null : $vendor);
    $class = $host->writeTool('VendorTool', 'vendor_tool');

    config(['chatbot.tools.discover_paths' => [$host->path]]);

    // Kontrola, že test není vakuózní: třída JE autoloadovatelná a JE nástroj,
    // takže jediný důvod, proč ji registr nevidí, je vendor guard.
    expect(class_exists($class))->toBeTrue()
        ->and(is_subclass_of($class, ChatTool::class))->toBeTrue();

    $registry = new ChatToolRegistry;

    expect($registry->all())->toBe([])
        ->and($registry->get('vendor_tool'))->toBeNull();
});

/*
 * Regrese: guard na kořeni cesty nestačí. Když host nakonfiguruje NADŘAZENOU
 * cestu (typicky base_path()), leží `vendor/` uvnitř ní a rekurzivní sken by
 * cizí balíčky pohltil — balíček by instancioval třídy z cizích balíčků
 * (porušení ADR-019 §6).
 */
it('nadřazená cesta nevytáhne nástroj z vendor/', function () {
    $vendor = base_path('vendor');

    $host = HostFixture::in($vendor.'/acme/evil/Tools', File::isDirectory($vendor) ? null : $vendor);
    $class = $host->writeTool('EvilTool', 'evil_tool');

    // Host omylem nakonfiguruje kořen aplikace — vendor/ je jeho podadresář.
    config(['chatbot.tools.discover_paths' => [base_path()]]);

    // Kontrola, že test není vakuózní: třída JE autoloadovatelná a JE nástroj.
    expect(class_exists($class))->toBeTrue()
        ->and(is_subclass_of($class, ChatTool::class))->toBeTrue();

    $registry = new ChatToolRegistry;

    expect($registry->get('evil_tool'))->toBeNull()
        ->and(array_map(fn (ChatTool $tool): string => $tool->name(), $registry->all()))
        ->not->toContain('evil_tool');
});

/*
 * Kontrakt slibuje, že přidání souboru je bezpečná operace. Nástroj, který
 * kontejner neumí sestavit, proto nesmí shodit celý chat — jen sebe.
 */
it('nástroj, který nejde resolvovat, shodí jen sebe', function () {
    $host = HostFixture::make();
    $host->writeTool('HealthyTool', 'read_healthy');
    $host->writeRaw('BrokenTool.php', sprintf(
        <<<'PHP'
            <?php

            namespace %s;

            use Webyashopy\Chatbot\Contracts\ChatTool;

            class BrokenTool implements ChatTool
            {
                // Skalár bez defaultu — kontejner ho neumí resolvovat.
                public function __construct(private string $apiKey) {}

                public function name(): string { return 'read_broken'; }

                public function definition(): array { return ['name' => 'read_broken']; }

                public function handle(array $input, mixed $user): array { return []; }
            }
            PHP,
        rtrim($host->namespace, '\\'),
    ));

    config(['chatbot.tools.discover_paths' => [$host->path]]);

    $names = array_map(fn (ChatTool $tool): string => $tool->name(), (new ChatToolRegistry)->all());

    expect($names)->toBe(['read_healthy']);
});

it('nenajde nic, když nakonfigurovaná cesta neexistuje', function () {
    config(['chatbot.tools.discover_paths' => [sys_get_temp_dir().'/chatbot-neexistujici-cesta']]);

    expect((new ChatToolRegistry)->all())->toBe([]);
});

it('přeskočí soubory, které nejsou nástroje', function () {
    $host = HostFixture::make();
    $host->writeTool('RealTool', 'read_real');
    $host->writeRaw('README.md', 'Tohle není PHP.');
    $host->writeRaw('NotATool.php', sprintf(
        "<?php\n\nnamespace %s;\n\nclass NotATool {}\n",
        rtrim($host->namespace, '\\'),
    ));
    $host->writeRaw('AbstractTool.php', sprintf(
        "<?php\n\nnamespace %s;\n\nabstract class AbstractTool implements \\Webyashopy\\Chatbot\\Contracts\\ChatTool {}\n",
        rtrim($host->namespace, '\\'),
    ));

    config(['chatbot.tools.discover_paths' => [$host->path]]);

    $names = array_map(fn (ChatTool $tool): string => $tool->name(), (new ChatToolRegistry)->all());

    expect($names)->toBe(['read_real']);
});

it('registerTool doplní discovery o třídu mimo prohledávané cesty', function () {
    $host = HostFixture::make();
    $host->writeTool('DiscoveredTool', 'read_discovered');

    $outside = HostFixture::make();
    $manual = $outside->writeTool('ManualTool', 'read_manual');

    // Prohledává se jen první cesta — druhá třída se dostane dovnitř registrací.
    config(['chatbot.tools.discover_paths' => [$host->path]]);
    Chatbot::registerTool($manual);

    $registry = new ChatToolRegistry;
    $names = array_map(fn (ChatTool $tool): string => $tool->name(), $registry->all());

    expect($names)->toEqualCanonicalizing(['read_discovered', 'read_manual'])
        ->and($registry->get('read_manual'))->toBeInstanceOf(ChatTool::class);
});

it('nezduplikuje nástroj registrovaný i nalezený discovery', function () {
    $host = HostFixture::make();
    $class = $host->writeTool('BothWaysTool', 'read_both');

    config(['chatbot.tools.discover_paths' => [$host->path]]);
    Chatbot::registerTool($class);

    expect((new ChatToolRegistry)->all())->toHaveCount(1);
});

it('kill-switch vrátí prázdný registr, i když nástroje existují', function () {
    $host = HostFixture::make();
    $manual = $host->writeTool('DisabledTool', 'fixture_echo');

    config([
        'chatbot.tools.discover_paths' => [$host->path],
        'chatbot.chat.tools.enabled' => false,
    ]);
    Chatbot::registerTool($manual);

    $registry = new ChatToolRegistry;

    expect($registry->all())->toBe([])
        ->and($registry->get('fixture_echo'))->toBeNull()
        ->and($registry->definitions())->toBe([]);
});
