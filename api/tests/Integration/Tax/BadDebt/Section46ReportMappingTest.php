<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\BadDebt;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Tax\BadDebt\Section46Service;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 46 ZDPH — napojení věřitelské opravy do DPHDP3 (ř. 1/2 + ř. 33 `opr_verit`) a do KH
 * (A.4 se `zdph_44='P'`).
 *
 * Tenhle test je jádro nálezu N-021: doteď šlo podat kontrolní hlášení s příznakem opravy
 * nedobytné pohledávky, zatímco v přiznání nevznikla žádná částka — klíč `'33'` v mapě
 * řádků vůbec nebyl. Test proto neověřuje jen čísla, ale hlavně to, že se OBA výkazy
 * naplní ze stejného zdroje a shodně.
 *
 * Validace proti XSD je tu záměrně: u § 78 se ukázalo, že atribut na špatné Vetě projde
 * čtením kódu i výpočtem, a odhalí ho teprve schéma.
 *
 * ⚠️ Integrační (DB): vše v transakci s rollbackem.
 */
#[Group('integration')]
final class Section46ReportMappingTest extends TestCase
{
    private Connection $db;
    private Section46Service $service;
    private DphPriznaniBuilder $dphdp3;
    private KontrolniHlaseniBuilder $kh;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $czId = 0;
    private int $rate21Id = 0;
    private int $rate12Id = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db      = $c->get(Connection::class);
            $this->service = $c->get(Section46Service::class);
            // Buildery MUSÍ pocházet z TÉHOŽ kontejneru — jinak dostanou vlastní připojení
            // mimo transakci testu a seedovaná data vůbec neuvidí.
            $this->dphdp3  = $c->get(DphPriznaniBuilder::class);
            $this->kh      = $c->get(KontrolniHlaseniBuilder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'vat_s46_corrections'")->fetch() === false) {
            $this->markTestSkipped('Migrace 1150 neproběhla.');
        }
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier WHERE is_vat_payer = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->rate21Id   = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->rate12Id   = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 12 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if (in_array(0, [$this->supplierId, $this->userId, $this->currencyId, $this->czId, $this->rate21Id, $this->rate12Id], true)) {
            $this->markTestSkipped('Chybí základní data v DB (plátce DPH / sazby 21+12).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Dlužník §46 report", "Test 1", "Praha", "11000", ?, "CZ46464646", "d@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    /** Rozpad podle sazby: 21 % na ř. 1, 12 % na ř. 2, ř. 33 nese součet daně kladně. */
    public function testMixedRateInvoiceSplitsAcrossBothLines(): void
    {
        $id = $this->seedMixedRateInvoice();
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2099-09-20', 'OD-1', null, $this->userId);

        $lines = $this->service->periodCorrectionLines($this->supplierId, 2099, 9);

        self::assertEqualsWithDelta(-10000.0, $lines['basic']['base'], 0.01);
        self::assertEqualsWithDelta(-2100.0, $lines['basic']['vat'], 0.01);
        self::assertEqualsWithDelta(-5000.0, $lines['reduced']['base'], 0.01);
        self::assertEqualsWithDelta(-600.0, $lines['reduced']['vat'], 0.01);
        self::assertEqualsWithDelta(2700.0, $lines['opr_verit'], 0.01);
    }

    /** Položka 0 % (zaokrouhlení, poplatek) na ř. 1/2 nebyla, oprava ji na ř. 2 nesmí poslat. */
    public function testZeroRateItemStaysOutOfCorrection(): void
    {
        $id = $this->seedMixedRateInvoice();
        $this->seedItem($id, $this->rate21Id, 0.0, 300.0, 0.0, 3);
        $this->db->pdo()->prepare('UPDATE invoices SET total_without_vat = 15300, total_with_vat = 18000 WHERE id = ?')->execute([$id]);
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2099-09-20', 'OD-1', null, $this->userId);

        $lines = $this->service->periodCorrectionLines($this->supplierId, 2099, 9);

        self::assertEqualsWithDelta(-10000.0, $lines['basic']['base'], 0.01);
        self::assertEqualsWithDelta(-5000.0, $lines['reduced']['base'], 0.01);
        self::assertEqualsWithDelta(-600.0, $lines['reduced']['vat'], 0.01);
        self::assertEqualsWithDelta(2700.0, $lines['opr_verit'], 0.01);
    }

    /**
     * Oprava musí dotéct do PŘIZNÁNÍ, projít XSD a sedět na ř. 33 `opr_verit` kladně,
     * zatímco ř. 1/2 klesnou. Přesně tohle nález N-021 postrádal.
     */
    public function testCorrectionReachesDphdp3AndPassesXsd(): void
    {
        $id = $this->seedMixedRateInvoice();
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2099-09-20', 'OD-1', null, $this->userId);

        $xml = (string) ($this->dphdp3->build($this->supplierId, 2099, 9, 'monthly')['xml'] ?? '');

        self::assertMatchesRegularExpression('/opr_verit="2700"/', $xml,
            'ř. 33 nese daň opravy KLADNĚ — anotace XSD: „věřitel uvede kladnou hodnotu opravy daně".');
        $this->assertXsdValid($xml, 'dphdp3.xsd');

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $veta1 = $dom->getElementsByTagName('Veta1')->item(0);
        self::assertNotNull($veta1, 'Oprava musí vytvořit Veta1 se zápornými hodnotami.');
        self::assertSame('-10000', $veta1->getAttribute('obrat23'));
        self::assertSame('-2100', $veta1->getAttribute('dan23'));
        self::assertSame('-5000', $veta1->getAttribute('obrat5'));
        self::assertSame('-600', $veta1->getAttribute('dan5'));
    }

    /** Obnova po úhradě (§ 46e) obrací znaménka na obou místech. */
    public function testRestorationInvertsSignsInDphdp3(): void
    {
        $id = $this->seedMixedRateInvoice();
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2099-09-20', 'OD-1', null, $this->userId);
        $this->pay($id, 17700.0);
        $this->service->recordRestorations($this->supplierId, 2099, 10, $this->userId);

        $xml = (string) ($this->dphdp3->build($this->supplierId, 2099, 10, 'monthly')['xml'] ?? '');

        self::assertMatchesRegularExpression('/opr_verit="-2700"/', $xml);
        $this->assertXsdValid($xml, 'dphdp3.xsd');
    }

    /**
     * Do KH jde řádek A.4 se `zdph_44='P'` a stejnými částkami jako do přiznání. Dokud
     * tohle neplatilo, mohl uživatel podat KH s příznakem opravy bez protějšku v přiznání.
     */
    public function testCorrectionReachesKontrolniHlaseniA4(): void
    {
        $id = $this->seedMixedRateInvoice();
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2099-09-20', 'OD-1', null, $this->userId);

        $xml = (string) ($this->kh->build($this->supplierId, 2099, 9)['xml'] ?? '');
        $this->assertXsdValid($xml, 'dphkh1.xsd');

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $found = null;
        foreach ($dom->getElementsByTagName('VetaA4') as $v) {
            if ($v->getAttribute('zdph_44') === 'P') {
                $found = $v;
                break;
            }
        }

        self::assertNotNull($found, 'Oprava § 46 musí v KH vytvořit řádek A.4 se zdph_44="P".');
        // KH nese DIČ bez prefixu země (cleanDic).
        self::assertSame('46464646', $found->getAttribute('dic_odb'));
        // KH uvádí částky na haléře (na rozdíl od přiznání, které zaokrouhluje na Kč).
        self::assertSame('-10000.00', $found->getAttribute('zakl_dane1'));
        self::assertSame('-2100.00', $found->getAttribute('dan1'));
        self::assertSame('-5000.00', $found->getAttribute('zakl_dane2'));
        self::assertSame('-600.00', $found->getAttribute('dan2'));
    }

    /**
     * Bez DIČ odběratele řádek v KH vzniknout NEMŮŽE — místo tichého vynechání se hlásí
     * varování. Tichý drop by znamenal KH bez opravy, kterou přiznání obsahuje.
     */
    public function testMissingDicProducesWarningInsteadOfSilentDrop(): void
    {
        $this->db->pdo()->prepare('UPDATE clients SET dic = NULL WHERE id = ?')->execute([$this->clientId]);
        $id = $this->seedMixedRateInvoice();
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2099-09-20', 'OD-1', null, $this->userId);

        $result = $this->kh->build($this->supplierId, 2099, 9);
        $warnings = implode("\n", $result['warnings'] ?? []);

        self::assertStringContainsString('§46', $warnings);
        self::assertStringContainsString('DIČ', $warnings);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** Faktura 17 700 Kč: 10 000 + 2 100 (21 %) a 5 000 + 600 (12 %), neuhrazená. */
    private function seedMixedRateInvoice(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, paid_total, status, created_by)
             VALUES (?, ?, "2099001", "invoice", "2099-01-15", "2099-01-15", "2099-01-15",
                     ?, 0, "{}", "{}", 15000, 2700, 17700, 0, "issued", ?)'
        )->execute([$this->supplierId, $this->clientId, $this->currencyId, $this->userId]);
        $id = (int) $pdo->lastInsertId();

        $this->seedItem($id, $this->rate21Id, 21.0, 10000.0, 2100.0, 1);
        $this->seedItem($id, $this->rate12Id, 12.0, 5000.0, 600.0, 2);

        return $id;
    }

    private function seedItem(int $invoiceId, int $rateId, float $rate, float $base, float $vat, int $order): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Plnění", 1, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$invoiceId, $base, $rateId, $rate, $base, $vat, $base + $vat, $order]);
    }

    private function pay(int $invoiceId, float $amount): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET status = IF(paid_total + ? >= total_with_vat, "paid", status),
                    paid_total = paid_total + ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([$amount, $amount, $invoiceId, $this->supplierId]);
    }

    private function assertXsdValid(string $xml, string $xsd): void
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        libxml_use_internal_errors(true);
        $valid = $dom->schemaValidate(dirname(__DIR__, 4) . '/xsd/' . $xsd);
        $errors = array_map(static fn ($e): string => trim($e->message), libxml_get_errors());
        libxml_clear_errors();

        self::assertTrue($valid, "Výkaz s opravou § 46 musí projít {$xsd}:\n" . implode("\n", $errors));
    }
}
