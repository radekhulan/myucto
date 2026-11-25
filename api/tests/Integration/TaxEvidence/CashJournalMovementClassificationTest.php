<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Action\TaxEvidence\MovementClassificationAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Ruční klasifikační override pohybu (Epic DE, G2 — migrace 1027, audit 2026-07).
 *
 * POST/DELETE /api/tax-evidence/classification přes MovementClassificationAction
 * volaná přímo z DI kontejneru (vzor SavedFilterActionTest). Ověřuje:
 *  - R10 dopad: blokující warning zmizí, jakmile je nezařazený pohyb klasifikován
 *    (CashJournalRepository čte de_movement_classification fresh při každém build()).
 *  - DELETE vrátí pohyb k auto-klasifikaci.
 *  - Tenant izolace (R4-analog): cizí bankovní transakce / pokladní doklad → 404.
 *  - RBAC: readonly nesmí zapisovat (403).
 */
#[Group('integration')]
final class CashJournalMovementClassificationTest extends CashJournalTestCase
{
    private MovementClassificationAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->container->get(MovementClassificationAction::class);
    }

    public function testCreateClassifiesUnclassifiedBankMovementAndClearsR10Warning(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, 5000.0, ['description' => 'Nezařazený příjem']);

        $before = $this->fullYear($this->supplierId);
        self::assertEqualsWithDelta(5000.0, $before['totals']['nezarazeno'], 0.01);
        self::assertTrue($this->hasBlockingWarning($before, 'bank', $tx));

        $c = $this->call($this->supplierId, 'create', 'POST', [
            'body' => ['source_type' => 'bank', 'source_id' => $tx, 'tax_bucket' => 'income_taxable'],
        ]);
        self::assertSame(201, $c['status']);
        self::assertSame('income_taxable', $c['body']['tax_bucket']);
        self::assertSame($tx, $c['body']['bank_transaction_id']);
        self::assertSame('bank', $c['body']['source_type']);

        $after = $this->fullYear($this->supplierId);
        self::assertEqualsWithDelta(0.0, $after['totals']['nezarazeno'], 0.01, 'Po klasifikaci žádný nezařazený zůstatek.');
        self::assertEqualsWithDelta(5000.0, $after['totals']['prijem_danovy'], 0.01);
        self::assertFalse($this->hasBlockingWarning($after, 'bank', $tx), 'R10 blokující varování zmizí.');
    }

    public function testCreateOverwritesExistingClassification(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, 2000.0);
        $this->classifyOverride($this->supplierId, 'bank', $tx, 'private');

        $c = $this->call($this->supplierId, 'create', 'POST', [
            'body' => ['source_type' => 'bank', 'source_id' => $tx, 'tax_bucket' => 'income_taxable'],
        ]);
        self::assertSame(201, $c['status']);
        self::assertSame('income_taxable', $c['body']['tax_bucket']);

        $res = $this->fullYear($this->supplierId);
        self::assertEqualsWithDelta(2000.0, $res['totals']['prijem_danovy'], 0.01, 'Nová klasifikace přepíše starou (upsert).');
        self::assertEqualsWithDelta(0.0, $res['totals']['private'], 0.01);
    }

    public function testCreateClassifiesCashDocument(): void
    {
        $cd = $this->cashDoc('out', 'other', 1500.0);

        $c = $this->call($this->supplierId, 'create', 'POST', [
            'body' => ['source_type' => 'cash', 'source_id' => $cd, 'tax_bucket' => 'expense_taxable'],
        ]);
        self::assertSame(201, $c['status']);
        self::assertSame($cd, $c['body']['cash_document_id']);
        self::assertNull($c['body']['bank_transaction_id']);

        $res = $this->fullYear($this->supplierId);
        self::assertEqualsWithDelta(1500.0, $res['totals']['vydaj_danovy'], 0.01);
    }

    public function testDeleteRemovesOverrideAndMovementReturnsToUnclassified(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, 3000.0);
        $this->classifyOverride($this->supplierId, 'bank', $tx, 'income_taxable');

        $before = $this->fullYear($this->supplierId);
        self::assertEqualsWithDelta(3000.0, $before['totals']['prijem_danovy'], 0.01);

        $d = $this->call($this->supplierId, 'delete', 'DELETE', ['args' => ['source_type' => 'bank', 'source_id' => (string) $tx]]);
        self::assertSame(200, $d['status']);
        self::assertTrue($d['body']['deleted']);

        $after = $this->fullYear($this->supplierId);
        self::assertEqualsWithDelta(0.0, $after['totals']['prijem_danovy'], 0.01, 'Override zrušen — pohyb se vrací k auto-klasifikaci.');
        self::assertEqualsWithDelta(3000.0, $after['totals']['nezarazeno'], 0.01, 'Auto-klasifikace nespárovaného pohybu = nezařazeno (R10).');
    }

    public function testFinalYearRejectsReclassifyingBankAndDeletingCashOverride(): void
    {
        $statement = $this->statement($this->supplierId, $this->accountA);
        $bank = $this->bankTx($statement, 3000.0);
        $cash = $this->cashDoc('out', 'other', 1500.0);
        $this->classifyOverride($this->supplierId, 'bank', $bank, 'income_taxable');
        $this->classifyOverride($this->supplierId, 'cash', $cash, 'expense_taxable');
        $this->db->pdo()->prepare(
            "INSERT INTO tax_evidence_closings (supplier_id, year, status) VALUES (?, ?, 'final')"
        )->execute([$this->supplierId, self::YEAR]);

        $reclassified = $this->call($this->supplierId, 'create', 'POST', [
            'body' => ['source_type' => 'bank', 'source_id' => $bank, 'tax_bucket' => 'private'],
        ]);
        self::assertSame(409, $reclassified['status']);
        self::assertSame('closing_final', $reclassified['body']['error']['code']);

        $deleted = $this->call($this->supplierId, 'delete', 'DELETE', [
            'args' => ['source_type' => 'cash', 'source_id' => (string) $cash],
        ]);
        self::assertSame(409, $deleted['status']);
        self::assertSame('closing_final', $deleted['body']['error']['code']);

        $journal = $this->fullYear($this->supplierId);
        self::assertEqualsWithDelta(3000.0, $journal['totals']['prijem_danovy'], 0.01);
        self::assertEqualsWithDelta(1500.0, $journal['totals']['vydaj_danovy'], 0.01);

        $nextYearBank = $this->bankTx($statement, 1000.0, ['posted_at' => (self::YEAR + 1) . '-01-01']);
        $accepted = $this->call($this->supplierId, 'create', 'POST', [
            'body' => ['source_type' => 'bank', 'source_id' => $nextYearBank, 'tax_bucket' => 'income_taxable'],
        ]);
        self::assertSame(201, $accepted['status']);
    }

    public function testDeleteNonExistentReturnsDeletedFalse(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, 1000.0);

        $d = $this->call($this->supplierId, 'delete', 'DELETE', ['args' => ['source_type' => 'bank', 'source_id' => (string) $tx]]);
        self::assertSame(200, $d['status']);
        self::assertFalse($d['body']['deleted'], 'Pohyb existuje, ale žádnou klasifikaci nemá — mazat nebylo co.');
    }

    public function testCreateForeignBankMovementReturns404(): void
    {
        // Supplier B — vlastní účet/výpis/pohyb; A na něj NESMÍ zapsat klasifikaci (R4-analog).
        $supplierB = $this->cloneSupplier('tax_evidence', true);
        $accountB = '880000333';
        $this->db->pdo()->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, "CZK", "CZK", "CZK", "CZK", "CZK", 2, 1, 1, ?, "0300")'
        )->execute([$supplierB, $accountB]);
        $stB = $this->statement($supplierB, $accountB, '0300');
        $txB = $this->bankTx($stB, 9000.0);

        $c = $this->call($this->supplierId, 'create', 'POST', [
            'body' => ['source_type' => 'bank', 'source_id' => $txB, 'tax_bucket' => 'income_taxable'],
        ]);
        self::assertSame(404, $c['status'], 'Supplier A nesmí klasifikovat cizí bankovní pohyb (tenant izolace).');

        $d = $this->call($this->supplierId, 'delete', 'DELETE', ['args' => ['source_type' => 'bank', 'source_id' => (string) $txB]]);
        self::assertSame(404, $d['status']);
    }

    public function testCreateForeignCashDocumentReturns404(): void
    {
        $supplierB = $this->cloneSupplier('tax_evidence', true);
        $regB = $this->cashRegisterFor($supplierB);
        $cdB = $this->cashDocFor($supplierB, $regB);

        $c = $this->call($this->supplierId, 'create', 'POST', [
            'body' => ['source_type' => 'cash', 'source_id' => $cdB, 'tax_bucket' => 'income_taxable'],
        ]);
        self::assertSame(404, $c['status'], 'Supplier A nesmí klasifikovat cizí pokladní doklad.');
    }

    /** Issue #113 — UI (CashJournal.vue) posílá jen bucket; uložená poznámka nesmí zmizet. */
    public function testReclassifyWithoutNoteKeepsStoredNote(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, 2500.0);
        $c = $this->call($this->supplierId, 'create', 'POST', [
            'body' => ['source_type' => 'bank', 'source_id' => $tx, 'tax_bucket' => 'private', 'note' => 'Syntetická poznámka'],
        ]);
        self::assertSame(201, $c['status']);

        $c = $this->call($this->supplierId, 'create', 'POST', [
            'body' => ['source_type' => 'bank', 'source_id' => $tx, 'tax_bucket' => 'income_taxable'],
        ]);
        self::assertSame(201, $c['status']);
        self::assertSame('income_taxable', $c['body']['tax_bucket']);
        self::assertSame('Syntetická poznámka', $c['body']['note']);

        $c = $this->call($this->supplierId, 'create', 'POST', [
            'body' => ['source_type' => 'bank', 'source_id' => $tx, 'tax_bucket' => 'income_taxable', 'note' => null],
        ]);
        self::assertNull($c['body']['note'], 'Explicitní null poznámku maže.');
    }

    public function testInvalidTaxBucketReturns422(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, 1000.0);
        $c = $this->call($this->supplierId, 'create', 'POST', [
            'body' => ['source_type' => 'bank', 'source_id' => $tx, 'tax_bucket' => 'nezarazeno'],
        ]);
        self::assertSame(422, $c['status'], "'nezarazeno' není zapisovatelný stav (jen výsledek chybějící klasifikace).");
    }

    public function testInvalidSourceTypeReturns422(): void
    {
        $c = $this->call($this->supplierId, 'create', 'POST', [
            'body' => ['source_type' => 'invoice_payment', 'source_id' => 1, 'tax_bucket' => 'income_taxable'],
        ]);
        self::assertSame(422, $c['status']);
    }

    public function testReadonlyRoleCannotCreate(): void
    {
        $st = $this->statement($this->supplierId, $this->accountA);
        $tx = $this->bankTx($st, 1000.0);
        $c = $this->call($this->supplierId, 'create', 'POST', [
            'body' => ['source_type' => 'bank', 'source_id' => $tx, 'tax_bucket' => 'income_taxable'],
            'role' => 'readonly',
        ]);
        self::assertSame(403, $c['status']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $result */
    private function hasBlockingWarning(array $result, string $sourceType, int $sourceId): bool
    {
        foreach ($result['warnings'] as $w) {
            if (($w['blocking'] ?? false) && ($w['source_type'] ?? '') === $sourceType && (int) ($w['source_id'] ?? 0) === $sourceId) {
                return true;
            }
        }
        return false;
    }

    private function cashRegisterFor(int $supplierId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO cash_registers (supplier_id, name, currency_code, account_code, is_default)
             VALUES (?, 'Pokladna B', 'CZK', '211', 1)"
        )->execute([$supplierId]);
        return (int) $pdo->lastInsertId();
    }

    private function cashDocFor(int $supplierId, int $registerId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, partner_name,
                 description, vat_mode, total_amount, currency_code, fx_rate, status, created_by)
             VALUES (?, ?, 'in', 'sale', ?, ?, 'Protistrana B', 'Pokladní pohyb B', 'none', 1000, 'CZK', 1, 'posted', ?)"
        )->execute([$supplierId, $registerId, self::nextDocNumber('PPD-B'), self::YEAR . '-06-15', $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $opts
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(int $supplierId, string $method, string $httpMethod, array $opts = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/tax-evidence/classification')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $opts['role'] ?? 'accountant']);
        if (isset($opts['body'])) {
            $req = $req->withParsedBody($opts['body']);
        }
        $args = $opts['args'] ?? [];
        $resp = $args === []
            ? $this->action->{$method}($req, new Psr7Response())
            : $this->action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
