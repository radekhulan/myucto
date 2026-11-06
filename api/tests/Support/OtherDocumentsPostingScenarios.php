<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\AssetSale\InvoiceAssetSaleService;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\InvoiceSettlementService;
use MyInvoice\Service\Accounting\OffsetService;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use Psr\Container\ContainerInterface;

/**
 * Doklady mimo faktury, banku a pokladnu pro regresní test „bez nastavení se účtuje
 * bajtově stejně" (Účtování podle dimenzí, F4): ostatní pohledávka a závazek, vzájemný
 * zápočet, zápočet proti účtu a životní cyklus majetku (zařazení, odpis, vyřazení).
 *
 * Firma má dimenze zapnuté a založené typy i hodnoty, ale doklady, karty ani klienti
 * žádné dimenze nenesou. Výstup jsou řádky zaúčtovaných zápisů s dimenzemi v podobě
 * nezávislé na id. Používá jen API, které existovalo už před F4 (master c844a173a) —
 * očekávaný výstup testu byl spočítán touto třídou nad tehdejším kódem.
 *
 * `$prepare` (volitelně) dostane kontext před zaúčtováním — testy funkce F4 jím
 * nastaví dimenze dokladů a karet a ověří, že se promítnou.
 *
 * Volající drží transakci a po doběhu ji vrátí.
 */
final class OtherDocumentsPostingScenarios
{
    use IsolatedSupplierTrait;

    public const YEAR = 2091;

