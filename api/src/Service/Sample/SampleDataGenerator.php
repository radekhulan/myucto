<?php

declare(strict_types=1);

namespace MyInvoice\Service\Sample;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingFrameworkImportGuard;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Repository\ManufacturerRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Repository\StockItemCategoryRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Repository\WarehouseRepository;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\PurchaseInvoice\PurchaseNumberSeries;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Eshop\CategoryTreeService;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Stock\StockDocumentService;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;

/**
 * Generuje rozsáhlý, deterministický a účetně konzistentní demonstrační dataset.
 * Základ tvoří 24 klientů, 36 zakázek, 120 vydaných faktur, 12 dobropisů,
 * 12 dodavatelů a 120 přijatých faktur. Pro právnickou osobu nebo plátce DPH
 * navíc zapne podvojné účetnictví a sklad, vytvoří 120 skladových dokladů,
 * 2 karty dlouhodobého a 4 karty drobného majetku, 6 bankovních výpisů s 233 pohyby, e-shopové číselníky
 * a vše zaúčtuje. Pro každou firmu navíc vytvoří pokladnu se sedmi doklady.
 * Sdílená logika pro `bin/sample.php` (CLI) i `SetupSampleAction` (HTTP wizard).
 *
 * Všechna jména, identifikátory, účty a kontakty jsou syntetické; e-maily používají
 * nedoručitelnou doménu example.invalid. Data jsou odvozena od data spuštění bez
 * náhodnosti, takže stejný den vznikne shodný scénář.
 */
final class SampleDataGenerator
{
    private const STOCK_NAMES = [
        'Síťový router', 'Wi-Fi access point', 'SSD disk 2 TB', 'Operační paměť 32 GB',
        'Dokovací stanice USB-C', 'Business notebook', 'LCD monitor 27 palců',
        'Mechanická klávesnice', 'Bezdrátová myš', 'Webkamera Full HD',
        'Headset pro videohovory', 'Záložní napájecí zdroj',
    ];
    private const STOCK_COSTS = [1450, 1950, 2100, 1650, 2400, 18400, 3900, 1250, 420, 1100, 1350, 3100];
    private const STOCK_PRICES = [2190, 2890, 2990, 2390, 3490, 24900, 5490, 1790, 690, 1590, 1990, 4490];
    private const CLIENT_COUNT = 24;
    private const PROJECT_COUNT = 36;
    private const INVOICE_COUNT = 120;
    private const CREDIT_NOTE_COUNT = 12;
    private const PURCHASE_COUNT = 120;
    private const BANK_STATEMENT_COUNT = 6;

    public function __construct(
        private readonly Connection $db,
        private readonly StatsRecomputer $stats,
        private readonly RecurringTemplateRepository $recurring,
        private readonly AccountingModeRepository $accountingModes,
        private readonly AccountingPeriodRepository $periods,
        private readonly ChartOfAccountsSeeder $coaSeeder,
        private readonly DocumentAutoPoster $autoPoster,
        private readonly InvoicePaymentService $invoicePayments,
        private readonly BankPostingService $bankPosting,
        private readonly BankPostingSuggestionRepository $bankSuggestions,
        private readonly PostingService $posting,
        private readonly SupplierBankAccountRepository $supplierBankAccounts,
        private readonly CashRegisterService $cashRegisters,
        private readonly CashDocumentService $cashDocuments,
        private readonly WarehouseRepository $warehouses,
        private readonly StockItemRepository $stockItems,
        private readonly ManufacturerRepository $manufacturers,
        private readonly StockItemCategoryRepository $stockItemCategories,
        private readonly CategoryTreeService $categoryTree,
        private readonly StockDocumentService $stockDocuments,
        private readonly AssetService $assetService,
        private readonly DepreciationPostingService $depreciation,
        private readonly SmallAssetService $smallAssets,
        private readonly SampleDocumentGenerator $sampleDocuments,
        private readonly SamplePayrollGenerator $samplePayroll,
        private readonly SampleWorkflowGenerator $sampleWorkflow,
        private readonly SampleRuleGenerator $sampleRules,
        private readonly SampleDimensionGenerator $sampleDimensions,
    ) {}

