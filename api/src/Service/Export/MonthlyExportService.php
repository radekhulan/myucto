<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\CancelledException;
use MyInvoice\Service\Pdf\DphBookPdfRenderer;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\PurchaseInvoicePdfRenderer;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use ZipArchive;

/**
 * Hromadný export — sbalí existující exporty za zvolené období (měsíc nebo celé
 * čtvrtletí) do jednoho ZIPu s pojmenovanými složkami. Běží jako background job
 * (import_jobs, source='monthly_export'), protože renderování PDF u hodně faktur
 * může přesáhnout web timeout.
 *
 * Zařazení dokladů do období (shodné s VatLedgerService → Kniha DPH i přiznání DPH):
 *   - vystavené: dle DUZP (daň na výstupu),
 *   - přijaté: dle pozdějšího z (DUZP, vystavení) — odpočet nelze uplatnit dřív, než
 *     plátce drží daňový doklad (§ 73 ZDPH),
 *   - výpisy: dle data výpisu,
 *   - GoPay: dle období vyúčtování.
 *
 * Struktura ZIPu:
 *   Vystavene-faktury/PDF|ISDOC/…   Prijate-faktury/PDF|ISDOC/…
 *   Vypisy-z-uctu/PDF|GPC/…         Kniha-DPH/kniha-dph-YYYY-MM.pdf
 *   GoPay/PDF|XML/…                  Kontrolni-hlaseni/dphkh1-…xml
 *   README.txt
 *   (kvartálně je Kniha DPH per měsíc — tři PDF.)
 */
final class MonthlyExportService
{
    /** Všechny podporované části (a zároveň default, když uživatel nic nezvolí). */
    public const ALL_PARTS = [
        'sales_pdf', 'sales_isdoc', 'purchase_pdf', 'purchase_isdoc',
        'bank_pdf', 'bank_gpc', 'gopay_pdf', 'gopay_xml',
        'dph_book', 'vat_control_statement',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly ImportJobRepository $jobs,
        private readonly ActivityLogger $logger,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly InvoicePdfRenderer $invoicePdf,
        private readonly IsdocExporter $isdoc,
        private readonly PurchaseInvoicePdfRenderer $purchasePdf,
        private readonly PurchaseInvoiceExportService $purchaseExport,
        private readonly DphBookBuilder $dphBookBuilder,
        private readonly DphBookPdfRenderer $dphBookRenderer,
        private readonly KontrolniHlaseniBuilder $vatControlStatement,
        private readonly ExportPeriodResolver $periodResolver,
    ) {}

    /** Absolutní základ úložiště ZIPů (pod data_dir, jinak repo root). */
    public function storageBaseDir(): string
    {
        $base = ($this->config->dataDir() ?? \MyInvoice\Bootstrap::rootDir())
            . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'monthly-exports';
        return $base;
    }