    private Connection $db;
    private PostingService $posting;
    public int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $vatRateId = 0;
    private int $cashRegister = 0;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->db = $container->get(Connection::class);
        $this->posting = $container->get(PostingService::class);
    }

    /**
     * @param (callable(string $scenario, int $docId, self $ctx): void)|null $prepare
     * @return array<string,mixed> scénář => řádky zápisů
     */
    public function run(?callable $prepare = null): array
    {
        $this->setUp();
        $prepare ??= static function (): void {};
        $out = [];

        $client = $this->client('Regresní partner');
        $this->ids['client'] = $client;
        $other = $this->container->get(OtherItemService::class);
        $receivable = $other->create($this->supplierId, [
            'side' => 'receivable', 'kind' => 'claim', 'title' => 'Regresní pohledávka', 'partner_id' => $client,
            'issued_on' => self::YEAR . '-02-01', 'due_on' => self::YEAR . '-02-28', 'currency' => 'CZK', 'amount' => 1000,
            'posting_lines' => [['account_code' => '602', 'amount' => 600], ['account_code' => '648', 'amount' => 400]],
        ], $this->userId);
        $this->ids['other_receivable'] = (int) $receivable['id'];
        $prepare('other_receivable', (int) $receivable['id'], $this);
        $out['other_receivable'] = $this->entryLines((int) $other->post($this->supplierId, (int) $receivable['id'], $this->userId)['journal_entry_id']);

        $payable = $other->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Regresní nájem', 'partner_id' => $client,
            'issued_on' => self::YEAR . '-02-01', 'due_on' => self::YEAR . '-02-20', 'currency' => 'CZK', 'amount' => 1200,
            'counter_account_code' => '518',
        ], $this->userId);
        $this->ids['other_payable'] = (int) $payable['id'];
        $prepare('other_payable', (int) $payable['id'], $this);
        $out['other_payable'] = $this->entryLines((int) $other->post($this->supplierId, (int) $payable['id'], $this->userId)['journal_entry_id']);

        $fv = $this->invoice($client, 10000.00);
        $pf = $this->purchaseInvoice($client, 6000.00);
        $offsets = $this->container->get(OffsetService::class);
        $agreement = $offsets->create($this->supplierId, $client, self::YEAR . '-04-01', [
            ['doc_type' => 'invoice', 'doc_id' => $fv, 'amount' => 6000.00],
            ['doc_type' => 'purchase_invoice', 'doc_id' => $pf, 'amount' => 6000.00],
        ], 'Regresní zápočet', $this->userId);
        $agreementId = (int) $agreement['agreement']['id'];
        $prepare('offset', $agreementId, $this);
        $offsets->confirm($this->supplierId, $agreementId, ['posted_by' => $this->userId, 'user_id' => $this->userId]);
        $out['offset'] = $this->sourceLines('offset', $agreementId);

        $settled = $this->invoice($client, 2420.00);
        $this->ids['settled'] = $settled;
        $prepare('settlement', $settled, $this);
        $settlement = $this->container->get(InvoiceSettlementService::class)->create($this->supplierId, 'invoice', $settled, [
            'settled_on' => self::YEAR . '-05-31', 'amount' => 2420.00, 'account_id' => $this->accountId('355'),
        ], $this->userId);
        $out['settlement'] = $this->entryLines((int) $settlement['journal_entry_id']);

        $assets = $this->container->get(AssetService::class);
        $asset = (int) $assets->create($this->supplierId, [
            'inventory_number' => 'REGR-M1', 'name' => 'Regresní stroj', 'input_price' => 120000.00,
            'acquisition_date' => self::YEAR . '-01-15', 'tax_method' => 'straight', 'tax_group' => 2,
            'acc_useful_life_months' => 60,
        ], ['user_id' => $this->userId])['asset']['id'];
        $this->ids['asset'] = $asset;
        $prepare('asset', $asset, $this);
        $meta = ['user_id' => $this->userId, 'posted_by' => $this->userId];
        $assets->putIntoUse($this->supplierId, $asset, self::YEAR . '-01-15', true, $meta);
        $out['asset_in_use'] = $this->sourceLines('asset', $asset);
        $this->container->get(DepreciationPostingService::class)->bookYear($this->supplierId, self::YEAR, $meta);
        $depreciation = (int) $this->db->pdo()->query(
            "SELECT id FROM depreciation_entries WHERE asset_id = {$asset} AND kind = 'accounting' AND fiscal_year = " . self::YEAR
        )->fetchColumn();
        $out['depreciation'] = $this->sourceLines('depreciation', $depreciation);
        $assets->dispose($this->supplierId, $asset, ['date' => (self::YEAR + 1) . '-03-10', 'type' => 'liquidated'], $meta);
        $out['depreciation_disposal_year'] = $this->sourceLines('depreciation', (int) $this->db->pdo()->query(
            "SELECT id FROM depreciation_entries WHERE asset_id = {$asset} AND kind = 'accounting' AND fiscal_year = " . (self::YEAR + 1)
        )->fetchColumn());
        $out['asset_disposal'] = $this->sourceLines('asset_disposal', $asset);

        // Úhrada ostatního závazku bankou a pohledávky pokladnou (spárování po zaúčtování platby).
        $txId = $this->bankTransaction(-1200.00, self::YEAR . '-02-20');
        $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            ['account_code' => '325', 'side' => 'debit', 'amount' => 1200.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 1200.00],
        ], ['entry_date' => self::YEAR . '-02-20', 'posted' => true]);
        $this->ids['bank'] = $txId;
        $prepare('other_payable_bank', $txId, $this);
        $other->allocate($this->supplierId, (int) $payable['id'], ['bank_transaction_id' => $txId, 'amount' => 1200.00], $this->userId);
        $out['other_payable_bank'] = $this->sourceLines('bank', $txId);

        $cashId = $this->cashDocument(1000.00, self::YEAR . '-02-25');
        $this->posting->postDocument($this->supplierId, 'cash', $cashId, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 1000.00],
            ['account_code' => '315', 'side' => 'credit', 'amount' => 1000.00],
        ], ['entry_date' => self::YEAR . '-02-25', 'posted' => true]);
        $this->ids['cash'] = $cashId;
        $prepare('other_receivable_cash', $cashId, $this);
        $other->allocate($this->supplierId, (int) $receivable['id'], ['cash_document_id' => $cashId, 'amount' => 1000.00], $this->userId);
        $out['other_receivable_cash'] = $this->sourceLines('cash', $cashId);

        // Rozvrh opakování: výskyt vygenerovaný ze zaúčtované pohledávky.
        $schedules = $this->container->get(OtherItemScheduleService::class);
        $schedule = $schedules->create($this->supplierId, (int) $receivable['id'], ['frequency' => 'monthly'], $this->userId);
        $occurrence = (int) $schedules->generate($this->supplierId, (int) $schedule['id'], self::YEAR . '-03-15', $this->userId)['created_ids'][0];
        $prepare('other_schedule', $occurrence, $this);
        $out['other_schedule'] = $this->entryLines((int) $other->post($this->supplierId, $occurrence, $this->userId)['journal_entry_id']);

        // Karta majetku z položky přijaté faktury.
        $purchase = $this->purchaseInvoice($client, 90000.00, [30000.00, 60000.00]);
        $item = (int) $this->db->pdo()->query(
            "SELECT id FROM purchase_invoice_items WHERE purchase_invoice_id = {$purchase} ORDER BY order_index, id LIMIT 1 OFFSET 1"
        )->fetchColumn();
        $this->ids['purchase'] = $purchase;
        $prepare('asset_from_purchase', $purchase, $this);
        $fromPurchase = (int) $assets->create($this->supplierId, [
            'inventory_number' => 'REGR-M2', 'name' => 'Regresní stroj z faktury', 'input_price' => 60000.00,
            'acquisition_date' => self::YEAR . '-03-11', 'tax_method' => 'straight', 'tax_group' => 2,
            'acc_useful_life_months' => 60, 'purchase_invoice_id' => $purchase, 'purchase_invoice_item_id' => $item,
        ], ['user_id' => $this->userId])['asset']['id'];
        $this->ids['asset_from_purchase'] = $fromPurchase;
        $assets->putIntoUse($this->supplierId, $fromPurchase, self::YEAR . '-07-01', true, $meta);
        $out['asset_from_purchase'] = $this->sourceLines('asset', $fromPurchase);

        // Prodej majetku vystavenou fakturou (vyřazení z faktury).
        $sold = (int) $assets->create($this->supplierId, [
            'inventory_number' => 'REGR-M3', 'name' => 'Regresní prodaný stroj', 'input_price' => 90000.00,
            'acquisition_date' => self::YEAR . '-01-20', 'tax_method' => 'straight', 'tax_group' => 2,
            'acc_useful_life_months' => 60,
        ], ['user_id' => $this->userId])['asset']['id'];
        $this->ids['sold'] = $sold;
        $prepare('asset_sale', $sold, $this);
        $assets->putIntoUse($this->supplierId, $sold, self::YEAR . '-01-20', true, $meta);
        $saleInvoice = $this->invoice($client, 50000.00, $sold);
        $this->container->get(InvoiceAssetSaleService::class)->applyForIssuedInvoice($this->supplierId, $saleInvoice, ['user_id' => $this->userId]);
        $out['asset_sale_depreciation'] = $this->sourceLines('depreciation', (int) $this->db->pdo()->query(
            "SELECT id FROM depreciation_entries WHERE asset_id = {$sold} AND kind = 'accounting' AND fiscal_year = " . self::YEAR
        )->fetchColumn());
        $out['asset_sale_disposal'] = $this->sourceLines('asset_disposal', $sold);
        return $out;
    }

    /** @var array<string,int> id dokladů scénářů pro testy */
    public array $ids = [];

    /** Kontext po {@see run()}, aby šlo nad jednou commitnutou sadou scénářů pracovat z dalších testů. */
    public function state(): array
    {
        return ['supplierId' => $this->supplierId, 'currencyId' => $this->currencyId, 'userId' => $this->userId, 'czId' => $this->czId,
            'vatRateId' => $this->vatRateId, 'cashRegister' => $this->cashRegister, 'ids' => $this->ids];
    }

    public function restore(array $state): void
    {
        foreach ($state as $property => $value) $this->{$property} = $value;
    }

    public function bankTransaction(float $amount, string $date): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date, currency)
             VALUES (?, "synteticky-vypis-regrese", ?, "1000000005/0100", ?, "CZK")'
        )->execute([$this->supplierId, hash('sha256', uniqid('', true)), $date]);
        $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, match_status, counterparty_name, description)
             VALUES (?, ?, ?, "CZK", "unmatched", "Syntetická protistrana", "Regrese")'
        )->execute([(int) $pdo->lastInsertId(), $date, $amount]);
        return (int) $pdo->lastInsertId();
    }

    public function cashDocument(float $amount, string $date): int
    {
        $pdo = $this->db->pdo();
        if ($this->cashRegister === 0) {
            $pdo->prepare('INSERT INTO cash_registers (supplier_id, name, account_code, is_default) VALUES (?, "Regresní pokladna", "211", 0)')
                ->execute([$this->supplierId]);
            $this->cashRegister = (int) $pdo->lastInsertId();
        }
        $register = $this->cashRegister;
        $pdo->prepare(
            'INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date,
                                         description, vat_mode, total_amount, status, created_by)
             VALUES (?, ?, "in", "other", ?, ?, ?, "Úhrada pohledávky", "none", ?, "posted", ?)'
        )->execute([$this->supplierId, $register, 'REGR-P' . random_int(1000, 9999), $date, $date, $amount, $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,int> kód typu => id typu */
    public function types(): array
    {
        return $this->container->get(DimensionService::class)->ensureDefaultTypes($this->supplierId, ['projekt', 'stredisko']);
    }

    public function value(int $typeId, string $code): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id FROM dimension_values WHERE type_id = ? AND code = ?');
        $stmt->execute([$typeId, $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        return (int) $this->container->get(DimensionService::class)
            ->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => $code])['id'];
    }

    private function setUp(): void
    {
        $pdo = $this->db->pdo();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $periods = $this->container->get(AccountingPeriodRepository::class);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        // Dimenze zapnuté a založené PŘED prvním zaúčtováním, jen žádný doklad je nenese.
        $this->container->get(DimensionService::class)->setEnabled($this->supplierId, true);
        $types = $this->types();
        $this->value($types['cost_center'], 'REGR-C1');
        $this->value($types['project'], 'REGR-P1');
    }

    private function client(string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "regrese@example.invalid", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function invoice(int $client, float $total, ?int $assetId = null): int
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-03-10';
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, 0, ?, 0, "issued", "1", ?)'
        )->execute([$this->supplierId, (string) random_int(1000000000, 1999999999), $client, $date, $date, $date,
            $this->currencyId, $total, $total, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        if ($assetId !== null) {
            $pdo->prepare(
                'INSERT INTO invoice_items
                    (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                     total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code, asset_id)
                 VALUES (?, "Prodej majetku", 1, "ks", ?, ?, 0, ?, 0, ?, 0, "1", ?)'
            )->execute([$id, $total, $this->vatRateId, $total, $total, $assetId]);
        }
        $this->posting->postDocument($this->supplierId, 'invoice', $id, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => $total],
            ['account_code' => '602', 'side' => 'credit', 'amount' => $total],
        ], ['entry_date' => $date, 'posted_by' => $this->userId]);
        return $id;
    }

    /** @param list<float> $items základy položek (prázdné = bez položek) */
    private function purchaseInvoice(int $vendor, float $total, array $items = []): int
    {
        $pdo = $this->db->pdo();
        $date = self::YEAR . '-03-11';
        $vs = 'PF-' . random_int(1000000000, 1999999999);
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, varsymbol, vendor_id, vendor_invoice_number, document_kind,
                 issue_date, due_date, received_at, currency_id, vendor_snapshot, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, "{}", ?, "received", ?)'
        )->execute([$this->supplierId, $vs, $vendor, $vs, $date, $date, $date, $this->currencyId, $total, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        foreach ($items as $i => $net) {
            $pdo->prepare(
                'INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                     total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, "Regresní nákup", 1, "ks", ?, ?, 0, ?, 0, ?, ?)'
            )->execute([$id, $net, $this->vatRateId, $net, $net, $i]);
        }
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $id, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => $total],
            ['account_code' => '321', 'side' => 'credit', 'amount' => $total],
        ], ['entry_date' => $date, 'posted_by' => $this->userId]);
        return $id;
    }

    private function accountId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$this->supplierId, $code]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array{account:string, side:string, amount:string, dims:array<string,string>, splits:array<string,array<string,float>>}> */
    public function sourceLines(string $sourceType, int $sourceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL ORDER BY id'
        );
        $stmt->execute([$this->supplierId, $sourceType, $sourceId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $entryId) {
            array_push($out, ...$this->entryLines((int) $entryId));
        }
        return $out;
    }

    /** @return list<array{account:string, side:string, amount:string, dims:array<string,string>, splits:array<string,array<string,float>>}> */
    public function entryLines(int $entryId): array
    {
        $pdo = $this->db->pdo();
        $codes = static fn (string $sql): array => array_map('strval', $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR));
        $typeCodes = $codes('SELECT id, code FROM dimension_types WHERE supplier_id = ' . $this->supplierId);
        $valueCodes = $codes('SELECT id, code FROM dimension_values WHERE supplier_id = ' . $this->supplierId);
        $accountCodes = $codes('SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ' . $this->supplierId);
        $assignments = $this->container->get(DimensionAssignmentRepository::class);
        $dims = $assignments->entryLineDimensions($this->supplierId, $entryId);
        $splits = $assignments->entryLineSplits($this->supplierId, $entryId);
        $out = [];
        foreach ($this->container->get(JournalEntryRepository::class)->linesForEntry($entryId, $this->supplierId) as $line) {
            $named = [];
            foreach ($dims[(int) $line['id']] ?? [] as $typeId => $valueId) {
                $named[$typeCodes[$typeId] ?? (string) $typeId] = $valueCodes[$valueId] ?? (string) $valueId;
            }
            ksort($named);
            $namedSplits = [];
            foreach ($splits[(int) $line['id']] ?? [] as $typeId => $shares) {
                foreach ($shares as $valueId => $share) {
                    $namedSplits[$typeCodes[$typeId] ?? (string) $typeId][$valueCodes[$valueId] ?? (string) $valueId] = round((float) $share, 6);
                }
            }
            ksort($namedSplits);
            $out[] = [
                'account' => $accountCodes[(int) $line['account_id']] ?? '?',
                'side' => (string) $line['side'],
                'amount' => number_format((float) $line['amount'], 2, '.', ''),
                'dims' => $named,
                'splits' => $namedSplits,
            ];
        }
        return $out;
    }
}
