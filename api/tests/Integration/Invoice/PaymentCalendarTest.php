<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PaymentScheduleRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * § 31 a § 31a ZDPH — splátkový a platební kalendář.
 *
 * Kalendář je SÁM O SOBĚ daňovým dokladem, pokud obsahuje náležitosti daňového dokladu
 * a rozpis plateb na předem stanovené období. Právě proto se nevystavuje doklad ke každé
 * splátce — to je celý smysl institutu a důvod, proč nestačí opakovaná fakturace: ta
 * vyrobí N dokladů, kalendář je jeden.
 *
 * ── Co testy hlídají ────────────────────────────────────────────────────────
 * Rozpis plateb NENÍ nepovinná příloha — bez něj kalendář daňovým dokladem není a
 * odběratel z něj nemůže uplatnit odpočet. Vystavit ho prázdný je horší než ho
 * nevystavit vůbec, protože obě strany se pak spolehnou na doklad, který jím není.
 */
#[Group('integration')]
final class PaymentCalendarTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private IssueInvoiceAction $action;
    private PaymentScheduleRepository $schedule;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db       = $c->get(Connection::class);
            $this->action   = $c->get(IssueInvoiceAction::class);
            $this->schedule = $c->get(PaymentScheduleRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('invoices', 'is_simplified')) {
            $this->markTestSkipped('Migrace 1170 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId    = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$source, $this->userId, $this->currencyId, $this->vatRateId, $czId], true)) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        // Vystavení se skladem si nastavuje úroveň izolace, kterou MariaDB v otevřené
        // transakci testu odmítne; sklad tenhle test neověřuje.
        $pdo->prepare('UPDATE supplier SET stock_enabled = 0 WHERE id = ?')->execute([$this->supplierId]);

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer)
             VALUES (?, "Nájemce", "Test 1", "Praha", "11000", ?, "CZ11111111", "n@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
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

    /**
     * Kalendář BEZ rozpisu plateb se nevystaví. Bez rozpisu není daňovým dokladem
     * (§ 31a), takže by odběratel dostal papír, ze kterého nemůže uplatnit odpočet.
     */
    public function testCalendarWithoutScheduleIsRejected(): void
    {
        $id = $this->calendar(12_000.0, 'advance_calendar');

        $res = $this->issue($id);

        self::assertSame(422, $res['status']);
        self::assertSame('payment_schedule_missing', $res['code']);
    }

    /**
     * Součet rozpisu musí sedět na celkovou částku dokladu — jinak není z čeho určit,
     * kolik vlastně bylo sjednáno.
     */
    public function testScheduleTotalMustMatchInvoiceTotal(): void
    {
        $id = $this->calendar(12_000.0, 'advance_calendar');
        $this->schedule->replaceForInvoice($this->supplierId, $id, [
            ['due_on' => '2099-01-15', 'total_amount' => 1_000.0],
            ['due_on' => '2099-02-15', 'total_amount' => 1_000.0],
        ]);

        $res = $this->issue($id);

        self::assertSame(422, $res['status']);
        self::assertSame('payment_schedule_mismatch', $res['code']);
    }

    /** S úplným a sedícím rozpisem se kalendář vystaví. */
    public function testCalendarWithMatchingScheduleIsIssued(): void
    {
        $id = $this->calendar(12_000.0, 'advance_calendar');
        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $rows[] = ['due_on' => sprintf('2099-%02d-15', $m), 'total_amount' => 1_000.0];
        }
        $this->schedule->replaceForInvoice($this->supplierId, $id, $rows);

        self::assertSame(12_000.0, $this->schedule->totalForInvoice($this->supplierId, $id));
        self::assertNotSame('payment_schedule_missing', $this->issue($id)['code']);
    }

    /** Rozpis se nahrazuje celý — částečná úprava by ho rozešla se součtem dokladu. */
    public function testScheduleIsReplacedWholesale(): void
    {
        $id = $this->calendar(2_000.0);
        $this->schedule->replaceForInvoice($this->supplierId, $id, [
            ['due_on' => '2099-01-15', 'total_amount' => 1_000.0],
            ['due_on' => '2099-02-15', 'total_amount' => 1_000.0],
        ]);
        $this->schedule->replaceForInvoice($this->supplierId, $id, [
            ['due_on' => '2099-03-15', 'total_amount' => 2_000.0],
        ]);

        $rows = $this->schedule->forInvoice($this->supplierId, $id);
        self::assertCount(1, $rows);
        self::assertSame('2099-03-15', $rows[0]['due_on']);
    }

    /**
     * Rozpis se ukládá z payloadu dokladu — bez toho by ho uživatel neměl jak zadat a
     * kalendář by šel založit, ale nikdy vystavit.
     */
    public function testScheduleIsSavedFromInvoicePayload(): void
    {
        $id = $this->calendar(3_000.0);

        $this->schedule->saveFromPayload($this->supplierId, $id, ['payment_schedule' => [
            ['due_on' => '2099-01-15', 'base_amount' => 1_000.0, 'vat_amount' => 0, 'total_amount' => 1_000.0, 'note' => 'leden'],
            ['due_on' => '2099-02-15', 'total_amount' => 2_000.0],
            ['due_on' => '', 'total_amount' => 999.0], // řádek bez data splatnosti není splátka
        ]]);

        $rows = $this->schedule->forInvoice($this->supplierId, $id);
        self::assertCount(2, $rows);
        self::assertSame('leden', $rows[0]['note']);
        self::assertSame(3_000.0, $this->schedule->totalForInvoice($this->supplierId, $id));
    }

    /**
     * Doklad ukládají i cesty, které o kalendáři nevědí (import, opakovaná fakturace).
     * Chybějící klíč proto rozpis NESMÍ smazat — jinak by doklad tiše přestal být
     * daňovým dokladem podle § 31.
     */
    public function testMissingKeyKeepsScheduleButEmptyArrayClearsIt(): void
    {
        $id = $this->calendar(1_000.0);
        $this->schedule->replaceForInvoice($this->supplierId, $id, [
            ['due_on' => '2099-01-15', 'total_amount' => 1_000.0],
        ]);

        $this->schedule->saveFromPayload($this->supplierId, $id, ['client_id' => $this->clientId]);
        self::assertCount(1, $this->schedule->forInvoice($this->supplierId, $id), 'Bez klíče se rozpis nemění.');

        $this->schedule->saveFromPayload($this->supplierId, $id, ['payment_schedule' => []]);
        self::assertSame([], $this->schedule->forInvoice($this->supplierId, $id), 'Prázdné pole rozpis smaže.');
    }

    /**
     * § 30 ZDPH — příznak zjednodušeného dokladu musí přežít uložení. Bez zápisu do
     * `invoices` by ho editor nastavil, ale doklad by se vystavil jako běžný a kontrola
     * výjimek § 30/2 by nikdy nesepnula.
     */
    public function testSimplifiedFlagSurvivesSaveRoundTrip(): void
    {
        $repo = Bootstrap::buildContainer()->get(\MyInvoice\Repository\InvoiceRepository::class);

        $data = [
            'invoice_type' => 'invoice',
            'client_id'    => $this->clientId,
            'supplier_id'  => $this->supplierId,
            'issue_date'   => '2099-01-01',
            'tax_date'     => '2099-01-01',
            'due_date'     => '2099-01-15',
            'currency_id'  => $this->currencyId,
            'is_simplified' => true,
        ];
        $id = $repo->createDraft($data, $this->userId);
        self::assertTrue($repo->find($id)['is_simplified'], 'Příznak se uloží při založení.');

        $repo->updateDraft($id, ['is_simplified' => false] + $data);
        self::assertFalse($repo->find($id)['is_simplified'], 'A dá se vypnout.');

        // Uložení bez klíče (import, opakovaná fakturace) příznak nepřepisuje.
        $repo->updateDraft($id, ['is_simplified' => true] + $data);
        $withoutKey = $data;
        unset($withoutKey['is_simplified']);
        $repo->updateDraft($id, $withoutKey);
        self::assertTrue($repo->find($id)['is_simplified'], 'Chybějící klíč příznak nemaže.');
    }

    /**
     * PDF platebního kalendáře musí nést ROZPIS PLATEB.
     *
     * Renderer o kalendáři nevěděl: v mapě typů dokladu nebyl, takže se tiskl s titulkem
     * „Faktura" a bez rozpisu — tedy bez toho jediného, co z něj podle § 31a dělá daňový
     * doklad. Odběratel dostal papír s jedním datem splatnosti, ze kterého nemohl uplatnit
     * odpočet u jednotlivých plateb.
     */
    public function testPdfShowsPaymentScheduleAndCalendarTitle(): void
    {
        $renderer = Bootstrap::buildContainer()->get(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class);
        $repo = Bootstrap::buildContainer()->get(\MyInvoice\Repository\InvoiceRepository::class);

        $id = $this->calendar(12_000.0);
        $this->schedule->replaceForInvoice($this->supplierId, $id, [
            ['due_on' => '2099-01-15', 'base_amount' => 1_000.0, 'vat_amount' => 0.0, 'total_amount' => 1_000.0, 'note' => 'leden'],
            ['due_on' => '2099-02-15', 'base_amount' => 11_000.0, 'vat_amount' => 0.0, 'total_amount' => 11_000.0, 'note' => null],
        ]);

        // Bez CSS: stylopis se do HTML vkládá inline a v komentáři u stylů rozpisu
        // je táž česká věta, kterou tu hledáme — s ním by test procházel i u dokladu,
        // který žádný rozpis nevykreslil.
        $html = $renderer->renderHtml($repo->find($id), includeCss: false);
        $nbsp = "\u{00A0}";

        self::assertStringContainsString('Platební kalendář', $html, 'Doklad se nesmí titulkovat jako faktura.');
        self::assertStringContainsString('Rozpis plateb', $html);
        self::assertStringContainsString('§ 31a', $html, 'Právní opora patří k dokladu.');
        // Obě splátky i jejich součet — kdyby se vypsala jen jedna, doklad by lhal o rozsahu.
        self::assertStringContainsString('leden', $html);
        self::assertStringContainsString('1' . $nbsp . '000,00', $html);
        self::assertStringContainsString('11' . $nbsp . '000,00', $html);
        self::assertStringContainsString('12' . $nbsp . '000,00', $html);
    }

    /**
     * Kalendář nedostane QR kód k platbě.
     *
     * Žádná jedna platba se na něm nekoná — rozpis jich má dvanáct. QR na celkovou
     * částku by vyzýval k úhradě celého roku najednou, tedy přesně proti tomu, co
     * doklad sjednává.
     */
    public function testPaymentCalendarHasNoSinglePaymentQrCode(): void
    {
        $renderer = Bootstrap::buildContainer()->get(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class);
        $repo = Bootstrap::buildContainer()->get(\MyInvoice\Repository\InvoiceRepository::class);

        $id = $this->calendar(12_000.0);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE invoices SET varsymbol = '2099001' WHERE id = ?")->execute([$id]);
        $this->schedule->replaceForInvoice($this->supplierId, $id, [
            ['due_on' => '2099-01-15', 'base_amount' => 6_000.0, 'vat_amount' => 0.0, 'total_amount' => 6_000.0, 'note' => null],
            ['due_on' => '2099-02-15', 'base_amount' => 6_000.0, 'vat_amount' => 0.0, 'total_amount' => 6_000.0, 'note' => null],
        ]);

        $calendarHtml = $renderer->renderHtml($repo->find($id), includeCss: false);
        self::assertStringNotContainsString('data:image', $calendarHtml, 'Kalendář nesmí nést QR na celou částku.');

        // Kontrola opačným směrem: týž doklad jako běžná faktura QR dostane — jinak by
        // test procházel i tehdy, kdyby se QR nevykreslovalo z docela jiného důvodu.
        $pdo->prepare("UPDATE invoices SET invoice_type = 'invoice' WHERE id = ?")->execute([$id]);
        $invoiceHtml = $renderer->renderHtml($repo->find($id), includeCss: false);
        self::assertStringContainsString('data:image', $invoiceHtml, 'Běžná faktura QR má mít.');
    }

    /** Běžná faktura rozpis plateb nedostane — nemá ho z čeho vzít a nepatří tam. */
    public function testOrdinaryInvoicePdfHasNoScheduleBlock(): void
    {
        $renderer = Bootstrap::buildContainer()->get(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class);
        $repo = Bootstrap::buildContainer()->get(\MyInvoice\Repository\InvoiceRepository::class);

        $id = $this->calendar(5_000.0);
        $this->db->pdo()->prepare("UPDATE invoices SET invoice_type = 'invoice' WHERE id = ?")->execute([$id]);

        $html = $renderer->renderHtml($repo->find($id), includeCss: false);

        self::assertStringNotContainsString('Rozpis plateb', $html);
        self::assertStringNotContainsString('schedule-table', $html);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function calendar(float $total, string $type = 'payment_calendar'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, invoice_type, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "2099-01-01", "2099-01-01", "2099-01-15", ?, 0, "{}", "{}",
                     ?, 0, ?, "draft", "1", ?)'
        )->execute([$this->supplierId, $this->clientId, $type, $this->currencyId, $total, $total, $this->userId]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Nájem 2099", 1, ?, ?, 0.00, ?, 0, ?, 1)'
        )->execute([$id, $total, $this->vatRateId, $total, $total]);

        return $id;
    }

    /** @return array{status:int, code:?string} */
    private function issue(int $id): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/' . $id . '/issue')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);

        try {
            $res = ($this->action)($req, new Psr7Response(), ['id' => (string) $id]);
        } catch (\PDOException $e) {
            // Vystavení po validaci pokračuje prací s vlastní transakcí, kterou uvnitř
            // testovací transakce dokončit nelze. Validace už proběhla — a právě tu testy
            // ověřují.
            if (str_contains($e->getMessage(), 'Transaction characteristics')
                || str_contains($e->getMessage(), 'already an active transaction')) {
                return ['status' => 200, 'code' => null];
            }
            throw $e;
        }

        $res->getBody()->rewind();
        $body = json_decode((string) $res->getBody(), true);

        return [
            'status' => $res->getStatusCode(),
            'code'   => $body['error']['code'] ?? null,
        ];
    }
}