    /** Absolutní cesta k souboru z relativního result_path (sup-N/file.zip). */
    public function resolveResultPath(string $relative): string
    {
        return $this->storageBaseDir() . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * Počty dostupných položek per část (pro UI checkboxy / preview).
     *
     * @return array<string,int>
     */
    public function previewCounts(int $supplierId, ExportPeriod $period): array
    {
        $sales    = count($this->findSalesInvoiceIds($supplierId, $period));
        $purchase = count($this->findPurchaseInvoices($supplierId, $period));
        [$bankPdf, $bankGpc] = $this->countStatementFiles($supplierId, $period);
        [$gopayPdf, $gopayXml] = $this->countGoPayFiles($supplierId, $period);
        return [
            'sales_pdf'      => $sales,
            'sales_isdoc'    => $sales,
            'purchase_pdf'   => $purchase,
            'purchase_isdoc' => $purchase,
            'bank_pdf'       => $bankPdf,
            'bank_gpc'       => $bankGpc,
            'gopay_pdf'      => $gopayPdf,
            'gopay_xml'      => $gopayXml,
            'dph_book'       => ($sales + $purchase) > 0 ? 1 : 0,
            // KH může mít i jiné řádky než běžné faktury (např. pokladní doklady),
            // proto jej nenulujeme podle zjednodušeného počtu dokladů. Přesný obsah
            // ověřuje builder při skutečném exportu.
            'vat_control_statement' => 1,
        ];
    }

    /**
     * @param list<string> $requested
     * @return list<string>
     */
    public static function normalizeParts(array $requested): array
    {
        $valid = array_values(array_intersect($requested, self::ALL_PARTS));
        return $valid !== [] ? $valid : self::ALL_PARTS;
    }

    /**
     * Worker entrypoint — vyrobí ZIP a uloží ho jako výsledek jobu.
     * Vlastní try/catch → markFailed; cancel přes cancel_requested flag.
     */
    public function run(int $jobId): void
    {
        $job = $this->findJob($jobId);
        if ($job === null) return;
        // Atomický queued → running (jiný worker nás nemohl předběhnout).
        if (!$this->jobs->markRunning($jobId)) return;

        $supplierId = (int) $job['supplier_id'];
        $userId = (int) ($job['created_by'] ?? 0) ?: null;
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $parts = self::normalizeParts(array_map('strval', (array) ($params['parts'] ?? [])));

        // Období z params (period/year/month/quarter). Selhání → fail jobu rovnou.
        try {
            $period = $this->periodResolver->resolve($params);
        } catch (\InvalidArgumentException $e) {
            $this->jobs->markFailed($jobId, 'Neplatné období: ' . $e->getMessage());
            $this->logFinished($jobId, $userId, $supplierId, '?', 'failed', ['error' => $e->getMessage()]);
            return;
        }
        $month = $period->label;  // jen pro logy / názvy souborů (YYYY-MM nebo YYYY-Qn)

        try {
            $companyName = $this->supplierCompanyName($supplierId);

            // Připrav cílovou cestu (sup-N/<jobId>-<firma>-hromadny-export-<obdobi>.zip).
            $relDir = 'sup-' . $supplierId;
            $absDir = $this->storageBaseDir() . DIRECTORY_SEPARATOR . $relDir;
            if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
                throw new \RuntimeException('Nelze vytvořit úložiště exportů.');
            }
            // Název firmy (bez diakritiky) v názvu ZIPu — ať jdou exporty víc dodavatelů rozlišit.
            $companySlug = $this->asciiSlug($companyName);
            $fileName = $companySlug !== ''
                ? sprintf('%s-hromadny-export-%s.zip', $companySlug, $month)
                : sprintf('myinvoice-hromadny-export-%s.zip', $month);
            $relPath  = $relDir . '/' . $jobId . '-' . $fileName;
            $absPath  = $this->storageBaseDir() . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relPath);

            $zip = new ZipArchive();
            if ($zip->open($absPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Nelze vytvořit ZIP.');
            }

            // Předpočítej zdroje + total pro progress bar.
            $salesIds   = array_intersect(['sales_pdf', 'sales_isdoc'], $parts) ? $this->findSalesInvoiceIds($supplierId, $period) : [];
            $purchRows  = array_intersect(['purchase_pdf', 'purchase_isdoc'], $parts) ? $this->findPurchaseInvoices($supplierId, $period) : [];
            $statements = array_intersect(['bank_pdf', 'bank_gpc'], $parts) ? $this->findStatements($supplierId, $period) : [];
            $gopayClearings = array_intersect(['gopay_pdf', 'gopay_xml'], $parts) ? $this->findGoPayClearings($supplierId, $period) : [];

            $salesParts = count(array_intersect(['sales_pdf', 'sales_isdoc'], $parts));
            $purchParts = count(array_intersect(['purchase_pdf', 'purchase_isdoc'], $parts));
            $bankFiles  = 0;
            foreach ($statements as $s) {
                if (in_array('bank_pdf', $parts, true) && $this->hasContent($s['pdf_content'] ?? null)) $bankFiles++;
                if (in_array('bank_gpc', $parts, true) && $this->hasContent($s['file_content'] ?? null)) $bankFiles++;
            }
            $gopayFiles = 0;
            foreach ($gopayClearings as $clearing) {
                if (in_array('gopay_pdf', $parts, true) && $this->hasContent($clearing['pdf_content'] ?? null)) $gopayFiles++;
                if (in_array('gopay_xml', $parts, true) && $this->hasContent($clearing['file_content'] ?? null)) $gopayFiles++;
            }
            $dphMonths = $this->monthsInPeriod($period);
            $total = count($salesIds) * $salesParts + count($purchRows) * $purchParts + $bankFiles + $gopayFiles
                + (in_array('dph_book', $parts, true) ? count($dphMonths) : 0)
                + (in_array('vat_control_statement', $parts, true) ? 1 : 0);
            $this->jobs->updateProgress($jobId, ['total_items' => $total, 'current_step' => 'Příprava…']);

            $added = 0;
            $processed = 0;
            $summary = [];
            $warnings = [];

            $bump = function (string $step) use (&$processed, $jobId): void {
                $processed++;
                if ($processed % 5 === 0) {
                    $this->jobs->updateProgress($jobId, ['processed' => $processed, 'current_step' => $step]);
                }
            };

            // 1) Vystavené faktury
            if ($salesIds !== []) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                $this->jobs->appendLog($jobId, 'Vystavené faktury: ' . count($salesIds) . ' ks');
                foreach ($salesIds as $id) {
                    $inv = $this->invoiceRepo->find($id);
                    if ($inv === null) continue;
                    $typeLabel = match ($inv['invoice_type'] ?? 'invoice') {
                        'proforma' => 'Proforma', 'credit_note' => 'Dobropis',
                        'cancellation' => 'Storno', 'tax_document' => 'DanovyDoklad',
                        default => 'Faktura',
                    };
                    $base = $typeLabel . '-' . $this->sanitize((string) ($inv['varsymbol'] ?? ('draft-' . $id)));
                    if (in_array('sales_pdf', $parts, true)) {
                        try {
                            $path = $this->invoicePdf->render($id, false, $userId);
                            if (is_file($path)) {
                                $zip->addFile($path, "Vystavene-faktury/PDF/{$base}.pdf");
                                $added++; $summary['sales_pdf'] = ($summary['sales_pdf'] ?? 0) + 1;
                            }
                        } catch (\Throwable $e) { $warnings[] = "VF {$base} PDF: " . $e->getMessage(); }
                        $bump('Vystavené faktury — PDF');
                    }
                    if (in_array('sales_isdoc', $parts, true)) {
                        try {
                            $zip->addFromString("Vystavene-faktury/ISDOC/{$base}.isdoc", $this->isdoc->buildXml($inv));
                            $added++; $summary['sales_isdoc'] = ($summary['sales_isdoc'] ?? 0) + 1;
                        } catch (\Throwable $e) { $warnings[] = "VF {$base} ISDOC: " . $e->getMessage(); }
                        $bump('Vystavené faktury — ISDOC');
                    }
                }
            }

            // 2) Přijaté faktury (originál > rekonstrukce)
            if ($purchRows !== []) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                $this->jobs->appendLog($jobId, 'Přijaté faktury: ' . count($purchRows) . ' ks');
                $archiveRoot = $this->resolveArchiveRoot();
                $archiveRootReal = realpath($archiveRoot);
                $isWindows = DIRECTORY_SEPARATOR === '\\';
                foreach ($purchRows as $r) {
                    $id = (int) $r['id'];
                    $vs = (string) ($r['varsymbol'] ?? $r['vendor_invoice_number'] ?? ('id-' . $id));
                    $vendor = (string) ($r['vendor_company_name'] ?? 'vendor');
                    $entryBase = substr($this->sanitize($vs . '-' . $vendor) ?: 'invoice', 0, 100);
                    if (in_array('purchase_pdf', $parts, true)) {
                        $originalAbs = null;
                        if (!empty($r['pdf_path'])) {
                            $absReal = realpath($archiveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $r['pdf_path']));
                            if ($absReal !== false && is_file($absReal) && $archiveRootReal !== false
                                && str_starts_with(
                                    $isWindows ? strtolower($absReal) : $absReal,
                                    ($isWindows ? strtolower($archiveRootReal) : $archiveRootReal) . DIRECTORY_SEPARATOR
                                )) {
                                $originalAbs = $absReal;
                            }
                        }
                        if ($originalAbs !== null) {
                            $zip->addFile($originalAbs, "Prijate-faktury/PDF/Prijata-{$entryBase}.pdf");
                            $added++; $summary['purchase_pdf'] = ($summary['purchase_pdf'] ?? 0) + 1;
                        } else {
                            try {
                                $zip->addFromString("Prijate-faktury/PDF/Prijata-{$entryBase}-rekonstrukce.pdf", $this->purchasePdf->render($id, $supplierId));
                                $added++; $summary['purchase_pdf'] = ($summary['purchase_pdf'] ?? 0) + 1;
                            } catch (\Throwable $e) { $warnings[] = "PF {$vs} PDF: " . $e->getMessage(); }
                        }
                        $bump('Přijaté faktury — PDF');
                    }
                    if (in_array('purchase_isdoc', $parts, true)) {
                        try {
                            $zip->addFromString("Prijate-faktury/ISDOC/Prijata-{$entryBase}.isdoc", $this->purchaseExport->toIsdocXml($id, $supplierId));
                            $added++; $summary['purchase_isdoc'] = ($summary['purchase_isdoc'] ?? 0) + 1;
                        } catch (\Throwable $e) { $warnings[] = "PF {$vs} ISDOC: " . $e->getMessage(); }
                        $bump('Přijaté faktury — ISDOC');
                    }
                }
            }

            // 3) Výpisy z účtu
            if ($statements !== []) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                $this->jobs->appendLog($jobId, 'Výpisy z účtu: ' . count($statements) . ' ks');
                foreach ($statements as $s) {
                    $id = (int) $s['id'];
                    if (in_array('bank_pdf', $parts, true) && $this->hasContent($s['pdf_content'] ?? null)) {
                        $name = $this->statementFilename((string) ($s['pdf_name'] ?? ''), (string) ($s['account_number'] ?? ''), $id, 'pdf');
                        $zip->addFromString("Vypisy-z-uctu/PDF/{$name}", (string) $s['pdf_content']);
                        $added++; $summary['bank_pdf'] = ($summary['bank_pdf'] ?? 0) + 1;
                        $bump('Výpisy z účtu — PDF');
                    }
                    if (in_array('bank_gpc', $parts, true) && $this->hasContent($s['file_content'] ?? null)) {
                        $name = $this->statementFilename((string) ($s['file_name'] ?? ''), (string) ($s['account_number'] ?? ''), $id, 'gpc');
                        $zip->addFromString("Vypisy-z-uctu/GPC/{$name}", (string) $s['file_content']);
                        $added++; $summary['bank_gpc'] = ($summary['bank_gpc'] ?? 0) + 1;
                        $bump('Výpisy z účtu — GPC');
                    }
                }
            }

            // 4) GoPay vyúčtování
            if ($gopayClearings !== []) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                $this->jobs->appendLog($jobId, 'GoPay vyúčtování: ' . count($gopayClearings) . ' ks');
                foreach ($gopayClearings as $clearing) {
                    $id = (int) $clearing['id'];
                    $clearingNumber = (string) ($clearing['clearing_id'] ?? $id);
                    if (in_array('gopay_pdf', $parts, true) && $this->hasContent($clearing['pdf_content'] ?? null)) {
                        $name = $this->gopayFilename((string) ($clearing['pdf_name'] ?? ''), $clearingNumber, $id, 'pdf');
                        $zip->addFromString("GoPay/PDF/{$name}", (string) $clearing['pdf_content']);
                        $added++; $summary['gopay_pdf'] = ($summary['gopay_pdf'] ?? 0) + 1;
                        $bump('GoPay vyúčtování - PDF');
                    }
                    if (in_array('gopay_xml', $parts, true) && $this->hasContent($clearing['file_content'] ?? null)) {
                        $name = $this->gopayFilename((string) ($clearing['file_name'] ?? ''), $clearingNumber, $id, 'xml');
                        $zip->addFromString("GoPay/XML/{$name}", (string) $clearing['file_content']);
                        $added++; $summary['gopay_xml'] = ($summary['gopay_xml'] ?? 0) + 1;
                        $bump('GoPay vyúčtování - XML');
                    }
                }
            }

            // 5) Kniha DPH, měsíční žurnál; kvartálně tři PDF (per měsíc kvartálu).
            if (in_array('dph_book', $parts, true)) {
                foreach ($dphMonths as [$bookYear, $bookMon]) {
                    $this->ensureNotCancelled($jobId, $zip, $absPath);
                    try {
                        $data = $this->dphBookBuilder->build($supplierId, $bookYear, $bookMon);
                        if (!empty($data['sections'])) {
                            $zip->addFromString(sprintf('Kniha-DPH/kniha-dph-%04d-%02d.pdf', $bookYear, $bookMon), $this->dphBookRenderer->render($data));
                            $added++; $summary['dph_book'] = ($summary['dph_book'] ?? 0) + 1;
                        }
                    } catch (\Throwable $e) { $warnings[] = sprintf('Kniha DPH %04d-%02d: %s', $bookYear, $bookMon, $e->getMessage()); }
                    $bump('Kniha DPH');
                }
            }

            // 6) Kontrolní hlášení, měsíční nebo čtvrtletní XML podle zvoleného
            // ExportPeriod. Nearchivuje se jako podání (to je vedlejší efekt ručního
            // downloadu); tady jde o reprodukovatelný podklad uvnitř hromadného ZIPu.
            if (in_array('vat_control_statement', $parts, true)) {
                try {
                    $khPeriod = $period->type === 'quarterly' ? 'quarterly' : 'monthly';
                    $kh = $this->vatControlStatement->build(
                        $supplierId,
                        $period->year,
                        $period->type === 'quarterly' ? (((int) $period->quarter - 1) * 3) + 1 : (int) $period->month,
                        $khPeriod,
                    );
                    $zip->addFromString(
                        sprintf('Kontrolni-hlaseni/dphkh1-%s.xml', $period->label),
                        (string) $kh['xml'],
                    );
                    $added++; $summary['vat_control_statement'] = 1;
                } catch (\Throwable $e) {
                    $warnings[] = 'Kontrolní hlášení: ' . $e->getMessage();
                }
                $bump('Kontrolní hlášení');
            }

            if ($added === 0) {
                $zip->close();
                @unlink($absPath);
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'failed_count' => count($warnings)]);
                $this->jobs->markFailed($jobId, "Za období {$month} nejsou pro zvolené části žádná data k exportu.");
                $this->logFinished($jobId, $userId, $supplierId, $month, 'failed', ['reason' => 'no_data']);
                return;
            }

            $zip->addFromString('README.txt', $this->buildReadme($companyName, $month, $summary, $warnings));
            $zip->close();

            $size = (int) (@filesize($absPath) ?: 0);
            $this->jobs->setResult($jobId, $relPath, $fileName, $size, 'application/zip');
            $this->jobs->updateProgress($jobId, [
                'processed' => $processed,
                'created_count' => $added,
                'failed_count' => count($warnings),
                'current_step' => 'Hotovo',
            ]);
            foreach (array_slice($warnings, 0, 50) as $w) {
                $this->jobs->appendLog($jobId, 'Upozornění: ' . $w);
            }
            $this->jobs->appendLog($jobId, "Export dokončen — {$added} souborů (" . $this->humanSize($size) . ').');
            $this->jobs->markCompleted($jobId);
            $this->logFinished($jobId, $userId, $supplierId, $month, 'completed', [
                'files' => $added, 'size_bytes' => $size, 'warnings' => count($warnings),
            ]);
        } catch (CancelledException) {
            // úklid řeší ensureNotCancelled (zavřel + smazal zip), tady jen status
            $this->jobs->markCancelled($jobId);
            $this->logFinished($jobId, $userId, $supplierId, $month, 'cancelled', []);
        } catch (\Throwable $e) {
            if (isset($absPath) && is_file($absPath)) @unlink($absPath);
            $this->jobs->markFailed($jobId, $e->getMessage());
            $this->logFinished($jobId, $userId, $supplierId, $month, 'failed', ['error' => $e->getMessage()]);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** Throws CancelledException (po úklidu zipu) když uživatel požádal o zrušení. */
    private function ensureNotCancelled(int $jobId, ZipArchive $zip, string $absPath): void
    {
        if ($this->jobs->isCancelRequested($jobId)) {
            $zip->close();
            @unlink($absPath);
            throw new CancelledException();
        }
    }

    private function hasContent(mixed $v): bool
    {
        return $v !== null && $v !== '';
    }

    /**
     * Zaloguj dokončení jobu do activity_log (páruje se s 'reports.monthly_export_started').
     * Běží v CLI workeru → ip/ua null.
     *
     * @param array<string,mixed> $extra
     */
    private function logFinished(int $jobId, ?int $userId, int $supplierId, string $month, string $status, array $extra): void
    {
        $this->logger->log('reports.monthly_export_finished', $userId, 'import_job', $jobId,
            array_merge(['month' => $month, 'status' => $status], $extra), null, null, $supplierId);
    }

    /** Načte job řádek cross-tenant (worker). */
    private function findJob(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT supplier_id, created_by, params, status FROM import_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if ($row['params'] !== null) {
            $decoded = json_decode((string) $row['params'], true);
            $row['params'] = is_array($decoded) ? $decoded : [];
        }
        return $row;
    }

    /**
     * Měsíce spadající do období [from, toExclusive). Měsíčně 1 prvek, kvartálně 3.
     *
     * @return list<array{0:int,1:int}> [[year, month], …]
     */
    private function monthsInPeriod(ExportPeriod $period): array
    {
        $out = [];
        $cur = new \DateTimeImmutable($period->dateFrom);
        $end = new \DateTimeImmutable($period->dateToExclusive);
        while ($cur < $end) {
            $out[] = [(int) $cur->format('Y'), (int) $cur->format('n')];
            $cur = $cur->modify('+1 month');
        }
        return $out;
    }

    /** @return int[] */
    private function findSalesInvoiceIds(int $sid, ExportPeriod $period): array
    {
        // Vystavené dle DUZP (daň na výstupu vzniká k DUZP). Shodné s VatLedgerService.
        $sql = "SELECT id FROM invoices
                 WHERE supplier_id = ?
                   AND effective_tax_date >= ?
                   AND effective_tax_date <  ?
                   AND status IN ('issued','sent','reminded','paid')
              ORDER BY effective_tax_date, id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$sid, $period->dateFrom, $period->dateToExclusive]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<array<string,mixed>> */
    private function findPurchaseInvoices(int $sid, ExportPeriod $period): array
    {
        // Rozhodné datum nároku na odpočet (§ 73) — SDÍLENÝ výraz s knihou DPH.
        //
        // Dřív tu byla vlastní kopie s komentářem „Shodné s VatLedgerService", která
        // shodná NEBYLA: chyběla jí větev `received_at_source = 'manual'` (§ 73/1 —
        // nárok nejdřív v měsíci doručení dokladu) i rozpoznání reverse charge podle
        // klasifikačního kódu a položek. Na ostrých datech kvůli tomu 2 z 248 dokladů
        // padly v exportu do jiného měsíce než v podaném přiznání, takže podklady pro
        // účetní neseděly na to, co se odeslalo na FÚ.
        //
        // Výraz počítá s aliasy `pi` a `co` — ty tenhle dotaz má. CASE (ne GREATEST)
        // zůstává kvůli přenositelnosti na SQLite.
        $dateExpr = VatLedgerService::purchaseClaimDateExpr();
        $sql = "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.pdf_path,
                       c.company_name AS vendor_company_name
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
             LEFT JOIN countries co ON co.id = c.country_id
                 WHERE pi.supplier_id = ?
                   AND $dateExpr >= ?
                   AND $dateExpr <  ?
                   AND pi.status IN ('received', 'booked', 'paid')
                 ORDER BY $dateExpr, pi.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$sid, $period->dateFrom, $period->dateToExclusive]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> výpisy za období vč. blob obsahů */
    private function findStatements(int $sid, ExportPeriod $period): array
    {
        // SEC-01: vlastnictví řeší výhradně BankStatementOwnershipResolver. Starý
        // wildcard predikát tady vydával syrové GPC/PDF výpisy cizí firmy komukoliv
        // přihlášenému (route /api/reports/monthly-export/start).
        $sql = "SELECT bs.id, bs.account_number, bs.file_name, bs.file_content, bs.pdf_name, bs.pdf_content
                  FROM bank_statements bs
                 WHERE bs.statement_date >= ?
                   AND bs.statement_date <  ?
                   AND " . BankStatementOwnershipResolver::sql() . "
                 ORDER BY bs.statement_date, bs.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge(
            [$period->dateFrom, $period->dateToExclusive],
            BankStatementOwnershipResolver::params($sid),
        ));
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array{0:int,1:int} [bankPdfCount, bankGpcCount] bez načítání blobů */
    private function countStatementFiles(int $sid, ExportPeriod $period): array
    {
        // SEC-01: shodný scope jako findStatements() — i pouhý počet prozrazuje
        // existenci cizích výpisů.
        $sql = "SELECT SUM(bs.pdf_content IS NOT NULL) AS pdf_count, SUM(bs.file_content IS NOT NULL) AS gpc_count
                  FROM bank_statements bs
                 WHERE bs.statement_date >= ?
                   AND bs.statement_date <  ?
                   AND " . BankStatementOwnershipResolver::sql();
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge(
            [$period->dateFrom, $period->dateToExclusive],
            BankStatementOwnershipResolver::params($sid),
        ));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [(int) ($row['pdf_count'] ?? 0), (int) ($row['gpc_count'] ?? 0)];
    }

    /** @return list<array<string,mixed>> GoPay vyúčtování, jejichž období zasahuje do exportovaného období */
    private function findGoPayClearings(int $sid, ExportPeriod $period): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,clearing_id,file_name,file_content,pdf_name,pdf_content
               FROM gopay_clearings
              WHERE supplier_id=? AND cleared_to>=? AND cleared_from<?
              ORDER BY cleared_from,id'
        );
        $stmt->execute([$sid, $period->dateFrom, $period->dateToExclusive]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array{0:int,1:int} [gopayPdfCount, gopayXmlCount] bez načítání blobů */
    private function countGoPayFiles(int $sid, ExportPeriod $period): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT SUM(pdf_content IS NOT NULL AND OCTET_LENGTH(pdf_content)>0) pdf_count,
                    SUM(file_content IS NOT NULL AND OCTET_LENGTH(file_content)>0) xml_count
               FROM gopay_clearings
              WHERE supplier_id=? AND cleared_to>=? AND cleared_from<?'
        );
        $stmt->execute([$sid, $period->dateFrom, $period->dateToExclusive]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [(int) ($row['pdf_count'] ?? 0), (int) ($row['xml_count'] ?? 0)];
    }

    private function resolveArchiveRoot(): string
    {
        $dir = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($dir !== '') return $dir;
        $uploads = (string) $this->config->get('storage.uploads_dir', '');
        if ($uploads !== '') return dirname($uploads) . '/purchase-invoices';
        return \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices');
    }

    private function sanitize(string $s): string
    {
        // Diakritiku přepíšeme na ASCII (č→c, ě→e, …) místo nahrazení podtržítkem.
        return ExportFilename::sanitize($s);
    }

    /** Název firmy dodavatele (pro README i název ZIPu). Prázdný string = nenalezeno. */
    private function supplierCompanyName(int $sid): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name FROM supplier WHERE id = ?');
        $stmt->execute([$sid]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    }

    /**
     * Název firmy → ASCII slug pro název souboru: ořízne českou/slovenskou diakritiku
     * (č→c, ž→z, …), zbytek nealfanumerických znaků nahradí pomlčkou. Délku omezí na 60.
     */
    private function asciiSlug(string $s): string
    {
        return \MyInvoice\Support\Slugifier::slug($s, '-', 'keep', 60);
    }

    private function statementFilename(string $name, string $account, int $id, string $ext): string
    {
        $name = $name !== '' ? $name : ('vypis-' . $id . '.' . $ext);
        $account = ltrim(trim($account), '0');
        if ($account !== '') {
            $acctDigits = preg_replace('/\D/', '', $account) ?? '';
            $nameDigits = preg_replace('/\D/', '', $name) ?? '';
            $alreadyHas = str_contains($name, $account) || ($acctDigits !== '' && str_contains($nameDigits, $acctDigits));
            $acctSafe = preg_replace('/[^A-Za-z0-9_-]/', '', $account) ?? '';
            if (!$alreadyHas && $acctSafe !== '') $name = $acctSafe . '-' . $name;
        }
        return $this->sanitize($name);
    }

    private function gopayFilename(string $name, string $clearingId, int $id, string $ext): string
    {
        $original = $name !== '' ? $name : ('vyuctovani.' . $ext);
        $prefix = $this->sanitize('GoPay-' . $clearingId . '-' . $id);
        return $prefix . '-' . $this->sanitize($original);
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024) . ' kB';
        return $bytes . ' B';
    }

    /**
     * @param array<string,int> $summary
     * @param list<string> $warnings
     */
    private function buildReadme(string $companyName, string $month, array $summary, array $warnings): string
    {
        $labels = [
            'sales_pdf' => 'Vystavené faktury (PDF)', 'sales_isdoc' => 'Vystavené faktury (ISDOC)',
            'purchase_pdf' => 'Přijaté faktury (PDF)', 'purchase_isdoc' => 'Přijaté faktury (ISDOC)',
            'bank_pdf' => 'Výpisy z účtu (PDF)', 'bank_gpc' => 'Výpisy z účtu (GPC)',
            'gopay_pdf' => 'GoPay vyúčtování (PDF)', 'gopay_xml' => 'GoPay vyúčtování (XML)',
            'dph_book' => 'Kniha DPH (PDF)',
            'vat_control_statement' => 'Kontrolní hlášení DPH (XML)',
        ];
        $lines = [
            'Hromadný export MyÚčto.cz', '=========================',
        ];
        if (trim($companyName) !== '') {
            $lines[] = 'Firma: ' . $companyName;
        }
        $lines = array_merge($lines, [
            'Období: ' . $month, 'Vygenerováno: ' . date('Y-m-d H:i:s'), '',
            'Zařazení dokladů do období (shodné s Knihou DPH a přiznáním DPH):',
            '  - Vystavené faktury: podle data zdanitelného plnění (DUZP).',
            '  - Přijaté faktury: podle pozdějšího z dat DUZP / vystavení',
            '    (nárok na odpočet nelze uplatnit dříve než podle daňového dokladu).',
            '  - Výpisy z účtu: podle data výpisu.',
            '  - GoPay vyúčtování: podle období vyúčtování.', '', 'Obsah:',
        ]);
        foreach ($labels as $key => $label) {
            if (isset($summary[$key])) $lines[] = sprintf('  - %s: %d', $label, $summary[$key]);
        }
        if (!empty($warnings)) {
            $lines[] = '';
            $lines[] = 'Upozornění (přeskočené položky):';
            foreach (array_slice($warnings, 0, 50) as $w) $lines[] = '  - ' . $w;
        }
        return implode("\r\n", $lines) . "\r\n";
    }
}
