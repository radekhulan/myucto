<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Rozbalená záloha agendy Money S3 (soubor `.lz` = ZIP z funkce „Zálohovat agendu")
 * a přístup k jejím tabulkám.
 *
 * Záloha přichází z uploadu, takže se nerozbaluje naslepo: projde jen `AgendaInfo.ini`
 * a datové soubory `*.DAT` v kořeni a v podadresářích `ROK.nnn`. Cesta v cíli se skládá
 * z rozpoznaných částí jména, ne z cesty uložené v ZIPu — položka typu `../x.dat` nemá
 * kudy vylézt z cílového adresáře. Indexy (`.MDT`, `.MDK`) a šifrované `.s3db` se
 * nerozbalují vůbec: k převodu nejsou potřeba a zbytečně by zabíraly úložiště.
 */
final class Ms3Backup
{
    /** Strop rozbalených dat — obrana proti ZIP bombě. Reálná agenda má jednotky až stovky MB. */
    public const MAX_UNCOMPRESSED_BYTES = 2 * 1024 * 1024 * 1024;

    private const ENTRY_PATTERN = '#^(?:(rok\.\d{3})/)?([a-z0-9_$]{1,40}\.dat|agendainfo\.ini)$#i';

    private string $dir;

    private function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
    }

    public static function extract(string $lzPath, string $targetDir): self
    {
        if (!is_file($lzPath)) {
            throw new MoneyS3Exception('backup_missing', 'Záloha agendy nebyla nalezena.');
        }
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new MoneyS3Exception('storage_not_writable', 'Úložiště pro rozbalení zálohy není zapisovatelné.', [], 500);
        }
        $zip = new \ZipArchive();
        if ($zip->open($lzPath) !== true) {
            throw new MoneyS3Exception('backup_not_zip', 'Soubor není záloha agendy Money S3 (očekává se .lz, tedy ZIP).');
        }
        try {
            $plan = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = str_replace('\\', '/', (string) $stat['name']);
                if (preg_match(self::ENTRY_PATTERN, $name, $m) !== 1) {
                    continue;
                }
                $sub = ($m[1] ?? '') !== '' ? strtoupper($m[1]) : '';
                $plan[] = [$i, $sub, $m[2]];
                $total += (int) $stat['size'];
            }
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new MoneyS3Exception('backup_too_large', 'Záloha je po rozbalení příliš velká.');
            }
            $written = 0;
            foreach ($plan as [$index, $sub, $file]) {
                $dir = $sub === '' ? $targetDir : $targetDir . DIRECTORY_SEPARATOR . $sub;
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new MoneyS3Exception('storage_not_writable', 'Úložiště pro rozbalení zálohy není zapisovatelné.', [], 500);
                }
                $in = $zip->getStream((string) $zip->getNameIndex($index));
                $out = @fopen($dir . DIRECTORY_SEPARATOR . $file, 'wb');
                if ($in === false || $out === false) {
                    throw new MoneyS3Exception('backup_corrupted', 'Zálohu agendy se nepodařilo rozbalit.');
                }
                // Skutečně zapsané bajty se počítají zvlášť — velikost v hlavičce ZIPu
                // je údaj od odesílatele, ne záruka.
                $copied = stream_copy_to_stream($in, $out, self::MAX_UNCOMPRESSED_BYTES - $written + 1);
                fclose($in);
                fclose($out);
                $written += (int) $copied;
                if ($written > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new MoneyS3Exception('backup_too_large', 'Záloha je po rozbalení příliš velká.');
                }
            }
        } finally {
            $zip->close();
        }
        $backup = new self($targetDir);
        if ($backup->yearDirs() === [] && $backup->table('Agenda') === null) {
            throw new MoneyS3Exception('backup_not_agenda', 'Archiv neobsahuje data agendy Money S3 (chybí adresáře ROK.nnn).');
        }
        return $backup;
    }

    public static function open(string $dir): self
    {
        if (!is_dir($dir)) {
            throw new MoneyS3Exception('backup_missing', 'Rozbalená záloha agendy nebyla nalezena.');
        }
        return new self($dir);
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * `AgendaInfo.ini` (CP1250): název agendy, IČO, verze Money, datum zálohy.
     *
     * @return array{name:string,ico:string,version:string,backup_at:string}
     */
    public function agendaInfoIni(): array
    {
        $out = ['name' => '', 'ico' => '', 'version' => '', 'backup_at' => ''];
        $path = $this->dir . DIRECTORY_SEPARATOR . 'AgendaInfo.ini';
        if (!is_file($path)) {
            foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.[iI][nN][iI]') ?: [] as $candidate) {
                if (strcasecmp(basename($candidate), 'AgendaInfo.ini') === 0) {
                    $path = $candidate;
                }
            }
        }
        if (!is_file($path)) {
            return $out;
        }
        $txt = (string) @iconv('CP1250', 'UTF-8//TRANSLIT', (string) file_get_contents($path));
        foreach (preg_split('/\R/', $txt) ?: [] as $line) {
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = self::foldKey(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            match ($key) {
                'nazev' => $out['name'] = $val,
                'ico' => $out['ico'] = preg_replace('/\s+/', '', $val) ?? $val,
                'version', 'verze' => $out['version'] = $val,
                'datum' => $out['backup_at'] = $val,
                default => null,
            };
        }
        return $out;
    }

    /**
     * Identita vlastní firmy, jak ji má Money v `Agenda.DAT` (sekce „Údaje o firmě",
     * řádky Variable/Value). Prázdné pole = Money ho nemá vyplněné.
     *
     * @return array{name:string,street:string,city:string,zip:string,country:string,ico:string,dic:string}
     */
    public function agendaCompany(): array
    {
        $map = ['nazev' => 'name', 'ulice' => 'street', 'misto' => 'city', 'psc' => 'zip',
            'kod statu' => 'country', 'ico' => 'ico', 'dic' => 'dic'];
        $out = ['name' => '', 'street' => '', 'city' => '', 'zip' => '', 'country' => '', 'ico' => '', 'dic' => ''];
        $table = $this->table('Agenda');
        if ($table === null || !$table->hasData()) {
            return $out;
        }
        foreach ($table->rows() as $r) {
            if (self::foldKey((string) ($r['Section1'] ?? '')) !== 'udaje o firme') {
                continue;
            }
            $key = $map[self::foldKey((string) ($r['Variable'] ?? ''))] ?? null;
            if ($key !== null) {
                $out[$key] = trim((string) ($r['Value'] ?? ''));
            }
        }
        return $out;
    }

    /**
     * Účetní roky v záloze: podadresáře ROK.001, ROK.002, …
     *
     * @return list<string>
     */
    public function yearDirs(): array
    {
        $dirs = [];
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $d) {
            if (preg_match('/^rok\.\d{3}$/i', basename($d)) === 1) {
                $dirs[] = $d;
            }
        }
        sort($dirs, SORT_NATURAL);
        return $dirs;
    }

    /**
     * Money míchá casing názvů souborů (UcDenik.DAT vs UCDENIK.DAT), tak se
     * hledá case-insensitive — na Linuxu by přesný název selhal.
     */
    public function table(string $name, ?string $inDir = null): ?Ms3Table
    {
        $base = $inDir ?? $this->dir;
        foreach (glob($base . DIRECTORY_SEPARATOR . '*.[Dd][Aa][Tt]') ?: [] as $path) {
            if (strcasecmp(pathinfo($path, PATHINFO_FILENAME), $name) !== 0) {
                continue;
            }
            if (!Ms3Table::isMs3Table($path)) {
                return null;
            }
            return Ms3Table::open($path);
        }
        return null;
    }

    /**
     * Všechny řádky tabulky ze všech účetních roků, s rokem-adresářem u každého řádku
     * (`__dir`). Tabulky vázané na rok (doklady, deník) jsou v každém ROK.nnn zvlášť.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public function rowsAcrossYears(string $name): \Generator
    {
        foreach ($this->yearDirs() as $dir) {
            $table = $this->table($name, $dir);
            if ($table === null || !$table->hasData()) {
                continue;
            }
            foreach ($table->rows() as $row) {
                $row['__dir'] = basename($dir);
                yield $row;
            }
        }
    }

    /** Klíč z INI / Agenda.DAT bez diakritiky a velikosti písmen („Název" → „nazev"). */
    private static function foldKey(string $key): string
    {
        $key = mb_strtolower(trim($key));
        return strtr($key, [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n',
            'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        ]);
    }
}
