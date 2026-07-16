<?php

declare(strict_types=1);

namespace Webyashopy\Chatbot\Support;

use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use SplFileInfo;

/**
 * Vyhledávač tříd v adresářích HOST aplikace (self-discovery).
 *
 * Prohledá zadané adresáře (rekurzivně) a vrátí názvy tříd, které
 * implementují požadovaný kontrakt. Nahrazuje ruční seznam v configu
 * (ADR-019, zamítnutá alternativa 4): přidání nové třídy = nový soubor
 * v prohledávaném adresáři, BEZ editace jakéhokoli sdíleného souboru —
 * dva tasky tak mohou přidávat nástroje souběžně bez kolize v gitu.
 *
 * Namespace se neodvozuje natvrdo (jako v původní registry v JNS), ale
 * z PSR-4 mapy composeru — balíček tím nezná strukturu hosta a funguje
 * i pro `App\`, `Domain\` nebo jinak namapované adresáře.
 *
 * Sken běží VÝHRADNĚ nad adresáři hosta. `vendor/` se přeskakuje (ADR-019 §6)
 * — balíček nesmí instanciovat cizí třídy z cizích balíčků. Guard je DVOJITÝ:
 * na kořeni nakonfigurované cesty (varování do logu, že config je špatně)
 * a znovu nad každým NALEZENÝM souborem — `vendor/` totiž může být podadresář
 * nakonfigurované cesty (typicky `discover_paths => [base_path()]`), kdy by
 * kořenový guard neplatil a rekurzivní sken by cizí balíčky pohltil.
 */
final class HostClassLocator
{
    /**
     * PSR-4 mapa composeru: absolutní realpath adresáře => namespace prefix.
     * Cache pro tuto instanci — mapa se za běhu requestu nemění.
     *
     * @var array<string, string>|null
     */
    private ?array $psr4 = null;

    /**
     * Normalizovaný realpath `vendor/` hosta; `false` = adresář neexistuje.
     * Cache pro tuto instanci (vyhodnocuje se pro každý nalezený soubor).
     */
    private string|false|null $vendorPath = null;

    /**
     * Najde třídy implementující `$contract` v zadaných adresářích.
     *
     * @param  array<int, mixed>  $paths  Adresáře hosta (config `*.discover_paths`).
     * @param  class-string  $contract
     * @return array<int, class-string>
     */
    public function locate(array $paths, string $contract): array
    {
        /** @var array<string, class-string> $classes */
        $classes = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '' || ! File::isDirectory($path)) {
                continue;
            }

            if ($this->isInsideVendor($path)) {
                Log::warning('Chatbot: discovery cesta uvnitř vendor/ se přeskakuje.', [
                    'path' => $path,
                ]);

                continue;
            }

            foreach (File::allFiles($path) as $file) {
                // Druhý guard: `vendor/` může ležet POD nakonfigurovanou cestou.
                if ($this->isInsideVendor((string) $file->getRealPath())) {
                    continue;
                }

                $class = $this->classFromFile($file);

                if ($class === null || ! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if (! $reflection->isInstantiable() || ! $reflection->implementsInterface($contract)) {
                    continue;
                }

                $classes[$class] = $class;
            }
        }

        return array_values($classes);
    }

    /**
     * Odvodí plně kvalifikovaný název třídy ze souboru podle PSR-4 mapy.
     *
     * Vrátí `null`, pokud soubor není PHP nebo neleží pod žádným PSR-4
     * prefixem (takový soubor by stejně nešel autoloadovat).
     *
     * @return class-string|null
     */
    private function classFromFile(SplFileInfo $file): ?string
    {
        if ($file->getExtension() !== 'php') {
            return null;
        }

        $realPath = $file->getRealPath();

        if ($realPath === false) {
            return null;
        }

        $realPath = $this->normalize($realPath);
        $best = null;
        $bestLength = -1;

        // Nejdelší shoda vyhrává — vnořený prefix (App\Domain\ => app/Domain)
        // musí přebít obecnější (App\ => app).
        foreach ($this->psr4() as $directory => $prefix) {
            if (! str_starts_with($realPath, $directory.'/')) {
                continue;
            }

            if (strlen($directory) > $bestLength) {
                $best = [$directory, $prefix];
                $bestLength = strlen($directory);
            }
        }

        if ($best === null) {
            return null;
        }

        [$directory, $prefix] = $best;

        $relative = substr($realPath, strlen($directory) + 1, -4);   // bez vedoucího '/' a '.php'

        /** @var class-string $class */
        $class = $prefix.str_replace('/', '\\', $relative);

        return $class;
    }

    /**
     * PSR-4 mapa registrovaného composer autoloaderu (adresář => prefix).
     *
     * @return array<string, string>
     */
    private function psr4(): array
    {
        if ($this->psr4 !== null) {
            return $this->psr4;
        }

        $map = [];

        foreach (spl_autoload_functions() ?: [] as $autoloader) {
            if (! is_array($autoloader) || ! ($autoloader[0] ?? null) instanceof ClassLoader) {
                continue;
            }

            foreach ($autoloader[0]->getPrefixesPsr4() as $prefix => $directories) {
                foreach ($directories as $directory) {
                    $real = realpath($directory);

                    if ($real !== false) {
                        $map[$this->normalize($real)] = $prefix;
                    }
                }
            }
        }

        return $this->psr4 = $map;
    }

    /**
     * Leží cesta (adresář i soubor) uvnitř `vendor/` host aplikace?
     */
    private function isInsideVendor(string $path): bool
    {
        $vendor = $this->vendorPath();
        $real = $path === '' ? false : realpath($path);

        if ($vendor === false || $real === false) {
            return false;
        }

        return str_starts_with($this->normalize($real).'/', $vendor.'/');
    }

    /**
     * Normalizovaný realpath `vendor/` hosta (cache pro tuto instanci).
     */
    private function vendorPath(): string|false
    {
        if ($this->vendorPath !== null) {
            return $this->vendorPath;
        }

        $vendor = realpath(base_path('vendor'));

        return $this->vendorPath = $vendor === false ? false : $this->normalize($vendor);
    }

    /**
     * Sjednotí oddělovače cest (Windows host) na `/`.
     */
    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
