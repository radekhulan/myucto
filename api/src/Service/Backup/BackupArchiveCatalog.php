<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup;

use DateTimeImmutable;
use FilesystemIterator;
use MyInvoice\Infrastructure\Config\Config;

/**
 * Čtecí pohled na adresář se zálohami ({@see BackupLocation}) pro stránku
 * „Stažení záloh".
 *
 * Zálohy vyrábí čtveřice cronů a každý si drží vlastní jmennou konvenci:
 *
 *   {db}-RRRR-MM-DD_HH-MM.zip             cron-backup.php            (databáze)
 *   {db}-pdf-RRRR-MM-DD_HH-MM.zip         cron-backup-pdf.php        (PDF doklady)
 *   {db}-documents-RRRR-MM-DD_HH-MM.zip   cron-backup-documents.php  (dokumenty a přílohy)
 *   {db}-payroll-RRRR-MM-DD_HH-MM.zip     cron-backup-payroll.php    (mzdové podklady)
 *
 * Rozdělení do sekcí se proto počítá z tvaru názvu. Prefix `{db}` se záměrně
 * nesrovnává s aktuálním `db.name`: je to jméno databáze v době zálohy, a po
 * přejmenování databáze nebo lokálním přepnutí na jinou (cfg.local.php) by jinak
 * všechny automatické zálohy spadly do `other`. Co konvenci neodpovídá (ruční
 * kopie bez data v názvu, cizí export) se NEZAHAZUJE — skončí v sekci `other`.
 * Tichý filtr by byl horší než nepřehledný seznam: soubor, který v adresáři je
 * a v UI ne, vypadá jako ztracená záloha.
 *
 * Skryté soubory (tečkou začínající) jsou pracovní pozůstatky cronů — rozdělaný
 * `.{db}-{date}.sql`, `.dump.cnf` s heslem k databázi, `.last-error`. Ty ven
 * nesmí ani vyjmenované.
 */
final class BackupArchiveCatalog
{
    public const KIND_DATABASE  = 'database';
    public const KIND_PDF       = 'pdf';
    public const KIND_DOCUMENTS = 'documents';
    public const KIND_PAYROLL   = 'payroll';
    public const KIND_OTHER     = 'other';

    /** Pořadí sekcí v UI — od nejmenší a nejdůležitější (databáze) po zbytek. */
    public const KINDS = [
        self::KIND_DATABASE,
        self::KIND_DOCUMENTS,
        self::KIND_PDF,
        self::KIND_PAYROLL,
        self::KIND_OTHER,
    ];

    /** Přípony, které považujeme za zálohu. Vše ostatní v adresáři ignorujeme. */
    private const EXTENSIONS = ['.zip', '.sql.gz', '.sql', '.gz'];

    /**
     * Kolik nejnovějších záloh sekce nabízí. Retence jich drží víc (při 4× denním
     * dumpu desítky), ke stažení ale člověk sahá po čerstvé záloze.
     */
    public const LATEST_PER_KIND = 5;

    /** `{db}-[infix-]RRRR-MM-DD[_HH-MM].zip`; `.sql.gz` jsou dumpy z doby před ZIPy. */
    private const CRON_NAME = '/^.+?-(?:(pdf|documents|payroll)-)?\d{4}-\d{2}-\d{2}(?:_\d{2}-\d{2})?\.(?:zip|sql\.gz)$/i';

    /** Infix v názvu → sekce. */
    private const INFIX_KINDS = [
        'pdf'       => self::KIND_PDF,
        'documents' => self::KIND_DOCUMENTS,
        'payroll'   => self::KIND_PAYROLL,
    ];

    public function __construct(private readonly Config $config) {}

    public function directory(): string
    {
        return BackupLocation::resolve($this->config);
    }

    public function exists(): bool
    {
        return is_dir($this->directory());
    }

    /**
     * Všechny zálohy v adresáři, od nejnovější.
     *
     * @return list<array{name:string,kind:string,size_bytes:int,modified_at:string,taken_at:?string}>
     */
    public function list(): array
    {
        $dir = $this->directory();
        if (!is_dir($dir)) {
            return [];
        }

        $items = [];
        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $name = $entry->getFilename();
            if (str_starts_with($name, '.') || !self::hasBackupExtension($name)) {
                continue;
            }
            $mtime = (int) $entry->getMTime();
            $items[] = [
                'name'        => $name,
                'kind'        => self::classify($name),
                'size_bytes'  => (int) $entry->getSize(),
                'modified_at' => (new DateTimeImmutable('@' . $mtime))
                    ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s'),
                'taken_at'    => self::timestampFromName($name),
            ];
        }

