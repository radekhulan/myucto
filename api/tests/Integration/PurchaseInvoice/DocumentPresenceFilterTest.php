<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\ListPurchaseInvoicesAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\DocumentViewerContext;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\EffectiveRole;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** Syntetická data v transakci; žádný závislý doklad ani firma z pracovní databáze. */
#[Group('integration')]
final class DocumentPresenceFilterTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private PurchaseInvoiceRepository $repo;
    private ListPurchaseInvoicesAction $action;
    private DocumentRepository $documents;
    private int $supplier;
    private int $currency;
    private int $vendor;
    private int $user;
    private int $otherUser;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $c = Bootstrap::buildContainer();
        $this->db = $c->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $this->repo = $c->get(PurchaseInvoiceRepository::class);
        $this->action = $c->get(ListPurchaseInvoicesAction::class);
        $this->documents = $c->get(DocumentRepository::class);
        $this->pdo->beginTransaction();
        [$this->supplier, $this->currency] = $this->company();
        $country = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $this->pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, is_vendor)
            VALUES (?, 'Synthetic vendor', 'Test 1', 'Test', '11000', ?, ?, 1)")->execute([$this->supplier, $country, $this->currency]);
        $this->vendor = (int) $this->pdo->lastInsertId();
        $this->user = $this->user();
        $this->otherUser = $this->user();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testAllFourCombinationsThroughAction(): void
    {
        $ids = [];
        foreach (['0', '1'] as $visual) {
            foreach (['0', '1'] as $structured) {
                $ids[$visual . $structured] = $this->invoice($visual === '1' ? 'photo-converted.pdf' : null,
                    $structured === '1' ? 'isdoc' : null);
            }
        }
        foreach ($ids as $combination => $id) {
            $combination = (string) $combination;
            $result = $this->call(['has_visual_document' => $combination[0], 'has_structured_source' => $combination[1]]);
            self::assertSame(200, $result['status']);
            self::assertSame([$id], $this->ids($result['body']));
            self::assertSame(1, $result['body']['meta']['total']);
        }
        self::assertCount(4, $this->ids($this->call([])['body']));
    }

    public function testSupportedSourcesAndNullOrEmptyMetadata(): void
    {
        $structured = [];
        foreach (['isdoc', 'isdocx', 'pohoda_xml', 'idoklad_json', 'fakturoid_json'] as $format) {
            $structured[] = $this->invoice(null, $format);
        }
        $pdfSource = $this->invoice(null, 'pdf');
        $missing = $this->invoice();
        $empty = $this->invoice('', 'isdoc');
        $this->pdo->prepare("UPDATE purchase_invoices SET source_path = '' WHERE id = ?")->execute([$empty]);
        $unknown = $this->invoice();
        $this->pdo->prepare("UPDATE purchase_invoices SET source_path = 'unknown', source_format = NULL WHERE id = ?")->execute([$unknown]);
        self::assertEqualsCanonicalizing($structured, $this->ids($this->list(['has_structured_source' => '1'])));
        self::assertEqualsCanonicalizing([$pdfSource, $missing, $empty, $unknown], $this->ids($this->list(['has_structured_source' => '0'])));
        self::assertSame([$pdfSource], $this->ids($this->list(['has_visual_document' => '1'])));
        self::assertContains($unknown, $this->ids($this->list(['has_visual_document' => '0'])));
    }

    public function testLinkedPdfAndImageWithoutDuplicateRows(): void
    {
        $linked = $this->invoice();
        $this->document($linked, 'application/pdf');
        $this->document($linked, 'image/jpeg');
        $xml = $this->invoice();
        $this->document($xml, 'application/xml');
        self::assertSame([$linked], $this->ids($this->list(['has_visual_document' => '1'])));
        self::assertSame([$xml], $this->ids($this->list(['has_visual_document' => '0'])));
        self::assertSame([], $this->ids($this->list(['has_structured_source' => '1'])));
        self::assertSame(1, $this->list(['has_visual_document' => '1'])['meta']['total']);
    }

    public function testUnlinkTrashRestoreAndMainPdfRemoval(): void
    {
        $id = $this->invoice();
        $doc = $this->document($id, 'image/png');
        self::assertSame([$id], $this->ids($this->list(['has_visual_document' => '1'])));
        self::assertSame([], $this->ids($this->list(['has_visual_document' => '0'])));
        $this->documents->softDelete($doc, $this->supplier, $this->user);
        self::assertSame([], $this->ids($this->list(['has_visual_document' => '1'])));
        self::assertSame([$id], $this->ids($this->list(['has_visual_document' => '0'])));
        $this->documents->restore($doc, $this->supplier);
        self::assertSame([$id], $this->ids($this->list(['has_visual_document' => '1'])));
        $this->pdo->prepare('DELETE FROM document_links WHERE document_id = ?')->execute([$doc]);
        self::assertSame([$id], $this->ids($this->list(['has_visual_document' => '0'])));
        $this->pdo->prepare("UPDATE purchase_invoices SET pdf_path = 'scan.pdf' WHERE id = ?")->execute([$id]);
        self::assertSame([$id], $this->ids($this->list(['has_visual_document' => '1'])));
        $this->pdo->prepare('UPDATE purchase_invoices SET pdf_path = NULL WHERE id = ?')->execute([$id]);
        self::assertSame([$id], $this->ids($this->list(['has_visual_document' => '0'])));
    }

    public function testVisibilityAndDocumentsPermissionThroughAction(): void
    {
        $company = $this->invoice();
        $this->document($company, 'application/pdf');
        $own = $this->invoice();
        $this->document($own, 'application/pdf', 'user', $this->user);
        $other = $this->invoice();
        $this->document($other, 'application/pdf', 'user', $this->otherUser);
        $main = $this->invoice('original.pdf');
        self::assertEqualsCanonicalizing([$company, $own, $main], $this->ids($this->call(['has_visual_document' => '1'])['body']));
        self::assertSame([$other], $this->ids($this->call(['has_visual_document' => '0'])['body']));
        self::assertSame([$main], $this->ids($this->call(['has_visual_document' => '1'], false)['body']));
        self::assertEqualsCanonicalizing([$company, $own, $other], $this->ids($this->call(['has_visual_document' => '0'], false)['body']));
        self::assertCount(4, $this->ids($this->repo->listGroupedByMonth(
            ['supplier_id' => $this->supplier, 'has_visual_document' => '1'], 1, 50, DocumentViewerContext::admin($this->user))));
    }

    public function testDifferentCompanyCannotSupplyAnAttachment(): void
    {
        $local = $this->invoice();
        [$foreign] = $this->company();
        // Platná vazba cizí firmy se stejným číselným entity_id nesmí ovlivnit naši fakturu.
        $this->document($local, 'application/pdf', supplier: $foreign);
        self::assertSame([$local], $this->ids($this->list(['has_visual_document' => '0'])));
        self::assertSame([], $this->ids($this->list(['has_visual_document' => '1'])));
        self::assertSame([], $this->ids($this->repo->listGroupedByMonth(['supplier_id' => $foreign, 'has_visual_document' => '1'])));
    }

    public function testYearStatusPaginationAndTotals(): void
    {
        $expected = [];
        for ($i = 0; $i < 7; $i++) $expected[] = $this->invoice('scan.pdf');
        $this->invoice();
        $old = $this->invoice('scan.pdf');
        $this->pdo->prepare("UPDATE purchase_invoices SET issue_date = '2098-01-01' WHERE id = ?")->execute([$old]);
        $paid = $this->invoice('scan.pdf');
        $this->pdo->prepare("UPDATE purchase_invoices SET status = 'paid' WHERE id = ?")->execute([$paid]);
        foreach ([true, false] as $grouped) {
            $filters = ['has_visual_document' => '1', 'year' => 2099, 'status' => 'received', 'group_by_month' => $grouped];
            $first = $this->list($filters, 1, 5);
            $second = $this->list($filters, 2, 5);
            self::assertSame(7, $first['meta']['total']);
            self::assertSame(7, $second['meta']['total']);
            self::assertCount(5, $this->ids($first));
            self::assertCount(2, $this->ids($second));
            self::assertEqualsCanonicalizing($expected, [...$this->ids($first), ...$this->ids($second)]);
            self::assertEqualsWithDelta(500, $first['data'][0]['totals_per_currency'][0]['with_vat'], 0.001);
        }
    }

    public function testInvalidValuesAreRejectedBeforeQuerying(): void
    {
        foreach (['has_visual_document', 'has_structured_source'] as $key) {
            foreach (['', 'true', 'false', '2', '01', ' 1', ['1'], null] as $value) {
                $result = $this->call([$key => $value]);
                self::assertSame(422, $result['status'], $key . ': ' . json_encode($value));
                self::assertSame('validation_failed', $result['body']['error']['code']);
            }
        }
    }

    private function list(array $filters, int $page = 1, int $perPage = 50): array
    {
        return $this->repo->listGroupedByMonth(['supplier_id' => $this->supplier] + $filters, $page, $perPage, DocumentViewerContext::forUser($this->user));
    }

    private function call(array $filters, bool $readDocuments = true): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/purchase-invoices')
            ->withQueryParams(['filter' => $filters])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->user, 'role' => 'readonly'])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Synthetic reader', 'staff', true,
                ['purchase_invoices' => 1, 'documents' => $readDocuments ? 1 : 0]));
        $response = ($this->action)($request, new Response());
        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
    }

    private function ids(array $result): array
    {
        return array_merge([], ...array_map(static fn (array $g): array => array_column($g['invoices'], 'id'), $result['data']));
    }

    private function invoice(?string $pdf = null, ?string $sourceFormat = null): int
    {
        $number = 'SYN-' . ++$this->sequence;
        $this->pdo->prepare("INSERT INTO purchase_invoices
            (supplier_id, vendor_id, currency_id, created_by, varsymbol, vendor_invoice_number, vendor_snapshot,
             issue_date, due_date, received_at, status, total_without_vat, total_with_vat, pdf_path, source_path, source_format)
            VALUES (?, ?, ?, ?, ?, ?, '{}', '2099-01-01', '2099-01-15', '2099-01-01', 'received', 100, 100, ?, ?, ?)")
            ->execute([$this->supplier, $this->vendor, $this->currency, $this->user, $number, $number, $pdf,
                $sourceFormat === null ? null : 'synthetic-source', $sourceFormat]);
        return (int) $this->pdo->lastInsertId();
    }

    private function document(int $invoice, string $mime, string $scope = 'company', ?int $owner = null, ?int $supplier = null): int
    {
        $supplier ??= $this->supplier;
        $id = $this->documents->insert(['supplier_id' => $supplier, 'folder_id' => null,
            'title' => 'Synthetic attachment', 'description' => null, 'original_name' => 'synthetic',
            'filename' => 'synthetic', 'sha256' => hash('sha256', random_bytes(16)), 'mime_type' => $mime,
            'size_bytes' => 10, 'doc_type' => 'other', 'uploaded_by' => $this->user, 'scope' => $scope, 'owner_user_id' => $owner]);
        $this->pdo->prepare("INSERT INTO document_links (supplier_id, document_id, entity_type, entity_id)
            VALUES (?, ?, 'purchase_invoice', ?)")->execute([$supplier, $id, $invoice]);
        return $id;
    }

    private function user(): int
    {
        $this->pdo->prepare("INSERT INTO users (email, name, password_hash) VALUES (?, 'Synthetic reader', ?)")
            ->execute(['document-filter-' . bin2hex(random_bytes(8)) . '@example.test', str_repeat('x', 60)]);
        return (int) $this->pdo->lastInsertId();
    }

    private function company(): array
    {
        $country = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
        $vat = (int) $this->pdo->query('SELECT MIN(id) FROM vat_rates')->fetchColumn();
        // Stejný bootstrap cyklu supplier ↔ currencies jako v setup.php; ihned doplněn platný FK.
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->pdo->prepare("INSERT INTO supplier (company_name, street, city, zip, email, country_id, default_currency_id, default_vat_rate_id)
                VALUES ('Synthetic filter company', 'Test 1', 'Test', '11000', 'filter@example.test', ?, 0, ?)")->execute([$country, $vat]);
            $supplier = (int) $this->pdo->lastInsertId();
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, name_cs, name_en, symbol) VALUES (?, 'CZK', 'CZK', 'CZK', 'CZK', 'Kč')")->execute([$supplier]);
        $currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currency, $supplier]);
        return [$supplier, $currency];
    }
}
