<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\Mpdf;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Codebook\UnitPrecision;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\SupplierPaymentQrSettingsRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Branding\AccentColor;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Invoice\InvoiceCalendar;
use MyInvoice\Service\Invoice\SimplifiedDocumentPolicy;
use MyInvoice\Service\Invoice\CzkRecap;
use MyInvoice\Service\Invoice\RefundDocument;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Oss\OssInvoiceClause;
use MyInvoice\Service\Qr\PaymentQrDueDate;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;
use MyInvoice\Service\Vat\VatStatusService;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renderuje fakturu jako PDF.
 *
 *   1. Načte fakturu (full + items + snapshots)
 *   2. Vyrendrované Twig šablonu invoice.twig
 *   3. Vygeneruje QR (pokud má varsymbol + amount + bank)
 *   4. mPDF z HTML
 *   5. Cache do storage/invoices/YYYY-MM/Faktura-YY-MM-NNN.pdf
 */
final class InvoicePdfRenderer
{
    use SignsPdf;

    private ?Environment $twig = null;

    /** Aktuální locale pro Twig funkci `t()` — přenastavuje renderHtml(). */
    private string $locale = 'cs';

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly Config $config,
        private readonly QrPaymentGenerator $qr,
        private readonly WorkReportRepository $workReports,
        private readonly SnapshotBuilder $snapshots,
        private readonly PdfArchiveService $archive,
        private readonly IsdocExporter $isdoc,
        private readonly PdfSigningService $pdfSigning,
        private readonly \MyInvoice\Repository\PaymentScheduleRepository $paymentSchedule,
        private readonly VatStatusService $vatStatus,
        private readonly SupplierPaymentQrSettingsRepository $paymentQrSettings,
    ) {}

    /**
     * Vyrendrované PDF do souboru a vrátí cestu.
     *
     * @param array<string,mixed>|null $invoiceData
     * @return string  absolutní cesta k vygenerovanému PDF
     */
    public function render(
        int $invoiceId,
        bool $forceRegenerate = false,
        ?int $userId = null,
        ?array $invoiceData = null,
    ): string
    {
        $persist = !(bool) $this->config->get('demo.enabled', false);
        $invoice = $invoiceData ?? $this->repo->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Faktura #{$invoiceId} nenalezena");
        }
        // Interní doklad splátky odběratel nedostává, daňovým dokladem je kalendář (§ 31, § 31a).
        if (!empty($invoice['calendar_id'])) {
            return $this->render((int) $invoice['calendar_id'], $forceRegenerate, $userId);
        }

        $cachedPath = $this->cachePath($invoice);
        $supplierData = $this->getSupplierData((int) ($invoice['supplier_id'] ?? 0));
        $signatureCacheDependsOnUser = $this->pdfSigning->outputDependsOnUserProfile(
            $supplierData,
            'invoice',
            $invoiceId,
        );

        // Cache je validní jen když je novější než šablona, CSS a kód renderu
        $tplMtime = max(
            @filemtime(Bootstrap::rootDir() . '/styles/invoice.css') ?: 0,
            @filemtime(Bootstrap::rootDir() . '/' . PdfBranding::MONOCHROME_CSS) ?: 0,
            @filemtime(Bootstrap::rootDir() . '/api/templates/invoice/invoice.twig') ?: 0,
            @filemtime(__DIR__ . '/TimeBillingPdfPresenter.php') ?: 0,
            @filemtime(__FILE__) ?: 0,
        );
        $isFresh = static fn (string $p): bool =>
            is_file($p) && (@filemtime($p) ?: 0) >= $tplMtime;

        // pdf_path je uložený RELATIVNĚ ke storage/invoices (N-016) — na disk se
        // sahá až přes resolvePdfPath(), která snese i legacy absolutní hodnoty.
        $storedPdf = self::resolvePdfPath($invoice['pdf_path'] ?? null);
        if (!$forceRegenerate && !$signatureCacheDependsOnUser && $storedPdf !== null && $isFresh($storedPdf)) {
            return $storedPdf;
        }
        // cachePath fallback je orphan-recovery (pdf_path je null, ale soubor leží na
        // deterministické cestě). MUSÍ vyžadovat pdf_generated_at NOT NULL — jinak
        // by invalidate() s uzamčeným souborem (Windows: PDF otevřené v prohlížeči →
        // rename a unlink selžou) skončila s pdf_path=NULL ale původní soubor zůstal
        // na disku, a tahle větev by ho zde znovu pickla → stale PDF.
        if (
            !$forceRegenerate
            && !$signatureCacheDependsOnUser
            && (!$persist || !empty($invoice['pdf_generated_at']))
            && $isFresh($cachedPath)
        ) {
            if ($persist) {
                $this->updatePdfPath($invoiceId, $cachedPath);
            }
            return $cachedPath;
        }

        // Force regenerate = také obnov supplier/client/bank snapshoty z live dat.
        // (Snapshoty jsou primární zdroj pro issued+ faktury — bez tohoto by se
        // změny v supplier/client tabulkách neprojevily ani po regenerate.)
        if ($forceRegenerate && $persist) {
            $invoice = $this->refreshSnapshots($invoice);
        }

        $rootDir = Bootstrap::rootDir();
        $tmpDir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        // ISDOC attachment: vyrobíme XML v paměti a předáme do SetAssociatedFiles
        // jako 'content' (žádný tmp soubor — mPDF API umí XML zpracovat in-memory).
        // Twigu předáme jen boolean flag, ať může vykreslit vizuální badge.
        // Gating: jen pokud supplier má embed_isdoc=1 a faktura je v CZK (ISDOC
        // je CZ standard, EUR/USD doklady by accounting SW jen zmátly).
        $isdocXml = null;
        if ($this->shouldEmbedIsdoc($invoice)) {
            try {
                $isdocXml = $this->isdoc->buildXml($invoice);
            } catch (\Throwable) {
                // ISDOC build selhal (chybný snapshot, neexistující data) —
                // PDF renderujeme bez přílohy, nezdržujeme uživatele.
                $isdocXml = null;
            }
            // Vložené ISDOC se podepisuje vlastním XML podpisem (POHODA ho při
            // importu ověřuje přímo v XML) a ještě před vložením do PDF, aby ho
            // následný podpis PDF kryl už podepsané. Mimo try výše: při
            // failure_policy fail_closed se chyba podpisu nesmí tiše spolknout.
            if ($isdocXml !== null) {
                $isdocXml = $this->pdfSigning->signIsdocIfEnabled($isdocXml, $supplierData, $invoiceId, $userId);
            }
        }

        $rendered = $this->renderHtmlAndCss($invoice, $isdocXml !== null);

        $mpdf = $this->newMpdf($tmpDir, $this->bankInFooter($invoice));
        // PDF metadata — bez Title/Author, aby Chrome viewer nezobrazoval text nad PDF.
        $mpdf->SetTitle('');
        $mpdf->SetAuthor('');
        $mpdf->SetCreator('MyÚčto.cz');

        // PDF/A-3 style associated file: zapsáno do /Names /EmbeddedFiles + /AF
        // catalog entry. Accounting SW (Pohoda, Money S3, …) skenuje tuhle cestu.
        if ($isdocXml !== null) {
            $mpdf->SetAssociatedFiles([[
                'content'        => $isdocXml,
                'name'           => 'invoice.isdoc',
                'mime'           => 'application/x-isdoc',
                'description'    => 'ISDOC ' . IsdocExporter::VERSION . ' invoice data',
                'AFRelationship' => 'Source',
            ]]);
        }

        // CSS separately (mPDF handluje líp než inline <style> tag)
        if ($rendered['css'] !== '') {
            $mpdf->WriteHTML($rendered['css'], \Mpdf\HTMLParserMode::HEADER_CSS);
        }
        $mpdf->WriteHTML($rendered['body'], \Mpdf\HTMLParserMode::HTML_BODY);

        if (!is_dir(dirname($cachedPath))) {
            @mkdir(dirname($cachedPath), 0755, true);
        }
        // Write to .new sibling first, pak atomický rename — obchází Windows file lock
        // (když je starý PDF otevřený v Chrome PDF viewer, přepis přímo by selhal).
        $tmpPath = $cachedPath . '.new';
        $mpdf->Output($tmpPath, \Mpdf\Output\Destination::FILE);

        // Podpis PDF (PAdES) — má-li dodavatel zapnuto; měkký fallback při chybě.
        $tmpPath = $this->signPdfIfEnabled(
            $tmpPath,
            $supplierData,
            $this->pdfSigning,
            'invoice',
            $invoiceId,
            $userId,
        );

        if (is_file($cachedPath)) {
            @unlink($cachedPath); // pokud locked, fail silently
        }
        if (!@rename($tmpPath, $cachedPath)) {
            // Rename selhal (target locked) — nech temp, vrať tmpPath přímo
            $cachedPath = $tmpPath;
        }

        if ($persist) {
            $this->updatePdfPath($invoiceId, $cachedPath);
        }

        return $cachedPath;
    }

    /**
     * Vyrenderuje pouze samotnou fakturu do zadaného dočasného souboru.
     *
     * Výstup záměrně neobsahuje ISDOC ani výkaz práce a nikdy se zde nepodepisuje.
     * Používá ho hromadný tisk, který jednotlivé dokumenty nejprve spojí a případný
     * elektronický podpis aplikuje až na výsledný celek.
     */
    public function renderUnsignedInvoiceOnly(int $invoiceId, string $outputPath): void
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Faktura #{$invoiceId} nenalezena");
        }

        $tmpDir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        if (!is_dir(dirname($outputPath))) {
            @mkdir(dirname($outputPath), 0755, true);
        }

        $rendered = $this->renderHtmlAndCss($invoice, false, false);
        $mpdf = $this->newMpdf($tmpDir, $this->bankInFooter($invoice));
        $mpdf->SetTitle('');
        $mpdf->SetAuthor('');
        $mpdf->SetCreator('MyInvoice.cz');
        if ($rendered['css'] !== '') {
            $mpdf->WriteHTML($rendered['css'], \Mpdf\HTMLParserMode::HEADER_CSS);
        }
        $mpdf->WriteHTML($rendered['body'], \Mpdf\HTMLParserMode::HTML_BODY);
        $mpdf->Output($outputPath, \Mpdf\Output\Destination::FILE);
    }

    /**
     * ISDOC se přiloží jen pro CZK faktury dodavatele s embed_isdoc=1.
     * Drafty bez varsymbolu skipujeme — buildXml() by vyrobil placeholder
     * "DRAFT-{id}" jako ID, což účetní SW odmítne.
     */
    private function shouldEmbedIsdoc(array $invoice): bool
    {
        $currency = strtoupper((string) ($invoice['currency'] ?? ''));
        if ($currency !== 'CZK') return false;
        if (empty($invoice['varsymbol'])) return false;
        $supplier = $this->getSupplierData((int) ($invoice['supplier_id'] ?? 0));
        return (bool) ($supplier['embed_isdoc'] ?? true);
    }

    /**
     * @return array{body:string, css:string}
     */
    public function renderHtmlAndCss(
        array $invoice,
        bool $hasIsdocAttachment = false,
        bool $includeWorkReport = true,
    ): array
    {
        $cssPath = Bootstrap::rootDir() . '/styles/invoice.css';
        $css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
        // Per-supplier branding barva, nebo úsporná černobílá varianta (ta akcent nahrazuje).
        $css .= $this->brandThemeCss($this->resolveSupplier($invoice));
        // Renderuj template BEZ inline <style> bloku — CSS pošleme do mPDF zvlášť
        $body = $this->renderHtml(
            $invoice,
            includeCss: false,
            hasIsdocAttachment: $hasIsdocAttachment,
            includeWorkReport: $includeWorkReport,
        );
        return ['body' => $body, 'css' => $css];
    }

    public function renderHtml(
        array $invoice,
        bool $includeCss = true,
        bool $hasIsdocAttachment = false,
        bool $includeWorkReport = true,
    ): string
    {
        // Použij snapshots pokud jsou (issued+), jinak živá data
        $supplierData = $this->resolveSupplier($invoice);
        if (($invoice['status'] ?? 'draft') === 'draft' || empty($invoice['supplier_snapshot'])) {
            $supplierData = $this->applyLiveBrandingProfile($supplierData, $invoice);
        }
        $clientData   = $this->resolveClient($invoice);
        $bankData     = $this->resolveBank($invoice);

        // QR generování:
        //   CZK SPAYD vyžaduje VS jako mandatory pole → bez varsymbolu skip
        //   SEPA EPC (EUR i další) VS nepoužívá, jen volitelný remittance text
        //     → drafty bez VS dostanou QR taky (preview pro klienta), remittance fallback
        //   Skip pro zaplacené faktury a pro non-bank-transfer payment_method
        $qrUri = null;
        // Částečné úhrady (#89): QR i výzva k platbě znějí na ZBÝVAJÍCÍ částku
        // (amount_to_pay − paid_total) — znovu stažené/poslané PDF po částečné
        // úhradě nesmí chtít celou částku. Cache se při změně plateb invaliduje.
        $remaining = round((float) $invoice['amount_to_pay'] - (float) ($invoice['paid_total'] ?? 0), 2);
        $hasAmount = $remaining > 0;
        $isCzk = ((string) $invoice['currency']) === 'CZK';
        $hasVs = VariableSymbolNormalizer::forInvoicePayment($invoice) !== '';
        // Stejné „zaplaceno" jako e-mail a web faktura: zaplacená faktura s dodatečně
        // přidanými položkami nesmí tvrdit „Neplaťte prosím znovu" a skrýt QR doplatku.
        $isPaid = \MyInvoice\Service\Invoice\InvoicePaidState::isEffectivelyPaid($invoice);
        $paymentMethod = (string) ($invoice['payment_method'] ?? 'bank_transfer');
        $isBankTransfer = $paymentMethod === 'bank_transfer';
        // Platební kalendář QR kód nedostane. Žádná jedna platba se na něm nekoná —
        // rozpis jich má dvanáct — takže QR na celkovou částku by vyzýval k úhradě
        // celého roku najednou, přesně proti tomu, co doklad sjednává.
        $isPaymentCalendar = InvoiceCalendar::hasSchedule($invoice['invoice_type'] ?? null);
        if ($hasAmount && $bankData !== null && (!$isCzk || $hasVs) && !$isPaid && $isBankTransfer && !$isPaymentCalendar) {
            $qrSettings = $this->paymentQrSettings->find((int) ($invoice['supplier_id'] ?? 0));
            $qrUri = $this->qr->generate(
                (string) $invoice['currency'],
                $remaining,
                VariableSymbolNormalizer::forInvoicePayment($invoice),
                $bankData,
                (string) ($supplierData['display_name'] ?? $supplierData['company_name'] ?? 'MyÚčto.cz'),
                PaymentQrDueDate::parse($invoice['due_date'] ?? null),
                includeDueDate: (bool) ($qrSettings[SupplierPaymentQrSettingsRepository::INVOICE_FIELD] ?? false),
            );
        }

        $locale = $invoice['language'] ?? 'cs';
        $cssPath = Bootstrap::rootDir() . '/styles/invoice.css';
        $css = $includeCss
            ? (is_file($cssPath) ? (string) file_get_contents($cssPath) : '')
            : '';
        if ($includeCss && $css !== '') {
            $css .= $this->brandThemeCss($supplierData);
        }

        // Locale pro `t()` — čte ho closure registrovaná v twig() (viz komentář tam).
        $this->locale = (string) $locale;
        $twig = $this->twig();

        $logoPath = $this->resolveLogoPath($supplierData, (int) ($invoice['supplier_id'] ?? 0));

        $clientCountry = strtoupper(trim((string) ($clientData['country_iso2'] ?? '')));
        $hidePdfCzkRecap = $clientCountry !== '' && $clientCountry !== 'CZ';
        $pdfCzkVat = [];
        if ($hidePdfCzkRecap
            && ($invoice['invoice_type'] ?? '') !== 'proforma'
            && is_array($invoice['czk_recap'] ?? null)
        ) {
            $exchangeRate = (float) ($invoice['czk_recap']['rate'] ?? 0);
            $items = (array) ($invoice['items'] ?? []);
            $pdfCzkVat = CzkRecap::buildCzechVatForDocument($items, $exchangeRate);

            if ($items === []) {
                foreach ((array) ($invoice['czk_recap']['breakdown'] ?? []) as $row) {
                    if (abs((float) ($row['vat_czk'] ?? 0)) < 0.000001) continue;
                    $pdfCzkVat[] = [
                        'rate'    => (float) ($row['rate'] ?? 0),
                        'vat_czk' => (float) $row['vat_czk'],
                    ];
                }
            }
        }

        $invoice['items'] = $this->withQuantityDecimals(TimeBillingPdfPresenter::invoiceItems(
            $this->withTrackingLines($this->withBaseQuantity($invoice), (int) ($invoice['supplier_id'] ?? 0), (string) $locale),
            !empty($invoice['prices_include_vat']),
        ));
        $lineDiscountColumns = false;
        if (self::hasLineDiscount($invoice['items']) && $this->showsLineDiscount((int) ($invoice['supplier_id'] ?? 0))) {
            $invoice['items'] = self::withLineDiscount(
                $invoice['items'],
                !empty($invoice['prices_include_vat']),
                !empty($invoice['reverse_charge']),
                (string) $locale,
            );
            $lineDiscountColumns = true;
        }
        $workReport = $includeWorkReport
            ? $this->workReports->findByInvoice((int) $invoice['id'], (int) ($invoice['supplier_id'] ?? 0))
            : null;
        if ($workReport !== null) {
            $workReport = TimeBillingPdfPresenter::workReport($workReport);
        }

        $vars = [
            'invoice'           => $invoice,
            'supplier'          => $supplierData,
            'client'            => $clientData,
            // Zjednodušený doklad s volbou „netisknout odběratele" (migrace 2037).
            'hide_customer'     => SimplifiedDocumentPolicy::hidesCustomer($invoice, $supplierData),
            'bank'              => $bankData,
            'qr_data_uri'       => $qrUri,
            // Platební VS = samostatný payment_variable_symbol, jinak číslice z čísla
            // dokladu (max 10). Velký titulek dokladu zůstává s pomlčkou, do platebního
            // řádku tiskneme validní VS (shodné s QR a párováním).
            'payment_varsymbol' => VariableSymbolNormalizer::forInvoicePayment($invoice),
            'is_paid'           => $isPaid,
            'payment_method'    => $paymentMethod,
            // Vyúčtování s výsledkem k vyplacení: místo výzvy k úhradě „K vrácení"
            // s kladnou částkou. Dobropis má vlastní texty a sem nepatří.
            'refund_amount'     => self::refundInvoiceAmount($invoice),
            'locale'            => $locale,
            'doc_type_label'    => $this->docTypeLabel($invoice, $locale, $supplierData),
            'doc_title'         => $this->docTitle($invoice),
            'parent_varsymbol'  => $this->parentVarsymbol($invoice),
            'work_report'       => $workReport,
            'date_format'       => $locale === 'en' ? 'M j, Y' : 'j. n. Y',
            'decimal_sep'       => $locale === 'en' ? '.' : ',',
            // Nezlomitelná mezera (NBSP, U+00A0) jako oddělovač tisíců — mPDF v úzkých
            // číselných buňkách (Cena/j, Bez/S DPH) láme i přes white-space:nowrap; NBSP
            // drží celé číslo „298 833,00" na jednom řádku spolehlivě.
            'thousand_sep'      => $locale === 'en' ? ',' : "\u{00A0}",
            'css'               => $css,
            'logo_path'         => $logoPath,
            // Opt-in: vedle loga vykreslit i název firmy (migrace 0058). Jen když logo
            // reálně je — bez loga se název ukazuje vždy (textový brand-name fallback).
            'logo_show_name'    => $logoPath !== null && !empty($supplierData['pdf_logo_show_name']),
            'issued_by_label'   => $this->issuedByLabel($invoice),
            'isdoc_attachment'  => $hasIsdocAttachment, // bool — badge gate
            'hide_pdf_czk_recap'=> $hidePdfCzkRecap,
            'pdf_czk_vat'       => $pdfCzkVat,
            // Doložka o odvodu daně v režimu OSS (§ 110a a násl. ZDPH). Doklad s cizí
            // sazbou musí říct, PROČ ta sazba na něm je — jinak ji příjemce i účetní
            // čtou jako českou daň ve špatné sazbě. Protějšek RC doložky výše.
            'oss_clause'        => $this->ossClause($invoice),
            // § 31a ZDPH — rozpis plateb na předem stanovené období. Právě ten dělá
            // z platebního kalendáře daňový doklad; bez něj se doklad tiskl jako běžná
            // faktura s jediným datem splatnosti, tedy bez toho jediného, kvůli čemu
            // vzniká. U ostatních typů dokladu zůstává prázdný.
            'payment_schedule'  => InvoiceCalendar::hasSchedule($invoice['invoice_type'] ?? null)
                ? $this->paymentSchedule->forInvoice((int) $invoice['supplier_id'], (int) $invoice['id'])
                : [],
            // Sloupce „Cena/j před slevou | Sleva | Cena/j po slevě" (migrace 2035).
            'line_discount_columns' => $lineDiscountColumns,
        ];
        return $twig->render('invoice.twig', $vars);
    }

    /**
     * Má doklad aspoň jeden řádek se slevou z ceníkové ceny? Přirážka (záporná sleva)
     * se nepočítá, ta se zákazníkovi nerozepisuje.
     *
     * @param array<int|string,mixed> $items
     */
    private static function hasLineDiscount(array $items): bool
    {
        foreach ($items as $item) {
            if (is_array($item) && self::lineDiscountPct($item) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $item */
    private static function lineDiscountPct(array $item): ?float
    {
        if (($item['item_kind'] ?? 'standard') !== 'standard' || ($item['list_unit_price'] ?? null) === null) {
            return null;
        }
        $pct = $item['line_discount_pct'] ?? null;
        return $pct !== null && (float) $pct > 0 ? (float) $pct : null;
    }

    /**
     * Volba `supplier.invoice_pdf_show_line_discount` (migrace 2035). Čte se živě jako
     * rozpis balení: je to volba vzhledu, ne údaj dokladu, a snapshot dodavatele ji
     * u starších dokladů nemá.
     */
    private function showsLineDiscount(int $supplierId): bool
    {
        if ($supplierId <= 0) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('SELECT invoice_pdf_show_line_discount FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * Řádkům se slevou doplní `pdf_list_unit_price` (cena za jednotku před slevou bez DPH)
     * a `pdf_discount_label` (procento na nejvýš dvě místa, bez koncových nul). Ostatní řádky, přirážku
     * i hlavičkový slevový řádek nechá beze změny: ty tisknou jen výslednou cenu.
     *
     * Cena před slevou je ve stejném základu jako `pdf_unit_price`. V režimu „ceny
     * s DPH" nese řádek brutto, proto se ceníková cena převede poměrem netto/brutto
     * téhož řádku (sedí i na přenesenou daňovou povinnost, kde je poměr 1). Řádek
     * se 100% slevou poměr nemá, ceník se převede sazbou.
     *
     * @param array<int|string,mixed> $items
     * @return array<int|string,mixed>
     */
    private static function withLineDiscount(array $items, bool $pricesIncludeVat, bool $reverseCharge, string $locale): array
    {
        foreach ($items as $i => $item) {
            $pct = is_array($item) ? self::lineDiscountPct($item) : null;
            if ($pct === null) {
                continue;
            }
            $list = (float) $item['list_unit_price'];
            if ($pricesIncludeVat) {
                $stored = (float) ($item['unit_price_without_vat'] ?? 0);
                $rate = (float) ($item['vat_rate_snapshot'] ?? 0);
                $list = abs($stored) > 0.0000001
                    ? $list * (float) ($item['pdf_unit_price'] ?? $stored) / $stored
                    : ($reverseCharge ? $list : $list * 100 / (100 + $rate));
            }
            $decimals = (int) ($item['unit_price_decimals'] ?? 2);
            $items[$i]['pdf_list_unit_price'] = round($list, max(2, $decimals));
            // Na dokladu stačí dvě desetinná místa (16,67 %), uložené procento má tři.
            $items[$i]['pdf_discount_label'] = rtrim(rtrim(number_format($pct, 2, $locale === 'en' ? '.' : ',', ''), '0'), '.,');
        }
        return $items;
    }

    /**
     * Podklad pro OSS doložku na dokladu — public stejně jako resolve*(), protože
     * ho vedle PDF potřebuje i veřejný HTML náhled (PublicInvoiceGetAction).
     * Rozhodnutí samo dělá {@see OssInvoiceClause}, tady se k němu jen doplní
     * názvy států z číselníku zemí.
     *
     * @param array<string,mixed> $invoice
     * @return array{all_items:bool, countries:list<array{iso2:string,name_cs:string,name_en:string}>}|null
     */
    /**
     * Částka k vrácení u faktury se zápornou částkou k úhradě ({@see RefundDocument}),
     * null u všech ostatních dokladů. Finální faktura ze zálohy (parent) sem nepatří.
     */
    public static function refundInvoiceAmount(array $invoice): ?float
    {
        if (($invoice['invoice_type'] ?? '') !== 'invoice'
            || (int) ($invoice['parent_invoice_id'] ?? 0) > 0
            || !RefundDocument::isRefundDocument($invoice)
        ) {
            return null;
        }

        return RefundDocument::refundAmount($invoice);
    }

    public function ossClause(array $invoice): ?array
    {
        $items = (array) ($invoice['items'] ?? []);
        $codes = OssInvoiceClause::consumerCountryCodes($items);
        $names = [];
        if ($codes !== []) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT iso2, name_cs, name_en FROM countries WHERE iso2 IN ($in)"
            );
            $stmt->execute($codes);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $names[strtoupper((string) $row['iso2'])] = [
                    'name_cs' => $row['name_cs'] ?? null,
                    'name_en' => $row['name_en'] ?? null,
                ];
            }
        }
        return OssInvoiceClause::build($items, $names);
    }

    /**
     * @param bool $bankInFooter bankovní spojení v patičce (migrace 2198): dlouhý název banky
     *        nebo zahraniční IBAN patičku zalomí, spodní okraj proto roste s její výškou
     *        (`stretch` = nejméně 18 mm). Bez volby zůstává pevný okraj.
     */
    private function newMpdf(string $tmpDir, bool $bankInFooter = false): Mpdf
    {
        return new Mpdf([
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'margin_top'        => 15,
            'margin_bottom'     => 18,
            'margin_left'       => 12,
            'margin_right'      => 12,
            'tempDir'           => $tmpDir,
            'autoPageBreak'     => true,
            ...($bankInFooter ? ['setAutoBottomMargin' => 'stretch'] : []),
            ...MpdfFontConfig::options(),
        ]);
    }

    /** Volba firmy `invoice_pdf_bank_in_footer` (migrace 2198), čtená stejně jako šablonou. */
    private function bankInFooter(array $invoice): bool
    {
        return !empty($this->resolveSupplier($invoice)['invoice_pdf_bank_in_footer']);
    }

    /**
     * Per-supplier branding accent — přebarví fialové akcenty PDF (#3B2D83 + sekundární
     * labely #6753AE) na barvu zvolenou dodavatelem (`email_accent_color`). Vrací override
     * CSS blok připojovaný ZA base invoice.css (vyšší priorita díky pořadí + stejná
     * specificita).
     *
     * Gating stejný jako logo: jen když má dodavatel zapnutý branding toggle
     * (`email_branding_enabled`) a nedefaultní hex barvu — pro #3B2D83 negenerujeme nic,
     * ten je už v base CSS.
     *
     * Sémantické barvy (dobropis červená .head.credit-note, storno šedá .cancellation,
     * RC amber, UHRAZENO zelená) NEpřebarvujeme — credit-note/cancellation selektory mají
     * vyšší specificitu (2 třídy), takže tenhle 1-třídový override je nepřebije.
     *
     * Kromě popředí (texty/hlavičky) přebarvujeme i světlé plochy a tenké linky, které
     * jsou v base napevno odvozené od defaultní fialové — světlé varianty akcentu počítá
     * AccentColor::tint() (mix s bílou). Šedá paleta CZK rekapitulace (bg #F2F2F2/…) je
     * záměrně neutrální, tu necháváme být — barvíme jen její fialové linky/text.
     */
    private function brandThemeCss(array $supplier): string
    {
        // Sdíleno s výkazem víceprací, viz PdfBranding::themeCss (černobílá varianta
        // `invoice_pdf_monochrome` akcent nahrazuje) a PdfBranding::accentCss.
        return PdfBranding::themeCss($supplier);
    }

    /**
     * Invaliduje cached PDF všech draftů dodavatele — volá se po změně brandingu
     * (barva/logo/toggle), protože ty se v PDF renderují živě (nejsou ve snapshotu),
     * ale mtime-based cache je sama od sebe neobnoví. Drafty mažeme bez archive entry
     * (archive:false) — jsou to jen preview, ne odeslané doklady. Vystavené faktury
     * regenerují při příští změně šablony/CSS/kódu nebo přes ?regenerate=1.
     *
     * Vrací počet invalidovaných draftů.
     */
    public function invalidateDraftsBySupplier(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM invoices WHERE supplier_id = ? AND status = "draft"'
        );
        $stmt->execute([$supplierId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($ids as $id) $this->invalidate($id, 'invalidate_branding', archive: false);
        return count($ids);
    }

    /**
     * Invaliduje cached CZK PDF, jejichž QR může obsahovat SPAYD datum splatnosti.
     * Vystavené verze archivuje, draft preview pouze smaže. Volá se jen při
     * skutečné změně invoice_qr_include_due_date.
     */
    public function invalidatePaymentQrBySupplier(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.status
               FROM invoices i
               JOIN currencies c ON c.id = i.currency_id
              WHERE i.supplier_id = ?
                AND c.code = "CZK"
                AND i.status <> "paid"
                AND i.payment_method = "bank_transfer"
                AND i.invoice_type NOT IN ("payment_calendar", "rent_calendar", "advance_calendar")
                AND i.varsymbol IS NOT NULL AND i.varsymbol <> ""
                AND (i.pdf_path IS NOT NULL OR i.pdf_generated_at IS NOT NULL)'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $this->invalidate(
                (int) $row['id'],
                'invalidate_payment_qr_settings',
                archive: (string) $row['status'] !== 'draft',
            );
        }
        return count($rows);
    }

    private function twig(): Environment
    {
        if ($this->twig !== null) {
            return $this->twig;
        }

        $loader = new FilesystemLoader(dirname(__DIR__, 3) . '/templates/invoice');
        $this->twig = new Environment($loader, [
            'autoescape' => 'html',
            'strict_variables' => false,
        ] + TwigCache::options('invoice'));

        // `t` se registruje jen jednou (addFunction() jde volat pouze před prvním
        // renderem). Locale proto NESMÍ být zachycené `use ($locale)` — closure
        // čte mutovatelný stav, který renderHtml() přenastaví před každým renderem.
        $this->twig->addFunction(new \Twig\TwigFunction('t', function (string $cs, string $en) {
            return $this->locale === 'en' ? $en : $cs;
        }));

        return $this->twig;
    }

    /** @param array<string,mixed> $supplier @param array<string,mixed> $invoice */
    private function applyLiveBrandingProfile(array $supplier, array $invoice): array
    {
        if (empty($invoice['branding_profile_id'])) return $supplier;
        $profile = $this->snapshots->effectiveBrandingProfile(
            (int) ($invoice['supplier_id'] ?? 0),
            (int) $invoice['branding_profile_id'],
        );
        if ($profile === null) return $supplier;
        return \MyInvoice\Service\Branding\BrandingProfileOverlay::apply($supplier, $profile);
    }

    /**
     * resolveSupplier/resolveClient/resolveBank jsou public — kromě PDF renderu
     * je používá i veřejná web faktura (PublicInvoiceGetAction), aby HTML náhled
     * ukazoval STEJNÁ data jako PDF (snapshot-first s defensive merge).
     */
    public function resolveSupplier(array $invoice): array
    {
        $live = $this->getSupplierData((int) ($invoice['supplier_id'] ?? 0));
        $snap = null;
        if (!empty($invoice['supplier_snapshot'])) {
            $decoded = is_string($invoice['supplier_snapshot']) ? json_decode($invoice['supplier_snapshot'], true) : $invoice['supplier_snapshot'];
            if (is_array($decoded)) {
                $snap = $decoded;
            }
        }
        // Defensive merge: snapshot je primární (zachovává historické údaje), ale chybějící
        // klíče (př. legacy snapshoty bez street) doplníme z live supplier dat. Zabrání
        // tomu, aby se vystavená faktura ze stubu vykreslila jen s názvem firmy.
        $row = $snap !== null ? array_merge($live, $snap) : $live;

        // Plátcovství DPH ale z live řádku doplňovat NESMÍME — je to cache dneška.
        // Draft (bez snapshotu) i legacy snapshot bez is_vat_payer dostanou stav
        // k rozhodnému datu dokladu z historie (VatStatusService).
        if (($snap === null || !array_key_exists('is_vat_payer', $snap))
            && !empty($invoice['supplier_id'])
            && $row !== []
        ) {
            $row['is_vat_payer'] = $this->vatStatus->isVatPayerAt(
                (int) $invoice['supplier_id'],
                (string) (($invoice['tax_date'] ?? null) ?: ($invoice['issue_date'] ?? date('Y-m-d'))),
            );
        }
        return $row;
    }

    public function resolveClient(array $invoice): array
    {
        // Defensive merge: snapshot je primární (historický stav), live data
        // doplní chybějící klíče. Bez merge by legacy/cizí snapshoty (import
        // z ISDOC/Pohody, ruční insert) vykreslily fakturu s prázdnou adresou.
        $live = [];
        if (!empty($invoice['client_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT c.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
                   FROM clients c JOIN countries co ON co.id = c.country_id
                  WHERE c.id = ?'
            );
            $stmt->execute([$invoice['client_id']]);
            $live = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        }
        if (!empty($invoice['client_snapshot'])) {
            $snap = is_string($invoice['client_snapshot']) ? json_decode($invoice['client_snapshot'], true) : $invoice['client_snapshot'];
            if (is_array($snap)) {
                return array_merge($live, $snap);
            }
        }
        return $live;
    }

    public function resolveBank(array $invoice): ?array
    {
        // Live data z currencies (account/bank/IBAN/BIC podle currency_id).
        // Stejný defensive-merge pattern jako u supplier/client — snapshot vyhrává,
        // live doplní chybějící IBAN/BIC/bank_name v legacy snapshotech.
        $live = [];
        if (!empty($invoice['currency_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT account_number, bank_code, bank_name, iban, bic FROM currencies WHERE id = ?'
            );
            $stmt->execute([(int) $invoice['currency_id']]);
            $live = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        }
        $row = $live;
        if (!empty($invoice['bank_snapshot'])) {
            $snap = is_string($invoice['bank_snapshot']) ? json_decode($invoice['bank_snapshot'], true) : $invoice['bank_snapshot'];
            if (is_array($snap)) {
                $row = array_merge($live, $snap);
                // Snapshot je primární (historický stav účtu), ALE prázdná hodnota ve snapshotu
                // nesmí přebít neprázdné live pole. Týká se hlavně `bank_name`: starší faktury
                // se vystavily dřív, než CRPDPH enrichment doplnil název banky do currencies →
                // snapshot má bank_name='' a array_merge ho nechá vyhrát (banka se netiskne).
                // Název banky je jen popisek bankovního kódu, takže ho doplníme z live JEN když
                // jde o stejný účet (shodný bank_code) — jinak by mohl popisovat jiný účet.
                $sameAccount = (string) ($snap['bank_code'] ?? '') === (string) ($live['bank_code'] ?? '')
                    && (string) ($snap['iban'] ?? '') === (string) ($live['iban'] ?? '');
                if ($sameAccount) {
                    foreach (['bank_name', 'bic'] as $k) {
                        if (empty($row[$k]) && !empty($live[$k])) {
                            $row[$k] = $live[$k];
                        }
                    }
                }
            }
        }
        if (empty($row)) return null;
        $hasCzk = !empty($row['account_number']) && !empty($row['bank_code']);
        $hasIban = !empty($row['iban']);
        return ($hasCzk || $hasIban) ? $row : null;
    }

    /**
     * Vybraná sériová čísla a šarže řádku (migrace 2016) pod popisem položky:
     * „Výr. č.: VIN1, VIN2“ a „Šarže: L1 (exp. 31. 12. 2027): 2 ks, L2: 1 ks“.
     * Tiskne se vždy, když řádek výběr má: VIN na faktuře za vozidlo potřebuje
     * kupující k registraci, šarže slouží k dohledání při stažení zboží.
     *
     * Množství šarže je vždy v základní jednotce karty, i když řádek fakturuje balení
     * a firma rozpis balení na PDF netiskne (`base_unit` z withBaseQuantity pak chybí).
     *
     * @param array<int|string,mixed> $items
     * @return array<int|string,mixed>
     */
    private function withTrackingLines(array $items, int $supplierId, string $locale): array
    {
        $en = $locale === 'en';
        $lotLines = [];
        foreach ($items as $i => $item) {
            if (is_array($item) && !empty($item['stock_item_id']) && is_array($item['tracking_allocations'] ?? null)
                && array_filter($item['tracking_allocations'], static fn ($a): bool => is_array($a) && !empty($a['lot_code'])) !== []) {
                $lotLines[$i] = ['stock_item_id' => (int) $item['stock_item_id'], 'unit' => (string) ($item['unit'] ?? '')];
            }
        }
        $baseUnits = $lotLines !== [] && $supplierId > 0
            ? array_map(static fn (array $ratio): string => $ratio['base_unit'], (new \MyInvoice\Service\Stock\StockUnitConverter($this->db))->ratios($supplierId, $lotLines))
            : [];
        foreach ($items as $i => $item) {
            $allocations = is_array($item) ? ($item['tracking_allocations'] ?? []) : [];
            if (!is_array($allocations) || $allocations === []) {
                continue;
            }
            $unit = (string) ($baseUnits[$i] ?? $item['base_unit'] ?? $item['unit'] ?? '');
            $serials = [];
            $lots = [];
            foreach ($allocations as $a) {
                if (!empty($a['serial_number'])) {
                    $serials[] = (string) $a['serial_number'];
                } elseif (!empty($a['lot_code'])) {
                    $expires = !empty($a['expires_on'])
                        ? ' (exp. ' . date($en ? 'M j, Y' : 'j. n. Y', (int) strtotime((string) $a['expires_on'])) . ')'
                        : '';
                    $qty = rtrim(rtrim(number_format((float) $a['quantity'], 3, $en ? '.' : ',', ''), '0'), '.,');
                    $lots[] = $a['lot_code'] . $expires . ': ' . $qty . ($unit !== '' ? ' ' . $unit : '');
                }
            }
            $lines = [];
            if ($serials !== []) {
                $lines[] = ($en ? 'Serial no.: ' : 'Výr. č.: ') . implode(', ', $serials);
            }
            if ($lots !== []) {
                $lines[] = ($en ? 'Lot: ' : 'Šarže: ') . implode(', ', $lots);
            }
            $items[$i]['tracking_lines'] = $lines;
        }
        return $items;
    }

    /**
     * Pevný počet desetinných míst množství podle jednotky (2036). Řádek s jednotkou,
     * která nastavení nemá, dostane null a šablona tiskne dnešní adaptivní formát.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function withQuantityDecimals(array $items): array
    {
        if ($items === []) {
            return $items;
        }
        $map = (new UnitPrecision($this->db))->decimalsByUnit();
        foreach ($items as &$item) {
            $item['quantity_decimals'] = UnitPrecision::printDecimals(
                $item['quantity'] ?? null,
                $map[UnitPrecision::key((string) ($item['unit'] ?? ''))] ?? null,
            );
        }
        unset($item);
        return $items;
    }

    /**
     * Balení na PDF (issue #17): řádek skladové karty fakturovaný v balení dostane
     * `base_qty` a `base_unit` a šablona pod jednotku doplní „(celkem 80 ks)".
     * Přepíná `supplier.invoice_pdf_show_base_qty` (čte se živě — je to volba
     * vzhledu, ne údaj dokladu). Převod dělá jen StockUnitConverter; řádek
     * v základní nebo neznámé jednotce zůstane beze změny.
     *
     * @param array<string,mixed> $invoice
     * @return list<array<string,mixed>>|array<int|string,mixed>
     */
    private function withBaseQuantity(array $invoice): array
    {
        $items = (array) ($invoice['items'] ?? []);
        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        $lines = [];
        foreach ($items as $i => $item) {
            if (is_array($item) && !empty($item['stock_item_id']) && trim((string) ($item['unit'] ?? '')) !== '') {
                $lines[$i] = ['stock_item_id' => (int) $item['stock_item_id'], 'unit' => (string) $item['unit']];
            }
        }
        if ($lines === [] || $supplierId <= 0) {
            return $items;
        }
        // Jen firma se zapnutým skladem A zapnutým rozpisem — jinak PDF beze změny.
        $flag = $this->db->pdo()->prepare('SELECT invoice_pdf_show_base_qty, stock_enabled FROM supplier WHERE id = ?');
        $flag->execute([$supplierId]);
        $settings = $flag->fetch(\PDO::FETCH_ASSOC);
        if ($settings === false || (int) $settings['invoice_pdf_show_base_qty'] !== 1 || (int) $settings['stock_enabled'] !== 1) {
            return $items;
        }
        $converter = new \MyInvoice\Service\Stock\StockUnitConverter($this->db);
        foreach ($converter->ratios($supplierId, $lines) as $i => $ratio) {
            if ($ratio['is_base']) {
                continue;
            }
            $items[$i]['base_qty'] = (float) \MyInvoice\Service\Stock\StockUnitConverter::applyRatio(
                (string) ($items[$i]['quantity'] ?? '0'),
                $ratio['numerator'],
                $ratio['denominator'],
            );
            $items[$i]['base_unit'] = $ratio['base_unit'];
        }
        return $items;
    }

    /**
     * Řádek „Vystavil" (migrace 2032): jméno zapsané při vystavení, u starších
     * dokladů a konceptů autor dokladu. Přepíná `supplier.invoice_pdf_show_issued_by`
     * a čte se živě jako {@see withBaseQuantity()}: je to volba vzhledu, ne údaj dokladu.
     *
     * @param array<string,mixed> $invoice
     */
    private function issuedByLabel(array $invoice): ?string
    {
        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            return null;
        }
        $name = trim((string) ($invoice['issued_by_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($invoice['created_by_name'] ?? ''));
        }
        if ($name === '') {
            return null;
        }
        $flag = $this->db->pdo()->prepare('SELECT invoice_pdf_show_issued_by FROM supplier WHERE id = ?');
        $flag->execute([$supplierId]);
        return (int) $flag->fetchColumn() === 1 ? $name : null;
    }

    private function getSupplierData(int $supplierId): array
    {
        if ($supplierId <= 0) return [];
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, co.iso2 AS country_iso2, co.name_cs AS country_name_cs, co.name_en AS country_name_en
               FROM supplier s JOIN countries co ON co.id = s.country_id WHERE s.id = ?'
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function docTypeLabel(array $invoice, string $locale, array $supplier = []): string
    {
        // Identifikovaná osoba (§ 6g–6l, #94): její RC faktura do EU JE daňový
        // doklad (povinnost ho vystavit do 15 dnů od konce měsíce, § 28) —
        // label „daňový doklad" si zaslouží stejně jako plátcovská.
        $isVatPayer = (bool) ($supplier['is_vat_payer'] ?? true)
            || ((bool) ($supplier['is_identified'] ?? false) && !empty($invoice['reverse_charge']));
        $labels = [
            'cs' => [
                'invoice'      => $isVatPayer ? 'Faktura — daňový doklad' : 'Faktura',
                'proforma'     => 'Zálohová faktura',
                'credit_note'  => $isVatPayer ? 'Opravný daňový doklad' : 'Opravná faktura',
                'cancellation' => 'Storno (interní)',
                'tax_document' => 'Daňový doklad k přijaté platbě',
                // § 31a ZDPH: platební kalendář JE daňový doklad, obsahuje-li rozpis plateb
                // na předem stanovené období. Bez vlastního popisku se tiskl jako „Faktura"
                // a příjemce z něj nepoznal, o jaký doklad jde.
                'payment_calendar' => $isVatPayer ? 'Platební kalendář — daňový doklad' : 'Platební kalendář',
                'rent_calendar' => $isVatPayer ? 'Splátkový kalendář — daňový doklad' : 'Splátkový kalendář',
                'advance_calendar' => $isVatPayer ? 'Platební kalendář — daňový doklad' : 'Platební kalendář',
            ],
            'en' => [
                'invoice'      => $isVatPayer ? 'Invoice — Tax document' : 'Invoice',
                'proforma'     => 'Proforma invoice',
                'credit_note'  => $isVatPayer ? 'Credit note — Tax adjustment' : 'Credit note',
                'cancellation' => 'Cancellation (internal)',
                'tax_document' => 'Tax document for payment received',
                'payment_calendar' => $isVatPayer ? 'Payment calendar — Tax document' : 'Payment calendar',
                'rent_calendar' => $isVatPayer ? 'Rent instalment calendar — Tax document' : 'Rent instalment calendar',
                'advance_calendar' => $isVatPayer ? 'Payment calendar — Tax document' : 'Payment calendar',
            ],
        ];
        // § 30 ZDPH: zjednodušený daňový doklad se jako takový označí, jinak by příjemce
        // nepoznal, proč na dokladu chybí odběratel.
        if (!empty($invoice['is_simplified']) && ($invoice['invoice_type'] ?? '') === 'invoice' && $isVatPayer) {
            return $locale === 'en' ? 'Simplified tax document' : 'Zjednodušený daňový doklad';
        }
        return $labels[$locale][$invoice['invoice_type']] ?? $labels['cs'][$invoice['invoice_type']] ?? '';
    }

    private function docTitle(array $invoice): string
    {
        $vs = $invoice['varsymbol'] ?? ('DRAFT-' . $invoice['id']);
        $t = match ($invoice['invoice_type']) {
            'proforma'     => 'Zálohová faktura',
            'credit_note'  => 'Dobropis',
            'cancellation' => 'Storno',
            'tax_document' => 'Daňový doklad k platbě',
            'payment_calendar', 'advance_calendar' => 'Platební kalendář',
            'rent_calendar' => 'Splátkový kalendář',
            default        => !empty($invoice['is_simplified']) ? 'Zjednodušený daňový doklad' : 'Faktura',
        };
        return "$t $vs";
    }

    private function parentVarsymbol(array $invoice): ?string
    {
        if (!$invoice['parent_invoice_id']) return null;
        $stmt = $this->db->pdo()->prepare('SELECT varsymbol FROM invoices WHERE id = ?');
        $stmt->execute([$invoice['parent_invoice_id']]);
        return $stmt->fetchColumn() ?: null;
    }

    private function resolveLogoPath(array $supplier, int $supplierIdFallback = 0): ?string
    {
        // Logo se v PDF zobrazí jen když má dodavatel zapnutý branding
        // (`email_branding_enabled` = 1). Pokud je toggle vypnutý, vykreslí se
        // textový brand-name fallback. Stejný toggle gatuje branding emailů,
        // takže UX je konzistentní napříč PDF i emaily.
        if (empty($supplier['email_branding_enabled'])) return null;

        $logoPath = $supplier['logo_path'] ?? null;
        if (!$logoPath) return null;

        // SafeLogoPath: defense-in-depth proti podstrčenému logo_path (security
        // report @andrejtomci #2). Mass-assign už je zavřený, ale tohle je 2.
        // vrstva pro případ legacy snapshotu s bad-value nebo jiné cesty vstupu.
        // Pokud snapshot postrádá `id`, použijeme fallback z invoice.supplier_id.
        $supplierId = (int) ($supplier['id'] ?? $supplierIdFallback);
        $abs = \MyInvoice\Service\Mail\SafeLogoPath::resolve((string) $logoPath, $supplierId);
        if ($abs === null) return null;

        // V PDF preferujeme SVG sidecar (vektor = crisp v libovolném zoomu/tisku);
        // PNG je fallback pokud SVG chybí nebo obsahuje mPDF-nekompatibilní prvky.
        //
        // mPDF SVG renderer má známé limity: `<clipPath>`, `<use xlink:href>`,
        // `<mask>`, `<pattern>` a `<filter>` se často vykreslí černým fillem nebo
        // posunutě. Adobe Illustrator export tohle používá běžně. U takových SVG
        // fallneme na PNG (rasterizovaný SupplierLogoConverterem s alfa kanálem
        // = transparentní pozadí).
        //
        // `<linearGradient>` / `<radialGradient>` v blocklistu ZÁMĚRNĚ NEJSOU
        // (issue #37): mPDF je vykresluje správně a rastrový fallback naopak
        // rozbíjel loga, která mají vedle gradientu i `<text>` — rasterizace
        // závisí na fontech hostu, takže na serveru bez daného fontu z loga
        // zbylo jen barevné pozadí bez písmen.
        // Email vždy používá PNG (Outlook/Gmail SVG nepodporují) — to řeší
        // Mailer + InvoiceEmailVarsBuilder, ne tahle metoda.
        $svgSibling = preg_replace('/\.png$/i', '.svg', (string) $logoPath);
        if (is_string($svgSibling) && $svgSibling !== $logoPath) {
            $svgAbs = \MyInvoice\Service\Mail\SafeLogoPath::resolve($svgSibling, $supplierId);
            if ($svgAbs !== null && $this->svgIsMpdfCompatible($svgAbs)) {
                return $svgAbs;
            }
        }
        // PNG fallback: splácni alfa kanál na bílou — mPDF neumí SMask u truecolor
        // RGBA PNG a vykreslil by průhledné pozadí černě (issue #152).
        return PdfLogoFlattener::flattenedPath($abs);
    }

    /**
     * Detekce SVG features, které mPDF neumí korektně vykreslit.
     * Pokud SVG obsahuje něco z {clipPath, use, mask, pattern, filter},
     * vrátíme false → caller fallne na PNG variantu. Gradienty mPDF zvládá,
     * proto v blocklistu nejsou (issue #37).
     */
    private function svgIsMpdfCompatible(string $svgPath): bool
    {
        $svg = (string) @file_get_contents($svgPath);
        if ($svg === '') return false;
        // Známé problematické features v mPDF SVG rendereru
        $bad = '/<(?:clipPath|use|mask|pattern|filter)\b/i';
        return !preg_match($bad, $svg);
    }


    /**
     * Resnapshot supplier/client/bank z live dat a uloží do invoices. Volá se při
     * forceRegenerate, aby `regenerate=1` propsalo i změny v supplier/client/banku.
     * Drafty (bez existujících snapshotů) přeskoč — ty stejně renderují z live.
     *
     * Defensive guard: pro non-draft fakturu (issued/sent/reminded/paid) NIKDY
     * nepřepisuj snapshot, i když si někdo vynutí ?regenerate=1. Snapshoty u
     * vystavených faktur jsou immutable audit trail toho, co bylo na dokladu.
     *
     * @return array  invoice array s aktualizovanými snapshoty (in-memory)
     */
    private function refreshSnapshots(array $invoice): array
    {
        $status = (string) ($invoice['status'] ?? 'draft');
        if ($status !== 'draft') {
            return $invoice;
        }
        $hasAny = !empty($invoice['supplier_snapshot'])
            || !empty($invoice['client_snapshot'])
            || !empty($invoice['bank_snapshot']);
        if (!$hasAny) return $invoice;

        return $this->writeSnapshots($invoice);
    }

    /**
     * Přepíše snapshoty (supplier/client/bank) z aktuálních live dat — voláno při
     * admin force-editu VYSTAVENÉ faktury, aby se opravené údaje stran (adresa/IČO/
     * název odběratele, banka) promítly do nově generovaného PDF.
     *
     * Narozdíl od refreshSnapshots() (regenerate cesta, jen drafty) tahle metoda
     * ZÁMĚRNĚ přepíše snapshot i u issued/sent/paid faktury — je to vědomá oprava
     * dokladu, kterou UI uživateli avizuje („Změny přepíšou snapshoty"). Auditní
     * stopu (kdo/kdy/co) zajišťuje volající přes ActivityLogger.
     */
    public function rebuildSnapshots(int $invoiceId): void
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) return;
        $this->writeSnapshots($invoice);
    }

    /**
     * Postaví snapshoty z live dat a uloží do invoices. Sdílené jádro pro
     * refreshSnapshots() (drafty) i rebuildSnapshots() (force-edit vystavené).
     *
     * @return array  invoice array s aktualizovanými snapshoty (in-memory)
     */
    private function writeSnapshots(array $invoice): array
    {
        try {
            // Supplier část se staví k rozhodnému datu DOKLADU (tax ?? issue) — rebuild
            // opravuje adresy/názvy, nesmí přepsat historické plátcovství dnešní cache.
            $built = $this->snapshots->build(
                (int) $invoice['client_id'],
                (int) $invoice['currency_id'],
                (int) ($invoice['supplier_id'] ?? 0),
                isset($invoice['branding_profile_id']) ? (int) $invoice['branding_profile_id'] : null,
                (string) (($invoice['tax_date'] ?? null) ?: ($invoice['issue_date'] ?? date('Y-m-d'))),
            );
        } catch (\Throwable) {
            // Pokud klient/dodavatel neexistuje (smazaný), zachovej původní snapshot.
            return $invoice;
        }

        // Plátcovství klienta u VYSTAVENÉHO dokladu: živý stav dneška nemusí odpovídat
        // stavu při vystavení a klient historii nemá — zachovej hodnotu ze stávajícího
        // snapshotu, pokud existuje; jinak (legacy snapshot bez klíče) vezmi živou.
        if ((string) ($invoice['status'] ?? 'draft') !== 'draft' && !empty($invoice['client_snapshot'])) {
            $prevClient = is_string($invoice['client_snapshot'])
                ? json_decode($invoice['client_snapshot'], true)
                : $invoice['client_snapshot'];
            if (is_array($prevClient) && array_key_exists('is_vat_payer', $prevClient)) {
                $built['client']['is_vat_payer'] = $prevClient['is_vat_payer'] !== null
                    ? (bool) $prevClient['is_vat_payer']
                    : null;
            }
        }

        $supplierJson = json_encode($built['supplier'], JSON_UNESCAPED_UNICODE);
        $clientJson   = json_encode($built['client'], JSON_UNESCAPED_UNICODE);
        $bankJson     = $built['bank'] !== null ? json_encode($built['bank'], JSON_UNESCAPED_UNICODE) : null;

        $this->db->pdo()->prepare(
            'UPDATE invoices SET supplier_snapshot = ?, client_snapshot = ?, bank_snapshot = ? WHERE id = ?'
        )->execute([$supplierJson, $clientJson, $bankJson, (int) $invoice['id']]);

        $invoice['supplier_snapshot'] = $supplierJson;
        $invoice['client_snapshot']   = $clientJson;
        $invoice['bank_snapshot']     = $bankJson;
        return $invoice;
    }

    /**
     * Archivuje cached PDF (pokud existuje) a vynuluje invoices.pdf_path.
     * Volá se po změnách, které ovlivní obsah PDF nad rámec items
     * (např. work_report, edit faktury, vystavení).
     *
     * Reason je uložen do invoice_pdfs.reason a pomáhá v UI rozlišit,
     * proč se historická verze archivovala (edit / issue / workreport / ...).
     */
    public function invalidate(int $invoiceId, string $reason = 'invalidate_manual', bool $archive = true): void
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) return;

        $paths = array_unique(array_filter([
            self::resolvePdfPath($invoice['pdf_path'] ?? null),
            $this->cachePath($invoice),
        ]));
        foreach ($paths as $p) {
            if (!is_file($p)) continue;
            if ($archive) {
                // Archivuj místo unlink — zachová verzi pro audit a UI historii.
                // archive() přesune soubor do _archive/ (atomic rename, fallback copy+unlink).
                $this->archive->archive($invoiceId, $p, $reason);
            } else {
                // Pro draft cache (např. před alokací VS): jen smaž, bez archive entry.
                @unlink($p);
            }
        }
        $this->db->pdo()->prepare('UPDATE invoices SET pdf_path = NULL, pdf_generated_at = NULL WHERE id = ?')
            ->execute([$invoiceId]);
    }

    /**
     * Bulk invalidate — pro všechny faktury v dané měně, které renderují bank info live
     * (drafts + faktury bez snapshotu). Issued/sent/paid s bank_snapshot mají immutable kopii
     * bank údajů a invalidace by zbytečně regenerovala stejný PDF.
     *
     * Vrací počet invalidovaných faktur.
     */
    public function invalidateByCurrency(int $currencyId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM invoices WHERE currency_id = ? AND (status = "draft" OR bank_snapshot IS NULL)'
        );
        $stmt->execute([$currencyId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($ids as $id) $this->invalidate($id, 'invalidate_currency');
        return count($ids);
    }

    private function cachePath(array $invoice): string
    {
        $rootDir = Bootstrap::rootDir();
        $issueDate = new \DateTimeImmutable($invoice['issue_date']);
        // Multi-supplier: supplier subfolder zabraňuje kolizi varsymbolu mezi suppliery
        $supplierId = (int) ($invoice['supplier_id'] ?? 1);
        $dir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices') . '/sup-' . $supplierId . '/' . $issueDate->format('Y-m');

        $vs = $invoice['varsymbol'] ?? ('draft-' . $invoice['id']);
        // Sanitize varsymbol pro filesystem — defense-in-depth proti path traversal
        // přes importovaný varsymbol (security report @andrejtomci #3 — `varsymbol`
        // se sice už validuje na vstupu ImportService::processOne, ale tady je to
        // belt-and-braces pro případ legacy řádků v DB nebo jiných cest vstupu).
        $vs = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $vs);
        $type = match ($invoice['invoice_type']) {
            'proforma'     => 'Proforma',
            'credit_note'  => 'Dobropis',
            'cancellation' => 'Storno',
            'tax_document' => 'DanovyDoklad',
            'payment_calendar', 'advance_calendar' => 'PlatebniKalendar',
            'rent_calendar' => 'SplatkovyKalendar',
            default        => 'Faktura',
        };
        return "$dir/$type-$vs.pdf";
    }

    private function updatePdfPath(int $invoiceId, string $path): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices SET pdf_path = ?, pdf_generated_at = NOW() WHERE id = ?'
        )->execute([self::toRelativePdfPath($path), $invoiceId]);
    }

    /**
     * N-016: do `invoices.pdf_path` patří cesta RELATIVNÍ ke `storage/invoices`.
     *
     * Absolutní cesta přežije jen do prvního přesunu instance — v produkci se takhle
     * nasbíralo 43 řádků ze dvou různých kořenů (instance byla přejmenována) a žádný
     * z nich už netrefil existující soubor. Přijatá větev (`purchase_invoices.pdf_path`)
     * relativní cesty ukládá odjakživa, tohle je dorovnání na stejný tvar.
     *
     * Ne-katastrofa jen shodou okolností: sloupec je cache renderu, takže netrefená
     * cesta znamená zbytečné přegenerování PDF, ne ztracený doklad.
     */
    private static function toRelativePdfPath(string $absolute): string
    {
        $root = str_replace('\\', '/', \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices'));
        $norm = str_replace('\\', '/', $absolute);
        if ($root !== '' && str_starts_with($norm, rtrim($root, '/') . '/')) {
            return ltrim(substr($norm, strlen(rtrim($root, '/'))), '/');
        }
        // Cesta mimo očekávaný kořen (jiná konfigurace, test) — ulož, jak přišla.
        // Resolve níž si s absolutní hodnotou poradí.
        return $norm;
    }

    /**
     * Protějšek {@see toRelativePdfPath} — z uložené hodnoty udělá absolutní cestu.
     *
     * Musí snést i LEGACY absolutní hodnoty: backfill je převádí, ale řádky ze záloh
     * a z jiných prostředí se můžou objevit kdykoliv. Rozpoznání absolutní cesty
     * pokrývá i Windows (`C:/…`) a UNC (`//server/…`).
     */
    public static function resolvePdfPath(?string $stored): ?string
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return null;
        }
        $norm = str_replace('\\', '/', $stored);
        $isAbsolute = str_starts_with($norm, '/')
            || str_starts_with($norm, '//')
            || preg_match('#^[A-Za-z]:/#', $norm) === 1;

        return $isAbsolute
            ? $norm
            : rtrim(str_replace('\\', '/', \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices')), '/')
                . '/' . ltrim($norm, '/');
    }
}
