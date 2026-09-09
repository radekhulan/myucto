<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vendorované zdroje pinované otiskem musí mít v `.gitattributes` zamčené konce řádků.
 *
 * ## Proč to hlídat
 *
 * Několik adresářů drží stažené úřední soubory (XSD schémata, číselníky, sazby) a vedle
 * nich `SHA256SUMS` s otiskem každého z nich. Otisk je nad BAJTY, takže jakmile git
 * soubor cestou převede na jiné konce řádků, přestane sedět.
 *
 * S `core.autocrlf=true` (běžné nastavení na Windows) se to stane tiše při checkoutu:
 * v repozitáři zůstane LF, v pracovní kopii je CRLF a integritní test hlásí rozchod
 * otisku — přitom se nezměnil obsah, jen konce řádků. Na Linuxovém CI přitom projde,
 * takže to vypadá jako lokální šum a snadno se to odbyde.
 *
 * Vyřešené je to per adresář pravidlem v `.gitattributes`. Jenže „nezapomeň přidat
 * pravidlo" není obrana — u `accident-insurance-rates` se na to zapomnělo jako u
 * čtvrtého pinovaného adresáře v řadě. Proto to hlídá test: nový pinovaný adresář
 * bez pravidla neprojde, ať ho přidá kdokoli.
 *
 * ## Co se kontroluje
 *
 * 1. Každý `SHA256SUMS` v repozitáři sedí na skutečný obsah pracovní kopie. To je ta
 *    vlastní vlastnost, na které záleží — chytí i jinou příčinu než konce řádků.
 * 2. Sám `SHA256SUMS` i všechny jím pinované soubory mají v `.gitattributes` výslovné
 *    pravidlo (`text eol=lf`, nebo `-text` u binárních a těch, co si CRLF nesou z
 *    originálu). Bez pravidla je bod 1 zelený jen náhodou, podle platformy.
 */
#[Group('architecture')]
final class PinnedResourceEolGuardTest extends TestCase
{
    public function testEveryPinnedFileMatchesItsChecksum(): void
    {
        $manifests = $this->manifests();
        self::assertNotSame([], $manifests, 'Guard by bez manifestů nekontroloval nic.');

        foreach ($manifests as $manifest) {
            foreach ($this->pinnedFiles($manifest) as $path => $expected) {
                self::assertFileExists($path);
                self::assertSame(
                    $expected,
                    hash_file('sha256', $path),
                    "Otisk {$path} nesedí. Bývá to CRLF v pracovní kopii — zkontroluj "
                    . 'pravidlo v .gitattributes a soubor si znovu vytáhni z gitu.',
                );
            }
        }
    }

    public function testEveryPinnedFileHasExplicitEolRule(): void
    {
        $paths = [];
        foreach ($this->manifests() as $manifest) {
            array_push($paths, $manifest, ...array_keys($this->pinnedFiles($manifest)));
        }
        $attributes = $this->gitAttributes($paths);
        foreach ($this->manifests() as $manifest) {
            foreach ([$manifest, ...array_keys($this->pinnedFiles($manifest))] as $path) {
                $attrs = $attributes[$this->relative($path)];
                self::assertNotSame(
                    'unspecified',
                    $attrs['text'],
                    'Pinovaný soubor ' . $this->relative($path) . ' nemá v .gitattributes '
                    . 'zamčené konce řádků. Doplň `text eol=lf` (nebo `-text` u binárních).',
                );
            }
        }
    }

    /** @return list<string> absolutní cesty k SHA256SUMS souborům v repozitáři */
    private function manifests(): array
    {
        static $manifests = null;
        if ($manifests !== null) {
            return $manifests;
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->repoRoot() . '/api', \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $file): bool => !str_contains($file->getPathname(), 'vendor'),
        ));
        foreach ($it as $file) {
            if ($file->getFilename() === 'SHA256SUMS' && !str_contains($file->getPathname(), 'vendor')) {
                $out[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($out);
        return $manifests = $out;
    }

    /**
     * @return array<string,string> absolutní cesta → očekávaný sha256
     */
    private function pinnedFiles(string $manifest): array
    {
        $dir = dirname($manifest);
        $out = [];
        foreach (file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/\A([0-9a-f]{64})\s+(\S.*)\z/D', $line, $m) !== 1) {
                continue;
            }
            $out[$dir . '/' . str_replace('\\', '/', $m[2])] = $m[1];
        }
        return $out;
    }

    /** @return array<string,array{text:string}> */
    private function gitAttributes(array $paths): array
    {
        $process = proc_open(
            ['git', '-C', $this->repoRoot(), 'check-attr', '-z', '--stdin', 'text'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            self::markTestSkipped('git check-attr není dostupný.');
        }
        $relative = array_map($this->relative(...), array_values(array_unique($paths)));
        fwrite($pipes[0], implode("\0", $relative) . "\0");
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            self::markTestSkipped('git check-attr není dostupný: ' . trim($error));
        }
        $fields = explode("\0", rtrim($output, "\0"));
        $attributes = [];
        for ($i = 0; $i + 2 < count($fields); $i += 3) {
            if ($fields[$i + 1] === 'text') {
                $attributes[$fields[$i]] = ['text' => $fields[$i + 2]];
            }
        }
        foreach ($relative as $path) {
            if (!isset($attributes[$path])) {
                self::fail('git check-attr nevrátil atribut pro ' . $path);
            }
        }
        return $attributes;
    }

    private function relative(string $path): string
    {
        $root = $this->repoRoot() . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function repoRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 3));
    }
}