        // Řadí se podle času z NÁZVU, ne podle mtime: kopírování adresáře (migrace
        // na jiný server, obnova ze zálohy zálohy) přepíše mtime všem souborům
        // najednou a pořadí by pak bylo náhodné. Fallback na mtime drží i soubory
        // mimo konvenci.
        usort($items, static fn (array $a, array $b): int
            => ($b['taken_at'] ?? $b['modified_at']) <=> ($a['taken_at'] ?? $a['modified_at']));

        return $items;
    }

    /**
     * Zálohy rozdělené do sekcí, prázdné sekce vynechané. Každá sekce nese jen
     * `$latest` nejnovějších souborů; `total_files` a `size_bytes` popisují celou
     * sekci, ať je vidět, kolik místa zálohy na disku skutečně zabírají.
     *
     * @return list<array{kind:string,files:list<array<string,mixed>>,total_files:int,size_bytes:int}>
     */
    public function sections(int $latest = self::LATEST_PER_KIND): array
    {
        $byKind = [];
        foreach ($this->list() as $file) {
            $byKind[$file['kind']][] = $file;
        }

        $sections = [];
        foreach (self::KINDS as $kind) {
            if (!isset($byKind[$kind])) {
                continue;
            }
            $sections[] = [
                'kind'        => $kind,
                'files'       => array_slice($byKind[$kind], 0, max(1, $latest)),
                'total_files' => count($byKind[$kind]),
                'size_bytes'  => array_sum(array_column($byKind[$kind], 'size_bytes')),
            ];
        }

        return $sections;
    }

    /**
     * Absolutní cesta k záloze daného názvu, nebo null.
     *
     * Jediná cesta ke stažení. Název se nebere jako cesta: musí projít
     * `basename()` beze změny a musí se shodovat s položkou, kterou katalog sám
     * vylistoval — členství v listingu je silnější důkaz než jakýkoli filtr na
     * podřetězce. Porovnání adresáře je case-insensitive, protože `realpath()`
     * na Windows vrací nekonzistentní velikost písmen.
     */
    public function resolveForDownload(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || $name !== basename($name) || str_contains($name, '..')) {
            return null;
        }

        $known = false;
        foreach ($this->list() as $file) {
            if ($file['name'] === $name) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            return null;
        }

        $dir = realpath($this->directory());
        $abs = realpath($this->directory() . DIRECTORY_SEPARATOR . $name);
        if ($dir === false || $abs === false || !is_file($abs)) {
            return null;
        }
        $dirNorm = rtrim(str_replace('\\', '/', strtolower($dir)), '/') . '/';
        $absNorm = str_replace('\\', '/', strtolower($abs));
        if (!str_starts_with($absNorm, $dirNorm)) {
            return null;
        }

        return $abs;
    }

    private static function hasBackupExtension(string $name): bool
    {
        $lower = strtolower($name);
        foreach (self::EXTENSIONS as $ext) {
            if (str_ends_with($lower, $ext)) {
                return true;
            }
        }

        return false;
    }

    /** Sekce podle tvaru názvu souboru; bez infixu zbývá rovnou datum = dump databáze. */
    private static function classify(string $name): string
    {
        if (preg_match(self::CRON_NAME, $name, $m) !== 1) {
            return self::KIND_OTHER;
        }

        return self::INFIX_KINDS[strtolower($m[1] ?? '')] ?? self::KIND_DATABASE;
    }

    /** `…-RRRR-MM-DD[_HH-MM].ext` → `Y-m-d H:i:s`, jinak null. */
    private static function timestampFromName(string $name): ?string
    {
        if (preg_match('/-(\d{4}-\d{2}-\d{2})(?:_(\d{2})-(\d{2}))?\./', $name, $m) !== 1) {
            return null;
        }

        return $m[1] . ' ' . ($m[2] ?? '00') . ':' . ($m[3] ?? '00') . ':00';
    }
}
