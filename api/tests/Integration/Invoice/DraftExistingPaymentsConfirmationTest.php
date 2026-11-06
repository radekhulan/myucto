<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use DI\Container;
use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Migration\Shared\BankStatementImportWriter;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceReceiver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class DraftExistingPaymentsConfirmationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Container $container;
    private Connection $db;
    private int $supplierId;
    private int $clientId;
    private int $currencyId;
    private int $originalCurrencyId;
    private int $vatId;
    private int $userId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $mailer = $this->createMock(Mailer::class);
        foreach (['sendTemplate', 'sendTemplateDetailed', 'flushDeferred'] as $method) {
            $mailer->expects(self::never())->method($method);
        }
        $this->container->set(Mailer::class, $mailer);
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $original = $pdo->prepare('SELECT default_currency_id FROM supplier WHERE id=?');
        $original->execute([$this->supplierId]);
        $this->originalCurrencyId = (int) $original->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $this->vatId = (int) $pdo->query('SELECT id FROM vat_rates WHERE rate_percent=21 AND is_reverse_charge=0 ORDER BY valid_from DESC LIMIT 1')->fetchColumn();
        $cz = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn();
        $pdo->prepare("UPDATE supplier SET stock_enabled=0, accounting_mode='tax_evidence', dimensions_enabled=0,
            auto_post_invoices=0, auto_post_purchases=0, payment_thanks_enabled=1,
            payment_thanks_auto_send=1, payment_thanks_max_age_days=0 WHERE id=?")->execute([$this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $pdo->prepare('INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default)
            VALUES (?,"CZK","CZK","Kč","Česká koruna","Czech Koruna",2,1,1)')->execute([$this->supplierId]);
        $this->currencyId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$this->currencyId,$this->supplierId]);
        $pdo->prepare('INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,currency_default_id,main_email,language,is_customer,is_vendor)
            VALUES (?,"Syntetická protistrana","Testovací 1","Praha","11000",?,?,"customer@example.invalid","cs",1,1)')
            ->execute([$this->supplierId,$cz,$this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    #[DataProvider('coverage')]
    public function testIssuingUsesExistingPayments(float $paid, float $advance, bool $imported, string $expected): void
    {
        $id = $this->invoice($advance);
        if ($imported) $this->markImported('issued',$id);
        if ($paid > 0) $this->db->pdo()->prepare('INSERT INTO invoice_payments(supplier_id,invoice_id,amount,currency,paid_on,source)
            VALUES (?,?,?,"CZK","2025-01-15","legacy")')->execute([$this->supplierId,$id,$paid]);
        self::assertSame('draft', $this->document('invoices',$id)['status']);
        $before = $this->sideEffects();
        $body = $this->action(IssueInvoiceAction::class,$id);
        self::assertSame($expected,$body['status']);
        $invoice = $this->document('invoices',$id);
        self::assertSame($expected,$invoice['status']);
        self::assertSame($expected === 'paid' ? '2025-01-15' : null, $invoice['paid_at']);
        self::assertEquals($paid,$invoice['paid_total']);
        self::assertSame($before,$this->sideEffects(),'Potvrzení musí použít existující úhrady, bez nových plateb nebo účetních pohybů.');
    }

    #[DataProvider('coverage')]
    public function testReceivingUsesExistingPayments(float $paid, float $advance, bool $imported, string $expected): void
    {
        $id = $this->purchase($advance);
        if ($imported) $this->markImported('purchase',$id);
        if ($paid > 0) $this->payPurchase($id,$paid);
        self::assertSame('draft',$this->document('purchase_invoices',$id)['status']);
        $before = $this->sideEffects();
        $body = $this->action(TransitionPurchaseInvoiceStatusAction::class,$id,['target'=>'received']);
        $expected = $expected === 'issued' ? 'received' : $expected;
        self::assertSame($expected,$body['status']);
        $doc = $this->document('purchase_invoices',$id);
        self::assertSame($expected,$doc['status']);
        self::assertSame($expected === 'paid' ? '2025-01-15' : null,$doc['paid_at']);
        self::assertSame($before,$this->sideEffects());
    }

    public static function coverage(): array
    {
        return ['nativní plná'=>[121.0,0.0,false,'paid'], 'převzatá plná'=>[121.0,0.0,true,'paid'],
            'převzatá částečná'=>[50.0,0.0,true,'issued'], 'doplatek po záloze'=>[50.0,71.0,false,'paid'],
            'bez úhrady'=>[0.0,0.0,false,'issued']];
    }

    public function testApprovalReceiverSettlesOnlyConfirmedDraft(): void
    {
        $id = $this->purchase();
        $this->markImported('purchase',$id);
        $this->payPurchase($id,121);
        self::assertSame('draft',$this->document('purchase_invoices',$id)['status']);
        $before = $this->sideEffects();
        self::assertTrue($this->container->get(PurchaseInvoiceReceiver::class)->receiveDraft($this->supplierId,$id,$this->userId));
        self::assertSame('paid',$this->document('purchase_invoices',$id)['status']);
        self::assertSame('2025-01-15',$this->document('purchase_invoices',$id)['paid_at']);
        self::assertSame($before,$this->sideEffects());
    }

    public function testManualReturnFromPaidRemainsReceived(): void
    {
        $id = $this->purchase();
        $this->payPurchase($id,121);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status='paid',paid_at='2025-01-15' WHERE id=?")->execute([$id]);
        $body = $this->action(TransitionPurchaseInvoiceStatusAction::class,$id,['target'=>'received']);
        self::assertSame('received',$body['status']);
        self::assertNull($this->document('purchase_invoices',$id)['paid_at']);
    }

    public function testFullyAdvanceCoveredInvoiceKeepsExistingIssuePolicy(): void
    {
        $parent = $this->invoice(0);
        $this->db->pdo()->prepare("UPDATE invoices SET invoice_type='proforma',status='paid',paid_at='2025-01-15' WHERE id=?")->execute([$parent]);
        $id = $this->invoice(121);
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id=? WHERE id=?')->execute([$parent, $id]);
        $body = $this->action(IssueInvoiceAction::class, $id);
        self::assertSame('paid', $body['status']);
        self::assertSame(date('Y-m-d'), $this->document('invoices', $id)['paid_at']);
        self::assertSame(0, $this->sideEffects()['invoice_payments']);
    }

    public function testZeroDuePurchaseNeedsPaidLinkedAdvance(): void
    {
        $advance = $this->purchase();
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind='advance',status='paid',paid_at='2025-01-15' WHERE id=?")
            ->execute([$advance]);
        $final = $this->purchase(121);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id=? WHERE id=?')->execute([$advance, $final]);
        $body = $this->action(TransitionPurchaseInvoiceStatusAction::class, $final, ['target' => 'received']);
        self::assertSame('paid', $body['status']);
        self::assertSame('2025-01-15', $this->document('purchase_invoices', $final)['paid_at']);
    }

    public function testCachedPaidTotalWithoutPaymentDoesNotSettleInvoice(): void
    {
        $id = $this->invoice(0);
        $this->db->pdo()->prepare('UPDATE invoices SET paid_total=121 WHERE id=?')->execute([$id]);
        $body = $this->action(IssueInvoiceAction::class, $id);
        self::assertSame('issued', $body['status']);
        self::assertEquals(0, $this->document('invoices', $id)['paid_total']);
    }

    public function testZeroDueWithoutLinkedAdvanceDoesNotInventPaymentDate(): void
    {
        $id = $this->purchase(121);
        $body = $this->action(TransitionPurchaseInvoiceStatusAction::class, $id, ['target' => 'received']);
        self::assertSame('received', $body['status']);
        self::assertNull($this->document('purchase_invoices', $id)['paid_at']);
    }

    #[DataProvider('issueEntrypoints')]
    public function testMultiplePaymentsUseLatestDateInEveryIssuePath(string $path): void
    {
        $id = $this->invoice(0);
        $this->db->pdo()->prepare('INSERT INTO invoice_payments(supplier_id,invoice_id,amount,currency,paid_on,source)
            VALUES (?,?,60,"CZK","2025-01-10","legacy"),(?,?,61,"CZK","2025-01-20","legacy")')
            ->execute([$this->supplierId, $id, $this->supplierId, $id]);
        $before = $this->sideEffects();
        if ($path === 'http') {
            $this->action(IssueInvoiceAction::class, $id);
        } else {
            $this->db->pdo()->prepare('UPDATE clients SET main_email="" WHERE id=?')->execute([$this->clientId]);
            $renderer = $this->createMock(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class);
            $renderer->method('render')->willReturn('synthetic.pdf');
            $this->container->set(\MyInvoice\Service\Pdf\InvoicePdfRenderer::class, $renderer);
            if ($path === 'auto') {
                $this->container->get(\MyInvoice\Service\Invoice\AutoIssueAndSendService::class)
                    ->run($id, $this->userId, '127.0.0.1', 'synthetic');
            } else {
                $generator = $this->container->get(\MyInvoice\Service\Invoice\RecurringInvoiceGenerator::class);
                (new \ReflectionMethod($generator, 'issueOnlyWithoutSend'))
                    ->invoke($generator, $id, $this->userId, $this->userId, '127.0.0.1', 'synthetic');
            }
        }
        $invoice = $this->document('invoices', $id);
        self::assertSame('paid', $invoice['status']);
        self::assertSame('2025-01-20', $invoice['paid_at']);
        self::assertEquals(121, $invoice['paid_total']);
        self::assertSame($before, $this->sideEffects());
    }

    public static function issueEntrypoints(): array
    {
        return [['http'], ['auto'], ['recurring']];
    }

    public function testReceiveUsesPaymentsCommittedAfterCallersSnapshot(): void
    {
        $id = $this->purchase();
        $this->payPurchase($id, 121);
        [$other, $repository] = Connection::withoutSharedTestConnection(static function (): array {
            $container = Bootstrap::buildContainer();
            return [$container->get(Connection::class), $container->get(\MyInvoice\Repository\PurchaseInvoiceRepository::class)];
        });
        $a = $this->db->pdo();
        $b = $other->pdo();
        self::assertNotSame($a, $b);
        $committed = false;
        try {
            $b->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $b->exec('SET SESSION innodb_snapshot_isolation = OFF');
            $b->beginTransaction();
            $q = $b->prepare('SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id=?');
            $q->execute([$this->supplierId]);
            self::assertSame(0, (int) $q->fetchColumn());
            $a->commit();
            $committed = true;
            self::assertTrue($repository->setStatus($id, 'received', $this->supplierId));
            $q = $b->prepare('SELECT status,paid_at FROM purchase_invoices WHERE supplier_id=? AND id=?');
            $q->execute([$this->supplierId, $id]);
            self::assertSame(['status' => 'paid', 'paid_at' => '2025-01-15'], $q->fetch(PDO::FETCH_ASSOC));
        } finally {
            if ($b->inTransaction()) $b->rollBack();
            $other->close();
            if ($committed) {
                // Jen vlastní syntetický tenant; tento test musí kvůli dvěma snímkům commitnout fixture.
                // Smazání výpisu kaskádou odstraní jeho pohyby; bank_transactions nemá supplier_id.
                foreach (['payment_matches', 'bank_statements', 'purchase_invoices', 'clients'] as $table) {
                    $a->prepare("DELETE FROM {$table} WHERE supplier_id=?")->execute([$this->supplierId]);
                }
                $a->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$this->originalCurrencyId, $this->supplierId]);
                $a->prepare('DELETE FROM currencies WHERE supplier_id=?')->execute([$this->supplierId]);
                $a->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplierId]);
            }
        }
    }

    private function invoice(float $advance): int
    {
        $pdo=$this->db->pdo(); $date=date('Y-m-d');
        $pdo->prepare('INSERT INTO invoices(supplier_id,invoice_type,client_id,issue_date,tax_date,due_date,currency_id,language,status,
            total_without_vat,total_vat,total_with_vat,advance_paid_amount,created_by)
            VALUES (?,"invoice",?,?,?,?,?,"cs","draft",100,21,121,?,?)')
            ->execute([$this->supplierId,$this->clientId,$date,$date,$date,$this->currencyId,$advance,$this->userId]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO invoice_items(invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,
            total_without_vat,total_vat,total_with_vat,order_index) VALUES (?,"Syntetická služba",1,"ks",100,?,21,100,21,121,0)')->execute([$id,$this->vatId]);
        return $id;
    }

    private function purchase(float $advance=0): int
    {
        $pdo=$this->db->pdo();
        $pdo->prepare('INSERT INTO purchase_invoices(supplier_id,vendor_id,vendor_invoice_number,varsymbol,vendor_snapshot,document_kind,issue_date,tax_date,due_date,received_at,
            currency_id,status,total_without_vat,total_vat,total_with_vat,advance_paid_amount,created_by)
            VALUES (?,?,?,?,"{}","invoice","2025-01-01","2025-01-01","2025-01-31","2025-01-01",?,"draft",100,21,121,?,?)')
            ->execute([$this->supplierId,$this->clientId,'SYN-DRAFT-' . bin2hex(random_bytes(4)), 'SYN-DRAFT-' . bin2hex(random_bytes(4)), $this->currencyId,$advance,$this->userId]);
        return (int)$pdo->lastInsertId();
    }

    private function payPurchase(int $id,float $amount): void
    {
        $writer=new BankStatementImportWriter($this->db,'synthetic-confirmation');
        $statement=$writer->createStatement($this->supplierId,'statement','SYN-BANK','1000000005','0100','CZK','2025-01-15',$this->userId);
        $transaction=$writer->insertTransaction($this->supplierId,$statement,'payment',['source_ref'=>'synthetic','posted_at'=>'2025-01-15',
            'amount'=>number_format(-$amount,2,'.',''),'currency'=>'CZK','bank_ref'=>null]);
        $this->db->pdo()->prepare('INSERT INTO payment_matches(supplier_id,bank_transaction_id,purchase_invoice_id,amount,match_type)
            VALUES (?,?,?,?,"manual")')->execute([$this->supplierId,$transaction,$id,$amount]);
    }

    private function markImported(string $kind,int $id): void
    {
        $this->db->pdo()->prepare('INSERT INTO stereo_nx_import_map(supplier_id,source_ico,source_company_index,kind,source_key,source_hash,target_id)
            VALUES (?,"12345678",0,?,"synthetic",?,?)')->execute([$this->supplierId,$kind,str_repeat('a',64),$id]);
    }

    private function action(string $class,int $id,array $body=[]): array
    {
        $request=(new ServerRequestFactory())->createServerRequest('POST','/synthetic-confirmation')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->userId,'role'=>'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD,'session')->withParsedBody($body);
        $response=($this->container->get($class))($request,new Response(),['id'=>(string)$id]);
        $json=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);
        self::assertSame(200,$response->getStatusCode(),json_encode($json,JSON_UNESCAPED_UNICODE));
        return $json['data'] ?? $json;
    }

    private function document(string $table,int $id): array
    {
        $query=$this->db->pdo()->prepare("SELECT * FROM {$table} WHERE supplier_id=? AND id=?");
        $query->execute([$this->supplierId,$id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    private function sideEffects(): array
    {
        $counts=[];
        foreach (['invoices','purchase_invoices','invoice_payments','payment_matches','cash_documents','journal_entries','stock_documents'] as $table) {
            $q=$this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id=?");
            $q->execute([$this->supplierId]); $counts[$table]=(int)$q->fetchColumn();
        }
        $counts['mail_outbox'] = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn();
        return $counts;
    }
}
