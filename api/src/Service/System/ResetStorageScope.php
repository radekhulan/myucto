<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

/**
 * Které soubory smí `reset.php` smazat. Soubory firem leží ve `storage/<oblast>/sup-N`
 * (u přijatých faktur `supplier-N`), jenže `storage/` může sdílet víc databází:
 * na vývojovém stroji bez `MYINVOICE_DATA_DIR` sahají do stejného adresáře všechny
 * instance. Plošné mazání celé oblasti tam smazalo soubory všech databází.
 *
 * Proto reset maže jen adresáře firem z resetované databáze. Adresář firmy, kterou
 * databáze nezná, je „cizí": dokazuje, že úložiště sdílí jiná instance, a pak ani
 * vlastní `sup-N` není jisté (jiná databáze může mít firmu se stejným id). Reset
 * v tom případě soubory nemaže bez výslovného `--force-files`.
 */
final class ResetStorageScope
{
    /**
     * Oblasti úložiště rozdělené po firmách. Soubory v nich patří k dokladům,
     * dokumentům a exportům, které reset maže z databáze.
     *
     * @var list<string>
     */
    public const TENANT_AREAS = [
        'invoices',
        'purchase-invoices',
        'purchase-invoices/sources',
        'documents',
        'journal',
        'archives',
        'work-reports',
        'payroll-documents',
        'payroll-payment-exports',
        'payroll-period-exports',
        'payroll-personnel',
    ];

    /** Loga firem (`sup-N.png` …) se mažou jen s firmou, tedy při úplném resetu. */
    public const LOGO_AREA = 'supplier-logos';

    /**
     * @param list<int> $ownSupplierIds firmy resetované databáze
     * @return array{own: list<string>, foreign: list<string>}
     */
    public static function plan(string $storageRoot, array $ownSupplierIds, bool $includeLogos): array
    {
        $own = array_fill_keys($ownSupplierIds, true);
        $plan = ['own' => [], 'foreign' => []];
        $areas = self::TENANT_AREAS;
        if ($includeLogos) {
            $areas[] = self::LOGO_AREA;
        }
        foreach ($areas as $area) {
            $dir = rtrim($storageRoot, '/\\') . '/' . $area;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                $id = self::supplierIdOf($entry);
                if ($id === null) {
                    continue;
                }
                $plan[isset($own[$id]) ? 'own' : 'foreign'][] = $dir . '/' . $entry;
            }
        }
        return $plan;
    }

    /**
     * Všechny soubory a adresáře jedné firmy v úložišti, bez ohledu na oblast. Firma má
     * soubory i mimo `TENANT_AREAS` a hlouběji než o úroveň pod oblastí
     * (`purchase-invoices/sources/supplier-N`, `signing/profiles/supplier-N`,
     * `abra-flexi/sup-N`), proto se prochází celý strom do tří úrovní. Do adresářů
     * firem (svých i cizích) se nesestupuje.
     *
     * @return list<string>
     */
    public static function supplierPaths(string $storageRoot, int $supplierId, int $maxDepth = 3): array
    {
        $paths = [];
        $walk = static function (string $dir, int $depth) use (&$walk, &$paths, $supplierId, $maxDepth): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                $id = self::supplierIdOf($entry);
                if ($id !== null) {
                    if ($id === $supplierId) {
                        $paths[] = $path;
                    }
                    continue;
                }
                if ($depth < $maxDepth && is_dir($path) && !is_link($path)) {
                    $walk($path, $depth + 1);
                }
            }
        };
        $root = rtrim($storageRoot, '/\\');
        if (is_dir($root)) {
            $walk($root, 1);
        }
        sort($paths);
        return $paths;
    }

    /** `sup-12`, `supplier-12`, `sup-12.png`, `sup-12-brand-3-<hash12>.png` → 12; cokoli jiného null. */
    public static function supplierIdOf(string $entry): ?int
    {
        if (preg_match('/^(?:sup|supplier)-(\d+)(?:-brand-\d+-[a-f0-9]{12})?(?:\.[A-Za-z0-9]+)?$/', $entry, $m) !== 1) {
            return null;
        }
        return (int) $m[1];
    }

    /** Smaže soubor nebo adresář i s obsahem. Vrací počet smazaných souborů. */
    public static function remove(string $path): int
    {
        if (is_file($path) || is_link($path)) {
            return @unlink($path) ? 1 : 0;
        }
        if (!is_dir($path)) {
            return 0;
        }
        $count = 0;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $f) {
            if ($f->isDir() && !$f->isLink()) {
                @rmdir($f->getPathname());
            } elseif (@unlink($f->getPathname())) {
                $count++;
            }
        }
        @rmdir($path);
        return $count;
    }
}
