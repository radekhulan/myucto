<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use PDO;

/**
 * Rozvržení zálohy mzdového úložiště.
 *
 * Mzdové soubory nespadaly do žádné ze stávajících záloh: `cron-backup.php`
 * dělá jen dump databáze, `cron-backup-documents.php` bere `storage/documents`
 * a `storage/journal` a `cron-backup-pdf.php` faktury a výkazy práce. Výplatní
 * pásky, měsíční archivy a soubory platebních příkazů tak existovaly **jen na
 * disku**. Po obnově ze zálohy by databáze měla všechna metadata včetně otisků,
 * ale obsah k nim ne — a jsou to zrovna dokumenty se zákonnou archivační
 * lhůtou.
 *
 * **Proč se do ZIPu ukládají původní cesty, a ne čitelné názvy** (na rozdíl od
 * {@see ReadableDocumentArchiveLayout}): mzdové soubory jsou šifrované a
 * obsahově adresované. Čitelný název by z nich neudělal nic, co jde otevřít —
 * dešifrovat je stejně umí jen aplikace s odpovídající databází — zato by
 * rozbil obnovu, protože aplikace hledá obsah přesně pod otiskem. Záloha se
 * proto rozbaluje jedna ku jedné do `storage/` a člověku slouží `MANIFEST.csv`,
 * kde je u každého otisku napsáno, co to je.
 *
 * Nedešifrovat je i věcné rozhodnutí: čitelná záloha výplatních pásek by byla
 * hromada osobních údajů v jednom souboru, u kterého nikdo nehlídá, kde leží.
 */
