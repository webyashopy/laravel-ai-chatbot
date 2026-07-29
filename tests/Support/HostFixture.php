<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Tests\Support;

use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Testovací pomocník: simuluje adresář HOST aplikace s PSR-4 namespacem.
 *
 * Discovery balíčku odvozuje namespace z PSR-4 mapy composeru — Testbench
 * ale žádný `App\` prefix registrovaný nemá (skeleton se needituje). Fixture
 * si proto vytvoří vlastní dočasný adresář a zaregistruje ho do composer
 * autoloaderu přesně tak, jak to dělá composer.json reálného hosta.
 *
 * Každá instance má UNIKÁTNÍ namespace i adresář — třídy se v jednom PHP
 * procesu nedají odregistrovat, takže sdílený namespace by mezi testy
 * způsoboval falešné nálezy.
 */
final class HostFixture
{
    private static int $sequence = 0;

    /** @var array<string, string> Adresáře k úklidu po testu. */
    private static array $purge = [];

    private function __construct(
        public readonly string $namespace,
        public readonly string $path,
    ) {}

    /**
     * Vytvoří fixture adresář v systémovém tempu (mimo vendor/).
     */
    public static function make(): self
    {
        $root = sys_get_temp_dir().'/chatbot-host-fixture';

        return self::in($root, $root);
    }

    /**
     * Vytvoří fixture adresář pod zadaným rodičem — použij pro ověření,
     * že sken do `vendor/` nikdy nevleze.
     *
     * @param  string|null  $purgeRoot  Adresář, který se má po testu smazat celý
     *                                  (místo jen vytvořeného podadresáře). Předej
     *                                  jen tehdy, když ho vytvořil test — jinak
     *                                  `null`, ať se nesmaže cizí obsah.
     */
    public static function in(string $parent, ?string $purgeRoot = null): self
    {
        $id = ++self::$sequence.substr(md5(uniqid('', true)), 0, 8);
        $path = $parent.'/'.$id;

        File::ensureDirectoryExists($path);

        $purgeRoot ??= $path;
        self::$purge[$purgeRoot] = $purgeRoot;

        $namespace = 'HostFixture'.$id.'\\';
        self::loader()->addPsr4($namespace, [$path]);

        return new self($namespace, $path);
    }

    /**
     * Zapíše třídu nástroje ({@see \Webyashopy\Chatbot\Contracts\ChatTool}).
     *
     * @param  string  $className  Např. `EchoTool` nebo `Read/EchoTool` (podadresář).
     * @return class-string Plně kvalifikovaný název zapsané třídy.
     */
    public function writeTool(string $className, string $toolName): string
    {
        return $this->writeClass($className, fn (string $short): string => <<<PHP
                use Webyashopy\\Chatbot\\Contracts\\ChatTool;

                class {$short} implements ChatTool
                {
                    public function name(): string
                    {
                        return '{$toolName}';
                    }

                    public function definition(): array
                    {
                        return [
                            'name' => '{$toolName}',
                            'description' => 'Testovací echo nástroj.',
                            'input_schema' => ['type' => 'object'],
                        ];
                    }

                    public function handle(array \$input, mixed \$user): array
                    {
                        return ['status' => 'ok'];
                    }
                }
                PHP);
    }

    /**
     * Zapíše třídu handleru ({@see \Webyashopy\Chatbot\Contracts\ChatActionHandler}).
     *
     * @return class-string
     */
    public function writeActionHandler(string $className, string $kind): string
    {
        return $this->writeClass($className, fn (string $short): string => <<<PHP
                use Webyashopy\\Chatbot\\Contracts\\ChatActionHandler;
                use Webyashopy\\Chatbot\\Support\\ChatActionResult;

                class {$short} implements ChatActionHandler
                {
                    public function kind(): string
                    {
                        return '{$kind}';
                    }

                    public function confirm(array \$payload, mixed \$user, array \$context = []): ChatActionResult
                    {
                        return ChatActionResult::success('Potvrzeno: {$kind}');
                    }
                }
                PHP);
    }

    /**
     * Zapíše třídu schématu dokumentu ({@see \Webyashopy\Chatbot\Contracts\DocumentSchema}).
     *
     * @return class-string
     */
    public function writeDocumentSchema(string $className, string $schemaName): string
    {
        return $this->writeClass($className, fn (string $short): string => <<<PHP
                use Webyashopy\\Chatbot\\Support\\BaseDocumentSchema;

                class {$short} extends BaseDocumentSchema
                {
                    public function name(): string
                    {
                        return '{$schemaName}';
                    }

                    public function description(): string
                    {
                        return 'Testovací schéma.';
                    }

                    public function jsonSchema(): array
                    {
                        return [
                            'type' => 'object',
                            'properties' => ['cislo' => ['type' => 'string']],
                        ];
                    }
                }
                PHP);
    }

    /**
     * Zapíše libovolný soubor (např. třídu, která kontrakt neimplementuje).
     */
    public function writeRaw(string $relativePath, string $contents): void
    {
        $target = $this->path.'/'.$relativePath;

        File::ensureDirectoryExists(dirname($target));
        File::put($target, $contents);
    }

    /**
     * Smaže všechny fixture adresáře vytvořené v tomto testu.
     *
     * Volá se z `afterEach` — tedy i když test spadne uprostřed. Úklid
     * uvnitř těla testu by se po neúspěšné aserci přeskočil a fixture
     * (např. v `vendor/`) by zůstal ležet dalšímu běhu.
     */
    public static function cleanup(): void
    {
        foreach (self::$purge as $path) {
            File::deleteDirectory($path);
        }

        self::$purge = [];
    }

    /**
     * @param  callable(string): string  $body  Tělo souboru bez `<?php` a `namespace`.
     * @return class-string
     */
    private function writeClass(string $className, callable $body): string
    {
        $className = trim($className, '/');
        $short = basename($className);
        $subNamespace = str_replace('/', '\\', dirname($className));
        $namespace = rtrim($this->namespace, '\\').($subNamespace === '.' ? '' : '\\'.$subNamespace);

        $this->writeRaw($className.'.php', "<?php\n\nnamespace {$namespace};\n\n".$body($short)."\n");

        /** @var class-string $fqcn */
        $fqcn = $namespace.'\\'.$short;

        return $fqcn;
    }

    /**
     * Composer autoloader registrovaný v tomto procesu.
     */
    private static function loader(): ClassLoader
    {
        foreach (spl_autoload_functions() ?: [] as $autoloader) {
            if (is_array($autoloader) && ($autoloader[0] ?? null) instanceof ClassLoader) {
                return $autoloader[0];
            }
        }

        throw new RuntimeException('Composer ClassLoader není registrovaný.');
    }
}