    /**
     * @return array<string,int|bool|list<string>>
     */
    public function generate(int $supplierId, int $adminUserId): array
    {
        $pdo = $this->db->pdo();
        $today = new \DateTimeImmutable('today');
        $frameworkGuard = new AccountingFrameworkImportGuard($this->db);
        $frameworkPeriods = $frameworkGuard->periodsForDates($supplierId, $today->modify('-2 years')->format('Y-01-01'), $today->format('Y-12-31'));
        $frameworkGuard->assertCz500Periods($supplierId, $frameworkPeriods);

        // Guard: sample data se generují JEN do prázdné DB. Bez této pojistky
        // šel `bin/sample.php` spustit i nad existujícími daty → duplicitní klienti/
        // faktury a pád na UNIQUE (cars.registration). HTTP wizard guard má taky
        // (SetupSampleAction), tady je sdílená pojistka pro CLI i wizard.
        $guard = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM clients          WHERE supplier_id = ?)
                  + (SELECT COUNT(*) FROM invoices         WHERE supplier_id = ?)
                  + (SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ?)
                  + (SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?)
                  + (SELECT COUNT(*) FROM cash_documents WHERE supplier_id = ?)
                  + (SELECT COUNT(*) FROM bank_statements WHERE supplier_id = ?)
                  + (SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?)'
        );
        $guard->execute(array_fill(0, 7, $supplierId));
        if ((int) $guard->fetchColumn() > 0) {
            throw new \RuntimeException(
                'Ukázková data nelze vygenerovat — pro tohoto dodavatele už existují klienti nebo doklady. '
                . 'Nejdřív je odeberte (Nastavení → Odebrat ukázková data) nebo spusťte `php api/bin/reset.php`.'
            );
        }

        // Kořenové entity vytvořené generátorem — na konci se zapíšou do
        // sample_data_entries, ať je lze později přesně odebrat (issue #162).
        $tracked = [];
        $track = static function (string $type, int $id) use (&$tracked): void {
            if ($id > 0) $tracked[$type . ':' . $id] = [$type, $id];
        };

        $resolveCurrency = function (string $code) use ($pdo, $supplierId): int {
            $stmt = $pdo->prepare(
                'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
            );
            $stmt->execute([$supplierId, strtoupper($code)]);
            $id = (int) $stmt->fetchColumn();
            if ($id === 0) {
                throw new \RuntimeException("Currency $code nenalezena pro supplier #$supplierId");
            }
            return $id;
        };
        $czkId = $resolveCurrency('CZK');
        $eurId = $resolveCurrency('EUR');
        $supplierStmt = $pdo->prepare(
            'SELECT company_name, taxpayer_type, is_vat_payer, accounting_mode, stock_enabled
               FROM supplier WHERE id = ?'
        );
        $supplierStmt->execute([$supplierId]);
        $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
        if ($supplier === false) {
            throw new \RuntimeException("Supplier #{$supplierId} neexistuje.");
        }
        $isVatPayer = (bool) ($supplier['is_vat_payer'] ?? false);
        $advanced = (string) ($supplier['taxpayer_type'] ?? '') === 'po' || $isVatPayer;

        // Vše v jedné transakci → při chybě (např. UNIQUE) se nic nezapíše a DB
        // nezůstane v polovičním stavu. Stats recompute běží AŽ po commitu, protože
        // StatsRecomputer si otevírá vlastní transakci (vnořené PDO transakce nejdou).
        $pdo->beginTransaction();
        try {
        $frameworkGuard->assertCz500Periods($supplierId, $frameworkPeriods, true);

        // RC flag (index 8) daňově smysluplně: tuzemští klienti BEZ reverse charge
        // (tuzemský RC §92a na IT služby neexistuje), EU klienti s DIČ (SK, DE)
        // S reverse charge — poskytnutí služby do JČS (ř.21 DPHDP3, kód 22, SHV).
        $customerNames = [
            'Demo Lípový ateliér s.r.o.', 'Demo Modrá dílna s.r.o.', 'Demo Hvězdná výroba a.s.',
            'Demo Javorová projekce s.r.o.', 'Demo Papírový svět s.r.o.', 'Demo Zelený obchod s.r.o.',
            'Demo Severní poradna s.r.o.', 'Demo Barevný interiér s.r.o.', 'Demo Měděná továrna s.r.o.',
            'Demo Digitální most s.r.o.', 'Demo Kancelář na kopci s.r.o.', 'Demo Malý trh s.r.o.',
            'Demo Výzkumná louka s.r.o.', 'Demo Datový sad s.r.o.', 'Demo Rychlý servis s.r.o.',
            'Demo Tvůrčí hnízdo s.r.o.', 'Demo Technický přístav s.r.o.', 'Demo Chytrá řešení s.r.o.',
            'Demo Vývojová zahrada s.r.o.', 'Demo Sluneční agentura s.r.o.', 'Demo Bratislava s.r.o.',
            'Demo Ukážka Košice s.r.o.', 'Demo Beispiel Nord GmbH', 'Demo Muster Süd GmbH',
        ];
        $cityProfiles = [
            ['Praha', '11000', 'CZ'], ['Brno', '60200', 'CZ'], ['Ostrava', '70200', 'CZ'],
            ['Plzeň', '30100', 'CZ'], ['Olomouc', '77900', 'CZ'], ['Liberec', '46001', 'CZ'],
            ['Hradec Králové', '50002', 'CZ'], ['Pardubice', '53002', 'CZ'],
            ['Bratislava', '81101', 'SK'], ['Košice', '04001', 'SK'],
            ['Berlin', '10115', 'DE'], ['München', '80331', 'DE'],
        ];
        $clients = [];
        foreach ($customerNames as $i => $company) {
            if ($i < 20) {
                [$city, $zip, $iso2] = $cityProfiles[$i % 8];
            } elseif ($i < 22) {
                [$city, $zip, $iso2] = $cityProfiles[8 + ($i - 20)];
            } else {
                [$city, $zip, $iso2] = $cityProfiles[10 + ($i - 22)];
            }
            $foreign = $iso2 !== 'CZ';
            $ic = $foreign ? null : str_pad((string) (12000000 + $i * 7919), 8, '0', STR_PAD_LEFT);
            $dic = $foreign ? $iso2 . (2000000000 + $i * 11731) : 'CZ' . $ic;
            $clients[] = [
                $company, $ic, $dic, 'Ukázková ' . (10 + $i), $zip, $city, $iso2,
                'fakturace+' . ($i + 1) . '@customer.example.invalid', $foreign ? 1 : 0,
                $iso2 === 'DE' ? 'en' : 'cs', $foreign ? $eurId : $czkId, $foreign ? 'EUR' : 'CZK',
            ];
        }

        $clientIds = [];
        $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        foreach ($clients as [$company, $ic, $dic, $street, $zip, $city, $iso2, $email, $rc, $lang, $currencyId, $currencyCode]) {
            $stmtCountry = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
            $stmtCountry->execute([$iso2]);
            $countryId = (int) $stmtCountry->fetchColumn() ?: $czId;
            $stmt = $pdo->prepare(
                'INSERT INTO clients (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email,
                                      language, currency_default_id, reverse_charge)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([$supplierId, $company, $ic, $dic, $street, $city, $zip, $countryId, $email, $lang, $currencyId, $rc]);
            $cid = (int) $pdo->lastInsertId();
            $clientIds[] = $cid;
            $track('client', $cid);
        }

        $projectNames = [
            'Správa webové aplikace', 'Implementace zákaznického portálu', 'Cloudová migrace',
            'Datová integrace', 'Mobilní aplikace', 'Bezpečnostní audit', 'Reporting a analytika',
            'Dlouhodobá technická podpora', 'Redesign klientské zóny', 'Automatizace procesů',
        ];
        $projects = [];
        for ($i = 0; $i < self::PROJECT_COUNT; $i++) {
            $clientIdx = $i % self::CLIENT_COUNT;
            $currencyId = $clients[$clientIdx][10];
            $currencyCode = $clients[$clientIdx][11];
            $projects[] = [
                $clientIdx,
                $projectNames[$i % count($projectNames)] . ' ' . $today->format('Y'),
                sprintf('Z%04d', 1000 + $i),
                sprintf('%s/SML/%03d', $today->format('Y'), $i + 1),
                [7, 14, 21, 30][$i % 4],
                $currencyCode === 'EUR' ? 85 : 1650,
                $currencyId,
                $currencyCode,
            ];
        }
        $projectIds = [];
        foreach ($projects as [$ci, $name, $projNum, $contractNum, $due, $rate, $currencyId, $currencyCode]) {
            $stmt = $pdo->prepare(
                'INSERT INTO projects (client_id, name, payment_due_days, project_number, contract_number,
                                       hourly_rate, currency_id, status)
                 VALUES (?,?,?,?,?,?,?,"active")'
            );
            $stmt->execute([$clientIds[$ci], $name, $due, $projNum, $contractNum, $rate, $currencyId]);
            $projId = (int) $pdo->lastInsertId();
            $projectIds[] = $projId;
            $track('project', $projId);
        }

        $stdVat = (int) $pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-21' LIMIT 1")->fetchColumn();
        $lowVat = (int) $pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-12' LIMIT 1")->fetchColumn();
        $rcVat  = (int) $pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-RC' LIMIT 1")->fetchColumn();

        $advancedData = [
            'warehouse_id' => 0,
            'stock_item_ids' => [],
            'manufacturer_ids' => [],
            'category_ids' => [],
        ];
        if ($advanced) {
            $this->enableAdvancedFeatures($pdo, $supplierId, $adminUserId, $today);
            $advancedData = $this->seedStockCatalog($supplierId, $stdVat, $track);
            $eshopData = $this->seedEshopCatalog($supplierId, $advancedData['stock_item_ids'], $track);
            $advancedData = array_merge($advancedData, $eshopData);
        }

        $invoices = [];
        $stockIssueCandidates = [];
        for ($i = 0; $i < self::INVOICE_COUNT; $i++) {
            $issueDate = $this->documentDate($today, $i, self::INVOICE_COUNT);
            $month = substr($issueDate, 0, 7);
            $clientIdx = $i % self::CLIENT_COUNT;
            $clientCurrencyId = $clients[$clientIdx][10];
            $clientCurrency   = $clients[$clientIdx][11];
            $clientReverseCharge = $clients[$clientIdx][8];
            $compatibleProjects = array_filter($projects, fn ($p, $k) => $p[0] === $clientIdx, ARRAY_FILTER_USE_BOTH);
            $compatibleProjectKeys = array_keys($compatibleProjects);
            $projKey = $compatibleProjectKeys[$i % count($compatibleProjectKeys)] ?? null;
            $projectId = $projKey !== null ? $projectIds[$projKey] : null;

            $taxDate = $issueDate;
            $dueDays = [7, 14, 21, 30][$i % 4];
            $dueDate = (new \DateTimeImmutable($issueDate))->modify("+{$dueDays} days")->format('Y-m-d');

            $period = str_replace('-', '', $month);
            $vs = $this->nextVarsymbol($pdo, $supplierId, 'invoice', $period);

            $status = $i < 100 ? 'sent' : 'issued';

            $vatRate = $clientReverseCharge ? $rcVat : $stdVat;
            $vatPct  = $clientReverseCharge ? 0 : 21;

            // Exchange rate pro non-CZK faktury — hardcoded ~25 CZK/EUR (rough CNB average).
            // Bez něj by ranking v Top klientech počítal EUR jako 1:1 CZK (1000 EUR ranked
            // jako 1000 Kč) — viz commit db85305 a issue ohledně NorthLight GmbH.
            // Pozn.: invoices tabulka NEMÁ exchange_rate_source (jen purchase_invoices má).
            $exchangeRate = $clientCurrency === 'CZK' ? null : 25.0;
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                    (supplier_id, varsymbol, invoice_type, client_id, project_id, issue_date, tax_date, due_date,
                     currency_id, exchange_rate, exchange_rate_date,
                     reverse_charge, language, vat_classification_code, total_without_vat, total_vat, total_with_vat,
                     status, sent_at, paid_at, created_by)
                 VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?)'
            );
            $sentAt = $status === 'sent' ? $issueDate . ' 14:00:00' : null;
            $stmt->execute([
                $supplierId, $vs, $clientIds[$clientIdx], $projectId, $issueDate, $taxDate, $dueDate,
                $clientCurrencyId, $exchangeRate, $exchangeRate !== null ? $issueDate : null,
                $clientReverseCharge ? 1 : 0,
                $clients[$clientIdx][9],
                // EU RC = poskytnutí služby do JČS → kód 22 (ř.21 DPHDP3 + SHV);
                // tuzemské nechávat bez kódu (fallback dle sazby → ř.1, KH A.4/A.5).
                $clientReverseCharge ? '22' : null,
                $status, $sentAt, null, $adminUserId,
            ]);
            $invId = (int) $pdo->lastInsertId();
            $track('invoice', $invId);
            $invoice = [
                'id' => $invId, 'vs' => $vs, 'currency' => $clientCurrency,
                'currency_id' => $clientCurrencyId, 'rc' => $clientReverseCharge,
                'issue_date' => $issueDate, 'client_idx' => $clientIdx,
                'company' => $clients[$clientIdx][0],
            ];

            $stocked = $advanced && $clientCurrency === 'CZK'
                && count(array_filter($stockIssueCandidates, static fn (array $item): bool => substr($item['date'], 0, 7) === $month)) < 5;
            $itemCount = $stocked ? 1 : 1 + ($i % 3);
            $totalBase = 0; $totalVat = 0;
            for ($k = 0; $k < $itemCount; $k++) {
                $stockIndex = $stocked ? count($stockIssueCandidates) % count($advancedData['stock_item_ids']) : null;
                $quantity = $stocked ? 5 : 4 + (($i * 7 + $k * 3) % 37);
                $rate = $stocked
                    ? self::STOCK_PRICES[$stockIndex]
                    : ($clientCurrency === 'EUR' ? 70 + (($i + $k) % 5) * 8 : 1250 + (($i + $k) % 7) * 125);
                $base = $quantity * $rate;
                $vatAmt = round($base * $vatPct / 100, 2); // RC má vatPct 0 → daň 0
                $totalBase += $base;
                $totalVat  += $vatAmt;

                $itemMonth = (new \DateTimeImmutable($issueDate))->format('n/Y');
                $description = $stocked
                    ? self::STOCK_NAMES[$stockIndex]
                    : match ($k) {
                        0 => "Konzultace a analýza $itemMonth",
                        1 => "Vývoj aplikace, sprint $itemMonth",
                        default => "Provozní podpora $itemMonth",
                    };
                $itemStmt = $pdo->prepare(
                    'INSERT INTO invoice_items
                        (invoice_id, description, quantity, unit, unit_price_without_vat,
                         vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat,
                         order_index, stock_item_id, warehouse_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $itemStmt->execute([
                    $invId, $description, $quantity, $stocked ? 'ks' : 'h', $rate,
                    $vatRate, $vatPct, $base, $vatAmt, $base + $vatAmt, $k,
                    $stocked ? $advancedData['stock_item_ids'][$stockIndex] : null,
                    $stocked ? $advancedData['warehouse_id'] : null,
                ]);
                if ($stocked) {
                    $stockIssueCandidates[] = [
                        'invoice_id' => $invId,
                        'invoice_item_id' => (int) $pdo->lastInsertId(),
                        'stock_item_id' => $advancedData['stock_item_ids'][$stockIndex],
                        'qty' => $quantity,
                        'date' => $issueDate,
                        'description' => $description,
                    ];
                }
            }
            $totalWithVat = $totalBase + $totalVat;
            $pdo->prepare(
                'UPDATE invoices SET total_without_vat = ?, total_vat = ?, total_with_vat = ? WHERE id = ?'
            )->execute([$totalBase, $totalVat, $totalWithVat, $invId]);
            $invoice['total'] = $totalWithVat;
            $invoices[] = $invoice;
        }

        // Dobropisy k nejstarším fakturám zůstávají mimo bankovní úhrady.
        $stockInvoiceIds = array_column($stockIssueCandidates, 'invoice_id');
        $creditTargets = array_slice(array_values(array_filter(
            $invoices,
            static fn (array $invoice): bool => !in_array($invoice['id'], $stockInvoiceIds, true),
        )), 0, self::CREDIT_NOTE_COUNT);
        $creditNotes = [];
        foreach ($creditTargets as $index => $parent) {
            $issueDate = min(
                $today->format('Y-m-d'),
                (new \DateTimeImmutable($parent['issue_date']))->modify('+' . (8 + $index) . ' days')->format('Y-m-d'),
            );
            $month = substr($issueDate, 0, 7);
            $period = str_replace('-', '', $month);
            $vs = $this->nextVarsymbol($pdo, $supplierId, 'credit_note', $period);

            $parentInv = $pdo->prepare(
                'SELECT i.*, cur.code AS currency
                   FROM invoices i
                   JOIN currencies cur ON cur.id = i.currency_id
                  WHERE i.id = ?'
            );
            $parentInv->execute([$parent['id']]);
            $p = $parentInv->fetch(PDO::FETCH_ASSOC);

            // Exchange rate kopírujeme z parent invoice (dobropis je její opak).
            // invoices tabulka NEMÁ exchange_rate_source.
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                    (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, project_id,
                     issue_date, tax_date, due_date, currency_id, exchange_rate, exchange_rate_date,
                     reverse_charge, language, vat_classification_code,
                     total_without_vat, total_vat, total_with_vat, status, sent_at, created_by)
                 VALUES (?, ?, "credit_note", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "sent", ?, ?)'
            );
            $stmt->execute([
                $supplierId, $vs, $p['id'], $p['client_id'], $p['project_id'],
                $issueDate, $issueDate, $issueDate,
                (int) $p['currency_id'],
                $p['exchange_rate'] ?? null,
                $p['exchange_rate'] !== null ? $issueDate : null,
                $p['reverse_charge'], $p['language'],
                $p['vat_classification_code'] ?? null, // dobropis dědí klasifikaci originálu
                -$p['total_without_vat'], -$p['total_vat'], -$p['total_with_vat'],
                $issueDate . ' 12:00:00', $adminUserId,
            ]);
            $cnId = (int) $pdo->lastInsertId();
            $track('credit_note', $cnId);
            $creditNotes[] = ['id' => $cnId, 'issue_date' => $issueDate];

            $pdo->prepare(
                'INSERT INTO invoice_items
                    (invoice_id, description, quantity, unit, unit_price_without_vat,
                     vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?,?,-1,"ks",?,?,?,?,?,?,0)'
            )->execute([
                $cnId,
                "Dobropis k faktuře {$p['varsymbol']}",
                $p['total_without_vat'],
                $p['reverse_charge'] ? $rcVat : $stdVat,
                $p['reverse_charge'] ? 0 : 21,
                -$p['total_without_vat'], -$p['total_vat'], -$p['total_with_vat'],
            ]);
        }

        // Dodavatelé jsou výhradně syntetičtí. Devět tuzemských profilů pokrývá
        // běžné náklady a tři zahraniční profily reverse-charge služby.
        $vendorNames = [
            'Demo Hardware s.r.o.', 'Ukázkový Autohaus s.r.o.', 'Sample Office s.r.o.',
            'Fiktivní Energie s.r.o.', 'Test Telecom s.r.o.', 'Modelový Pronájem s.r.o.',
            'Příklad Servis s.r.o.', 'Demo Logistika s.r.o.', 'Ukázka Marketing s.r.o.',
            'Sample Cloud GmbH', 'Demo Software GmbH', 'Example Services s.r.o.',
        ];
        $vendors = [];
        $vendorItemPools = [];
        foreach ($vendorNames as $i => $company) {
            $foreign = $i >= 9;
            $iso2 = $i === 11 ? 'SK' : ($foreign ? 'DE' : 'CZ');
            $ic = $foreign ? null : str_pad((string) (22000000 + $i * 6151), 8, '0', STR_PAD_LEFT);
            $dic = $foreign ? $iso2 . (3000000000 + $i * 9217) : 'CZ' . $ic;
            $vendors[] = [
                $company, $ic, $dic, 'Dodavatelská ' . (20 + $i), $foreign ? '10115' : '10000',
                $foreign ? ($iso2 === 'SK' ? 'Bratislava' : 'Berlin') : 'Praha', $iso2,
                'fakturace+' . ($i + 1) . '@vendor.example.invalid',
                $foreign ? $eurId : $czkId, $foreign ? 'EUR' : 'CZK',
            ];
            $vendorItemPools[$company] = [
                'rc' => $foreign,
                'items' => $foreign
                    ? [['Cloudové služby', 21, '24'], ['Vývojářské licence', 21, '24']]
                    : [match ($i) {
                        0 => ['Síťové příslušenství', 21, '40'],
                        1 => ['Servis firemního automobilu', 21, '40'],
                        2 => ['Kancelářský a obalový materiál', 21, '40'],
                        3 => ['Dodávka elektrické energie', 21, '40'],
                        4 => ['Mobilní a internetové služby', 21, '40'],
                        5 => ['Nájem kanceláře a skladu', 21, '40'],
                        6 => ['Údržba technického vybavení', 21, '40'],
                        7 => ['Přeprava zásilek zákazníkům', 21, '40'],
                        default => ['Správa reklamní kampaně', 21, '40'],
                    }],
            ];
        }
        $vendorIds = [];
        $vendorMeta = [];
        foreach ($vendors as [$company, $ic, $dic, $street, $zip, $city, $iso2, $email, $currencyId, $currencyCode]) {
            $stmtCountry = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
            $stmtCountry->execute([$iso2]);
            $countryId = (int) $stmtCountry->fetchColumn() ?: $czId;
            $stmt = $pdo->prepare(
                'INSERT INTO clients (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email,
                                      language, currency_default_id, is_customer, is_vendor)
                 VALUES (?,?,?,?,?,?,?,?,?, "cs", ?, 0, 1)'
            );
            $stmt->execute([$supplierId, $company, $ic, $dic, $street, $city, $zip, $countryId, $email, $currencyId]);
            $vid = (int) $pdo->lastInsertId();
            $vendorIds[] = $vid;
            $track('vendor', $vid);
            $vendorMeta[] = [
                'id' => $vid, 'company' => $company, 'ic' => $ic, 'dic' => $dic,
                'street' => $street, 'zip' => $zip, 'city' => $city, 'iso2' => $iso2,
                'currency_id' => $currencyId, 'currency' => $currencyCode,
            ];
        }

        // ───── Přijaté faktury (120 ks za posledních 12 měsíců) ─────
        $purchaseCount = 0;
        $purchaseInvoices = [];
        $stockReceiptCandidates = [];
        $smallAssetPurchaseIds = [];
        for ($i = 0; $i < self::PURCHASE_COUNT; $i++) {
            $issueDate = $i < 2
                ? $today->modify('-1 year')->modify($i === 0 ? '-3 months' : '-1 month')->format('Y-m-d')
                : $this->documentDate($today, $i, self::PURCHASE_COUNT);
            $issueDt = new \DateTimeImmutable($issueDate);
            $taxDate   = $issueDate;
            $dueDate   = $issueDt->modify('+' . [7, 14, 21, 30][$i % 4] . ' days')->format('Y-m-d');
            $receivedAt = $issueDt->modify('+2 days')->format('Y-m-d');

            $v = $vendorMeta[$i % count($vendorMeta)];
            $status = 'received';
            $vendorSnapshot = json_encode([
                'company_name' => $v['company'],
                'ic' => $v['ic'], 'dic' => $v['dic'],
                'street' => $v['street'], 'city' => $v['city'], 'zip' => $v['zip'],
                'country_iso2' => $v['iso2'],
            ], JSON_UNESCAPED_UNICODE);

            $exchangeRate = $v['currency'] === 'CZK' ? null : 25.0;
            $pool = $vendorItemPools[$v['company']];
            $isRc = $pool['rc'];

            $isFixedAsset = $advanced && $i < 2;
            $smallAssetSpec = $advanced ? match ($i) {
                24 => ['Notebook pro obchodní tým', 1, 32900.0],
                43 => ['Ergonomická kancelářská židle', 2, 7900.0],
                68 => ['Mobilní telefon pro technika', 1, 18900.0],
                96 => ['Multifunkční tiskárna a skener', 1, 12400.0],
                default => null,
            } : null;
            $isSmallAsset = $smallAssetSpec !== null;
            $stocked = $advanced && !$isFixedAsset && !$isSmallAsset && $v['currency'] === 'CZK'
                && count($stockReceiptCandidates) < 60;
            if ($isFixedAsset || $isSmallAsset || $stocked) {
                $v = $vendorMeta[$i === 1 ? 1 : 0];
                $pool = $vendorItemPools[$v['company']];
                $isRc = false;
                $exchangeRate = null;
                if ($stocked) {
                    $issueDt = (new \DateTimeImmutable($stockIssueCandidates[count($stockReceiptCandidates)]['date']))->modify('-2 days');
                    $issueDate = $taxDate = $issueDt->format('Y-m-d');
                    $dueDate = $issueDt->modify('+14 days')->format('Y-m-d');
                    $receivedAt = $issueDate;
                }
                $vendorSnapshot = json_encode([
                    'company_name' => $v['company'], 'ic' => $v['ic'], 'dic' => $v['dic'],
                    'street' => $v['street'], 'city' => $v['city'], 'zip' => $v['zip'],
                    'country_iso2' => $v['iso2'],
                ], JSON_UNESCAPED_UNICODE);
            }
            $receivedAt = min($receivedAt, $today->format('Y-m-d'));
            $period = $issueDt->format('Ym');
            $vs = $this->nextPurchaseVarsymbol($pdo, $supplierId, $period);
            $vendorInvoiceNumber = sprintf('DOD-%s-%04d', substr($period, 2), $i + 100);
            $stmt = $pdo->prepare(
                'INSERT INTO purchase_invoices
                    (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                     issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, exchange_rate_date,
                     exchange_rate_source, reverse_charge, language, vendor_snapshot, vat_classification_code,
                     is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, created_by)
                 VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, "cnb", ?, "cs", ?, ?, ?, 0, 0, 0, ?, ?)'
            );
            $stmt->execute([
                $supplierId, $v['id'], $vs, $vendorInvoiceNumber,
                $issueDate, $taxDate, $dueDate, $receivedAt,
                $v['currency_id'], $exchangeRate, $exchangeRate !== null ? $issueDate : null,
                $isRc ? 1 : 0,
                $vendorSnapshot,
                $isRc ? '24' : null, // dovoz služby (ř.12 + mirror ř.43); tuzemsko per položka
                $isFixedAsset ? 1 : 0,
                $status, $adminUserId,
            ]);
            $piId = (int) $pdo->lastInsertId();
            $track('purchase_invoice', $piId);

            $itemCount = ($isFixedAsset || $isSmallAsset || $stocked) ? 1 : 1 + ($i % 2);
            $totalBase = 0; $totalVat = 0;
            for ($k = 0; $k < $itemCount; $k++) {
                [$description, $ratePct, $clsCode] = $pool['items'][($i + $k) % count($pool['items'])];
                if ($isFixedAsset) {
                    $description = $i === 0 ? 'Výkonný server pro aplikační provoz' : 'Firemní užitkový automobil';
                    $qty = 1;
                    $rate = $i === 0 ? 180000 : 720000;
                } elseif ($isSmallAsset) {
                    [$description, $qty, $rate] = $smallAssetSpec;
                    $ratePct = 21;
                    $clsCode = '40';
                } elseif ($stocked) {
                    $stockIndex = count($stockReceiptCandidates) % count($advancedData['stock_item_ids']);
                    $description = self::STOCK_NAMES[$stockIndex];
                    $qty = 20;
                    $rate = self::STOCK_COSTS[$stockIndex];
                } else {
                    $qty = 1 + (($i + $k) % 5);
                    $rate = $v['currency'] === 'CZK' ? 650 + (($i + $k) % 12) * 375 : 35 + (($i + $k) % 8) * 20;
                }
                $base = $qty * $rate;
                // RC: nominální sazba zůstává, daň 0 (samovyměří se až ve výkazech)
                $vatAmt = $isRc ? 0.0 : round($base * $ratePct / 100, 2);
                $totalBase += $base; $totalVat += $vatAmt;
                $itemStmt = $pdo->prepare(
                    'INSERT INTO purchase_invoice_items
                        (purchase_invoice_id, description, quantity, unit, unit_price_without_vat,
                         vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat,
                         vat_classification_code, order_index, stock_item_id, is_fixed_asset,
                         expense_kind, expense_account_code)
                     VALUES (?,?,?,"ks",?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $expenseKind = $isFixedAsset ? 'fixed_asset' : ($isSmallAsset ? 'small_asset' : null);
                $itemStmt->execute([
                    $piId, $description, $qty, $rate,
                    $ratePct >= 21 ? $stdVat : $lowVat, $ratePct,
                    $base, $vatAmt, $base + $vatAmt, $clsCode, $k,
                    $stocked ? $advancedData['stock_item_ids'][$stockIndex] : null,
                    $isFixedAsset ? 1 : 0,
                    $expenseKind,
                    null,
                ]);
                if ($stocked) {
                    $stockReceiptCandidates[] = [
                        'purchase_invoice_id' => $piId,
                        'purchase_invoice_item_id' => (int) $pdo->lastInsertId(),
                        'stock_item_id' => $advancedData['stock_item_ids'][$stockIndex],
                        'qty' => $qty,
                        'unit_cost' => $rate,
                        'date' => $issueDate,
                        'description' => $description,
                    ];
                }
            }
            $totalWithVat = $totalBase + $totalVat;
            $pdo->prepare(
                'UPDATE purchase_invoices SET total_without_vat = ?, total_vat = ?, total_with_vat = ? WHERE id = ?'
            )->execute([$totalBase, $totalVat, $totalWithVat, $piId]);
            $purchaseInvoices[] = [
                'id' => $piId,
                'vs' => $vs,
                'issue_date' => $issueDate,
                'currency' => $v['currency'],
                'company' => $v['company'],
                'total' => $totalWithVat,
                'total_without_vat_czk' => round($totalBase * ($exchangeRate ?? 1.0), 2),
            ];
            if ($isSmallAsset) $smallAssetPurchaseIds[] = $piId;
            $purchaseCount++;
        }

        $smallAssetCount = 0;
        foreach ($smallAssetPurchaseIds as $purchaseInvoiceId) {
            $generatedCards = $this->smallAssets->generateFromPurchaseInvoice(
                $supplierId,
                $purchaseInvoiceId,
                $adminUserId,
            );
            foreach ($generatedCards['created'] as $id) {
                $track('small_asset', $id);
                $smallAssetCount++;
            }
        }

        // ───── Pravidelné fakturace (2 šablony) ─────
        // Vystavení od 1. dne příštího měsíce (ať cron hned něco negeneruje a uživatel
        // si je v klidu prohlédne). Přes RecurringTemplateRepository (stejné defaulty jako UI).
        $firstNextMonth = $today->modify('first day of next month')->format('Y-m-d');
        $recurringTemplates = [
            [
                'client_idx' => 0, 'project_idx' => 0, 'currency_id' => $czkId,
                'name' => 'Měsíční hosting a údržba webu', 'frequency' => 'monthly',
                'language' => 'cs', 'rc' => 0, 'vat' => $stdVat,
                'items' => [
                    ['Webhosting + správa serveru', 2500.0],
                    ['Údržba webu (měsíční paušál)', 3500.0],
                ],
            ],
            [
                'client_idx' => 20, 'project_idx' => 20, 'currency_id' => $eurId,
                'name' => 'Quarterly support retainer', 'frequency' => 'quarterly',
                'language' => 'en', 'rc' => 1, 'vat' => $rcVat,
                'items' => [
                    ['Quarterly support & maintenance retainer', 1200.0],
                ],
            ],
        ];
        $recurringCount = 0;
        foreach ($recurringTemplates as $rt) {
            $tplId = $this->recurring->create([
                'supplier_id'     => $supplierId,
                'client_id'       => $clientIds[$rt['client_idx']],
                'project_id'      => $projectIds[$rt['project_idx']],
                'name'            => $rt['name'],
                'frequency'       => $rt['frequency'],
                'day_of_month'    => 1,
                'anchor_date'     => $firstNextMonth,
                'invoice_type'    => 'invoice',
                'currency_id'     => $rt['currency_id'],
                'language'        => $rt['language'],
                'reverse_charge'  => $rt['rc'],
                'payment_due_days' => 14,
                'auto_issue'      => 1,
                'auto_send_email' => 0,  // sample: negenerovat reálné e-maily
                'status'          => 'active',
            ], $adminUserId);
            $this->recurring->replaceItems($tplId, array_map(
                fn (array $it, int $k) => [
                    'description'            => $it[0],
                    'quantity'               => 1,
                    'unit'                   => 'ks',
                    'unit_price_without_vat' => $it[1],
                    'vat_rate_id'            => $rt['vat'],
                    'order_index'            => $k,
                ],
                $rt['items'],
                array_keys($rt['items']),
            ));
            $track('recurring_template', (int) $tplId);
            $recurringCount++;
        }

        // ───── Kniha jízd (1 firemní auto, 15 jízd, 6 tankování) ─────
        $logbook = $this->seedLogbook($pdo, $supplierId, $adminUserId, $today);
        $carId = (int) ($logbook['car_id'] ?? 0);
        $track('car', $carId);

        $cashResult = $this->seedCash($supplierId, $adminUserId, $today, $isVatPayer);
        if ($cashResult['register_created']) $track('cash_register', $cashResult['register_id']);
        foreach ($cashResult['document_ids'] as $id) $track('cash_document', $id);

        $stockDocumentCount = 0;
        $assetCount = 0;
        $bankStatementCount = 0;
        $bankTransactionCount = 0;
        $bankResult = [
            'automation_auto' => 0,
            'automation_pending' => 0,
            'automation_needs_input' => 0,
            'automation_approved' => 0,
        ];
        $journalIds = $cashResult['journal_entry_ids'];
        if ($advanced) {
            $stockDocIds = $this->seedStockDocuments(
                $supplierId,
                $adminUserId,
                (int) $advancedData['warehouse_id'],
                $stockReceiptCandidates,
                $stockIssueCandidates,
            );
            foreach ($stockDocIds as $id) $track('stock_document', $id);
            $stockDocumentCount = count($stockDocIds);

            $journalIds = array_merge($journalIds, $this->postBusinessDocuments(
                $supplierId,
                $adminUserId,
                $invoices,
                $creditNotes,
                $purchaseInvoices,
            ));

            $assetResult = $this->seedAssets(
                $pdo,
                $supplierId,
                $adminUserId,
                array_slice($purchaseInvoices, 0, 2),
            );
            foreach ($assetResult['asset_ids'] as $id) $track('asset', $id);
            $assetCount = count($assetResult['asset_ids']);
            $journalIds = array_merge($journalIds, $assetResult['journal_entry_ids']);

            $bankResult = $this->seedBankStatements(
                $pdo,
                $supplierId,
                $adminUserId,
                $czkId,
                $today,
                $invoices,
                array_column($creditTargets, 'id'),
                $purchaseInvoices,
            );
            foreach ($bankResult['statement_ids'] as $id) $track('bank_statement', $id);
            if ($bankResult['bank_account_id'] > 0) {
                $track('supplier_bank_account', $bankResult['bank_account_id']);
            }
            $bankStatementCount = count($bankResult['statement_ids']);
            $bankTransactionCount = $bankResult['transaction_count'];
            $journalIds = array_merge($journalIds, $bankResult['journal_entry_ids']);

        }
        $workflowResult = $this->sampleWorkflow->generate($supplierId, $adminUserId, $today, $advanced, $track);
        $bankTransactionCount += $workflowResult['transaction_count'];
        $bankResult['automation_auto'] += $workflowResult['transaction_count'];
        $journalIds = array_merge($journalIds, $workflowResult['journal_entry_ids']);
        $payrollResult = $this->samplePayroll->generate($supplierId, $adminUserId, $today, $advanced, $track);
        $journalIds = array_merge($journalIds, $payrollResult['journal_entry_ids']);
        unset($payrollResult['journal_entry_ids']);
        foreach (array_values(array_unique(array_map('intval', $journalIds))) as $id) {
            $track('journal_entry', $id);
        }

        $documentCount = $this->sampleDocuments->generate($supplierId, $adminUserId, $track);

        // Zapiš evidenci sample entit — řídí „Odebrat ukázková data" (přesné smazání)
        // i zobrazení tlačítka v UI (issue #162).
        if ($tracked !== []) {
            $ins = $pdo->prepare(
                'INSERT INTO sample_data_entries (supplier_id, entity_type, entity_id) VALUES (?, ?, ?)'
            );
            foreach ($tracked as [$type, $id]) {
                $ins->execute([$supplierId, $type, $id]);
            }
        }

        $ruleCounts = $this->sampleRules->generate($supplierId, $adminUserId, $advanced, static function (string $type, int $id) use ($ins, $supplierId): void {
            $ins->execute([$supplierId, $type, $id]);
        });
        $dimensionCounts = $this->sampleDimensions->generate($supplierId, static function (string $type, int $id) use ($ins, $supplierId): void {
            $ins->execute([$supplierId, $type, $id]);
        });

        $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $this->sampleDocuments->cleanupAfterRollback($supplierId);
            throw $e;
        }

        $warnings = [];
        try {
            foreach ($projectIds as $pid) $this->stats->recomputeProject((int) $pid);
            foreach ($clientIds  as $cid) $this->stats->recomputeClient((int) $cid);
            foreach ($vendorIds  as $vid) $this->stats->recomputeClient((int) $vid);
        } catch (\Throwable $e) {
            // Data jsou už atomicky commitnutá; chyba odvozené cache nesmí tvrdit,
            // že generování selhalo a zablokovat bezpečný purge/retry.
            $warnings[] = 'Ukázková data vznikla, ale nepodařilo se přepočítat statistické cache: ' . $e->getMessage();
        }

        return [
            ...$dimensionCounts,
            ...$ruleCounts,
            ...$payrollResult,
            'clients'           => count($clientIds),
            'projects'          => count($projectIds),
            'invoices'          => count($invoices),
            'drafts'            => $workflowResult['drafts'],
            'credit_notes'      => count($creditNotes),
            'vendors'           => count($vendorIds),
            'purchase_invoices' => $purchaseCount,
            'recurring'         => $recurringCount,
            'cars'              => $logbook['cars'],
            'trips'             => $logbook['trips'],
            'fuelings'          => $logbook['fuelings'],
            'cash_registers'    => 1,
            'cash_documents'    => count($cashResult['document_ids']),
            'accounting_enabled' => $advanced,
            'stock_items'       => count($advancedData['stock_item_ids']),
            'manufacturers'     => count($advancedData['manufacturer_ids']),
            'eshop_categories'  => count($advancedData['category_ids']),
            'stock_documents'   => $stockDocumentCount,
            'assets'            => $assetCount,
            'small_assets'      => $smallAssetCount,
            'bank_statements'   => $bankStatementCount,
            'bank_transactions' => $bankTransactionCount,
            'automation_auto'   => $bankResult['automation_auto'],
            'automation_pending' => $bankResult['automation_pending'],
            'automation_needs_input' => $bankResult['automation_needs_input'],
            'automation_approved' => $bankResult['automation_approved'],
            'journal_entries'   => count(array_unique($journalIds)),
            'documents'         => $documentCount,
            'warnings'          => $warnings,
        ];
    }

    private function documentDate(\DateTimeImmutable $today, int $index, int $count): string
    {
        $monthIndex = min(11, intdiv($index * 12, max(1, $count)));
        $monthsBack = 11 - $monthIndex;
        $month = $today->modify('first day of this month')->modify("-{$monthsBack} months");
        $day = 1 + (($index * 7 + 3) % min(25, (int) $month->format('t')));
        $date = $month->modify('+' . ($day - 1) . ' days')->format('Y-m-d');
        return min($date, $today->format('Y-m-d'));
    }

    private function enableAdvancedFeatures(PDO $pdo, int $supplierId, int $userId, \DateTimeImmutable $today): void
    {
        $pdo->prepare(
            "UPDATE supplier
                SET accounting_mode = 'double_entry', stock_enabled = 1, stock_auto_issue = 1,
                    auto_post_invoices = 1, auto_post_purchases = 1
              WHERE id = ?"
        )->execute([$supplierId]);

        $effectiveFrom = $today->modify('-2 years')->format('Y-01-01');
        $this->accountingModes->record($supplierId, $effectiveFrom, 'double_entry');
        $this->coaSeeder->seedForSupplier($supplierId);
        $pdo->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'bank.payment.matched', 'auto', ?)
             ON DUPLICATE KEY UPDATE level = 'auto', updated_by = VALUES(updated_by)"
        )->execute([$supplierId, $userId]);

        $currentYear = (int) $today->format('Y');
        for ($year = $currentYear - 2; $year <= $currentYear; $year++) {
            $period = $this->periods->ensureOpenPeriodFor($supplierId, $year . '-06-30');
            if (($period['status'] ?? null) !== 'open') {
                throw new \RuntimeException("Ukázková data nelze zaúčtovat: období {$year} není otevřené.");
            }
        }
    }

    /** @return array{warehouse_id:int,stock_item_ids:list<int>} */
    private function seedStockCatalog(int $supplierId, int $vatRateId, callable $track): array
    {
        // Sklad s kódem HLAVNI už může existovat — např. jako sirotek po dřívějším běhu,
        // kterému `reset.php` smazal evidenci `sample_data_entries`. Tvrdý INSERT by spadl
        // na `uq_wh_supplier_code` a shodil celé generování. Převezmeme existující a
        // zaevidujeme ho, ať je zase odebratelný přes „Odebrat ukázková data".
        $existing = $this->warehouses->findByCode($supplierId, 'HLAVNI');
        $warehouseId = $existing !== null
            ? (int) $existing['id']
            : $this->warehouses->insert($supplierId, [
                'code' => 'HLAVNI',
                'name' => 'Hlavní ukázkový sklad',
                'is_default' => true,
                'is_active' => true,
                'note' => 'Syntetická skladová evidence vytvořená sample generátorem.',
            ]);
        if ($existing === null) $track('warehouse', $warehouseId);

        $names = self::STOCK_NAMES;
        $ids = [];
        foreach ($names as $i => $name) {
            $id = $this->stockItems->insert($supplierId, [
                'sku' => sprintf('DEMO-%04d', $i + 1),
                'name' => $name,
                'item_type' => 'goods',
                'unit' => 'ks',
                'ean' => sprintf('8599000%06d', $i + 1),
                'vat_rate_id' => $vatRateId,
                'sale_price_without_vat' => (string) self::STOCK_PRICES[$i],
                'min_qty' => '5',
                'is_active' => true,
                'note' => 'Syntetická ukázková skladová karta.',
            ]);
            $ids[] = $id;
            $track('stock_item', $id);
        }
        return ['warehouse_id' => $warehouseId, 'stock_item_ids' => $ids];
    }

    /**
     * @param list<int> $stockItemIds
     * @return array{manufacturer_ids:list<int>,category_ids:list<int>}
     */
    private function seedEshopCatalog(int $supplierId, array $stockItemIds, callable $track): array
    {
        $manufacturerRows = [
            ['DEMO-TECH', 'DemoTech Systems'],
            ['SAMPLE-NET', 'SampleNet'],
            ['EXAMPLE-GEAR', 'ExampleGear'],
        ];
        $manufacturerIds = [];
        foreach ($manufacturerRows as $i => [$code, $name]) {
            $id = $this->manufacturers->insert($supplierId, [
                'code' => $code,
                'name' => $name,
                'website' => 'https://' . strtolower($code) . '.example.invalid',
                'display_order' => ($i + 1) * 10,
                'export_eshop' => true,
            ]);
            $manufacturerIds[] = $id;
            $track('manufacturer', $id);
        }

        $categoryRows = [
            ['POCITACE', 'Počítače a komponenty'],
            ['SITE', 'Síťové prvky'],
            ['PRISLUSENSTVI', 'Příslušenství'],
        ];
        // Stejná ochrana jako u skladu výš: kategorie s tímto kódem už může být sirotek
        // po dřívějším běhu (uq_sc_supplier_code) — převezmi ji místo pádu na duplicitu.
        $findCategory = $this->db->pdo()->prepare(
            'SELECT id FROM stock_categories WHERE supplier_id = ? AND code = ? LIMIT 1'
        );
        $categoryIds = [];
        foreach ($categoryRows as $i => [$code, $name]) {
            $findCategory->execute([$supplierId, $code]);
            $id = (int) ($findCategory->fetchColumn() ?: 0);
            if ($id === 0) {
                $category = $this->categoryTree->create($supplierId, [
                    'code' => $code,
                    'name' => $name,
                    'display_order' => ($i + 1) * 10,
                    'export_eshop' => true,
                ]);
                $id = (int) $category['id'];
            }
            $categoryIds[] = $id;
            $track('stock_category', $id);
        }

        foreach ($stockItemIds as $i => $stockItemId) {
            $this->stockItems->updateEshopFields($supplierId, $stockItemId, [
                'manufacturer_id' => $manufacturerIds[$i % count($manufacturerIds)],
                'warranty_months' => [24, 36, 24][$i % 3],
                'delivery_days' => [1, 2, 3][$i % 3],
                'export_eshop' => true,
                'is_stocked' => true,
                'weight_g' => 250 + $i * 175,
                'pricing_base' => 'weighted_avg',
            ]);
            $this->stockItemCategories->add(
                $supplierId,
                $stockItemId,
                $categoryIds[$i % count($categoryIds)],
                true,
                ($i + 1) * 10,
            );
        }

        return ['manufacturer_ids' => $manufacturerIds, 'category_ids' => $categoryIds];
    }

    /** @return array{register_id:int,document_ids:list<int>,journal_entry_ids:list<int>} */
    private function seedCash(
        int $supplierId,
        int $userId,
        \DateTimeImmutable $today,
        bool $isVatPayer,
    ): array {
        // Pokladna má unique na (supplier_id, name) i (supplier_id, account_code) — po
        // resetu, který smazal evidenci, tu může zůstat sirotek z minulého běhu.
        $findRegister = $this->db->pdo()->prepare(
            'SELECT id FROM cash_registers
              WHERE supplier_id = ? AND (name = ? OR account_code = ?) LIMIT 1'
        );
        $findRegister->execute([$supplierId, 'Hlavní pokladna', '211']);
        $registerId = (int) ($findRegister->fetchColumn() ?: 0);
        $registerCreated = $registerId === 0;
        if ($registerId === 0) {
            $registerId = $this->cashRegisters->create($supplierId, [
                'name' => 'Hlavní pokladna',
                'currency_code' => 'CZK',
                'account_code' => '211',
                'is_default' => true,
            ]);
        }

        $rows = [
            ['transfer', 'in', 25000.00, 'Vklad hotovosti z bankovního účtu', 'Vlastní bankovní účet'],
            ['sale', 'in', 2000.00, 'Prodej síťového příslušenství za hotové', 'Drobný odběratel'],
            ['purchase', 'out', 1000.00, 'Nákup kancelářských potřeb', 'Papírnictví Ukázka s.r.o.'],
            ['sale', 'in', 3500.00, 'Prodej dokovací stanice za hotové', 'Maloobchodní zákazník'],
            ['purchase', 'out', 1800.00, 'Nákup obalového materiálu', 'Demo Obaly s.r.o.'],
            ['sale', 'in', 1500.00, 'Prodej klávesnice a myši za hotové', 'Koncový zákazník'],
            ['purchase', 'out', 750.00, 'Drobný provozní nákup', 'Ukázkové potřeby s.r.o.'],
        ];
        $documentIds = [];
        $journalEntryIds = [];
        foreach ($rows as $i => [$purpose, $docType, $baseAmount, $description, $partner]) {
            $date = $today->modify('-' . (42 - $i * 6) . ' days')->format('Y-m-d');
            $withVat = $isVatPayer && in_array($purpose, ['sale', 'purchase'], true);
            $vatAmount = $withVat ? round($baseAmount * 0.21, 2) : 0.0;
            $data = [
                'register_id' => $registerId,
                'doc_type' => $docType,
                'purpose' => $purpose,
                'issue_date' => $date,
                'partner_name' => $partner,
                'description' => $description,
                'vat_mode' => $withVat ? 'vat' : 'none',
                'total_amount' => $baseAmount + $vatAmount,
            ];
            if ($withVat) {
                $data['tax_date'] = $date;
                $data['vat_lines'] = [[
                    'vat_rate' => 21,
                    'base_amount' => $baseAmount,
                    'vat_amount' => $vatAmount,
                ]];
            }
            $result = $this->cashDocuments->create($supplierId, $data, $userId);
            $documentIds[] = (int) $result['id'];
            if ($result['journal_entry_id'] !== null) {
                $journalEntryIds[] = (int) $result['journal_entry_id'];
            }
        }

        return [
            'register_id' => $registerId,
            'register_created' => $registerCreated,
            'document_ids' => $documentIds,
            'journal_entry_ids' => $journalEntryIds,
        ];
    }

    /**
     * @param list<array<string,mixed>> $receipts
     * @param list<array<string,mixed>> $issues
     * @return list<int>
     */
    private function seedStockDocuments(
        int $supplierId,
        int $userId,
        int $warehouseId,
        array $receipts,
        array $issues,
    ): array {
        if (count($receipts) < 60 || count($issues) < 60) {
            throw new \RuntimeException('Pro 120 skladových dokladů nevznikl dostatek CZK řádků faktur.');
        }
        $ids = [];
        for ($i = 0; $i < 60; $i++) {
            $receipt = $receipts[$i];
            $issue = $issues[$i];

            $draft = $this->stockDocuments->create($supplierId, [
                'doc_type' => 'receipt',
                'origin' => 'purchase_invoice',
                'warehouse_id' => $warehouseId,
                'purchase_invoice_id' => $receipt['purchase_invoice_id'],
                'doc_date' => $receipt['date'],
                'description' => 'Příjem z ' . $receipt['description'],
                'lines' => [[
                    'stock_item_id' => $receipt['stock_item_id'],
                    'qty' => $receipt['qty'],
                    'unit_cost' => $receipt['unit_cost'],
                    'purchase_invoice_item_id' => $receipt['purchase_invoice_item_id'],
                    'source_description' => $receipt['description'],
                    'source_qty' => $receipt['qty'],
                ]],
            ], $userId);
            $posted = $this->stockDocuments->post($supplierId, (int) $draft['id'], $userId);
            $ids[] = (int) $posted['id'];

            $draft = $this->stockDocuments->create($supplierId, [
                'doc_type' => 'issue',
                'origin' => 'invoice',
                'warehouse_id' => $warehouseId,
                'invoice_id' => $issue['invoice_id'],
                'doc_date' => $issue['date'],
                'description' => 'Výdej k ' . $issue['description'],
                'lines' => [[
                    'stock_item_id' => $issue['stock_item_id'],
                    'qty' => $issue['qty'],
                    'invoice_item_id' => $issue['invoice_item_id'],
                    'source_description' => $issue['description'],
                    'source_qty' => $issue['qty'],
                ]],
            ], $userId);
            $posted = $this->stockDocuments->post($supplierId, (int) $draft['id'], $userId);
            $ids[] = (int) $posted['id'];
        }
        return $ids;
    }

    /**
     * @param list<array<string,mixed>> $invoices
     * @param list<array<string,mixed>> $creditNotes
     * @param list<array<string,mixed>> $purchases
     * @return list<int>
     */
    private function postBusinessDocuments(
        int $supplierId,
        int $userId,
        array $invoices,
        array $creditNotes,
        array $purchases,
    ): array {
        $ids = [];
        foreach (array_merge($invoices, $creditNotes) as $doc) {
            $ids[] = $this->autoPoster->post($supplierId, 'invoice', (int) $doc['id'], [
                'user_id' => $userId,
                'posted_by' => $userId,
                'entry_date' => (string) $doc['issue_date'],
            ], $userId);
        }
        foreach ($purchases as $doc) {
            $ids[] = $this->autoPoster->post($supplierId, 'purchase_invoice', (int) $doc['id'], [
                'user_id' => $userId,
                'posted_by' => $userId,
                'entry_date' => (string) $doc['issue_date'],
            ], $userId);
        }
        return $ids;
    }

    /**
     * @param list<array<string,mixed>> $sourcePurchases
     * @return array{asset_ids:list<int>,journal_entry_ids:list<int>}
     */
    private function seedAssets(PDO $pdo, int $supplierId, int $userId, array $sourcePurchases): array
    {
        $assetIds = [];
        $years = [];
        foreach ($sourcePurchases as $i => $purchase) {
            $date = (string) $purchase['issue_date'];
            $year = (int) substr($date, 0, 4);
            $years[$year] = true;
            $created = $this->assetService->create($supplierId, [
                'inventory_number' => sprintf('M-%06d', $i + 1),
                'name' => $i === 0 ? 'Aplikační server' : 'Firemní užitkový automobil',
                'description' => 'Syntetická karta majetku vytvořená sample generátorem.',
                'kind' => 'tangible',
                'asset_account_code' => '022',
                'accumulated_account_code' => '082',
                'acquisition_account_code' => '042',
                'purchase_invoice_id' => (int) $purchase['id'],
                'input_price' => (float) $purchase['total_without_vat_czk'],
                'acquisition_date' => $date,
                'tax_method' => 'straight',
                'tax_group' => $i === 0 ? 1 : 2,
                'tax_first_year_increase' => 'none',
                'is_first_owner' => true,
                'is_m1_vehicle' => false,
                'acc_useful_life_months' => $i === 0 ? 36 : 60,
                'acc_residual_value' => 0,
            ], ['user_id' => $userId, 'posted_by' => $userId]);
            $assetId = (int) $created['asset']['id'];
            $assetIds[] = $assetId;
            $useDate = (new \DateTimeImmutable($date))->modify('+5 days')->format('Y-m-d');
            $this->assetService->putIntoUse(
                $supplierId,
                $assetId,
                $useDate,
                true,
                ['user_id' => $userId, 'posted_by' => $userId],
            );
        }

        foreach (array_keys($years) as $year) {
            $this->periods->ensureOpenPeriodFor($supplierId, $year . '-12-31');
            $result = $this->depreciation->bookYear(
                $supplierId,
                (int) $year,
                ['user_id' => $userId, 'posted_by' => $userId],
            );
            if ($result['errors'] !== []) {
                throw new \RuntimeException('Zaúčtování ukázkových odpisů selhalo: ' . json_encode($result['errors']));
            }
        }

        $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT DISTINCT je.id
               FROM journal_entries je
          LEFT JOIN depreciation_entries de
                 ON je.source_type = 'depreciation' AND je.source_id = de.id
              WHERE je.supplier_id = ?
                AND ((je.source_type = 'asset' AND je.source_id IN ({$placeholders}))
                  OR (je.source_type = 'depreciation' AND de.asset_id IN ({$placeholders})))"
        );
        $stmt->execute([$supplierId, ...$assetIds, ...$assetIds]);
        return [
            'asset_ids' => $assetIds,
            'journal_entry_ids' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
        ];
    }

    /**
     * @param list<array<string,mixed>> $invoices
     * @param list<int> $creditedInvoiceIds
     * @param list<array<string,mixed>> $purchases
     * @return array{statement_ids:list<int>,transaction_count:int,journal_entry_ids:list<int>,bank_account_id:int,automation_auto:int,automation_pending:int,automation_needs_input:int,automation_approved:int}
     */
    private function seedBankStatements(
        PDO $pdo,
        int $supplierId,
        int $userId,
        int $czkCurrencyId,
        \DateTimeImmutable $today,
        array $invoices,
        array $creditedInvoiceIds,
        array $purchases,
    ): array {
        $accountStmt = $pdo->prepare(
            'SELECT account_number, bank_code FROM currencies WHERE id = ? AND supplier_id = ?'
        );
        $accountStmt->execute([$czkCurrencyId, $supplierId]);
        $account = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $accountNumber = trim((string) ($account['account_number'] ?? ''));
        $bankCode = trim((string) ($account['bank_code'] ?? ''));
        $usesPlaceholderAccount = $accountNumber === '';
        if ($usesPlaceholderAccount) {
            // Náhradní účet, když firma po setupu žádný nemá. ZÁMĚRNĚ číslo, které se
            // nevyskytuje v žádném testu: 1000000005 i 2000000010 používají testy detekce
            // vlastních převodů jako „cizí" účty, takže shoda s ukázkovými daty by z nich
            // udělala past (vlastní účet vs. protistrana).
            $accountNumber = '9900112236';
            $bankCode = '0800';
        }
        if ($bankCode === '') $bankCode = '0100';
        $canonical = AccountNumberNormalizer::canonical($accountNumber);
        $normalizedBankCode = AccountNumberNormalizer::canonicalBankCode($bankCode) ?? '';
        if ($canonical === null) {
            throw new \RuntimeException('Bankovní účet pro ukázkové výpisy nelze normalizovat.');
        }
        $registryStmt = $pdo->prepare(
            'SELECT id FROM supplier_bank_accounts
              WHERE supplier_id = ? AND account_canonical = ? AND bank_code_norm = ?'
        );
        // POZOR: `currencies.account_number` se ZÁMĚRNĚ nepřepisuje. Je to uživatelská
        // konfigurace firmy (skutečný účet), ne ukázková data — a purge sample dat by ji
        // musel umět vrátit, jinak po sobě generátor nechá stopu. Vazbu na výpisy drží
        // `supplier_bank_accounts` níže, což je registr, se kterým pracuje párování.
        $registryStmt->execute([$supplierId, $canonical, $normalizedBankCode]);
        $bankAccountId = (int) $registryStmt->fetchColumn();
        if ($bankAccountId === 0) {
            $this->supplierBankAccounts->registerSeen(
                $supplierId,
                $accountNumber,
                $bankCode,
                'CZK',
                $usesPlaceholderAccount ? null : $czkCurrencyId,
            );
            $registryStmt->execute([$supplierId, $canonical, $normalizedBankCode]);
            $bankAccountId = (int) $registryStmt->fetchColumn();
            if ($bankAccountId === 0) {
                throw new \RuntimeException('Bankovní účet pro ukázkové výpisy se nepodařilo zaevidovat.');
            }
        } else {
            $bankAccountId = 0;
        }

        $invoicePool = array_values(array_filter(
            $invoices,
            static fn (array $i): bool => $i['currency'] === 'CZK'
                && !in_array((int) $i['id'], $creditedInvoiceIds, true),
        ));
        $purchasePool = array_values(array_filter(
            $purchases,
            static fn (array $i): bool => $i['currency'] === 'CZK',
        ));
        if (count($invoicePool) < 60 || count($purchasePool) < 48) {
            throw new \RuntimeException('Pro bankovní výpisy nevznikl dostatek CZK dokladů.');
        }

        $statementIds = [];
        $journalIds = [];
        $transactionCount = 0;
        $balance = 850000.0;
        $openingDate = $today->modify('first day of this month')->modify('-5 months')->format('Y-m-d');
        $journalIds[] = $this->posting->postDocument($supplierId, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => $balance],
            ['account_code' => '411', 'side' => 'credit', 'amount' => $balance],
        ], [
            'entry_date' => $openingDate, 'document_date' => $openingDate,
            'document_no' => 'PS-SAMPLE-BANK', 'description' => 'Vložený kapitál, počáteční stav ukázkového bankovního účtu',
            'posted' => true, 'user_id' => $userId, 'posted_by' => $userId,
        ]);
        for ($s = 0; $s < self::BANK_STATEMENT_COUNT; $s++) {
            // Ukotvit na první den měsíce PŘED odečtem, ne po něm. `modify('-N months')`
            // nad 29.–31. dnem přeteče do dalšího měsíce (29. 7. − 5 měsíců = 29. 2.,
            // což se u nepřestupného roku normalizuje na 1. 3.), takže dva různé kroky
            // smyčky vyrobily TÝŽ měsíc — a s ním i shodný název souboru a hash výpisu.
            // Generování ukázkových dat pak padalo na duplicitním klíči výpisu,
            // spolehlivě vždy 29.–31. dne v měsíci.
            $month = $today->modify('first day of this month')->modify('-' . (5 - $s) . ' months');
            $statementDate = min($month->modify('last day of this month')->format('Y-m-d'), $today->format('Y-m-d'));
            $selectDocuments = static function (array &$pool, int $count) use ($statementDate): array {
                $eligible = array_values(array_filter($pool, static fn (array $doc): bool => $doc['issue_date'] <= $statementDate));
                usort($eligible, static fn (array $a, array $b): int => [$b['issue_date'], $b['id']] <=> [$a['issue_date'], $a['id']]);
                $selected = array_slice($eligible, 0, $count);
                if (count($selected) !== $count) {
                    throw new \RuntimeException('Pro ukázkový výpis chybí dostatek již vystavených dokladů.');
                }
                $selectedIds = array_column($selected, 'id');
                $pool = array_values(array_filter($pool, static fn (array $doc): bool => !in_array($doc['id'], $selectedIds, true)));
                return $selected;
            };
            $monthInvoices = $selectDocuments($invoicePool, 10);
            $monthPurchases = $selectDocuments($purchasePool, 8);
            $fileName = sprintf('demo-vypis-%s.gpc', $month->format('Y-m'));
            $pdo->prepare(
                'INSERT INTO bank_statements
                    (supplier_id, file_name, file_hash, account_number, bank_code, currency,
                     statement_number, statement_date, prev_balance, curr_balance, credit_total,
                     debit_total, transaction_count, matched_count, imported_by)
                 VALUES (?, ?, ?, ?, ?, "CZK", ?, ?, ?, ?, 0, 0, 0, 0, ?)'
            )->execute([
                $supplierId, $fileName, hash('sha256', "sample|{$supplierId}|{$fileName}"),
                $accountNumber, $bankCode, (string) ($s + 1), $statementDate,
                $balance, $balance, $userId,
            ]);
            $statementId = (int) $pdo->lastInsertId();
            $statementIds[] = $statementId;
            $creditTotal = 0.0;
            $debitTotal = 0.0;

            for ($j = 0; $j < 10; $j++) {
                $doc = $monthInvoices[$j];
                $postedAt = $month->modify('+' . min(24, 2 + $j * 2) . ' days')->format('Y-m-d');
                $postedAt = min($statementDate, max($postedAt, (string) $doc['issue_date']));
                $isPartial = $s === self::BANK_STATEMENT_COUNT - 1 && $j === 9;
                $amount = round((float) $doc['total'] * ($isPartial ? 0.4 : 1.0), 2);
                $txId = $this->insertBankTransaction($pdo, $statementId, $postedAt, $amount, [
                    'variable_symbol' => $doc['vs'],
                    'counterparty_account' => '1000000005',
                    'counterparty_bank' => '0800',
                    'counterparty_name' => $doc['company'],
                    'description' => 'Úhrada vydané faktury ' . $doc['vs'],
                    'matched_invoice_id' => $doc['id'],
                    'match_status' => $isPartial ? 'auto_partial' : 'auto_exact',
                ]);
                $this->invoicePayments->recordPayment((int) $doc['id'], $amount, $postedAt, [
                    'source' => 'bank',
                    'bank_transaction_id' => $txId,
                    'variable_symbol' => $doc['vs'],
                    'bank_reference' => 'SAMPLE-' . $txId,
                    'created_by' => $userId,
                ]);
                if ($s === self::BANK_STATEMENT_COUNT - 1 && $j >= 8) {
                    $this->bankSuggestions->createIfNoPending([
                        'supplier_id' => $supplierId,
                        'bank_transaction_id' => $txId,
                        'rule_id' => null,
                        'source' => 'payment_match',
                        'debit_account_code' => '221',
                        'credit_account_code' => '311',
                        'amount' => $amount,
                        'description' => 'Inkaso vydané faktury ' . $doc['vs'],
                        'status' => 'pending',
                        'note' => 'policy_suggest',
                        'confidence' => 1.00,
                        'operation_type' => OperationType::BANK_PAYMENT_MATCHED,
                    ]);
                } else {
                    $entryId = $this->bankPosting->postMatched($supplierId, $txId, $userId);
                    if ($entryId === null) throw new \RuntimeException("Bankovní inkaso #{$txId} se nezaúčtovalo.");
                    $journalIds[] = $entryId;
                }
                $creditTotal += $amount;
                $balance += $amount;
                $transactionCount++;
            }

            for ($j = 0; $j < 8; $j++) {
                $doc = $monthPurchases[$j];
                $postedAt = $month->modify('+' . min(25, 3 + $j * 3) . ' days')->format('Y-m-d');
                $postedAt = min($statementDate, max($postedAt, (string) $doc['issue_date']));
                $amount = round((float) $doc['total'], 2);
                $txId = $this->insertBankTransaction($pdo, $statementId, $postedAt, -$amount, [
                    'variable_symbol' => $doc['vs'],
                    'counterparty_account' => '1000000005',
                    'counterparty_bank' => '0300',
                    'counterparty_name' => $doc['company'],
                    'description' => 'Úhrada přijaté faktury ' . $doc['vs'],
                ]);
                $pdo->prepare(
                    'INSERT INTO payment_matches
                        (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type,
                         match_confidence, matched_by_user_id)
                     VALUES (?, ?, ?, ?, "manual", NULL, ?)'
                )->execute([$supplierId, $txId, $doc['id'], $amount, $userId]);
                $pdo->prepare(
                    'UPDATE purchase_invoices
                        SET status = "paid", paid_at = ?, payment_currency_id = ?,
                            paid_amount_payment_ccy = ?, paid_amount_invoice_ccy = ?
                      WHERE id = ? AND supplier_id = ?'
                )->execute([$postedAt, $czkCurrencyId, $amount, $amount, $doc['id'], $supplierId]);
                if ($s === self::BANK_STATEMENT_COUNT - 1 && $j >= 6) {
                    $this->bankSuggestions->createIfNoPending([
                        'supplier_id' => $supplierId,
                        'bank_transaction_id' => $txId,
                        'rule_id' => null,
                        'source' => 'payment_match',
                        'debit_account_code' => '321',
                        'credit_account_code' => '221',
                        'amount' => $amount,
                        'description' => 'Úhrada přijaté faktury ' . $doc['vs'],
                        'status' => 'pending',
                        'note' => 'policy_suggest',
                        'confidence' => 1.00,
                        'operation_type' => OperationType::BANK_PAYMENT_MATCHED,
                    ]);
                } else {
                    $entryId = $this->bankPosting->postMatched($supplierId, $txId, $userId);
                    if ($entryId === null) throw new \RuntimeException("Bankovní úhrada #{$txId} se nezaúčtovala.");
                    $journalIds[] = $entryId;
                }
                $debitTotal += $amount;
                $balance -= $amount;
                $transactionCount++;
            }

            foreach ([129.0, 249.0] as $feeIndex => $fee) {
                $postedAt = $statementDate;
                $isInterest = $s === self::BANK_STATEMENT_COUNT - 2 && $feeIndex === 1;
                $amount = $isInterest ? 347.25 : -$fee;
                $description = $isInterest
                    ? 'Připsaný úrok z běžného účtu'
                    : ($feeIndex === 0 ? 'Poplatek za vedení účtu' : 'Poplatek za odchozí platby');
                $txId = $this->insertBankTransaction($pdo, $statementId, $postedAt, $amount, [
                    'counterparty_name' => 'Ukázková banka',
                    'description' => $description,
                ]);
                if ($s < self::BANK_STATEMENT_COUNT - 2) {
                    $manual = $this->bankPosting->postManual($supplierId, $txId, [
                        'debit_account_code' => '568',
                        'credit_account_code' => '221',
                        'description' => $feeIndex === 0 ? 'Bankovní poplatek — vedení účtu' : 'Bankovní poplatky — transakce',
                    ], ['user_id' => $userId, 'posted_by' => $userId]);
                    $journalIds[] = (int) $manual['entry_id'];
                } elseif ($s === self::BANK_STATEMENT_COUNT - 2) {
                    $this->bankSuggestions->createIfNoPending([
                        'supplier_id' => $supplierId,
                        'bank_transaction_id' => $txId,
                        'rule_id' => null,
                        'source' => 'learned',
                        'debit_account_code' => $isInterest ? '221' : '568',
                        'credit_account_code' => $isInterest ? '662' : '221',
                        'amount' => abs($amount),
                        'description' => $description,
                        'status' => 'pending',
                        'note' => 'policy_suggest',
                        'confidence' => 0.90,
                        'operation_type' => $isInterest ? OperationType::BANK_INTEREST : OperationType::BANK_FEE,
                    ]);
                } elseif ($feeIndex === 0) {
                    $suggestion = $this->bankSuggestions->createIfNoPending([
                        'supplier_id' => $supplierId,
                        'bank_transaction_id' => $txId,
                        'rule_id' => null,
                        'source' => 'learned',
                        'debit_account_code' => '568',
                        'credit_account_code' => '221',
                        'amount' => abs($amount),
                        'description' => $description,
                        'status' => 'pending',
                        'note' => 'policy_suggest',
                        'confidence' => 0.90,
                        'operation_type' => OperationType::BANK_FEE,
                    ]);
                    $journalIds[] = $this->bankPosting->approveSuggestion(
                        $supplierId,
                        $suggestion['id'],
                        ['user_id' => $userId, 'posted_by' => $userId],
                    );
                } else {
                    $manualEntryId = $this->posting->postDocument($supplierId, 'manual', null, [
                        ['account_code' => '568', 'side' => 'debit', 'amount' => abs($amount)],
                        ['account_code' => '221', 'side' => 'credit', 'amount' => abs($amount)],
                    ], [
                        'entry_date' => $postedAt,
                        'document_date' => $postedAt,
                        'document_no' => 'RUC-SAMPLE-' . $txId,
                        'description' => 'Bankovní poplatek — ručně zaúčtováno',
                        'posted' => true,
                        'user_id' => $userId,
                        'posted_by' => $userId,
                    ]);
                    $journalIds[] = $manualEntryId;
                    $this->bankSuggestions->createIfNoPending([
                        'supplier_id' => $supplierId,
                        'bank_transaction_id' => $txId,
                        'rule_id' => null,
                        'source' => 'learned',
                        'debit_account_code' => '568',
                        'credit_account_code' => '221',
                        'amount' => abs($amount),
                        'description' => $description,
                        'status' => 'needs_input',
                        'note' => 'duplicate_suspect:#' . $manualEntryId,
                        'confidence' => 0.95,
                        'operation_type' => OperationType::BANK_FEE,
                    ]);
                }
                if ($amount > 0) {
                    $creditTotal += $amount;
                } else {
                    $debitTotal += abs($amount);
                }
                $balance += $amount;
                $transactionCount++;
            }

            $transferDate = $today->modify('-42 days')->format('Y-m-d');
            $hasCashTransfer = substr($transferDate, 0, 7) === $month->format('Y-m');
            if ($hasCashTransfer) {
                $txId = $this->insertBankTransaction($pdo, $statementId, $transferDate, -25000.0, [
                    'counterparty_name' => 'Hlavní pokladna',
                    'description' => 'Výběr hotovosti do hlavní pokladny',
                ]);
                $manual = $this->bankPosting->postManual($supplierId, $txId, [
                    'debit_account_code' => '261', 'credit_account_code' => '221',
                    'description' => 'Výběr hotovosti do hlavní pokladny',
                ], ['user_id' => $userId, 'posted_by' => $userId]);
                $journalIds[] = (int) $manual['entry_id'];
                $balance -= 25000.0;
                $debitTotal += 25000.0;
                $transactionCount++;
            }

            $pdo->prepare(
                'UPDATE bank_statements
                    SET curr_balance = ?, credit_total = ?, debit_total = ?,
                        transaction_count = ?, matched_count = 18
                  WHERE id = ?'
            )->execute([round($balance, 2), round($creditTotal, 2), round($debitTotal, 2), 20 + (int) $hasCashTransfer, $statementId]);
        }

        $automationCounts = ['auto_posted' => 0, 'pending' => 0, 'needs_input' => 0, 'approved' => 0];
        $placeholders = implode(',', array_fill(0, count($statementIds), '?'));
        $automationStmt = $pdo->prepare(
            "SELECT bps.status, COUNT(*) count
               FROM bank_posting_suggestions bps
               JOIN bank_transactions bt ON bt.id = bps.bank_transaction_id
              WHERE bps.supplier_id = ? AND bt.statement_id IN ($placeholders)
                AND bps.status IN ('auto_posted','pending','needs_input','approved')
           GROUP BY bps.status"
        );
        $automationStmt->execute([$supplierId, ...$statementIds]);
        foreach ($automationStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $automationCounts[(string) $row['status']] = (int) $row['count'];
        }

        return [
            'statement_ids' => $statementIds,
            'transaction_count' => $transactionCount,
            'journal_entry_ids' => $journalIds,
            'bank_account_id' => $bankAccountId,
            'automation_auto' => $automationCounts['auto_posted'],
            'automation_pending' => $automationCounts['pending'],
            'automation_needs_input' => $automationCounts['needs_input'],
            'automation_approved' => $automationCounts['approved'],
        ];
    }

    /** @param array<string,mixed> $data */
    private function insertBankTransaction(PDO $pdo, int $statementId, string $postedAt, float $amount, array $data): int
    {
        $fingerprint = hash('sha256', implode('|', [
            'sample', $statementId, $postedAt, number_format($amount, 2, '.', ''),
            (string) ($data['variable_symbol'] ?? ''), (string) ($data['description'] ?? ''),
        ]));
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, variable_symbol,
                 counterparty_account, counterparty_bank, counterparty_name, description,
                 bank_ref, import_fingerprint, matched_invoice_id, match_status, matched_at, matched_by)
             VALUES (?, "statement", ?, ?, "CZK", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $statementId, $postedAt, $amount,
            $data['variable_symbol'] ?? null,
            $data['counterparty_account'] ?? null,
            $data['counterparty_bank'] ?? null,
            $data['counterparty_name'] ?? null,
            $data['description'] ?? null,
            'SAMPLE-' . substr($fingerprint, 0, 24),
            $fingerprint,
            $data['matched_invoice_id'] ?? null,
            $data['match_status'] ?? 'unmatched',
            isset($data['matched_invoice_id']) ? $postedAt . ' 12:00:00' : null,
            isset($data['matched_invoice_id']) ? ($data['matched_by'] ?? null) : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Kniha jízd — 1 firemní auto, 15 jízd a 6 tankování za poslední ~2 měsíce.
     * Evidenční vrstva (do DPH/statistik/dashboardů NEvstupuje), proto stačí přímé
     * inserty. Odometer řetězíme spojitě od počátečního stavu auta, tankování
     * umisťujeme do téhož rozsahu km, ať na sebe přehledy a souhrny sedí.
     *
     * @return array{cars:int, trips:int, fuelings:int, car_id:int}
     */
    private function seedLogbook(PDO $pdo, int $supplierId, int $adminUserId, \DateTimeImmutable $today): array
    {
        // ── Auto (výchozí, firemní) ──
        $odometerStart = 85000;
        // Ukotvit před odečtem — viz seedBankStatements (přetečení konce měsíce).
        $startDate = $today->modify('first day of this month')->modify('-2 months')->format('Y-m-d');
        $pdo->prepare(
            'INSERT INTO cars (supplier_id, registration, name, brand, model, vin, fuel_type,
                               odometer_start, odometer_start_date, is_default, is_archived, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, "diesel", ?, ?, 1, 0, NULL, ?)'
        )->execute([
            $supplierId, '5AB 1234', 'Octavia firemní', 'Škoda', 'Octavia Combi 2.0 TDI',
            'TMBJJ7NE5L0123456', $odometerStart, $startDate, $adminUserId,
        ]);
        $carId = (int) $pdo->lastInsertId();

        // Kategorie cest (business/private) určují daňovou relevanci jízdy. Migrace 0109 je
        // seeduje per supplier, ale při fresh installu běží PŘED vznikem supplieru (a setup je
        // neseeduje) → nový tenant je nemá. Idempotentně je proto zajistíme tady; ON DUPLICATE
        // + LAST_INSERT_ID(id) vrátí id existující řádky bez přepsání případné úpravy uživatele.
        $ensureCat = function (string $code, string $label, int $isPrivate, int $order) use ($pdo, $supplierId): int {
            $pdo->prepare(
                'INSERT INTO trip_categories (supplier_id, code, label, is_private, display_order)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            )->execute([$supplierId, $code, $label, $isPrivate, $order]);
            return (int) $pdo->lastInsertId();
        };
        $catBusiness = $ensureCat('business', 'Služební', 0, 10);
        $catPrivate  = $ensureCat('private', 'Soukromá', 1, 20);

        // ── 15 jízd (chronologicky; odometer se řetězí spojitě) ──
        // [dní zpět, čas od, čas do, odkud, kam, účel, km, soukromá?]
        $tripDefs = [
            [68, '08:15', '11:40', 'Praha', 'Brno',            'Schůzka s klientem Demo Modrá dílna', 205, false],
            [66, '15:00', '18:20', 'Brno', 'Praha',            'Návrat z jednání',                    205, false],
            [60, '09:00', '10:35', 'Praha', 'Plzeň',           'Instalace u zákazníka',                95, false],
            [60, '16:10', '17:45', 'Plzeň', 'Praha',           'Návrat z instalace',                   95, false],
            [54, '10:30', '11:25', 'Praha', 'Kolín',           'Konzultace IT infrastruktury',         65, false],
            [50, '08:40', '10:30', 'Praha', 'Hradec Králové',  'Školení zaměstnanců klienta',         115, false],
            [49, '14:00', '15:50', 'Hradec Králové', 'Praha',  'Návrat ze školení',                   115, false],
            [44, '11:15', '11:55', 'Praha', 'Benešov',         'Servis serveru u zákazníka',           40, false],
            [40, '07:50', '09:35', 'Praha', 'Liberec',         'Obchodní jednání — nová zakázka',     105, false],
            [39, '17:20', '19:05', 'Liberec', 'Praha',         'Návrat z jednání',                    105, false],
            [32, '09:30', '11:30', 'Praha', 'Karlovy Vary',    'Soukromá cesta',                      130, true],
            [31, '18:00', '20:00', 'Karlovy Vary', 'Praha',    'Soukromá cesta — návrat',             130, true],
            [24, '08:25', '10:25', 'Praha', 'Pardubice',       'Předání hotové zakázky',              125, false],
            [16, '07:30', '11:00', 'Praha', 'Olomouc',         'Konference — prezentace řešení',      280, false],
            [6,  '13:10', '13:45', 'Praha', 'Kladno',          'Nákup HW vybavení',                    30, false],
        ];

        $tripStmt = $pdo->prepare(
            'INSERT INTO trips (supplier_id, car_id, trip_date, time_start, time_end,
                                odometer_start, odometer_end, distance_km, category_id,
                                purpose, origin, destination, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)'
        );
        $odometer = $odometerStart;
        $tripsCount = 0;
        foreach ($tripDefs as [$daysBack, $timeStart, $timeEnd, $origin, $destination, $purpose, $km, $isPrivate]) {
            $odoStart = $odometer;
            $odoEnd   = $odometer + $km;
            $odometer = $odoEnd;
            $tripStmt->execute([
                $supplierId, $carId, $today->modify("-{$daysBack} days")->format('Y-m-d'),
                $timeStart, $timeEnd, $odoStart, $odoEnd, $km,
                $isPrivate ? $catPrivate : $catBusiness,
                $purpose, $origin, $destination, $adminUserId,
            ]);
            $tripsCount++;
        }

        // ── 6 tankování (nafta; odometer ve stejném rozsahu km jako jízdy) ──
        // [dní zpět, čas, stanice, litry, cena/l vč. DPH, odometer]
        $fuelDefs = [
            [67, '07:55', 'Praha-Zličín / Demo Palivo',       48.62, 35.90, 85200],
            [58, '08:30', 'Plzeň, Borská / Demo Palivo',      45.18, 36.40, 85560],
            [46, '12:05', 'Praha, Strašnice / Demo Palivo',   50.07, 35.50, 85930],
            [36, '07:40', 'Liberec / Demo Palivo',           47.83, 37.10, 86250],
            [22, '09:15', 'Pardubice / Demo Palivo',         49.34, 36.80, 86560],
            [5,  '13:20', 'Praha-Zličín / Demo Palivo',      44.57, 38.20, 86790],
        ];

        $fuelStmt = $pdo->prepare(
            'INSERT INTO fuelings (supplier_id, car_id, fueled_date, fueled_time, fuel_type, quantity, unit,
                                   unit_price, amount_without_vat, amount_vat, amount_with_vat, currency,
                                   odometer, station, source, created_by)
             VALUES (?, ?, ?, ?, "Nafta", ?, "l", ?, ?, ?, ?, "CZK", ?, ?, "manual", ?)'
        );
        $fuelingsCount = 0;
        foreach ($fuelDefs as [$daysBack, $time, $station, $liters, $pricePerL, $odo]) {
            $withVat    = round($liters * $pricePerL, 2);
            $withoutVat = round($withVat / 1.21, 2);
            $vat        = round($withVat - $withoutVat, 2);
            $fuelStmt->execute([
                $supplierId, $carId, $today->modify("-{$daysBack} days")->format('Y-m-d'), $time,
                $liters, $pricePerL, $withoutVat, $vat, $withVat, $odo, $station, $adminUserId,
            ]);
            $fuelingsCount++;
        }

        return ['cars' => 1, 'trips' => $tripsCount, 'fuelings' => $fuelingsCount, 'car_id' => $carId];
    }

    /**
     * Interní číslo ukázkové přijaté faktury podle šablony firmy ({@see PurchaseNumberSeries}),
     * aby ukázková data vypadala jako doklady přijaté v aplikaci a sdílela s nimi řadu.
     */
    private function nextPurchaseVarsymbol(PDO $pdo, int $supplierId, string $period): string
    {
        $settings = $pdo->prepare('SELECT ' . implode(', ', PurchaseNumberSeries::supplierColumns()) . ' FROM supplier WHERE id = ?');
        $settings->execute([$supplierId]);
        ['key' => $key, 'template' => $template] = PurchaseNumberSeries::resolve((array) ($settings->fetch(PDO::FETCH_ASSOC) ?: []), 'invoice');
        $counterKey = PurchaseNumberSeries::counterKey($key, $template, $period);
        $exists = $pdo->prepare('SELECT 1 FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?');
        do {
            $pdo->prepare(
                'INSERT INTO purchase_invoice_counters (supplier_id, period, last_number) VALUES (?,?,1)
                 ON DUPLICATE KEY UPDATE last_number = last_number + 1'
            )->execute([$supplierId, $counterKey]);
            $stmt = $pdo->prepare('SELECT last_number FROM purchase_invoice_counters WHERE supplier_id=? AND period=?');
            $stmt->execute([$supplierId, $counterKey]);
            $vs = PurchaseNumberSeries::render($template, 'PF', $period, (int) $stmt->fetchColumn());
            $exists->execute([$supplierId, $vs]);
        } while ($exists->fetchColumn() !== false);
        return $vs;
    }

    private function nextVarsymbol(PDO $pdo, int $supplierId, string $type, string $period): string
    {
        $pdo->prepare(
            'INSERT INTO invoice_counters (supplier_id, invoice_type, period, last_number) VALUES (?,?,?,1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1'
        )->execute([$supplierId, $type, $period]);
        $stmt = $pdo->prepare('SELECT last_number FROM invoice_counters WHERE supplier_id=? AND invoice_type=? AND period=?');
        $stmt->execute([$supplierId, $type, $period]);
        $num = (int) $stmt->fetchColumn();
        $yy = substr($period, 2, 2);
        $mm = substr($period, 4, 2);
        $prefix = $type === 'proforma' ? '9' : ($type === 'credit_note' ? '7' : '');
        return $prefix . $yy . $mm . str_pad((string) $num, 3, '0', STR_PAD_LEFT);
    }
}