final class PayrollBackupArchiveLayout
{
    /**
     * Kořeny mzdového úložiště. Klíč je zároveň prefix uvnitř ZIPu, takže
     * rozbalením do `storage/` vznikne přesně původní rozložení.
     */
    private const ROOTS = [
        'payroll-documents',
        'payroll-period-exports',
        'payroll-payment-exports',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Soubory k zálohování.
     *
     * @return list<array{source:string,entry:string}>
     */
    public function all(): array
    {
        $files = [];
        foreach (self::ROOTS as $root) {
            $base = RuntimePaths::storage($root);
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $base,
                    \FilesystemIterator::SKIP_DOTS,
                ),
            );
            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                    continue;
                }
                $name = $item->getFilename();
                // Rozepsané soubory se do zálohy nesmí dostat: nesedí na otisk
                // a při obnově by je aplikace odmítla jako porušené.
                if (str_starts_with($name, '.tmp-') || str_ends_with($name, '.tmp')) {
                    continue;
                }
                $relative = str_replace(
                    '\\',
                    '/',
                    substr($item->getPathname(), strlen($base) + 1),
                );
                $files[] = [
                    'source' => $item->getPathname(),
                    'entry' => $root . '/' . $relative,
                ];
            }
        }
        usort(
            $files,
            static fn (array $a, array $b): int => strcmp($a['entry'], $b['entry']),
        );

        return $files;
    }

    /**
     * Manifest pro člověka: co se pod kterým otiskem skrývá.
     *
     * Bez něj je záloha neprůhledná — samé šedesátičtyřznakové názvy. Řádky
     * bez metadat se nevynechávají, jen se označí `neznamy`: soubor, který
     * databáze nezná, je sám o sobě nález a zamlčet ho by bylo horší.
     */
    public function manifestCsv(): string
    {
        /*
         * Popisy se hledají PODLE KOŘENE, ne jen podle otisku. Týž otisk se
         * legitimně objeví ve víc kořenech — archiv období si zmrazí kopii
         * pásky i platebního příkazu — a párování napříč kořeny by pak
         * o části archivu tvrdilo, že je to páska.
         */
        $dokumenty = $this->documentDescriptions();
        $obdobi = $this->periodExportDescriptions();
        $platby = $this->paymentExportDescriptions();
        $podleKorene = [
            'payroll-documents' => $dokumenty,
            'payroll-payment-exports' => $platby,
            'payroll-period-exports' => $obdobi,
        ];
        // Kořen archivů drží vedle archivu i zmrazené části, ze kterých vznikl.
        // Ty se popisují ze svých vlastních tabulek, ale označí se jako část —
        // jinak by vypadaly jako druhá kopie originálu.
        $castiArchivu = $dokumenty + $platby;

        $radky = ["cesta;druh;popis;velikost_b;vytvoreno"];
        foreach ($this->all() as $file) {
            $koren = strstr($file['entry'], '/', true) ?: '';
            $klic = basename($file['entry']);
            $popis = $podleKorene[$koren][$klic] ?? null;
            if ($popis === null && $koren === 'payroll-period-exports'
                && isset($castiArchivu[$klic])
            ) {
                $popis = $castiArchivu[$klic];
                $popis['druh'] = 'část archivu období';
            }
            $popis ??= [
                'druh' => 'neznamy',
                'popis' => 'v databázi k tomuhle otisku není záznam',
                'vytvoreno' => '',
            ];
            $radky[] = implode(';', [
                $file['entry'],
                $popis['druh'],
                str_replace(';', ',', $popis['popis']),
                (string) (@filesize($file['source']) ?: 0),
                $popis['vytvoreno'],
            ]);
        }

        return implode("\n", $radky) . "\n";
    }

    /** @return array<string,array{druh:string,popis:string,vytvoreno:string}> */
    private function documentDescriptions(): array
    {
        $sql = 'SELECT document.storage_key, document.document_kind,
                       document.document_revision_no, document.created_at,
                       employee.full_name, run.period_start
                  FROM payroll_generated_documents document
             LEFT JOIN payroll_employees employee
                    ON employee.supplier_id = document.supplier_id
                   AND employee.id = document.employee_id
             LEFT JOIN payroll_runs run
                    ON run.supplier_id = document.supplier_id
                   AND run.id = document.run_id';

        $out = [];
        foreach ($this->rows($sql) as $row) {
            $obdobi = is_string($row['period_start'] ?? null)
                ? substr($row['period_start'], 0, 7)
                : 'bez období';
            $osoba = is_string($row['full_name'] ?? null) && $row['full_name'] !== ''
                ? $row['full_name']
                : 'firma';
            $out[(string) $row['storage_key']] = [
                'druh' => 'dokument',
                'popis' => sprintf(
                    '%s · %s · %s · revize %s',
                    (string) $row['document_kind'],
                    $osoba,
                    $obdobi,
                    (string) $row['document_revision_no'],
                ),
                'vytvoreno' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return array<string,array{druh:string,popis:string,vytvoreno:string}> */
    private function periodExportDescriptions(): array
    {
        $sql = 'SELECT storage_key, export_scope, period_start, period_end,
                       suggested_filename, created_at
                  FROM payroll_period_exports';

        $out = [];
        foreach ($this->rows($sql) as $row) {
            $out[(string) $row['storage_key']] = [
                'druh' => 'archiv období',
                'popis' => sprintf(
                    '%s · %s až %s · %s',
                    (string) $row['export_scope'],
                    (string) $row['period_start'],
                    (string) $row['period_end'],
                    (string) $row['suggested_filename'],
                ),
                'vytvoreno' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return array<string,array{druh:string,popis:string,vytvoreno:string}> */
    private function paymentExportDescriptions(): array
    {
        $sql = 'SELECT export.storage_key, export.export_format,
                       export.export_revision_no, export.suggested_filename,
                       export.created_at, batch.planned_payment_date
                  FROM payroll_payment_exports export
             LEFT JOIN payroll_payment_batches batch
                    ON batch.supplier_id = export.supplier_id
                   AND batch.id = export.batch_id';

        $out = [];
        foreach ($this->rows($sql) as $row) {
            $out[(string) $row['storage_key']] = [
                'druh' => 'platební příkaz',
                'popis' => sprintf(
                    '%s · revize %s · splatnost %s · %s',
                    (string) $row['export_format'],
                    (string) $row['export_revision_no'],
                    (string) ($row['planned_payment_date'] ?? '—'),
                    (string) $row['suggested_filename'],
                ),
                'vytvoreno' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Tabulka nemusí existovat (instalace bez mezd) — chybějící metadata jsou
     * důvod k prázdnému popisu, ne k pádu zálohy.
     *
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql): array
    {
        try {
            $statement = $this->pdo->query($sql);
        } catch (\PDOException) {
            return [];
        }
        if ($statement === false) {
            return [];
        }
        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
