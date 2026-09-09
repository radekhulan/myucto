<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Invoice\PaymentDueResolver;
use MyInvoice\Service\Invoice\RecurringDraftReminder;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test pravidelné fakturace.
 *
 * Vytvoří dočasnou šablonu (s existujícím supplier/client/currency/vat_rate
 * z dev DB), zavolá RecurringInvoiceGenerator přímo (stejně jako cron), ověří:
 *   - faktura vznikla s vazbou recurring_template_id
 *   - položky z šablony se zkopírovaly
 *   - cron-flag auto_issue=true → faktura má varsymbol + status='issued'
 *   - šablona má posunutý next_run_date
 *   - posun popisu měsíce funguje (M/YYYY → +1 měsíc u monthly)
 *
 * Po sobě uklízí všechno (šablonu + vygenerovanou fakturu + items).
 */
#[Group('integration')]
final class RecurringGeneratorTest extends TestCase
{
    private Connection $db;
    private RecurringInvoiceGenerator $generator;
    private RecurringTemplateRepository $repo;
    private RecurringDraftReminder $reminder;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;

    /** @var int[] šablony k vyčištění */
    private array $createdTemplateIds = [];
    /** @var int[] faktury k vyčištění */
    private array $createdInvoiceIds = [];
    /** Původní plátcovství supplier-a — test ho vynucuje na plátce (viz setUp). */
    private ?array $origVatFlags = null;
    /** Původní registrace do OSS — nastavuje ji jen část testů (viz setSupplierOss()). */
    private ?array $origOssFlags = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }

        try {
            $app = Bootstrap::buildApp();
            $container = $app->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->db = $container->get(Connection::class);
            $this->generator = $container->get(RecurringInvoiceGenerator::class);
            $this->repo = $container->get(RecurringTemplateRepository::class);
            $this->reminder = $container->get(RecurringDraftReminder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        // Vezmi první existující supplier (aby kill-switch byl 1)
        $row = $this->db->pdo()->query(
            "SELECT id FROM supplier WHERE auto_generate_recurring = 1 LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('Žádný supplier s auto_generate_recurring=1');
        }
        $this->supplierId = (int) $row['id'];

        $row = $this->db->pdo()->prepare(
            "SELECT id FROM clients WHERE supplier_id = ? AND archived_at IS NULL LIMIT 1"
        );
        $row->execute([$this->supplierId]);
        $clientId = (int) $row->fetchColumn();
        if ($clientId <= 0) {
            $this->markTestSkipped("Supplier #{$this->supplierId} nemá žádné klienty");
        }
        $this->clientId = $clientId;

        $row = $this->db->pdo()->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 LIMIT 1"
        );
        $row->execute([$this->supplierId]);
        $this->currencyId = (int) $row->fetchColumn();
        if ($this->currencyId <= 0) {
            $this->markTestSkipped('Supplier nemá žádnou aktivní měnu');
        }

        $this->vatRateId = (int) $this->db->pdo()
            ->query("SELECT id FROM vat_rates WHERE is_reverse_charge = 0 ORDER BY is_default DESC, rate_percent DESC LIMIT 1")
            ->fetchColumn();
        if ($this->vatRateId <= 0) {
            $this->markTestSkipped('Žádná použitelná VAT sazba');
        }

        $this->userId = (int) $this->db->pdo()
            ->query("SELECT id FROM users ORDER BY id LIMIT 1")
            ->fetchColumn();

        // Test předpokládá PLÁTCE (sazby 21 % na fakturách) — generátor neplátci/IO
        // autoritativně přepíná položky na 0 % (applyNonVatPayerRate), takže reálné
        // nastavení dodavatele v dev DB by test rozbilo. Vynutit a v tearDown vrátit.
        // Generátor rozhoduje z historie (payerAtExpr k rozhodnému datu faktury),
        // proto se vynucuje řádkem historie k dnešku, ne jen živou cache.
        $flags = $this->db->pdo()->query(
            "SELECT is_vat_payer, is_identified FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->origVatFlags = $flags;
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 1, is_identified = 0 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->setPayerHistoryToday(true);
    }

    /** Upsert řádku historie plátcovství s účinností dnes (SSOT pro „plátce k datu"). */
    private function setPayerHistoryToday(bool $isVatPayer): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer)
             VALUES (?, CURDATE(), ?)
             ON DUPLICATE KEY UPDATE is_vat_payer = VALUES(is_vat_payer)'
        )->execute([$this->supplierId, $isVatPayer ? 1 : 0]);
    }

    /**
     * Registrace dodavatele do OSS na dobu určitou. Původní hodnoty se snímkují při prvním
     * volání a vrací je tearDown — dev DB se nesmí testem trvale přepnout do OSS.
     */
    private function setSupplierOss(bool $enabled, ?string $validFrom, ?string $validTo): void
    {
        $pdo = $this->db->pdo();
        if ($this->origOssFlags === null) {
            $this->origOssFlags = $pdo->query(
                "SELECT oss_enabled, oss_valid_from, oss_valid_to FROM supplier WHERE id = {$this->supplierId}"
            )->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $pdo->prepare(
            'UPDATE supplier SET oss_enabled = ?, oss_valid_from = ?, oss_valid_to = ? WHERE id = ?'
        )->execute([$enabled ? 1 : 0, $validFrom, $validTo, $this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->origOssFlags !== null && $this->supplierId > 0) {
            $this->db->pdo()->prepare(
                'UPDATE supplier SET oss_enabled = ?, oss_valid_from = ?, oss_valid_to = ? WHERE id = ?'
            )->execute([
                (int) ($this->origOssFlags['oss_enabled'] ?? 0),
                $this->origOssFlags['oss_valid_from'] ?? null,
                $this->origOssFlags['oss_valid_to'] ?? null,
                $this->supplierId,
            ]);
        }
        if (isset($this->db) && $this->origVatFlags !== null && $this->supplierId > 0) {
            $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ? WHERE id = ?')
                ->execute([
                    (int) ($this->origVatFlags['is_vat_payer'] ?? 1),
                    (int) ($this->origVatFlags['is_identified'] ?? 0),
                    $this->supplierId,
                ]);
            $this->db->pdo()
                ->prepare('DELETE FROM supplier_vat_status_history WHERE supplier_id = ? AND effective_from = CURDATE()')
                ->execute([$this->supplierId]);
        }
        if (empty($this->createdInvoiceIds) && empty($this->createdTemplateIds)) {
            if (isset($this->db)) $this->db->close();
            return;
        }
        $pdo = $this->db->pdo();
        // Faktury smazat dřív než šablonu (kvůli FK fk_inv_recurring SET NULL by to
        // teoreticky zvládlo, ale chceme řízený cleanup).
        foreach ($this->createdInvoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->createdTemplateIds as $id) {
            $pdo->prepare('DELETE FROM recurring_invoice_template_items WHERE template_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM recurring_invoice_templates WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testGeneratorCreatesIssuedInvoiceWithLinkBack(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $tplId = $this->repo->create([
            'supplier_id'    => $this->supplierId,
            'client_id'      => $this->clientId,
            'project_id'     => null,
            'name'           => 'TEST recurring (PHPUnit)',
            'frequency'      => 'monthly',
            'day_of_month'   => null,
            'end_of_month'   => false,
            'anchor_date'    => $today,
            'next_run_date'  => $today,
            'end_date'       => null,
            'invoice_type'   => 'invoice',
            'currency_id'    => $this->currencyId,
            'language'       => 'cs',
            'payment_method' => 'bank_transfer',
            'reverse_charge' => false,
            'payment_due_days' => 14,
            'note_above_items' => null,
            'note_below_items' => null,
            'increment_month_in_descriptions' => true,
            'auto_issue'     => true,
            'auto_send_email'=> false,  // ne odesílat, ať se test nesnaží SMTP
            'status'         => 'active',
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        $this->repo->replaceItems($tplId, [
            [
                'description' => 'Hosting ' . (new \DateTimeImmutable($today))->format('n/Y'),
                'quantity' => 1.0,
                'unit' => 'měs',
                'unit_price_without_vat' => 500.00,
                'vat_rate_id' => $this->vatRateId,
                'order_index' => 0,
            ],
            [
                'description' => 'Support paušál',
                'quantity' => 2.0,
                'unit' => 'h',
                'unit_price_without_vat' => 1500.00,
                'vat_rate_id' => $this->vatRateId,
                'order_index' => 1,
            ],
        ]);

        $result = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $result['invoice_id'];

        // Vygenerovaná faktura — basic asserts
        $this->assertGreaterThan(0, $result['invoice_id']);
        $this->assertTrue($result['issued'], 'auto_issue=true musí vystavit fakturu');
        $this->assertNotNull($result['varsymbol'], 'Vystavená faktura musí mít varsymbol');
        $this->assertEmpty($result['sent_to'], 'auto_send_email=false → žádné e-maily');

        // Faktura v DB
        $inv = $this->db->pdo()->prepare(
            "SELECT id, status, varsymbol, recurring_template_id, total_with_vat, supplier_id, client_id, currency_id, payment_method
               FROM invoices WHERE id = ?"
        );
        $inv->execute([$result['invoice_id']]);
        $row = $inv->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'Faktura existuje');
        $this->assertSame('issued', $row['status']);
        $this->assertNotEmpty($row['varsymbol']);
        $this->assertSame($tplId, (int) $row['recurring_template_id']);
        $this->assertSame($this->supplierId, (int) $row['supplier_id']);
        $this->assertSame($this->clientId, (int) $row['client_id']);
        $this->assertSame($this->currencyId, (int) $row['currency_id']);
        $this->assertSame('bank_transfer', $row['payment_method']);
        // 1×500 + 2×1500 = 3500 base + DPH (default rate je >0)
        $this->assertGreaterThanOrEqual(3500.00, (float) $row['total_with_vat']);

        // Položky se zkopírovaly
        $items = $this->db->pdo()->prepare(
            "SELECT description, quantity, unit_price_without_vat FROM invoice_items
              WHERE invoice_id = ? ORDER BY order_index"
        );
        $items->execute([$result['invoice_id']]);
        $itemRows = $items->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $itemRows);

        // Description sync — M/YYYY se synchronizuje k tax_date (= issue_date při default
        // tax_date_mode='same_as_issue'). Popis šablony "Hosting 5/2026" generuje
        // "Hosting 5/2026" pokud issue_date spadá do 5/2026, ne "Hosting 6/2026".
        $currentMonth = (int) (new \DateTimeImmutable($today))->format('n');
        $currentYear  = (int) (new \DateTimeImmutable($today))->format('Y');
        $this->assertStringContainsString(
            "Hosting {$currentMonth}/{$currentYear}",
            $itemRows[0]['description'],
            'description sync má synchronizovat M/YYYY na měsíc DUZP/issue_date',
        );

        // Šablona má posunutý next_run_date a last_run_date
        $tplRow = $this->db->pdo()->prepare(
            "SELECT next_run_date, last_run_date, status FROM recurring_invoice_templates WHERE id = ?"
        );
        $tplRow->execute([$tplId]);
        $tplData = $tplRow->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($today, $tplData['last_run_date']);
        $this->assertNotSame($today, $tplData['next_run_date'], 'next_run_date musí být posunut');
        $this->assertGreaterThan($today, $tplData['next_run_date']);
        $this->assertSame('active', $tplData['status']);
    }

    /**
     * Šablona bez vlastní jednotky splatnosti ji dědí z klienta (a ten z dodavatele).
     * Dřív generátor počítal vždy `+N days`, takže z klientova „1× měsíc" vznikla
     * splatnost 1 DEN.
     */
    public function testGeneratorInheritsCalendarMonthDueUnitFromClient(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $pdo = $this->db->pdo();

        $orig = $pdo->prepare('SELECT payment_due_default, payment_due_unit FROM clients WHERE id = ?');
        $orig->execute([$this->clientId]);
        $originalClient = $orig->fetch(PDO::FETCH_ASSOC);

        try {
            $pdo->prepare(
                "UPDATE clients SET payment_due_default = 1, payment_due_unit = 'month' WHERE id = ?"
            )->execute([$this->clientId]);

            $tplId = $this->repo->create([
                'supplier_id'    => $this->supplierId,
                'client_id'      => $this->clientId,
                'project_id'     => null,
                'name'           => 'TEST recurring due unit (PHPUnit)',
                'frequency'      => 'monthly',
                'day_of_month'   => null,
                'end_of_month'   => false,
                'anchor_date'    => $today,
                'next_run_date'  => $today,
                'end_date'       => null,
                'invoice_type'   => 'invoice',
                'currency_id'    => $this->currencyId,
                'language'       => 'cs',
                'payment_method' => 'bank_transfer',
                'reverse_charge' => false,
                'payment_due_days' => 1,
                'payment_due_unit' => null,
                'note_above_items' => null,
                'note_below_items' => null,
                'increment_month_in_descriptions' => false,
                'auto_issue'     => false,
                'auto_send_email' => false,
                'status'         => 'active',
            ], $this->userId);
            $this->createdTemplateIds[] = $tplId;

            $this->repo->replaceItems($tplId, [[
                'description' => 'Paušál',
                'quantity' => 1.0,
                'unit' => 'měs',
                'unit_price_without_vat' => 1000.00,
                'vat_rate_id' => $this->vatRateId,
                'order_index' => 0,
            ]]);

            $result = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
            $this->createdInvoiceIds[] = $result['invoice_id'];

            $due = $pdo->prepare('SELECT issue_date, due_date FROM invoices WHERE id = ?');
            $due->execute([$result['invoice_id']]);
            $row = $due->fetch(PDO::FETCH_ASSOC);

            $expected = PaymentDueResolver::dueDate(
                $today,
                ['payment_due_days' => 1, 'payment_due_unit' => null],
                ['payment_due_default' => 1, 'payment_due_unit' => 'month'],
                null,
            );
            $this->assertSame($expected, (string) $row['due_date'], 'splatnost má být +1 kalendářní měsíc');
            $this->assertNotSame(
                (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d'),
                (string) $row['due_date'],
                'jednotka se nesmí degradovat na dny',
            );
        } finally {
            $pdo->prepare('UPDATE clients SET payment_due_default = ?, payment_due_unit = ? WHERE id = ?')
                ->execute([
                    $originalClient['payment_due_default'] ?? null,
                    $originalClient['payment_due_unit'] ?? null,
                    $this->clientId,
                ]);
        }
    }

    public function testGeneratorDraftOnlyWhenAutoIssueFalse(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $tplId = $this->repo->create([
            'supplier_id'    => $this->supplierId,
            'client_id'      => $this->clientId,
            'name'           => 'TEST recurring draft-only (PHPUnit)',
            'frequency'      => 'monthly',
            'end_of_month'   => false,
            'anchor_date'    => $today,
            'next_run_date'  => $today,
            'invoice_type'   => 'invoice',
            'currency_id'    => $this->currencyId,
            'language'       => 'cs',
            'payment_method' => 'bank_transfer',
            'payment_due_days' => 7,
            'increment_month_in_descriptions' => false,
            'auto_issue'     => false,
            'auto_send_email'=> false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        $this->repo->replaceItems($tplId, [[
            'description' => 'Konzultace',
            'quantity' => 1.0,
            'unit' => 'h',
            'unit_price_without_vat' => 1000.00,
            'vat_rate_id' => $this->vatRateId,
            'order_index' => 0,
        ]]);

        $result = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $result['invoice_id'];

        $this->assertFalse($result['issued']);
        $this->assertNull($result['varsymbol']);

        $row = $this->db->pdo()->prepare("SELECT status, varsymbol FROM invoices WHERE id = ?");
        $row->execute([$result['invoice_id']]);
        $data = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('draft', $data['status']);
        $this->assertNull($data['varsymbol']);
    }

    public function testGeneratorMaterializesTemplateDiscountLine(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $tplId = $this->repo->create([
            'supplier_id'      => $this->supplierId,
            'client_id'        => $this->clientId,
            'name'             => 'TEST recurring sleva (PHPUnit)',
            'frequency'        => 'monthly',
            'end_of_month'     => false,
            'anchor_date'      => $today,
            'next_run_date'    => $today,
            'invoice_type'     => 'invoice',
            'currency_id'      => $this->currencyId,
            'language'         => 'cs',
            'payment_method'   => 'bank_transfer',
            'payment_due_days' => 7,
            'discount_percent' => 10.0,
            'increment_month_in_descriptions' => false,
            'auto_issue'       => false,
            'auto_send_email'  => false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        $this->repo->replaceItems($tplId, [[
            'description' => 'Paušál',
            'quantity' => 1.0,
            'unit' => 'měs',
            'unit_price_without_vat' => 1000.00,
            'vat_rate_id' => $this->vatRateId,
            'order_index' => 0,
        ]]);

        $result = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $result['invoice_id'];

        // Šablona si pamatuje discount_percent
        $tpl = $this->repo->find($tplId);
        $this->assertEqualsWithDelta(10.0, (float) $tpl['discount_percent'], 0.001);

        // Vygenerovaná faktura: standardní položka + záporná slevová (item_kind='discount')
        $items = $this->db->pdo()->prepare(
            "SELECT item_kind, total_without_vat FROM invoice_items WHERE invoice_id = ? ORDER BY order_index"
        );
        $items->execute([$result['invoice_id']]);
        $rows = $items->fetchAll(PDO::FETCH_ASSOC);
        $discountRows = array_values(array_filter($rows, fn ($r) => $r['item_kind'] === 'discount'));
        $this->assertCount(1, $discountRows, 'Měla by vzniknout 1 slevová položka');
        $this->assertEqualsWithDelta(-100.0, (float) $discountRows[0]['total_without_vat'], 0.001);

        // discount_percent na faktuře + základ po slevě
        $inv = $this->db->pdo()->prepare("SELECT discount_percent, total_without_vat, total_vat FROM invoices WHERE id = ?");
        $inv->execute([$result['invoice_id']]);
        $invRow = $inv->fetch(PDO::FETCH_ASSOC);
        $this->assertEqualsWithDelta(10.0, (float) $invRow['discount_percent'], 0.001);
        $this->assertEqualsWithDelta(900.0, (float) $invRow['total_without_vat'], 0.001);

        // Regrese: vat_rate_snapshot se musí nastavit z vat_rates (dřív se vkládalo 0 →
        // DPH vycházela 0). Vybraná sazba je >0 %, takže DPH PO slevě musí být kladné.
        $this->assertGreaterThan(0.0, (float) $invRow['total_vat'], 'DPH musí být aplikováno (regrese vat_rate_snapshot=0)');
        $snap = $this->db->pdo()->prepare('SELECT DISTINCT vat_rate_snapshot FROM invoice_items WHERE invoice_id = ?');
        $snap->execute([$result['invoice_id']]);
        $this->assertNotContains('0.00', $snap->fetchAll(PDO::FETCH_COLUMN), 'Položky musí mít reálný vat_rate_snapshot, ne 0');
    }

    /**
     * Neplátce DPH: i když šablona drží nominální sazbu (21 %), vygenerovaná faktura
     * musí mít DPH = 0 a položky 0% snapshot (generátor coercne sazbu na 0% Osvobozeno).
     * Chrání šablony uložené dřív, než frontend začal pro neplátce vybírat 0% sazbu.
     */
    public function testGeneratorZeroesVatForNonVatPayerSupplier(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        // Default sazba musí být >0 %, jinak by 0 DPH nic nedokázala.
        $stmt = $this->db->pdo()->prepare('SELECT rate_percent FROM vat_rates WHERE id = ?');
        $stmt->execute([$this->vatRateId]);
        if ((float) $stmt->fetchColumn() <= 0.0) {
            $this->markTestSkipped('Default sazba je 0 % — test neplátce nedává smysl.');
        }
        // Musí existovat 0% osvobozená sazba platná dnes, na kterou se coercne.
        $zeroId = (int) $this->db->pdo()->query(
            "SELECT id FROM vat_rates
              WHERE rate_percent = 0 AND is_reverse_charge = 0
                AND valid_from <= CURDATE() AND (valid_to IS NULL OR valid_to >= CURDATE())
              ORDER BY valid_from DESC LIMIT 1"
        )->fetchColumn();
        if ($zeroId <= 0) {
            $this->markTestSkipped('Žádná platná 0% osvobozená sazba v DB.');
        }

        // Šablona s nominální sazbou — jako kdyby ji založil neplátce před opravou.
        $tplId = $this->repo->create([
            'supplier_id'      => $this->supplierId,
            'client_id'        => $this->clientId,
            'name'             => 'TEST recurring neplátce DPH (PHPUnit)',
            'frequency'        => 'monthly',
            'end_of_month'     => false,
            'anchor_date'      => $today,
            'next_run_date'    => $today,
            'invoice_type'     => 'invoice',
            'currency_id'      => $this->currencyId,
            'language'         => 'cs',
            'payment_method'   => 'bank_transfer',
            'payment_due_days' => 14,
            'increment_month_in_descriptions' => false,
            'auto_issue'       => false,
            'auto_send_email'  => false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;
        $this->repo->replaceItems($tplId, [[
            'description' => 'Paušál',
            'quantity' => 1.0,
            'unit' => 'měs',
            'unit_price_without_vat' => 1000.00,
            'vat_rate_id' => $this->vatRateId, // nominální (>0 %)
            'order_index' => 0,
        ]]);

        // Dočasně přepni dodavatele na neplátce DPH (historie i cache); v finally vrátit.
        $orig = (int) $this->db->pdo()
            ->query("SELECT is_vat_payer FROM supplier WHERE id = {$this->supplierId}")
            ->fetchColumn();
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 0 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->setPayerHistoryToday(false);
        try {
            $result = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
            $this->createdInvoiceIds[] = $result['invoice_id'];

            $inv = $this->db->pdo()->prepare(
                'SELECT total_without_vat, total_vat, total_with_vat FROM invoices WHERE id = ?'
            );
            $inv->execute([$result['invoice_id']]);
            $invRow = $inv->fetch(PDO::FETCH_ASSOC);
            $this->assertEqualsWithDelta(0.0, (float) $invRow['total_vat'], 0.001, 'Neplátce DPH → DPH = 0');
            $this->assertEqualsWithDelta(1000.0, (float) $invRow['total_without_vat'], 0.001);
            $this->assertEqualsWithDelta(1000.0, (float) $invRow['total_with_vat'], 0.001);

            // Položky mají 0% snapshot a coercnuté vat_rate_id na 0% sazbu.
            $items = $this->db->pdo()->prepare(
                'SELECT vat_rate_id, vat_rate_snapshot, total_vat FROM invoice_items WHERE invoice_id = ?'
            );
            $items->execute([$result['invoice_id']]);
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $this->assertSame($zeroId, (int) $it['vat_rate_id'], 'vat_rate_id coercnuto na 0% sazbu');
                $this->assertEqualsWithDelta(0.0, (float) $it['vat_rate_snapshot'], 0.001);
                $this->assertEqualsWithDelta(0.0, (float) $it['total_vat'], 0.001);
            }
        } finally {
            $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = ? WHERE id = ?')
                ->execute([$orig, $this->supplierId]);
            $this->setPayerHistoryToday(true);
        }
    }

    /**
     * Přenos šablony s `prices_include_vat=true` do VYDANÉ faktury: režim se musí
     * propsat, DPH se počítá koeficientem (na haléř), unit_price_without_vat nese
     * brutto, ale uložený řádkový základ + zobrazené netto sedí na haléř.
     */
    public function testGeneratorPricesIncludeVatInvoiceUsesCoefficientAndKeepsGrossUnitPrice(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare('SELECT rate_percent FROM vat_rates WHERE id = ?');
        $stmt->execute([$this->vatRateId]);
        $rate = (float) $stmt->fetchColumn();
        if ($rate <= 0.0) {
            $this->markTestSkipped('Default sazba je 0 % — koeficient nelze ověřit.');
        }

        $tplId = $this->repo->create([
            'supplier_id'      => $this->supplierId,
            'client_id'        => $this->clientId,
            'name'             => 'TEST recurring s DPH (PHPUnit)',
            'frequency'        => 'monthly',
            'end_of_month'     => false,
            'anchor_date'      => $today,
            'next_run_date'    => $today,
            'invoice_type'     => 'invoice',
            'currency_id'      => $this->currencyId,
            'language'         => 'cs',
            'payment_method'   => 'bank_transfer',
            'reverse_charge'   => false,
            'prices_include_vat' => true,
            'payment_due_days' => 14,
            'increment_month_in_descriptions' => false,
            'auto_issue'       => false,
            'auto_send_email'  => false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        // 2 ks × 605 Kč S DPH → řádkový gross 1210.
        $this->repo->replaceItems($tplId, [[
            'description' => 'Paušál s DPH',
            'quantity' => 2.0,
            'unit' => 'ks',
            'unit_price_without_vat' => 605.00, // v režimu s DPH = cena včetně DPH
            'vat_rate_id' => $this->vatRateId,
            'order_index' => 0,
        ]]);

        $result = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $result['invoice_id'];

        // Očekávané hodnoty koeficientem z grossu 1210.
        $gross = 1210.00;
        $expVat  = round($gross * $rate / (100.0 + $rate), 2);
        $expBase = round($gross - $expVat, 2);

        $inv = $this->db->pdo()->prepare(
            'SELECT prices_include_vat, total_without_vat, total_vat, total_with_vat FROM invoices WHERE id = ?'
        );
        $inv->execute([$result['invoice_id']]);
        $invRow = $inv->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $invRow['prices_include_vat'], 'Režim ceny s DPH se musí propsat do faktury');
        $this->assertEqualsWithDelta($gross, (float) $invRow['total_with_vat'], 0.001, 'Celek s DPH musí sedět přesně na zadaný gross');
        $this->assertEqualsWithDelta($expVat, (float) $invRow['total_vat'], 0.001);
        $this->assertEqualsWithDelta($expBase, (float) $invRow['total_without_vat'], 0.001);

        // Položka: unit_price_without_vat nese BRUTTO (605), ale řádkový základ je netto.
        $it = $this->db->pdo()->prepare(
            'SELECT quantity, unit_price_without_vat, total_without_vat, total_with_vat FROM invoice_items WHERE invoice_id = ?'
        );
        $it->execute([$result['invoice_id']]);
        $itRow = $it->fetch(PDO::FETCH_ASSOC);
        $this->assertEqualsWithDelta(605.00, (float) $itRow['unit_price_without_vat'], 0.001, 'V režimu s DPH drží unit_price brutto');
        $this->assertEqualsWithDelta($expBase, (float) $itRow['total_without_vat'], 0.001);
        $this->assertEqualsWithDelta($gross, (float) $itRow['total_with_vat'], 0.001);

        // Zobrazené NETTO jednotkové ceny = base / množství; zpětně × množství × (1+sazba) = gross.
        $displayNetUnit = round((float) $itRow['total_without_vat'] / (float) $itRow['quantity'], 2);
        $this->assertEqualsWithDelta($gross, round($displayNetUnit * (float) $itRow['quantity'] * (1 + $rate / 100), 2), 0.02);

        // ISDOC export: UnitPrice musí být NETTO (ne brutto), UnitPriceTaxInclusive brutto/ks.
        try {
            $isdoc = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Service\Export\IsdocExporter::class);
        } catch (\Throwable $e) {
            return; // exporter nedostupný v DI — zbytek testu stačí
        }
        $repoInv = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Repository\InvoiceRepository::class)->find($result['invoice_id']);
        $xml = $isdoc->buildXml($repoInv);
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $unitPrice = $dom->getElementsByTagNameNS('*', 'UnitPrice')->item(0);
        $unitPriceVat = $dom->getElementsByTagNameNS('*', 'UnitPriceTaxInclusive')->item(0);
        $this->assertNotNull($unitPrice, 'ISDOC má UnitPrice');
        $this->assertEqualsWithDelta($displayNetUnit, (float) $unitPrice->nodeValue, 0.02, 'ISDOC UnitPrice musí být NETTO');
        $this->assertEqualsWithDelta(605.00, (float) $unitPriceVat->nodeValue, 0.02, 'ISDOC UnitPriceTaxInclusive = brutto/ks');
    }

    /**
     * Stejný režim s DPH, ale invoice_type=proforma — musí se chovat identicky
     * (přenos příznaku + koeficient).
     */
    public function testGeneratorPricesIncludeVatProformaPreservesFlagAndTotals(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare('SELECT rate_percent FROM vat_rates WHERE id = ?');
        $stmt->execute([$this->vatRateId]);
        $rate = (float) $stmt->fetchColumn();
        if ($rate <= 0.0) {
            $this->markTestSkipped('Default sazba je 0 % — koeficient nelze ověřit.');
        }

        $tplId = $this->repo->create([
            'supplier_id'      => $this->supplierId,
            'client_id'        => $this->clientId,
            'name'             => 'TEST recurring proforma s DPH (PHPUnit)',
            'frequency'        => 'monthly',
            'end_of_month'     => false,
            'anchor_date'      => $today,
            'next_run_date'    => $today,
            'invoice_type'     => 'proforma',
            'currency_id'      => $this->currencyId,
            'language'         => 'cs',
            'payment_method'   => 'bank_transfer',
            'reverse_charge'   => false,
            'prices_include_vat' => true,
            'payment_due_days' => 14,
            'increment_month_in_descriptions' => false,
            'auto_issue'       => false,
            'auto_send_email'  => false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        // 1 ks × 1000 Kč S DPH.
        $this->repo->replaceItems($tplId, [[
            'description' => 'Záloha s DPH',
            'quantity' => 1.0,
            'unit' => 'ks',
            'unit_price_without_vat' => 1000.00,
            'vat_rate_id' => $this->vatRateId,
            'order_index' => 0,
        ]]);

        $result = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $result['invoice_id'];

        $gross = 1000.00;
        $expVat  = round($gross * $rate / (100.0 + $rate), 2);
        $expBase = round($gross - $expVat, 2);

        $inv = $this->db->pdo()->prepare(
            'SELECT invoice_type, prices_include_vat, total_without_vat, total_vat, total_with_vat FROM invoices WHERE id = ?'
        );
        $inv->execute([$result['invoice_id']]);
        $invRow = $inv->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('proforma', $invRow['invoice_type']);
        $this->assertSame(1, (int) $invRow['prices_include_vat']);
        $this->assertEqualsWithDelta($gross, (float) $invRow['total_with_vat'], 0.001);
        $this->assertEqualsWithDelta($expVat, (float) $invRow['total_vat'], 0.001);
        $this->assertEqualsWithDelta($expBase, (float) $invRow['total_without_vat'], 0.001);
    }

    /**
     * Souhrn šablony v list() (TEMPLATE_TOTAL_SQL) musí v režimu „ceny s DPH" brát
     * brutto jako celek a NEpřičítat DPH navrch. Regrese: dříve počítal zdola →
     * total nafouknutý o DPH (1210 → 1210 + DPH).
     */
    public function testListTemplateTotalRespectsPricesIncludeVat(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $tplId = $this->repo->create([
            'supplier_id'      => $this->supplierId,
            'client_id'        => $this->clientId,
            'name'             => 'TEST list total s DPH (PHPUnit)',
            'frequency'        => 'monthly',
            'end_of_month'     => false,
            'anchor_date'      => $today,
            'next_run_date'    => $today,
            'invoice_type'     => 'invoice',
            'currency_id'      => $this->currencyId,
            'language'         => 'cs',
            'payment_method'   => 'bank_transfer',
            'reverse_charge'   => false,
            'prices_include_vat' => true,
            'discount_percent' => 0.0,
            'payment_due_days' => 14,
            'increment_month_in_descriptions' => false,
            'auto_issue'       => false,
            'auto_send_email'  => false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        $this->repo->replaceItems($tplId, [[
            'description' => 'Paušál s DPH',
            'quantity' => 1.0,
            'unit' => 'ks',
            'unit_price_without_vat' => 1210.00, // brutto
            'vat_rate_id' => $this->vatRateId,
            'order_index' => 0,
        ]]);

        $res = $this->repo->list(['client_id' => $this->clientId], 1, 0, '', []);
        $row = null;
        foreach ($res['data'] as $r) {
            if ((int) $r['id'] === $tplId) { $row = $r; break; }
        }
        $this->assertNotNull($row, 'Šablona musí být v listu');
        // Brutto 1210 → celek 1210, NE 1210 + DPH (starý bug).
        $this->assertEqualsWithDelta(1210.00, (float) $row['total_with_vat'], 0.01);
    }

    public function testGenerateForceDraftLeavesDraftDespiteAutoIssue(): void
    {
        // „Vygenerovat koncept" u at_issue šablony s auto_issue=true → přesto draft.
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $tplId = $this->repo->create([
            'supplier_id'      => $this->supplierId,
            'client_id'        => $this->clientId,
            'name'             => 'TEST force-draft (PHPUnit)',
            'frequency'        => 'monthly',
            'end_of_month'     => false,
            'anchor_date'      => $today,
            'next_run_date'    => $today,
            'invoice_type'     => 'invoice',
            'currency_id'      => $this->currencyId,
            'language'         => 'cs',
            'payment_method'   => 'bank_transfer',
            'payment_due_days' => 7,
            'increment_month_in_descriptions' => false,
            'auto_issue'       => true,
            'auto_send_email'  => false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;
        $this->repo->replaceItems($tplId, [[
            'description' => 'Konzultace', 'quantity' => 1.0, 'unit' => 'h',
            'unit_price_without_vat' => 1000.00, 'vat_rate_id' => $this->vatRateId, 'order_index' => 0,
        ]]);

        $res = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit', true);
        $this->createdInvoiceIds[] = $res['invoice_id'];

        $this->assertFalse($res['issued'], 'forceDraft musí nechat draft i u auto_issue=true');
        $this->assertNull($res['varsymbol']);
        $this->assertEmpty($res['sent_to']);

        $row = $this->db->pdo()->prepare('SELECT status, varsymbol FROM invoices WHERE id = ?');
        $row->execute([$res['invoice_id']]);
        $data = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('draft', $data['status']);
        $this->assertNull($data['varsymbol']);

        // Rozvrh se posune (mirror běžné generace — cron tutéž periodu nevygeneruje znovu).
        $tpl = $this->repo->find($tplId);
        $this->assertNotSame($today, $tpl['next_run_date'], 'forceDraft posouvá rozvrh jako běžná generace');
    }

    public function testLastErrorIsRecordedAndCleared(): void
    {
        // Cron při selhání volá setLastError → banner na šabloně; úspěch volá clearLastError.
        $tplId = $this->createPeriodTemplate('2027-03-31', ['end_of_month' => true]);

        $this->repo->setLastError($tplId, 'Sazba DPH CZ-21 není platná k datu plnění 2027-03-31');
        $tpl = $this->repo->find($tplId);
        $this->assertStringContainsString('CZ-21', (string) $tpl['last_error']);
        $this->assertNotNull($tpl['last_error_at']);

        $this->repo->clearLastError($tplId);
        $tpl = $this->repo->find($tplId);
        $this->assertNull($tpl['last_error']);
        $this->assertNull($tpl['last_error_at']);
    }

    public function testGeneratorRejectsExpiredVatRate(): void
    {
        $expiredRateId = (int) $this->db->pdo()->query(
            "SELECT id FROM vat_rates WHERE valid_to IS NOT NULL AND valid_to < CURDATE() ORDER BY id LIMIT 1"
        )->fetchColumn();
        if ($expiredRateId <= 0) {
            $this->markTestSkipped('Žádná vypršelá sazba v DB');
        }

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $tplId = $this->repo->create([
            'supplier_id'      => $this->supplierId,
            'client_id'        => $this->clientId,
            'name'             => 'TEST recurring expired rate (PHPUnit)',
            'frequency'        => 'monthly',
            'end_of_month'     => false,
            'anchor_date'      => $today,
            'next_run_date'    => $today,
            'invoice_type'     => 'invoice',
            'currency_id'      => $this->currencyId,
            'language'         => 'cs',
            'payment_method'   => 'bank_transfer',
            'payment_due_days' => 7,
            'increment_month_in_descriptions' => false,
            'auto_issue'       => false,
            'auto_send_email'  => false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        $this->repo->replaceItems($tplId, [[
            'description' => 'Položka s vypršelou sazbou',
            'quantity' => 1.0,
            'unit' => 'ks',
            'unit_price_without_vat' => 1000.00,
            'vat_rate_id' => $expiredRateId,
            'order_index' => 0,
        ]]);

        // Generování musí odmítnout vystavení faktury s vypršelou sazbou DPH.
        $this->expectException(\DomainException::class);
        $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
    }

    public function testGeneratorPreviousMonthLastDayTaxDateMode(): void
    {
        // Šablona s tax_date_mode='previous_month_last_day': fakturuje se 1.6. za 5/2026,
        // DUZP = 31.5.2026, popis položky se synchronizuje na "5/2026" (ne issue date 6/2026).
        $issueDate = '2026-06-01';

        $tplId = $this->repo->create([
            'supplier_id'    => $this->supplierId,
            'client_id'      => $this->clientId,
            'name'           => 'TEST recurring tax_date previous-month (PHPUnit)',
            'frequency'      => 'monthly',
            'end_of_month'   => false,
            'anchor_date'    => $issueDate,
            'next_run_date'  => $issueDate,
            'invoice_type'   => 'invoice',
            'currency_id'    => $this->currencyId,
            'language'       => 'cs',
            'payment_method' => 'bank_transfer',
            'payment_due_days' => 14,
            'tax_date_mode'  => 'previous_month_last_day',
            'increment_month_in_descriptions' => true,
            'auto_issue'     => true,
            'auto_send_email'=> false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        $this->repo->replaceItems($tplId, [[
            'description' => 'Hosting 06/2026',  // šablona může mít libovolný měsíc — sync to přepíše
            'quantity' => 1.0,
            'unit' => 'měs',
            'unit_price_without_vat' => 500.00,
            'vat_rate_id' => $this->vatRateId,
            'order_index' => 0,
        ]]);

        $result = $this->generator->generate($tplId, $issueDate, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $result['invoice_id'];

        $row = $this->db->pdo()->prepare(
            "SELECT i.issue_date, i.tax_date, ii.description
               FROM invoices i
               JOIN invoice_items ii ON ii.invoice_id = i.id
              WHERE i.id = ?"
        );
        $row->execute([$result['invoice_id']]);
        $data = $row->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('2026-06-01', $data['issue_date']);
        $this->assertSame('2026-05-31', $data['tax_date'], 'DUZP musí být poslední den předchozího měsíce');
        $this->assertStringContainsString('05/2026', $data['description'], 'Popis se musí synchronizovat na měsíc DUZP (5/2026), ne issue date (6/2026)');
    }

    public function testGeneratorRejectsTemplateWithNonPositiveAmountToPay(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $tplId = $this->repo->create([
            'supplier_id'    => $this->supplierId,
            'client_id'      => $this->clientId,
            'name'           => 'TEST recurring invalid discount (PHPUnit)',
            'frequency'      => 'monthly',
            'end_of_month'   => false,
            'anchor_date'    => $today,
            'next_run_date'  => $today,
            'invoice_type'   => 'invoice',
            'currency_id'    => $this->currencyId,
            'language'       => 'cs',
            'payment_method' => 'bank_transfer',
            'payment_due_days' => 7,
            'increment_month_in_descriptions' => false,
            'auto_issue'     => false,
            'auto_send_email'=> false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        $this->repo->replaceItems($tplId, [
            [
                'description' => 'Paušál',
                'quantity' => 1.0,
                'unit' => 'h',
                'unit_price_without_vat' => 1000.00,
                'vat_rate_id' => $this->vatRateId,
                'order_index' => 0,
            ],
            [
                'description' => 'Sleva 100 %',
                'quantity' => 1.0,
                'unit' => 'h',
                'unit_price_without_vat' => -1000.00,
                'vat_rate_id' => $this->vatRateId,
                'order_index' => 1,
            ],
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Výsledná částka k úhradě musí být větší než 0. Pro čistě záporný nebo nulový doklad použij dobropis.');

        try {
            $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
        } finally {
            $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE recurring_template_id = ?');
            $stmt->execute([$tplId]);
            $this->assertSame(0, (int) $stmt->fetchColumn(), 'Neplatný recurring draft se nesmí uložit.');
        }
    }

    public function testFindDueIncludesActiveAndSkipsPaused(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $activeId = $this->repo->create([
            'supplier_id'  => $this->supplierId,
            'client_id'    => $this->clientId,
            'name'         => 'TEST due active (PHPUnit)',
            'frequency'    => 'monthly',
            'end_of_month' => false,
            'anchor_date'  => $today,
            'next_run_date'=> $today,
            'currency_id'  => $this->currencyId,
            'language'     => 'cs',
            'payment_method' => 'bank_transfer',
            'auto_issue'   => false,
            'auto_send_email' => false,
            'status'       => 'active',
        ], $this->userId);
        $this->createdTemplateIds[] = $activeId;

        $pausedId = $this->repo->create([
            'supplier_id'  => $this->supplierId,
            'client_id'    => $this->clientId,
            'name'         => 'TEST due paused (PHPUnit)',
            'frequency'    => 'monthly',
            'end_of_month' => false,
            'anchor_date'  => $today,
            'next_run_date'=> $today,
            'currency_id'  => $this->currencyId,
            'language'     => 'cs',
            'payment_method' => 'bank_transfer',
            'auto_issue'   => false,
            'auto_send_email' => false,
            'status'       => 'paused',
        ], $this->userId);
        $this->createdTemplateIds[] = $pausedId;

        $futureId = $this->repo->create([
            'supplier_id'  => $this->supplierId,
            'client_id'    => $this->clientId,
            'name'         => 'TEST due future (PHPUnit)',
            'frequency'    => 'monthly',
            'end_of_month' => false,
            'anchor_date'  => (new \DateTimeImmutable($today))->modify('+1 month')->format('Y-m-d'),
            'next_run_date'=> (new \DateTimeImmutable($today))->modify('+1 month')->format('Y-m-d'),
            'currency_id'  => $this->currencyId,
            'language'     => 'cs',
            'payment_method' => 'bank_transfer',
            'auto_issue'   => false,
            'auto_send_email' => false,
            'status'       => 'active',
        ], $this->userId);
        $this->createdTemplateIds[] = $futureId;

        $due = $this->repo->findDue();
        $dueIds = array_map(fn ($t) => (int) $t['id'], $due);

        $this->assertContains($activeId, $dueIds,
            'Aktivní šablona s dnešním next_run_date musí být due. ' . $this->dueDiagnostics($activeId));
        $this->assertNotContains($pausedId, $dueIds, 'Pozastavená šablona nesmí být due');
        $this->assertNotContains($futureId, $dueIds, 'Budoucí next_run_date nesmí být due');
    }

    // ======================================================================
    //  „Otevřený koncept" (draft_open_mode = 'period_start')
    // ======================================================================

    /**
     * Vytvoří period_start šablonu s jednou fixní SLA položkou (5000) a daným
     * next_run_date (= plánované datum vystavení / konec období).
     */
    /**
     * Kontext k selhání `findDue()` — bez něj z hlášky „array neobsahuje 14" nejde
     * poznat, jestli šablonu vyřadila podmínka data, kill-switch dodavatele, nebo až
     * INNER JOIN v findMany() (chybějící klient/měna).
     */
    private function dueDiagnostics(int $templateId): string
    {
        $pdo = $this->db->pdo();
        $t = $pdo->prepare(
            'SELECT t.status, t.next_run_date, t.end_date, t.draft_open_mode, t.client_id, t.currency_id,
                    s.auto_generate_recurring,
                    (SELECT COUNT(*) FROM clients c WHERE c.id = t.client_id) AS client_rows,
                    (SELECT COUNT(*) FROM currencies cur WHERE cur.id = t.currency_id) AS currency_rows
               FROM recurring_invoice_templates t
               JOIN supplier s ON s.id = t.supplier_id
              WHERE t.id = ?'
        );
        $t->execute([$templateId]);
        $row = $t->fetch(PDO::FETCH_ASSOC) ?: [];
        $env = $pdo->query('SELECT CURDATE() AS curdate, @@session.time_zone AS tz')->fetch(PDO::FETCH_ASSOC) ?: [];

        return sprintf(
            'DIAG tpl=%d %s | CURDATE=%s tz=%s | PHP today=%s tz=%s',
            $templateId,
            json_encode($row, JSON_UNESCAPED_UNICODE),
            $env['curdate'] ?? '?',
            $env['tz'] ?? '?',
            date('Y-m-d'),
            date_default_timezone_get(),
        );
    }

    /**
     * OSS ze šablony se musí přenést na vygenerovanou fakturu (OSS-11, migrace 1297).
     *
     * Do téhle chvíle šablona OSS sloupce vůbec neměla, takže KAŽDÁ vygenerovaná faktura
     * vznikla bez OSS — u zákazníka, který polským spotřebitelům fakturuje měsíčně ze
     * šablony, by cron vyráběl doklady, které `VatLedgerService` pustí do TUZEMSKÉHO
     * přiznání, i po vyčištění historických dat.
     */
    public function testOssOnTemplateItemIsCarriedToGeneratedInvoice(): void
    {
        if (!$this->db->hasColumn('recurring_invoice_template_items', 'oss_applicable')) {
            $this->markTestSkipped('Migrace 1297 na téhle DB neproběhla.');
        }
        // Rozhodnutí šablony platí jen v rámci REGISTRACE do OSS — bez ní by řádek
        // nespadl do žádného přiznání (viz testExpiredOssRegistrationStopsTemplateDecision).
        $this->setSupplierOss(true, '2020-01-01', null);

        $tplId = $this->createPeriodTemplate((new \DateTimeImmutable('today'))->format('Y-m-d'));
        $this->repo->replaceItems($tplId, [[
            'description' => 'Zásilka do PL',
            'quantity' => 1.0,
            'unit' => 'ks',
            'unit_price_without_vat' => 1000.00,
            'vat_rate_id' => $this->vatRateId,
            'order_index' => 0,
            'oss_applicable' => 1,
            'oss_consumer_country' => 'PL',
            'oss_rate_type' => 'standard',
            'oss_supply_type' => 'goods',
        ]]);

        // Round-trip přes repozitář: bez čtecí strany by se rozhodnutí uložilo a zmizelo.
        $tpl = $this->repo->find($tplId);
        self::assertTrue($tpl['items'][0]['oss_applicable']);
        self::assertSame('PL', $tpl['items'][0]['oss_consumer_country']);

        $res = $this->generator->generate($tplId, null, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $res['invoice_id'];

        $stmt = $this->db->pdo()->prepare(
            'SELECT oss_applicable, oss_consumer_country, oss_rate_type, oss_supply_type
               FROM invoice_items WHERE invoice_id = ? ORDER BY order_index LIMIT 1'
        );
        $stmt->execute([$res['invoice_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        self::assertSame(1, (int) $row['oss_applicable'], 'OSS ze šablony se na fakturu nepřeneslo');
        self::assertSame('PL', $row['oss_consumer_country']);
        self::assertSame('standard', $row['oss_rate_type']);
        self::assertSame('goods', $row['oss_supply_type']);
    }

    /**
     * Ručně zvolená klasifikace DPH ze šablony se musí přenést na fakturu
     * (audit VAT klasifikací 2026-08, nález M-8, migrace 1782).
     *
     * Šablona kód dosud nenesla, takže ho cron při každém běhu DERIVOVAL ze sazby
     * a měrné jednotky. Rozhodnutí účetní se tím ztratilo měsíc co měsíc: dodání zboží
     * do jiného členského státu (kód `20`, ř. 20 + souhrnné hlášení s kódem plnění 0)
     * u jednotky „ks" skončilo jako `22` (služba, ř. 21 + kód plnění 3), prodej
     * dlouhodobého majetku (`1m`, secondary ř. 51) jako běžné plnění `1` — a s ním
     * i v čitateli koeficientu § 76, ze kterého ho § 76 odst. 4 vylučuje.
     */
    public function testVatClassificationOnTemplateItemIsCarriedToGeneratedInvoice(): void
    {
        if (!$this->db->hasColumn('recurring_invoice_template_items', 'vat_classification_code')) {
            $this->markTestSkipped('Migrace 1782 na téhle DB neproběhla.');
        }
        $tplId = $this->createPeriodTemplate((new \DateTimeImmutable('today'))->format('Y-m-d'));
        $this->repo->replaceItems($tplId, [
            [
                'description' => 'Prodej stroje',
                'quantity' => 1.0,
                'unit' => 'ks',
                'unit_price_without_vat' => 1000.00,
                'vat_rate_id' => $this->vatRateId,
                'vat_classification_code' => '1m',
                'order_index' => 0,
            ],
            [
                // Druhý řádek bez kódu ověřuje, že derivace zůstala funkční — oprava
                // nesmí ze „všechno derivovat" udělat „nic nederivovat".
                'description' => 'Servis',
                'quantity' => 1.0,
                'unit' => 'hod',
                'unit_price_without_vat' => 500.00,
                'vat_rate_id' => $this->vatRateId,
                'order_index' => 1,
            ],
        ]);

        // Round-trip přes repozitář: bez čtecí strany by se rozhodnutí uložilo a zmizelo.
        $tpl = $this->repo->find($tplId);
        self::assertSame('1m', $tpl['items'][0]['vat_classification_code']);
        self::assertNull($tpl['items'][1]['vat_classification_code']);

        $res = $this->generator->generate($tplId, null, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $res['invoice_id'];

        $stmt = $this->db->pdo()->prepare(
            'SELECT description, vat_classification_code FROM invoice_items
              WHERE invoice_id = ? ORDER BY order_index'
        );
        $stmt->execute([$res['invoice_id']]);
        $codes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $codes[(string) $row['description']] = $row['vat_classification_code'];
        }

        self::assertSame('1m', $codes['Prodej stroje'] ?? null, 'Klasifikace ze šablony se na fakturu nepřenesla.');
        self::assertSame('1', $codes['Servis'] ?? null, 'Řádek bez kódu se má pořád derivovat ze SSOT.');
    }

    /**
     * Šablona se zakládá jednou a generuje roky — registrace do OSS mezitím může skončit.
     * Uložené rozhodnutí je pak rozhodnutím o JINÉM období a řádek s `oss_applicable = 1`
     * by nespadl do žádného přiznání: z OSS podání ho vyřadí platnost registrace,
     * z tuzemského ho vyřadí OSS příznak. Cron generuje bez dozoru, takže by si toho
     * nikdo nevšiml.
     */
    public function testExpiredOssRegistrationStopsTemplateDecision(): void
    {
        if (!$this->db->hasColumn('recurring_invoice_template_items', 'oss_applicable')
            || !$this->db->hasColumn('invoice_items', 'oss_needs_manual_review')
        ) {
            $this->markTestSkipped('Migrace 1293/1297 na téhle DB neproběhly.');
        }
        // Registrace skončila loni — dnešní generovaný doklad je už mimo ni.
        $this->setSupplierOss(true, '2020-01-01', (new \DateTimeImmutable('-1 year'))->format('Y-m-d'));

        $tplId = $this->createPeriodTemplate((new \DateTimeImmutable('today'))->format('Y-m-d'));
        $this->repo->replaceItems($tplId, [[
            'description' => 'Zásilka do PL',
            'quantity' => 1.0,
            'unit' => 'ks',
            'unit_price_without_vat' => 1000.00,
            'vat_rate_id' => $this->vatRateId,
            'order_index' => 0,
            'oss_applicable' => 1,
            'oss_consumer_country' => 'PL',
            'oss_rate_type' => 'standard',
            'oss_supply_type' => 'goods',
        ]]);

        $res = $this->generator->generate($tplId, null, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $res['invoice_id'];

        $stmt = $this->db->pdo()->prepare(
            'SELECT oss_applicable, oss_consumer_country, oss_needs_manual_review
               FROM invoice_items WHERE invoice_id = ? ORDER BY order_index LIMIT 1'
        );
        $stmt->execute([$res['invoice_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        self::assertSame(0, (int) $row['oss_applicable'], 'řádek mimo registraci nesmí nést OSS');
        self::assertNull($row['oss_consumer_country']);
        self::assertSame(
            1,
            (int) $row['oss_needs_manual_review'],
            'přebití rozhodnutí ze šablony musí být vidět v datech, ne jen v logu cronu',
        );
    }

    public static function scheduleOperations(): array
    {
        return [['generate'], ['openDraft'], ['issuePeriod'], ['reschedule'], ['update']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scheduleOperations')]
    public function testScheduleOperationsRejectConcurrentOwner(string $operation): void
    {
        $id = $this->createPeriodTemplate('2090-06-30', ['auto_issue' => false]);
        $container = Bootstrap::buildContainer();
        $other = Connection::withoutSharedTestConnection(fn () => new Connection($container->get(\MyInvoice\Infrastructure\Config\Config::class)));
        $name = \MyInvoice\Infrastructure\Database\NamedLockName::for($this->db, 'recurring-schedule', $id);
        $lock = $other->pdo()->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$name]);
        self::assertSame(1, (int) $lock->fetchColumn());
        try {
            if ($operation === 'update') {
                $blocked = false;
                try {
                    $tpl = $this->repo->find($id);
                    $this->repo->updateWithItems($id, $tpl, $tpl['items']);
                } catch (\DomainException $e) {
                    $blocked = str_contains($e->getMessage(), 'Plán');
                }
                self::assertTrue($blocked, 'Ordinary edits must use the schedule lock.');
            } elseif ($operation === 'reschedule') {
                $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/recurring/' . $id . '/reschedule')
                    ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                    ->withParsedBody(['next_run_date' => '2090-07-31', 'expected_next_run_date' => '2090-06-30']);
                $response = $container->get(\MyInvoice\Action\Recurring\RecurringTemplateAction::class)->reschedule(
                    $request, (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(), ['id' => $id],
                );
                self::assertSame(409, $response->getStatusCode());
            } else {
                $blocked = false;
                try {
                    $this->generator->{$operation}($id, ...($operation === 'generate' ? [null, $this->userId] : [$this->userId]));
                } catch (\DomainException $e) {
                    $blocked = str_contains($e->getMessage(), 'Plán');
                }
                self::assertTrue($blocked, 'Concurrent schedule operation must stop before creating an invoice.');
            }
            self::assertSame(0, $this->countInvoicesForTemplate($id));
            self::assertSame('2090-06-30', $this->templateRow($id)['next_run_date']);
        } finally {
            $other->pdo()->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
            $stmt = $this->db->pdo()->prepare('SELECT id FROM invoices WHERE recurring_template_id = ?');
            $stmt->execute([$id]);
            array_push($this->createdInvoiceIds, ...array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
    }

    public static function staleScheduleOperations(): array
    {
        return [['generate'], ['openDraft'], ['issuePeriod'], ['reschedule']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('staleScheduleOperations')]
    public function testStaleScheduleCannotGenerateOrOverwriteNewDate(string $operation): void
    {
        $id = $this->createPeriodTemplate('2090-06-30', ['auto_issue' => false]);
        $old = $this->repo->find($id);
        self::assertTrue($this->repo->reschedule($old, '2090-07-31'));
        try {
            if ($operation === 'reschedule') {
                self::assertFalse($this->repo->reschedule($old, '2090-08-31'));
            } else {
                $blocked = false;
                try {
                    if ($operation === 'generate') {
                        $this->generator->generate($id, userId: $this->userId, expectedNextRunDate: '2090-06-30');
                    } else {
                        $this->generator->{$operation}($id, userId: $this->userId, expectedNextRunDate: '2090-06-30');
                    }
                } catch (\DomainException $e) {
                    $blocked = str_contains($e->getMessage(), 'Plán');
                }
                self::assertTrue($blocked, 'A stale cron candidate must not generate the rescheduled period.');
            }
            self::assertSame(0, $this->countInvoicesForTemplate($id));
            self::assertSame('2090-07-31', $this->templateRow($id)['next_run_date']);
        } finally {
            $stmt = $this->db->pdo()->prepare('SELECT id FROM invoices WHERE recurring_template_id = ?');
            $stmt->execute([$id]);
            array_push($this->createdInvoiceIds, ...array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
    }

    private function createPeriodTemplate(string $nextRun, array $overrides = []): int
    {
        $base = [
            'supplier_id'      => $this->supplierId,
            'client_id'        => $this->clientId,
            'name'             => 'TEST period_start (PHPUnit)',
            'frequency'        => 'monthly',
            'end_of_month'     => false,
            'anchor_date'      => $nextRun,
            'next_run_date'    => $nextRun,
            'invoice_type'     => 'invoice',
            'currency_id'      => $this->currencyId,
            'language'         => 'cs',
            'payment_method'   => 'bank_transfer',
            'payment_due_days' => 14,
            'tax_date_mode'    => 'same_as_issue',
            'draft_open_mode'  => 'period_start',
            'reminder_days_before' => 1,
            'increment_month_in_descriptions' => false,
            'auto_issue'       => true,
            'auto_send_email'  => false,  // ať testy nesahají na SMTP
            'status'           => 'active',
        ];
        $tplId = $this->repo->create(array_merge($base, $overrides), $this->userId);
        $this->createdTemplateIds[] = $tplId;
        $this->repo->replaceItems($tplId, [[
            'description' => 'SLA paušál',
            'quantity' => 1.0,
            'unit' => 'měs',
            'unit_price_without_vat' => 5000.00,
            'vat_rate_id' => $this->vatRateId,
            'order_index' => 0,
        ]]);
        return $tplId;
    }

    private function invoiceRow(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT status, varsymbol, issue_date, tax_date, due_date, total_with_vat, recurring_template_id
               FROM invoices WHERE id = ?"
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function templateRow(int $tplId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT next_run_date, last_run_date, status, last_reminder_date FROM recurring_invoice_templates WHERE id = ?"
        );
        $stmt->execute([$tplId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function countInvoicesForTemplate(int $tplId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE recurring_template_id = ?');
        $stmt->execute([$tplId]);
        return (int) $stmt->fetchColumn();
    }

    public function testOpenDraftCreatesDraftWithoutIssuingWithPeriodEndDates(): void
    {
        // Konec června: koncept se otevírá pro období, issue_date i DUZP = 30.6.
        $tplId = $this->createPeriodTemplate('2026-06-30', ['end_of_month' => true]);

        $r = $this->generator->openDraft($tplId, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $r['invoice_id'];

        $this->assertTrue($r['created'], 'První openDraft musí vytvořit koncept');

        $inv = $this->invoiceRow($r['invoice_id']);
        $this->assertSame('draft', $inv['status'], 'Otevřený koncept musí být draft');
        $this->assertNull($inv['varsymbol'], 'Koncept ještě nemá varsymbol (přiřadí se až při vystavení)');
        $this->assertSame('2026-06-30', $inv['issue_date'], 'Datum vystavení = konec období');
        $this->assertSame('2026-06-30', $inv['tax_date'], 'DUZP = konec období (same_as_issue)');
        $this->assertSame($tplId, (int) $inv['recurring_template_id']);

        // Položky šablony se zkopírovaly
        $cnt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ?');
        $cnt->execute([$r['invoice_id']]);
        $this->assertSame(1, (int) $cnt->fetchColumn());

        // draftOpenDate pro 30.6. = 1.6.
        $this->assertSame('2026-06-01', RecurringInvoiceGenerator::draftOpenDate('2026-06-30'));
    }

    public function testOpenDraftIsIdempotent(): void
    {
        $tplId = $this->createPeriodTemplate('2026-07-31', ['end_of_month' => true]);

        $r1 = $this->generator->openDraft($tplId, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $r1['invoice_id'];
        $r2 = $this->generator->openDraft($tplId, $this->userId, '127.0.0.1', 'phpunit');

        $this->assertTrue($r1['created']);
        $this->assertFalse($r2['created'], 'Druhé otevření nesmí vytvořit nový koncept');
        $this->assertSame($r1['invoice_id'], $r2['invoice_id'], 'Vrací stejný koncept');
        $this->assertSame(1, $this->countInvoicesForTemplate($tplId), 'Pro období smí existovat jen jeden koncept');
    }

    public function testOpenDraftDoesNotAdvanceSchedule(): void
    {
        $tplId = $this->createPeriodTemplate('2026-08-31', ['end_of_month' => true]);

        $r = $this->generator->openDraft($tplId, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $r['invoice_id'];

        $tpl = $this->templateRow($tplId);
        $this->assertSame('2026-08-31', $tpl['next_run_date'], 'openDraft NESMÍ posunout next_run_date');
        $this->assertNull($tpl['last_run_date'], 'openDraft NESMÍ nastavit last_run_date');
    }

    public function testIssuePeriodIssuesOpenedDraftAndAdvances(): void
    {
        $tplId = $this->createPeriodTemplate('2026-09-30', ['end_of_month' => true]);

        $open = $this->generator->openDraft($tplId, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $open['invoice_id'];

        $res = $this->generator->issuePeriod($tplId, $this->userId, '127.0.0.1', 'phpunit');

        $this->assertSame($open['invoice_id'], $res['invoice_id'], 'Vystaví SE TENTÝŽ koncept, ne nová faktura');
        $this->assertTrue($res['issued']);
        $this->assertNotNull($res['varsymbol']);
        $this->assertSame(1, $this->countInvoicesForTemplate($tplId), 'Žádný duplikát');

        $inv = $this->invoiceRow($open['invoice_id']);
        $this->assertSame('issued', $inv['status']);
        $this->assertNotEmpty($inv['varsymbol']);
        $this->assertSame('2026-09-30', $inv['issue_date'], 'Datum vystavení zůstává konec období');

        $tpl = $this->templateRow($tplId);
        $this->assertSame('2026-09-30', $tpl['last_run_date']);
        $this->assertSame('2026-10-31', $tpl['next_run_date'], 'Po vystavení se posune na další konec měsíce');
    }

    public function testIssuePeriodDispatchesSendWhenAutoSendEmail(): void
    {
        // Plný lifecycle „koncept → vystaveno A ODESLÁNO": ověříme, že issuePeriod při
        // auto_send_email=true dispatchne odeslání. AutoIssueAndSendService mockujeme,
        // ať test nesahá na SMTP — zbytek generátoru je reálný z containeru.
        $container = Bootstrap::buildApp()->getContainer();
        $issueAndSend = $this->createMock(\MyInvoice\Service\Invoice\AutoIssueAndSendService::class);
        $issueAndSend->expects($this->once())
            ->method('run')
            ->willReturn(['issued' => true, 'sent_to' => ['klient@test.local'], 'varsymbol' => 'TST-SEND-1']);

        $gen = new RecurringInvoiceGenerator(
            $container->get(\MyInvoice\Infrastructure\Database\Connection::class),
            $container->get(\MyInvoice\Repository\RecurringTemplateRepository::class),
            $container->get(\MyInvoice\Repository\InvoiceRepository::class),
            $container->get(\MyInvoice\Service\Invoice\InvoiceCalculator::class),
            $container->get(\MyInvoice\Service\Currency\ExchangeRateApplier::class),
            $issueAndSend,
            $container->get(\MyInvoice\Service\Invoice\VarsymbolGenerator::class),
            $container->get(\MyInvoice\Service\Invoice\SnapshotBuilder::class),
            $container->get(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class),
            $container->get(\MyInvoice\Service\Stats\StatsRecomputer::class),
            $container->get(\MyInvoice\Service\ActivityLogger::class),
            $container->get(\MyInvoice\Service\Stock\StockIssueService::class),
            $container->get(\MyInvoice\Service\Invoice\RecurringPriceListService::class),
            $container->get(\MyInvoice\Service\Accounting\DocumentAutoPoster::class),
            $container->get(\MyInvoice\Service\Oss\OssItemDeriver::class),
            $container->get(\MyInvoice\Repository\ClientRepository::class),
        );

        $tplId = $this->createPeriodTemplate(
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            ['auto_issue' => true, 'auto_send_email' => true],
        );

        $open = $gen->openDraft($tplId, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $open['invoice_id'];

        $res = $gen->issuePeriod($tplId, $this->userId, '127.0.0.1', 'phpunit');
        $this->assertTrue($res['issued']);
        $this->assertSame(['klient@test.local'], $res['sent_to'], 'auto_send_email=true → issuePeriod dispatchne odeslání');
    }

    public function testAutoIssuedInvoiceTriggersAutoPostHook(): void
    {
        // Faktura vystavená cronem ze šablony musí projít stejným auto-post hookem jako
        // ta z UI (IssueInvoiceAction) — jinak zůstane vystavená nezaúčtovaná. Samotné
        // zaúčtování si hlídá DocumentAutoPoster (firemní flag + podvojný režim), tady
        // testujeme jen že se hook vůbec zavolá.
        $container = Bootstrap::buildApp()->getContainer();
        $autoPoster = $this->createMock(\MyInvoice\Service\Accounting\DocumentAutoPoster::class);
        $autoPoster->expects($this->once())
            ->method('maybeAutoPost')
            ->with($this->supplierId, 'invoice', $this->isInt(), $this->userId);

        $gen = new RecurringInvoiceGenerator(
            $container->get(\MyInvoice\Infrastructure\Database\Connection::class),
            $container->get(\MyInvoice\Repository\RecurringTemplateRepository::class),
            $container->get(\MyInvoice\Repository\InvoiceRepository::class),
            $container->get(\MyInvoice\Service\Invoice\InvoiceCalculator::class),
            $container->get(\MyInvoice\Service\Currency\ExchangeRateApplier::class),
            $container->get(\MyInvoice\Service\Invoice\AutoIssueAndSendService::class),
            $container->get(\MyInvoice\Service\Invoice\VarsymbolGenerator::class),
            $container->get(\MyInvoice\Service\Invoice\SnapshotBuilder::class),
            $container->get(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class),
            $container->get(\MyInvoice\Service\Stats\StatsRecomputer::class),
            $container->get(\MyInvoice\Service\ActivityLogger::class),
            $container->get(\MyInvoice\Service\Stock\StockIssueService::class),
            $container->get(\MyInvoice\Service\Invoice\RecurringPriceListService::class),
            $autoPoster,
            $container->get(\MyInvoice\Service\Oss\OssItemDeriver::class),
            $container->get(\MyInvoice\Repository\ClientRepository::class),
        );

        // auto_issue=true, auto_send_email=false → větev issueOnlyWithoutSend()
        $tplId = $this->createPeriodTemplate('2026-05-31', ['end_of_month' => true]);
        $res = $gen->generate($tplId, null, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $res['invoice_id'];

        $this->assertTrue($res['issued'], 'auto_issue=true → faktura je vystavená');
        $this->assertSame('issued', $this->invoiceRow($res['invoice_id'])['status']);
    }

    public function testIssuePeriodPicksUpExtraWorkAddedDuringPeriod(): void
    {
        $tplId = $this->createPeriodTemplate('2026-11-30', ['end_of_month' => true]);

        $open = $this->generator->openDraft($tplId, $this->userId, '127.0.0.1', 'phpunit');
        $invId = $open['invoice_id'];
        $this->createdInvoiceIds[] = $invId;

        $baseTotal = (float) $this->invoiceRow($invId)['total_with_vat'];

        // Simulace víceprací doplněných během měsíce — WorkReportModal přidává invoice_item.
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
               (invoice_id, description, quantity, unit, unit_price_without_vat,
                vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, ?)'
        )->execute([$invId, 'Vícepráce', 3.0, 'h', 1000.00, $this->vatRateId, 1]);

        $res = $this->generator->issuePeriod($tplId, $this->userId, '127.0.0.1', 'phpunit');
        $this->assertSame($invId, $res['invoice_id']);

        $newTotal = (float) $this->invoiceRow($invId)['total_with_vat'];
        $this->assertGreaterThan($baseTotal, $newTotal, 'Vystavení musí přepočítat totály včetně víceprací');
        // 5000 (SLA) + 3×1000 (vícepráce) = 8000 base
        $this->assertGreaterThanOrEqual(8000.00, $newTotal);
    }

    public function testIssuePeriodCreatesFreshWhenNoDraftWasOpened(): void
    {
        // openDraft neproběhl (cron neběžel 1. dne) → issuePeriod fallback vytvoří + vystaví.
        $tplId = $this->createPeriodTemplate('2026-12-31', ['end_of_month' => true]);

        $res = $this->generator->issuePeriod($tplId, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $res['invoice_id'];

        $this->assertTrue($res['issued']);
        $this->assertNotNull($res['varsymbol']);
        $inv = $this->invoiceRow($res['invoice_id']);
        $this->assertSame('issued', $inv['status']);
        $this->assertSame('2026-12-31', $inv['issue_date']);
    }

    public function testIssuePeriodSkipsAlreadyIssuedDraft(): void
    {
        // Uživatel vystavil koncept ručně během měsíce → issuePeriod ho nesmí duplikovat,
        // jen posune rozvrh.
        $tplId = $this->createPeriodTemplate('2027-01-31', ['end_of_month' => true]);

        $open = $this->generator->openDraft($tplId, $this->userId, '127.0.0.1', 'phpunit');
        $invId = $open['invoice_id'];
        $this->createdInvoiceIds[] = $invId;

        $this->db->pdo()->prepare("UPDATE invoices SET status='issued', varsymbol=? WHERE id=?")
            ->execute(['TESTMANUAL27', $invId]);

        $res = $this->generator->issuePeriod($tplId, $this->userId, '127.0.0.1', 'phpunit');

        $this->assertSame($invId, $res['invoice_id'], 'Vrátí ručně vystavenou fakturu, nevytvoří novou');
        $this->assertSame(1, $this->countInvoicesForTemplate($tplId), 'Žádný duplikát i po ručním vystavení');
        $tpl = $this->templateRow($tplId);
        $this->assertSame('2027-02-28', $tpl['next_run_date'], 'Rozvrh se posune i u ručně vystavené faktury');
    }

    public function testFindDueIncludesPeriodStartInsideOpenWindow(): void
    {
        $today = new \DateTimeImmutable('today');
        $endOfThisMonth = $today->modify('last day of this month')->format('Y-m-d');
        if ($endOfThisMonth <= $today->format('Y-m-d')) {
            $this->markTestSkipped('Dnes je poslední den měsíce — open window edge, test přeskočen.');
        }

        // period_start s vystavením na konci TOHOTO měsíce → jsme v open window (1. už proběhl).
        $periodId = $this->createPeriodTemplate($endOfThisMonth, ['end_of_month' => true]);
        // Kontrola: at_issue se stejným budoucím next_run NESMÍ být due (čeká až na den vystavení).
        $atIssueId = $this->createPeriodTemplate($endOfThisMonth, [
            'end_of_month' => true, 'draft_open_mode' => 'at_issue', 'auto_issue' => false,
        ]);

        $dueIds = array_map(fn ($t) => (int) $t['id'], $this->repo->findDue());

        $this->assertContains($periodId, $dueIds, 'period_start v open window musí být due (kvůli otevření konceptu)');
        $this->assertNotContains($atIssueId, $dueIds, 'at_issue s budoucím next_run ještě není due');
    }

    public function testFindReminderDueWindowAndGuard(): void
    {
        $today = new \DateTimeImmutable('today');
        $tomorrow = $today->modify('+1 day')->format('Y-m-d');
        $inFiveDays = $today->modify('+5 days')->format('Y-m-d');

        $soonId = $this->createPeriodTemplate($tomorrow, ['reminder_days_before' => 1]);
        $farId  = $this->createPeriodTemplate($inFiveDays, ['reminder_days_before' => 1]);

        $reminderIds = array_map(fn ($t) => (int) $t['id'], $this->repo->findReminderDue());
        $this->assertContains($soonId, $reminderIds,
            'next_run = zítra a reminder=1 → v reminder okně. ' . $this->dueDiagnostics($soonId));
        $this->assertNotContains($farId, $reminderIds, 'next_run za 5 dní a reminder=1 → mimo okno');

        // Guard: po označení odeslání pro toto období už znovu nepřijde.
        $this->repo->markReminderSent($soonId, $tomorrow);
        $reminderIds2 = array_map(fn ($t) => (int) $t['id'], $this->repo->findReminderDue());
        $this->assertNotContains($soonId, $reminderIds2, 'Po markReminderSent se reminder pro období neopakuje');

        $tpl = $this->templateRow($soonId);
        $this->assertSame($tomorrow, $tpl['last_reminder_date']);
    }

    public function testReminderServiceReturnsFalseForNonDraftInvoice(): void
    {
        // Vystavená faktura → není co připomínat (early-return, žádné SMTP).
        $tplId = $this->createPeriodTemplate('2027-03-31', ['end_of_month' => true]);
        $res = $this->generator->issuePeriod($tplId, $this->userId, '127.0.0.1', 'phpunit');
        $this->createdInvoiceIds[] = $res['invoice_id'];

        $tpl = $this->repo->find($tplId);
        $sent = $this->reminder->send($tpl, $res['invoice_id'], 'phpunit');
        $this->assertFalse($sent, 'Reminder se neposílá pro vystavenou (ne-draft) fakturu');
    }

    // ======================================================================
    //  update(): přemapování next_run_date při změně dne / konce měsíce
    // ======================================================================

    public function testUpdateRescheduleSnapsDayForRunningTemplate(): void
    {
        // Běžící šablona: vystavení 20. v měsíci, už jednou proběhla (next_run = 20.6.).
        $tplId = $this->repo->create([
            'supplier_id'    => $this->supplierId,
            'client_id'      => $this->clientId,
            'name'           => 'TEST reschedule (PHPUnit)',
            'frequency'      => 'monthly',
            'day_of_month'   => 20,
            'end_of_month'   => false,
            'anchor_date'    => '2026-05-20',
            'next_run_date'  => '2026-06-20',
            'currency_id'    => $this->currencyId,
            'language'       => 'cs',
            'payment_method' => 'bank_transfer',
            'auto_issue'     => false,
            'auto_send_email'=> false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;
        // Simuluj, že už generovala
        $this->db->pdo()->prepare(
            "UPDATE recurring_invoice_templates SET last_run_date='2026-05-20', next_run_date='2026-06-20' WHERE id=?"
        )->execute([$tplId]);

        // Uživatel přepne na „konec měsíce"
        $this->repo->update($tplId, [
            'client_id'      => $this->clientId,
            'name'           => 'TEST reschedule (PHPUnit)',
            'frequency'      => 'monthly',
            'day_of_month'   => null,
            'end_of_month'   => true,
            'anchor_date'    => '2026-05-20',
            'end_date'       => null,
            'currency_id'    => $this->currencyId,
            'language'       => 'cs',
            'payment_method' => 'bank_transfer',
            'payment_due_days' => 14,
            'auto_issue'     => false,
            'auto_send_email'=> false,
        ]);

        $tpl = $this->templateRow($tplId);
        $this->assertSame('2026-06-30', $tpl['next_run_date'], 'Změna na „konec měsíce" musí přemapovat nejbližší vystavení 20.6. → 30.6.');
    }

    public function testUpdateNeverRunTemplateUsesAnchor(): void
    {
        $tplId = $this->repo->create([
            'supplier_id'    => $this->supplierId,
            'client_id'      => $this->clientId,
            'name'           => 'TEST anchor reschedule (PHPUnit)',
            'frequency'      => 'monthly',
            'day_of_month'   => 10,
            'end_of_month'   => false,
            'anchor_date'    => '2026-06-10',
            'next_run_date'  => '2026-06-10',
            'currency_id'    => $this->currencyId,
            'language'       => 'cs',
            'payment_method' => 'bank_transfer',
            'auto_issue'     => false,
            'auto_send_email'=> false,
        ], $this->userId);
        $this->createdTemplateIds[] = $tplId;

        // Nikdy negenerovala (last_run_date IS NULL) → update přepíše next_run na nové anchor.
        $this->repo->update($tplId, [
            'client_id'      => $this->clientId,
            'name'           => 'TEST anchor reschedule (PHPUnit)',
            'frequency'      => 'monthly',
            'day_of_month'   => 15,
            'end_of_month'   => false,
            'anchor_date'    => '2026-07-15',
            'end_date'       => null,
            'currency_id'    => $this->currencyId,
            'language'       => 'cs',
            'payment_method' => 'bank_transfer',
            'payment_due_days' => 14,
            'auto_issue'     => false,
            'auto_send_email'=> false,
        ]);

        $tpl = $this->templateRow($tplId);
        $this->assertSame('2026-07-15', $tpl['next_run_date'], 'Neběžící šablona: next_run = nové anchor_date');
    }

    // ======================================================================
    //  Kategorie tržby na šabloně (#119): override vs. fallback
    // ======================================================================

    /**
     * Pevná kategorie šablony (revenue_category_id) musí PŘEBÍT default klienta;
     * šablona bez kategorie (NULL) musí fallbacknout na default klienta (stávající
     * chování). Kategorie se na fakturu ukládá jako snapshot při generování.
     */
    public function testGeneratorRevenueCategoryOverrideBeatsClientDefault(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $pdo = $this->db->pdo();

        // Dvě dočasné kategorie: A = pevná na šabloně, B = default klienta.
        $catIds = [];
        foreach (['phpunit-tpl-a', 'phpunit-cli-b'] as $code) {
            $pdo->prepare(
                'INSERT INTO revenue_categories (supplier_id, code, label, display_order)
                 VALUES (?, ?, ?, 999)'
            )->execute([$this->supplierId, $code, 'TEST ' . $code]);
            $catIds[] = (int) $pdo->lastInsertId();
        }
        [$catTemplate, $catClient] = $catIds;

        // Default klienta dočasně přepnout na B; v finally vrátit.
        $origDefault = $pdo->query(
            "SELECT default_revenue_category_id FROM clients WHERE id = {$this->clientId}"
        )->fetchColumn();
        $pdo->prepare('UPDATE clients SET default_revenue_category_id = ? WHERE id = ?')
            ->execute([$catClient, $this->clientId]);

        try {
            $item = [
                'description' => 'Hosting',
                'quantity' => 1.0,
                'unit' => 'měs',
                'unit_price_without_vat' => 500.00,
                'vat_rate_id' => $this->vatRateId,
                'order_index' => 0,
            ];
            $base = [
                'supplier_id'      => $this->supplierId,
                'client_id'        => $this->clientId,
                'frequency'        => 'monthly',
                'end_of_month'     => false,
                'anchor_date'      => $today,
                'next_run_date'    => $today,
                'invoice_type'     => 'invoice',
                'currency_id'      => $this->currencyId,
                'language'         => 'cs',
                'payment_method'   => 'bank_transfer',
                'payment_due_days' => 14,
                'increment_month_in_descriptions' => false,
                'auto_issue'       => false,
                'auto_send_email'  => false,
            ];

            // 1) Šablona s pevnou kategorií A → faktura má A (ne klientovo B).
            $tplOverride = $this->repo->create($base + [
                'name' => 'TEST recurring kategorie override (PHPUnit)',
                'revenue_category_id' => $catTemplate,
            ], $this->userId);
            $this->createdTemplateIds[] = $tplOverride;
            $this->repo->replaceItems($tplOverride, [$item]);

            $found = $this->repo->find($tplOverride);
            $this->assertSame($catTemplate, $found['revenue_category_id'], 'find() vrací uloženou kategorii šablony');

            $r1 = $this->generator->generate($tplOverride, $today, $this->userId, '127.0.0.1', 'phpunit');
            $this->createdInvoiceIds[] = $r1['invoice_id'];
            $inv1 = $pdo->query("SELECT revenue_category_id FROM invoices WHERE id = {$r1['invoice_id']}")->fetchColumn();
            $this->assertSame($catTemplate, (int) $inv1, 'Pevná kategorie šablony přebíjí default klienta');

            // 2) Šablona BEZ kategorie (NULL) → fallback na default klienta B.
            $tplFallback = $this->repo->create($base + [
                'name' => 'TEST recurring kategorie fallback (PHPUnit)',
            ], $this->userId);
            $this->createdTemplateIds[] = $tplFallback;
            $this->repo->replaceItems($tplFallback, [$item]);

            $r2 = $this->generator->generate($tplFallback, $today, $this->userId, '127.0.0.1', 'phpunit');
            $this->createdInvoiceIds[] = $r2['invoice_id'];
            $inv2 = $pdo->query("SELECT revenue_category_id FROM invoices WHERE id = {$r2['invoice_id']}")->fetchColumn();
            $this->assertSame($catClient, (int) $inv2, 'Šablona bez kategorie fallbackne na default klienta');
        } finally {
            $pdo->prepare('UPDATE clients SET default_revenue_category_id = ? WHERE id = ?')
                ->execute([$origDefault !== false && $origDefault !== null ? (int) $origDefault : null, $this->clientId]);
            // Faktury/šablony uklízí tearDown; kategorie se musí odpojit od faktur dřív,
            // než by je delete() odmítl — tearDown maže faktury, ale běží AŽ PO finally,
            // proto kategorie odpojíme tady ručně a smažeme.
            $in = implode(',', $catIds);
            $pdo->exec("UPDATE invoices SET revenue_category_id = NULL WHERE revenue_category_id IN ($in)");
            $pdo->exec("UPDATE recurring_invoice_templates SET revenue_category_id = NULL WHERE revenue_category_id IN ($in)");
            $pdo->exec("DELETE FROM revenue_categories WHERE id IN ($in)");
        }
    }
}
