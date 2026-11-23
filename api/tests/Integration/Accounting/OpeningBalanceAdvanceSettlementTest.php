<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\OpeningBalanceDocumentAction;
use MyInvoice\Action\Invoice\AdvanceDrawdownAction;
use MyInvoice\Action\Invoice\IssueFinalFromProformaAction;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Invoice\AdvancePartialDrawdown;
use MyInvoice\Service\Invoice\AdvanceReceiptNotPostedException;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Záloha přijatá před otevřením knih (issue #409): zálohový list a DDKP převzaté
 * z počátečních stavů, zůstatek zálohy na 324 v otevíracím zápisu, bez bankovního pohybu.
 * Konečná faktura zálohu odečte a zúčtuje 324 MD / 311 D do výše převzaté zálohy po dani
 * DDKP, saldokonto 324 ji ukáže a po čerpání vyrovná. Označení dokladu jde přes REST API.
 * Záloha jen s ruční úhradou se v podvojném účetnictví čerpat nedá (advance_receipt_not_posted).
 */
#[Group('integration')]
final class OpeningBalanceAdvanceSettlementTest extends BankPostingTestCase
{
    private int $rate12 = 0;
    private int $openingEntry = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rate12 = (int) ($this->db->pdo()->query(
            'SELECT id FROM vat_rates WHERE rate_percent = 12 AND valid_to IS NULL AND is_reverse_charge = 0 ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($this->rate12 === 0) {
            self::markTestSkipped('Chybí sazba DPH 12 %.');
        }
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $this->openingEntry = $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => self::YEAR . '-01-01',
            'document_no' => 'PS-409',
            'description' => 'Počáteční stavy',
            'source_type' => 'opening',
            'source_id'   => null,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map['701']['id'], 'side' => 'debit', 'amount' => 500.00],
            ['account_id' => $map['324']['id'], 'side' => 'credit', 'amount' => 500.00],
        ]);
    }

    public function testAdvanceTakenOverFromOpeningBalanceSettlesAgainstOpening324(): void
    {
        $client = $this->client('Odběratel převzaté zálohy');
        [$proforma, $ddkp] = $this->takenOverAdvance($client, 'ZL-PS-1', 'DD-PS-1');

        $marked = $this->callOpening('markInvoice', 'PUT', $proforma);
        self::assertSame(200, $marked['status'], json_encode($marked['body'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertTrue($marked['body']['data']['opening_balance']['in_opening'] ?? $marked['body']['opening_balance']['in_opening'] ?? false);
        self::assertSame(200, $this->callOpening('markInvoice', 'PUT', $ddkp)['status']);

        try {
            $this->posting->buildFromInvoice($this->supplierId, $ddkp);
            self::fail('Převzatý DDKP se nesmí zaúčtovat, jeho daň nese otevírací zápis.');
        } catch (PostingException $e) {
            self::assertSame('document_in_opening_balance', $e->errorCode);
        }

        $open = $this->saldoItem($proforma);
        self::assertNotNull($open, 'Převzatá záloha je v saldokontu 324 otevřená proti počátečnímu stavu.');
        self::assertEqualsWithDelta(500.00, (float) $open['remaining_czk'], 0.001);

        $final = $this->draft($client, 1500.00, 180.00);
        $drawdown = $this->container->get(AdvancePartialDrawdown::class);
        $candidates = array_column($drawdown->candidates($this->supplierId, $final), null, 'id');
        self::assertEqualsWithDelta(560.00, $candidates[$proforma]['available'], 0.001);
        self::assertTrue($candidates[$proforma]['receipt_posted']);
        $drawdown->apply($this->supplierId, $final, $proforma, 560.00);
        $this->issue($final, 'FV-PS-1');

        $entry = $this->postInvoice($final);
        $lines = $this->linesByAccountCode($entry);
        self::assertEqualsWithDelta(1620.00, $lines['311']['debit'] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(500.00, $lines['311']['credit'] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(1500.00, $lines['602']['credit'] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(120.00, $this->sumPrefix($lines, '343', 'credit'), 0.001);
        self::assertEqualsWithDelta(500.00, $lines['324']['debit'] ?? 0.0, 0.001);

        self::assertNull($this->saldoItem($proforma), 'Čerpání konečnou fakturou zálohu v saldokontu 324 vyrovná.');

        $refused = $this->callOpening('unmarkInvoice', 'DELETE', $proforma);
        self::assertSame(409, $refused['status']);
        self::assertSame('opening_document_in_use', $this->errorCode($refused));
        $refused = $this->callOpening('unmarkInvoice', 'DELETE', $ddkp);
        self::assertSame('opening_document_in_use', $this->errorCode($refused), 'DDKP čerpané zálohy taky ne.');
    }

    public function testControlCaseWithBankReceiptPostsTheSameLines(): void
    {
        $client = $this->client('Odběratel zálohy bankou');
        $proforma = $this->document('proforma', 'ZL-BK-1', $client, 500.00, 60.00, self::YEAR . '-02-01');
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 560.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $proforma]);
        $this->invoicePayment($proforma, $tx, 560.00);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);
        $ddkp = $this->document('tax_document', 'DD-BK-1', $client, 500.00, 60.00, self::YEAR . '-02-01', $proforma);
        $this->postInvoice($ddkp);

        $final = $this->draft($client, 1500.00, 180.00);
        $this->container->get(AdvancePartialDrawdown::class)->apply($this->supplierId, $final, $proforma, 560.00);
        $this->issue($final, 'FV-BK-1');
        $lines = $this->linesByAccountCode($this->postInvoice($final));
        self::assertEqualsWithDelta(1620.00, $lines['311']['debit'] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(500.00, $lines['311']['credit'] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(1500.00, $lines['602']['credit'] ?? 0.0, 0.001);
        self::assertEqualsWithDelta(120.00, $this->sumPrefix($lines, '343', 'credit'), 0.001);
        self::assertEqualsWithDelta(500.00, $lines['324']['debit'] ?? 0.0, 0.001);
    }

    public function testManualReceiptOfAdvanceCannotBeDrawnInDoubleEntry(): void
    {
        $client = $this->client('Odběratel ruční úhrady');
        $proforma = $this->document('proforma', 'ZL-RU-1', $client, 500.00, 60.00, self::YEAR . '-02-01');
        $this->manualPayment($proforma, self::YEAR . '-02-02', 560.00);
        $this->document('tax_document', 'DD-RU-1', $client, 500.00, 60.00, self::YEAR . '-02-02', $proforma);
        $final = $this->draft($client, 1500.00, 180.00);

        $drawdown = $this->container->get(AdvancePartialDrawdown::class);
        $candidates = array_column($drawdown->candidates($this->supplierId, $final), null, 'id');
        self::assertFalse($candidates[$proforma]['receipt_posted'], 'Nabídka čerpání vyznačí zálohu bez zaúčtované úhrady.');

        try {
            $drawdown->apply($this->supplierId, $final, $proforma, 560.00);
            self::fail('Zálohu jen s ruční úhradou nejde v podvojném účetnictví čerpat.');
        } catch (AdvanceReceiptNotPostedException $e) {
            self::assertStringContainsString('ZL-RU-1', $e->getMessage());
        }

        $res = $this->callAction(
            $this->container->get(AdvanceDrawdownAction::class), 'apply', 'POST', 'admin',
            ['proforma_id' => $proforma, 'amount' => 560], ['id' => (string) $final],
        );
        self::assertSame(409, $res['status']);
        self::assertSame('advance_receipt_not_posted', $this->errorCode($res));

        // Ruční úhrada po otevření knih se nepočítá ani u zálohy převzaté z počátečních stavů.
        self::assertSame(422, $this->callOpening('markInvoice', 'PUT', $proforma)['status'], 'Záloha z doby po otevření knih se nepřevezme.');
    }

    public function testFinalFromProformaRefusesAdvanceWithoutPostedReceipt(): void
    {
        $client = $this->client('Odběratel vyúčtování k záloze');
        $proforma = $this->document('proforma', 'ZL-VF-1', $client, 500.00, 60.00, self::YEAR . '-02-01');
        $this->manualPayment($proforma, self::YEAR . '-02-02', 560.00);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_total = 560 WHERE id = ?")->execute([$proforma]);
        $this->document('tax_document', 'DD-VF-1', $client, 500.00, 60.00, self::YEAR . '-02-02', $proforma);

        $creator = $this->container->get(FinalFromProformaCreator::class);
        try {
            $creator->create($proforma, $this->userId, self::YEAR . '-02-10');
            self::fail('Vyúčtování zálohy jen s ruční úhradou nejde v podvojném účetnictví vytvořit.');
        } catch (AdvanceReceiptNotPostedException $e) {
            self::assertStringContainsString('ZL-VF-1', $e->getMessage());
        }
        self::assertSame(0, $this->finalCount($proforma), 'Koncept nevznikl.');

        $res = $this->callAction(
            $this->container->get(IssueFinalFromProformaAction::class), '__invoke', 'POST', 'admin',
            ['tax_date' => self::YEAR . '-02-10'], ['id' => (string) $proforma],
        );
        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame('advance_receipt_not_posted', $this->errorCode($res));

        // Převzatá záloha z počátečních stavů vyúčtování vytvořit dovolí.
        [$taken] = $this->takenOverAdvance($client, 'ZL-VF-2', 'DD-VF-2');
        $this->callOpening('markInvoice', 'PUT', $taken);
        self::assertGreaterThan(0, $creator->create($taken, $this->userId, self::YEAR . '-02-10'));

        // Úhrada zaúčtovaná bankou, která nepokrývá odpočet daňového dokladu, nestačí.
        $partial = $this->document('proforma', 'ZL-VF-3', $client, 500.00, 60.00, self::YEAR . '-02-01');
        $tx = $this->transaction($this->statement(), 100.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $partial]);
        $this->invoicePayment($partial, $tx, 100.00);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);
        $this->manualPayment($partial, self::YEAR . '-02-02', 460.00);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_total = 560 WHERE id = ?")->execute([$partial]);
        $this->document('tax_document', 'DD-VF-3', $client, 500.00, 60.00, self::YEAR . '-02-02', $partial);
        $this->expectException(AdvanceReceiptNotPostedException::class);
        $creator->create($partial, $this->userId, self::YEAR . '-02-10');
    }

    public function testMarkingGuardsAndApiAccess(): void
    {
        $client = $this->client('Odběratel pojistek');
        [$proforma] = $this->takenOverAdvance($client, 'ZL-PJ-1', 'DD-PJ-1');

        $readonly = $this->callOpening('markInvoice', 'PUT', $proforma, 'readonly');
        self::assertSame(403, $readonly['status'], 'Role bez práva zaúčtovat doklad neoznačí.');

        $bearer = $this->callOpening('markInvoice', 'PUT', $proforma, 'admin', ['scope' => 'read_write']);
        self::assertSame(403, $bearer['status'], 'Token bez plného zápisu doklad neoznačí.');
        $system = $this->callOpening('markInvoice', 'PUT', $proforma, 'admin', ['scope' => 'read_write', 'system_write' => true]);
        self::assertSame(200, $system['status'], 'Token s plným zápisem doklad označí.');
        self::assertSame(200, $this->callOpening('markInvoice', 'PUT', $proforma)['status'], 'Opakované označení nic nezmění.');

        $middleware = new ApiScopeMiddleware(new ResponseFactory());
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Psr7Response(200);
            }
        };
        foreach (['/api/v1/invoices/1/opening-balance', '/api/purchase-invoices/1/opening-balance'] as $path) {
            $request = (new ServerRequestFactory())->createServerRequest('PUT', str_replace('/api/v1/', '/api/', $path))
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
                ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => 'read_write']);
            self::assertSame(403, $middleware->process($request, $handler)->getStatusCode(), $path);
            self::assertSame(200, $middleware->process($request->withMethod('GET'), $handler)->getStatusCode(), $path);
            self::assertSame(200, $middleware->process(
                $request->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => 'read_write', 'system_write' => true]),
                $handler,
            )->getStatusCode(), $path);
        }

        $draft = $this->document('proforma', 'ZL-PJ-2', $client, 100.00, 12.00, '2098-12-01');
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'draft' WHERE id = ?")->execute([$draft]);
        self::assertSame('document_not_eligible', $this->errorCode($this->callOpening('markInvoice', 'PUT', $draft)));

        $later = $this->document('proforma', 'ZL-PJ-3', $client, 100.00, 12.00, self::YEAR . '-03-01');
        self::assertSame('document_after_opening', $this->errorCode($this->callOpening('markInvoice', 'PUT', $later)));

        $posted = $this->document('invoice', 'FV-PJ-4', $client, 100.00, 12.00, '2098-12-01');
        $this->postPredpis('invoice', $posted, '311', '602', 112.00);
        self::assertSame('document_posted', $this->errorCode($this->callOpening('markInvoice', 'PUT', $posted)));

        $vendor = $this->client('Dodavatel převzaté zálohy');
        $advance = $this->purchaseInvoice('ZP-PJ-1', $vendor, 1000.00, 'advance');
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET issue_date = '2098-12-01', tax_date = '2098-12-01' WHERE id = ?")->execute([$advance]);
        $res = $this->callOpening('markPurchase', 'PUT', $advance);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame($this->openingEntry, $this->openingState($res)['entry_id']);
        $res = $this->callOpening('unmarkPurchase', 'DELETE', $advance);
        self::assertSame(200, $res['status']);
        self::assertFalse($this->openingState($res)['in_opening']);
        self::assertSame('not_in_opening', $this->errorCode($this->callOpening('unmarkPurchase', 'DELETE', $advance)));

        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        self::assertSame('period_closed', $this->errorCode($this->callOpening('unmarkInvoice', 'DELETE', $proforma)));
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    /**
     * Zálohový list 500 + 12 % s ruční úhradou a DDKP, obojí před otevřením knih.
     *
     * @return array{0:int, 1:int} [proforma, DDKP]
     */
    private function takenOverAdvance(int $client, string $vs, string $ddkpVs): array
    {
        $proforma = $this->document('proforma', $vs, $client, 500.00, 60.00, '2098-12-10');
        $this->manualPayment($proforma, '2098-12-15', 560.00);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_total = 560 WHERE id = ?")->execute([$proforma]);
        $ddkp = $this->document('tax_document', $ddkpVs, $client, 500.00, 60.00, '2098-12-15', $proforma);
        return [$proforma, $ddkp];
    }

    private function document(string $type, string $vs, int $clientId, float $base, float $vat, string $date, ?int $parentId = null): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, prices_include_vat, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 0, "issued", "2", ?)'
        )->execute([$this->supplierId, $vs, $type, $parentId, $clientId, $date, $date, $date,
            $this->currencyId, $type === 'tax_document' ? 1 : 0, $base, $vat, $base + $vat, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, 'Položka', 1, 'ks', ?, ?, 12, ?, ?, ?, 0, '2')"
        )->execute([$id, $base, $this->rate12, $base, $vat, $base + $vat]);
        return $id;
    }

    private function draft(int $client, float $base, float $vat): int
    {
        $id = $this->document('invoice', '', $client, $base, $vat, self::YEAR . '-02-10');
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'draft', varsymbol = NULL WHERE id = ?")->execute([$id]);
        return $id;
    }

    private function finalCount(int $proformaId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM invoices WHERE parent_invoice_id = ? AND invoice_type = 'invoice'");
        $stmt->execute([$proformaId]);
        return (int) $stmt->fetchColumn();
    }

    private function issue(int $invoiceId, string $vs): void
    {
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', varsymbol = ? WHERE id = ?")->execute([$vs, $invoiceId]);
    }

    private function manualPayment(int $invoiceId, string $date, float $amount): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source) VALUES (?, ?, ?, ?, "CZK", "manual")'
        )->execute([$this->supplierId, $invoiceId, $date, $amount]);
    }

    private function postInvoice(int $invoiceId): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $this->posting->buildFromInvoice($this->supplierId, $invoiceId),
            ['entry_date' => self::YEAR . '-02-10'],
        );
    }

    /**
     * @param array<string,mixed>|null $token API token požadavku (bearer), jinak relace
     * @return array{status:int, body:array<string,mixed>}
     */
    private function callOpening(string $method, string $httpMethod, int $id, string $role = 'admin', ?array $token = null): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($httpMethod, '/api/invoices/' . $id . '/opening-balance')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $token === null ? 'session' : 'bearer');
        if ($token !== null) {
            $request = $request->withAttribute(AuthMiddleware::ATTR_API_TOKEN, $token);
        }
        $response = $this->container->get(OpeningBalanceDocumentAction::class)->{$method}($request, new Psr7Response(), ['id' => (string) $id]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @param array{status:int, body:array<string,mixed>} $res */
    private function errorCode(array $res): ?string
    {
        $error = $res['body']['error'] ?? null;
        return is_array($error) ? ($error['code'] ?? null) : ($res['body']['code'] ?? (is_string($error) ? $error : null));
    }

    /**
     * @param array{status:int, body:array<string,mixed>} $res
     * @return array<string,mixed>
     */
    private function openingState(array $res): array
    {
        return (array) ($res['body']['data']['opening_balance'] ?? $res['body']['opening_balance'] ?? []);
    }

    /** @return array<string,mixed>|null */
    private function saldoItem(int $proformaId): ?array
    {
        $report = $this->container->get(SaldoService::class)->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '324');
        foreach ($report['accounts'] as $account) {
            foreach ($account['partners'] as $partner) {
                foreach ($partner['items'] as $item) {
                    if ((string) $item['doc_type'] === 'invoice' && (int) $item['doc_id'] === $proformaId) {
                        return $item;
                    }
                }
            }
        }
        return null;
    }

    /** @param array<string,array{debit:float,credit:float}> $lines */
    private function sumPrefix(array $lines, string $prefix, string $side): float
    {
        $sum = 0.0;
        foreach ($lines as $code => $sides) {
            if (str_starts_with((string) $code, $prefix)) {
                $sum += $sides[$side];
            }
        }
        return round($sum, 2);
    }
}
