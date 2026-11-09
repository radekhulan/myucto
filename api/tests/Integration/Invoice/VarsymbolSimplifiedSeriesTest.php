<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\PreviewVarsymbolAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Vlastní číselná řada zjednodušených daňových dokladů (§ 30 ZDPH, migrace 2037).
 *
 * Prázdná `supplier.simplified_number_format` = zjednodušený doklad sdílí řadu faktur
 * (dosavadní chování, včetně řady klienta a kategorie). Vyplněná = vlastní řada
 * s vlastním počítadlem (`invoice_counters.invoice_type = 'simplified'`), která má
 * přednost před řadou klienta i kategorie: pokladní prodej se čísluje jednou řadou
 * bez ohledu na to, komu se prodalo.
 *
 * Izolace: vlastní dodavatel v transakci, rollback v tearDown.
 */
#[Group('integration')]
final class VarsymbolSimplifiedSeriesTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const INVOICE_TPL    = 'FV{YYYY}{CCCC}';
    private const SIMPLIFIED_TPL = 'ZD{YYYY}{CCCC}';
    private const CLIENT_TPL     = 'KL{YYYY}{CCCC}';

    private Connection $db;
    private VarsymbolGenerator $gen;
    private PreviewVarsymbolAction $previewAction;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private bool $inTx = false;
    private \DateTimeImmutable $date;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje: test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db  = $c->get(Connection::class);
            $this->gen = $c->get(VarsymbolGenerator::class);
            $this->previewAction = $c->get(PreviewVarsymbolAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = $currencyId;
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if (in_array(0, [$source, $countryId, $currencyId, $this->userId], true)) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            "UPDATE supplier SET invoice_number_format = ?, invoice_number_period = 'year',
                    simplified_number_format = NULL WHERE id = ?"
        )->execute([self::INVOICE_TPL, $this->supplierId]);

        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  currency_default_id, main_email, is_customer, is_vendor, invoice_number_format)
             VALUES (?, 'Řada klienta s.r.o.', 'Testovací 1', 'Praha', '11000', ?, ?,
                     'simplified-series@example.test', 1, 0, ?)"
        )->execute([$this->supplierId, $countryId, $currencyId, self::CLIENT_TPL]);
        $this->clientId = (int) $pdo->lastInsertId();

        $this->date = new \DateTimeImmutable('2096-03-10');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    private function setSimplifiedFormat(?string $template): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET simplified_number_format = ? WHERE id = ?')
            ->execute([$template, $this->supplierId]);
    }

    /**
     * Číslo z řady a zápis na doklad v téže transakci, jako při vystavení. Bez zápisu
     * by pojistka proti dírám ({@see \MyInvoice\Service\Invoice\NumberSeriesGapGuard})
     * nevydané číslo právem vrátila do řady.
     */
    private function issue(string $seriesType, int $clientId = 0): string
    {
        $vs = $this->gen->next($this->supplierId, $seriesType, $this->date, $clientId);
        $d = $this->date->format('Y-m-d');
        $this->db->pdo()->prepare(
            "INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, is_simplified, client_id, issue_date, tax_date, due_date,
                 currency_id, total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, 'invoice', ?, ?, ?, ?, ?, ?, 100, 21, 121, 'issued', ?)"
        )->execute([
            $this->supplierId, $vs, $seriesType === VarsymbolGenerator::SIMPLIFIED ? 1 : 0,
            $clientId > 0 ? $clientId : $this->clientId, $d, $d, $d, $this->currencyId, $this->userId,
        ]);
        return $vs;
    }

    private function counter(string $type, int $clientId = 0): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT last_number FROM invoice_counters
              WHERE supplier_id = ? AND client_id = ? AND revenue_category_id = 0 AND invoice_type = ?'
        );
        $stmt->execute([$this->supplierId, $clientId, $type]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function testSeriesTypeOnlyMarksSimplifiedInvoices(): void
    {
        self::assertSame('simplified', VarsymbolGenerator::seriesTypeFor(['invoice_type' => 'invoice', 'is_simplified' => 1]));
        self::assertSame('simplified', VarsymbolGenerator::seriesTypeFor(['invoice_type' => 'invoice', 'is_simplified' => true]));
        self::assertSame('invoice', VarsymbolGenerator::seriesTypeFor(['invoice_type' => 'invoice', 'is_simplified' => 0]));
        self::assertSame('invoice', VarsymbolGenerator::seriesTypeFor(['invoice_type' => 'invoice']));
        // Příznak na jiném typu dokladu řadu nemění, zjednodušený je jen daňový doklad.
        self::assertSame('proforma', VarsymbolGenerator::seriesTypeFor(['invoice_type' => 'proforma', 'is_simplified' => 1]));
        self::assertSame('credit_note', VarsymbolGenerator::seriesTypeFor(['invoice_type' => 'credit_note', 'is_simplified' => 1]));
        self::assertSame('tax_document', VarsymbolGenerator::seriesTypeFor(['invoice_type' => 'tax_document', 'is_simplified' => 1]));
    }

    public function testWithoutOwnFormatSimplifiedSharesInvoiceSeries(): void
    {
        $a = $this->issue('invoice');
        $b = $this->issue('simplified');
        $c = $this->issue('invoice');

        self::assertSame(['FV20960001', 'FV20960002', 'FV20960003'], [$a, $b, $c]);
        self::assertSame(3, $this->counter('invoice'));
        self::assertSame(0, $this->counter('simplified'), 'Bez vlastní šablony žádné vlastní počítadlo nevzniká.');
        self::assertSame('FV20960004', $this->gen->preview($this->supplierId, 'simplified', $this->date));
    }

    public function testUnknownPlaceholderInStoredFormatIsRejectedWithoutBurningCounter(): void
    {
        // Šablona uložená před validací placeholderů: `{1YY}` by prošlo do čísla doslova.
        $this->db->pdo()->prepare('UPDATE supplier SET simplified_number_format = ? WHERE id = ?')
            ->execute(['PK{YY0}{CCC}', $this->supplierId]);

        try {
            $this->issue('simplified');
            self::fail('Neplatná šablona musí vystavení odmítnout.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('neznámý zástupný symbol', $e->getMessage());
        }
        self::assertSame(0, $this->counter('simplified'), 'Odmítnuté číslo nesmí posunout počítadlo.');
    }

    public function testWithoutOwnFormatSimplifiedKeepsClientSeries(): void
    {
        // Dosavadní chování: zjednodušený doklad klienta s vlastní řadou jde do řady klienta.
        $vs = $this->gen->next($this->supplierId, 'simplified', $this->date, $this->clientId);

        self::assertSame('KL20960001', $vs);
        self::assertSame(1, $this->counter('invoice', $this->clientId));
    }

    public function testOwnFormatGivesOwnSeriesAndLeavesInvoicesIntact(): void
    {
        $this->setSimplifiedFormat(self::SIMPLIFIED_TPL);

        $a = $this->issue('invoice');
        $b = $this->issue('simplified');
        $c = $this->issue('simplified');
        $d = $this->issue('invoice');

        self::assertSame(['FV20960001', 'ZD20960001', 'ZD20960002', 'FV20960002'], [$a, $b, $c, $d]);
        self::assertSame(2, $this->counter('invoice'));
        self::assertSame(2, $this->counter('simplified'));
        self::assertSame('ZD20960003', $this->gen->preview($this->supplierId, 'simplified', $this->date));
    }

    public function testOwnFormatBeatsClientSeries(): void
    {
        $this->setSimplifiedFormat(self::SIMPLIFIED_TPL);

        $vs = $this->gen->next($this->supplierId, 'simplified', $this->date, $this->clientId);

        self::assertSame('ZD20960001', $vs, 'Řada zjednodušených dokladů má přednost před řadou klienta.');
        self::assertSame(0, $this->counter('invoice', $this->clientId));
        self::assertSame(1, $this->counter('simplified'));
    }

    public function testReleaseReturnsNumberToOwnSeries(): void
    {
        $this->setSimplifiedFormat(self::SIMPLIFIED_TPL);
        $vs = $this->gen->next($this->supplierId, 'simplified', $this->date, $this->clientId);

        self::assertFalse(
            $this->gen->releaseIfLatest($this->supplierId, 'invoice', $vs, $this->date, $this->clientId),
            'Řada faktur číslo zjednodušeného dokladu nevydala.',
        );
        self::assertTrue($this->gen->releaseIfLatest($this->supplierId, 'simplified', $vs, $this->date, $this->clientId));
        self::assertSame(0, $this->counter('simplified'));
        self::assertSame($vs, $this->gen->next($this->supplierId, 'simplified', $this->date));
    }

    public function testSetCounterNeedsOwnFormat(): void
    {
        try {
            $this->gen->setCounter($this->supplierId, 'simplified', 10, $this->date);
            self::fail('Bez vlastní šablony nemá zjednodušený doklad vlastní počítadlo.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('řad', $e->getMessage());
        }
        self::assertSame(0, $this->counter('invoice'), 'Počítadlo faktur zůstalo nedotčené.');

        $this->setSimplifiedFormat(self::SIMPLIFIED_TPL);
        $result = $this->gen->setCounter($this->supplierId, 'simplified', 10, $this->date);

        self::assertSame('ZD20960010', $result['preview']);
        self::assertSame('ZD20960010', $this->gen->next($this->supplierId, 'simplified', $this->date));
    }

    public function testEditorPreviewShowsNumberFromSimplifiedSeries(): void
    {
        $this->setSimplifiedFormat(self::SIMPLIFIED_TPL);

        self::assertSame('ZD20960001', $this->previewFor(['type' => 'invoice', 'simplified' => '1']));
        self::assertSame('FV20960001', $this->previewFor(['type' => 'invoice', 'simplified' => '0']));
        self::assertSame('FV20960001', $this->previewFor(['type' => 'invoice']));
        // Příznak u zálohy řadu nemění.
        $proforma = $this->previewFor(['type' => 'proforma', 'simplified' => '1']);
        self::assertStringStartsNotWith('ZD', $proforma);
    }

    /** @param array<string,string> $query */
    private function previewFor(array $query): string
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/invoices/preview-varsymbol')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withQueryParams($query + ['issue_date' => $this->date->format('Y-m-d')]);
        $res = ($this->previewAction)($req, new Psr7Response());
        $res->getBody()->rewind();
        $json = json_decode((string) $res->getBody(), true);
        return (string) ($json['data']['varsymbol'] ?? $json['varsymbol'] ?? '');
    }
}
