<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\PayrollPostingImportAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\Import\PamicaPosting\PamicaPostingCodeMap;
use MyInvoice\Service\Import\PamicaPosting\PamicaPostingException;
use MyInvoice\Service\Import\PamicaPosting\PamicaPostingImportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

/**
 * Měsíční zaúčtování mezd z PAMICY (migrace 2300): náhled, načtení interního dokladu
 * a ostatních závazků přes jejich agendy, závazky bez zaúčtování, ochrana proti druhému
 * načtení, cizí IČO, zamčený měsíc, chybějící účty a import faktur, který z téhož souboru
 * nesmí založit přijaté faktury. Fixture je syntetický dataPack ve Windows-1250.
 */
#[Group('integration')]
final class PamicaPostingImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const FIXTURE = __DIR__ . '/../../Fixtures/pamica/zauctovani-mezd-2099-03.xml';
    private const ICO = '87654321';

    private ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private PamicaPostingImportService $service;
    private int $supplierId;
    private int $userId;
    private string $xml;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('Test vyžaduje místní databázi.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $this->service = $this->container->get(PamicaPostingImportService::class);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->xml = (string) file_get_contents(self::FIXTURE);
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', ic = ? WHERE id = ?")
            ->execute([self::ICO, $this->supplierId]);
        $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en) VALUES (?, 'CZK', 'Syntetická CZK', 'Kč', 'Koruna', 'Crown')")
            ->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testFixtureIsWindows1250(): void
    {
        self::assertStringContainsString('encoding="Windows-1250"', $this->xml);
        self::assertFalse(mb_check_encoding($this->xml, 'UTF-8'), 'Fixture musí být v kódování Windows-1250, ne UTF-8.');
    }

    public function testPreviewDescribesDocumentsWithoutWriting(): void
    {
        $preview = $this->service->preview($this->supplierId, $this->xml);

        self::assertSame('MZ209903', $preview['source']['sym_par']);
        self::assertSame('2099-03', $preview['source']['period']);
        self::assertSame(self::ICO, $preview['source']['ico']);
        self::assertCount(1, $preview['internal_documents']);
        self::assertSame(30000.0, $preview['internal_documents'][0]['amount']);
        self::assertSame('Mzdy 2099/03, zaúčtování mzdových nákladů - hrubá mzda', $preview['internal_documents'][0]['title']);
        self::assertCount(6, $preview['commitments']);
        self::assertSame(['posted', 'posted', 'posted', 'without_posting', 'without_posting', 'without_posting'],
            array_column($preview['commitments'], 'posting'));
        self::assertSame(['company', 'company', 'company', 'person', 'person', 'none'], array_column($preview['commitments'], 'partner_kind'));
        self::assertSame('1000000005/0100', $preview['commitments'][3]['account']);
        self::assertSame('0138', $preview['commitments'][3]['constant_symbol']);
        self::assertSame(['internal' => 30000.0, 'commitments_posted' => 16620.0, 'commitments_without_posting' => 23520.0],
            array_intersect_key($preview['totals'], array_flip(['internal', 'commitments_posted', 'commitments_without_posting'])));
        self::assertSame(0.0, $preview['totals']['clearing']['331000']['difference']);

        $levels = array_column($preview['checks'], 'level', 'code');
        self::assertSame('ok', $levels['ico_match']);
        self::assertSame('ok', $levels['period_open']);
        self::assertSame('ok', $levels['not_imported_yet']);
        self::assertSame('ok', $levels['clearing_balanced']);
        // Osnova ze šablony člení 336 a 342 analyticky: PAMICA analytiky import nabídne založit.
        self::assertSame('error', $levels['accounts_creatable']);
        $byCode = array_column($preview['accounts'], null, 'source');
        self::assertSame('336.001', $byCode['336001']['proposed']);
        self::assertSame('342.000', $byCode['342000']['proposed']);
        self::assertSame('521', $byCode['521000']['target']);
        self::assertSame('331', $byCode['331000']['target']);
        self::assertFalse($preview['can_apply']);
        self::assertTrue($preview['can_apply_with_new_accounts']);

        self::assertSame(0, $this->rowCount('other_items'));
        self::assertSame(0, $this->rowCount('internal_documents'));
        self::assertSame(0, $this->rowCount('payroll_posting_imports'));
    }

    /** Kontrola zúčtování mezd píše účet tak, jak je v osnově firmy (331.000), ne kód PAMICY (331000). */
    public function testClearingCheckNamesAccountAsInChart(): void
    {
        $this->analytic('331.000');
        $messages = array_column($this->service->preview($this->supplierId, $this->xml)['checks'], 'message', 'code');
        self::assertStringStartsWith('Účet 331.000: hrubé mzdy', $messages['clearing_balanced']);

        $unbalanced = str_replace('<typ:priceNone>12000</typ:priceNone>', '<typ:priceNone>12100</typ:priceNone>', $this->xml);
        $messages = array_column($this->service->preview($this->supplierId, $unbalanced)['checks'], 'message', 'code');
        self::assertStringStartsWith('Účet 331.000 po načtení nebude vyrovnaný', $messages['clearing_difference'] ?? '');
    }

    public function testApplyPostsInternalDocumentAndCommitments(): void
    {
        $result = $this->service->apply($this->supplierId, $this->xml, 'mzdy.xml', true, $this->audit());

        self::assertEqualsCanonicalizing(['336.000', '336.001', '342.000'], $result['created_accounts']);

        // Interní doklad: hrubá mzda 521/331 v deníku.
        self::assertCount(1, $result['internal_documents']);
        $doc = $this->row('SELECT * FROM internal_documents WHERE id = ? AND supplier_id = ?', [$result['internal_documents'][0]['id'], $this->supplierId]);
        self::assertSame('posted', $doc['status']);
        self::assertSame('2099-03-31', $doc['entry_date']);
        self::assertSame(['331:credit:30000.00', '521:debit:30000.00'],$this->entryLines((int) $doc['journal_entry_id']));

        // Ostatní závazky se zaúčtováním: Dal na účtu závazku, MD z položek.
        $items = $this->items();
        self::assertCount(6, $items);
        self::assertSame(['posted', 'posted', 'posted', 'confirmed', 'confirmed', 'confirmed'], array_column($items, 'status'));
        self::assertSame(['331:debit:3000.00', '342.000:credit:3000.00'], $this->entryLines((int) $items[0]['journal_entry_id']));
        self::assertSame(['331:debit:2130.00', '336.001:credit:9570.00', '524:debit:7440.00'], $this->entryLines((int) $items[1]['journal_entry_id']));
        self::assertSame(['331:debit:1350.00', '336.000:credit:4050.00', '524:debit:2700.00'], $this->entryLines((int) $items[2]['journal_entry_id']));
        self::assertSame('Finanční úřad pro Testovací kraj', $items[0]['partner_name']);
        self::assertSame('87654321', $items[0]['variable_symbol']);
        self::assertSame(['3000000004', '0710'], [$items[0]['counterparty_account'], $items[0]['counterparty_bank_code']]);
        self::assertSame('2099-04-20', $items[0]['due_on']);
        self::assertStringContainsString('KS 1148', (string) $items[0]['note']);

        // Závazky bez zaúčtování (BEZ331000): potvrzené, bez vlastního zápisu, na účtu 331.
        foreach ([3, 4, 5] as $i) {
            self::assertNull($items[$i]['journal_entry_id']);
            self::assertSame('331', $items[$i]['account_code']);
            self::assertNotNull($items[$i]['document_no']);
        }
        self::assertSame(['12000.00', '8000.00', '3520.00'], [$items[3]['amount'], $items[4]['amount'], $items[5]['amount']]);
        self::assertSame(['1000000005', '0100'], [$items[3]['counterparty_account'], $items[3]['counterparty_bank_code']]);
        self::assertNull($items[5]['partner_id']);

        // Karta zaměstnance nese jen jméno, adresu bydliště import nepřebírá.
        $person = $this->row('SELECT company_name, street, city, zip, is_vendor FROM clients WHERE id = ? AND supplier_id = ?', [$items[3]['partner_id'], $this->supplierId]);
        self::assertSame('Testovací Jana', $person['company_name']);
        self::assertSame('', (string) $person['street']);
        self::assertSame('', (string) $person['city']);

        // Účet 331 po načtení: hrubé mzdy minus srážky = čisté mzdy k úhradě (závazky bez zaúčtování).
        self::assertSame('23520.00', $this->balance('331'));
        self::assertSame(0.0, round(array_sum(array_map(fn (array $i): float => $this->entryTotal($i['journal_entry_id']), $items)) - 16620.0, 2));
        self::assertSame(1, $this->rowCount('payroll_posting_imports'));
    }

    public function testSecondImportOfSameMonthIsRejected(): void
    {
        $this->service->apply($this->supplierId, $this->xml, null, true, $this->audit());
        $before = $this->rowCount('other_items');

        $preview = $this->service->preview($this->supplierId, $this->xml);
        self::assertSame('error', array_column($preview['checks'], 'level', 'code')['already_imported']);
        try {
            $this->service->apply($this->supplierId, str_replace('id="00042"', 'id="00043"', $this->xml), null, true, $this->audit());
            self::fail('Druhé načtení téhož měsíce musí být odmítnuto.');
        } catch (PamicaPostingException $e) {
            self::assertSame('already_imported', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        self::assertSame($before, $this->rowCount('other_items'));
        self::assertSame(1, $this->rowCount('internal_documents'));
    }

    public function testMonthCanBeImportedAgainOnlyAfterAllDocumentsAreReversed(): void
    {
        $first = $this->service->apply($this->supplierId, $this->xml, null, true, $this->audit());
        $this->container->get(\MyInvoice\Service\Accounting\InternalDocumentService::class)
            ->reverse($this->supplierId, $first['internal_documents'][0]['id'], 'Oprava exportu', null, $this->audit());
        $items = $this->container->get(\MyInvoice\Service\Accounting\OtherItemService::class);
        $last = array_pop($first['other_items']);
        foreach ($first['other_items'] as $item) {
            $items->reverse($this->supplierId, $item['id'], 'Oprava exportu', null);
        }
        // Jeden platný závazek z prvního načtení stačí, aby druhé načtení zdvojilo výplatu.
        $this->expectApplyErrorKeepingData('already_imported');

        $items->reverse($this->supplierId, $last['id'], 'Oprava exportu', null);
        $second = $this->service->apply($this->supplierId, $this->xml, null, true, $this->audit());
        self::assertNotSame($first['import_id'], $second['import_id']);
        self::assertSame(1, $this->rowCount('payroll_posting_imports'));
        self::assertSame(2, $this->rowCount('internal_documents'));
    }

    private function expectApplyErrorKeepingData(string $code): void
    {
        try {
            $this->service->apply($this->supplierId, $this->xml, null, true, $this->audit());
            self::fail('Načtení mělo být odmítnuto (' . $code . ').');
        } catch (PamicaPostingException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    public function testForeignIcoIsRejected(): void
    {
        $this->pdo->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute(['11223344', $this->supplierId]);
        $preview = $this->service->preview($this->supplierId, $this->xml);
        self::assertSame('error', array_column($preview['checks'], 'level', 'code')['ico_mismatch']);
        $this->expectApplyError('ico_mismatch');
    }

    public function testLockedMonthIsRejected(): void
    {
        $this->pdo->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, '2099-03-31']);
        $preview = $this->service->preview($this->supplierId, $this->xml);
        self::assertSame('error', array_column($preview['checks'], 'level', 'code')['period_locked']);
        $this->expectApplyError('period_locked');
    }

    public function testMissingSyntheticAccountIsReported(): void
    {
        $xml = str_replace('331000/342000', '331000/349000', $this->xml);
        $preview = $this->service->preview($this->supplierId, $xml);
        $check = array_column($preview['checks'], null, 'code')['accounts_missing'];
        self::assertSame('error', $check['level']);
        self::assertStringContainsString('349', $check['message']);
        self::assertFalse($preview['can_apply_with_new_accounts']);
        try {
            $this->service->apply($this->supplierId, $xml, null, true, $this->audit());
            self::fail('Chybějící syntetika musí import zastavit.');
        } catch (PamicaPostingException $e) {
            self::assertSame('accounts_missing', $e->errorCode);
        }
        // Bez souhlasu se založením analytik se nic nezaloží.
        $this->expectApplyError('accounts_creatable', false);
        self::assertSame(0, $this->countWhere('chart_of_accounts', "account_code IN ('336.000','336.001','342.000')"));
    }

    public function testInvoiceImportSkipsPayrollPostingWithoutCreatingPurchaseInvoices(): void
    {
        $report = $this->container->get(InvoiceImportService::class)->importBundle(
            [['name' => 'mzdy.xml', 'content' => $this->xml]], $this->supplierId, $this->userId, 'auto',
        );
        self::assertSame(0, $report['summary']['created'] ?? 0);
        self::assertCount(1, $report['results']);
        self::assertSame('skipped', $report['results'][0]['status']);
        self::assertSame('payroll_posting', $report['results'][0]['code']);
        self::assertSame(0, $this->rowCount('purchase_invoices'));
        self::assertSame(0, $this->rowCount('invoices'));
    }

    public function testActionDetectsOtherFormatsAndChecksPermissions(): void
    {
        $action = $this->container->get(PayrollPostingImportAction::class);
        $all = ['accounting' => 2, 'other_items' => 2, 'accounting.journal.write' => 2, 'accounting.journal.post' => 2];

        $zip = $action->preview($this->request($all, 'export.xml', '<?xml version="1.0"?><dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" application="POHODA" ico="' . self::ICO . '"/>'), new Response());
        self::assertSame(422, $zip->getStatusCode());
        self::assertSame('not_payroll_posting', $this->error($zip));

        $ok = $action->preview($this->request($all, 'mzdy.xml', $this->xml), new Response());
        self::assertSame(200, $ok->getStatusCode());
        self::assertTrue($this->data($ok)['can_write']);

        $denied = $action->apply($this->request(['accounting' => 2, 'accounting.journal.write' => 2, 'accounting.journal.post' => 2], 'mzdy.xml', $this->xml), new Response());
        self::assertSame(403, $denied->getStatusCode());
        // Založení analytik chce navíc právo k osnově (`accounting` zápis).
        $noChart = $action->apply($this->request(['accounting' => 1] + $all, 'mzdy.xml', $this->xml, true), new Response());
        self::assertSame(403, $noChart->getStatusCode());
        self::assertSame(0, $this->rowCount('other_items'));

        $applied = $action->apply($this->request($all, 'mzdy.xml', $this->xml, true), new Response());
        self::assertSame(201, $applied->getStatusCode());
        self::assertSame(1, $this->countWhere('activity_log', "action = 'payroll_posting.imported'"));
    }

    public function testCodesNeedMapPerSupplierAndSavedMapIsApplied(): void
    {
        $xml = strtr($this->xml, ['521000/331000' => '7Int', '331000/342000' => '2Oz']);
        self::assertSame(['7Int', '2Oz'], array_column($this->unmappedCodes($xml), 'code'));

        $map = $this->container->get(PamicaPostingCodeMap::class);
        $liability = (string) $this->pdo->query("SELECT account_code FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code LIKE '342.%' ORDER BY account_code LIMIT 1")->fetchColumn();
        self::assertNotSame('', $liability);
        foreach ([
            [['code' => '7Int', 'debit_account_code' => '521', 'credit_account_code' => '521']],
            [['code' => '7Int', 'debit_account_code' => '521', 'credit_account_code' => '999']],
            [['code' => '7Int', 'debit_account_code' => '', 'credit_account_code' => '331']],
            [['code' => '7/Int', 'debit_account_code' => '521', 'credit_account_code' => '331']],
        ] as $invalid) {
            try {
                $map->save($this->supplierId, PamicaPostingImportService::APPLICATION, $invalid, null);
                self::fail('Neplatné mapování se nesmí uložit.');
            } catch (PamicaPostingException $e) {
                self::assertSame('invalid_code_map', $e->errorCode);
            }
        }
        self::assertSame(0, $this->countWhere('payroll_posting_code_maps', '1 = 1'));

        $action = $this->container->get(PayrollPostingImportAction::class);
        $all = ['accounting' => 2, 'other_items' => 2, 'accounting.journal.write' => 2, 'accounting.journal.post' => 2];
        $codes = ['codes' => [
            ['code' => '7int', 'debit_account_code' => '521', 'credit_account_code' => '331'],
            ['code' => '2Oz', 'debit_account_code' => '331', 'credit_account_code' => $liability],
        ]];
        self::assertSame(403, $action->saveCodeMap($this->jsonRequest(['other_items' => 1] + $all, $codes), new Response())->getStatusCode());
        $saved = $action->saveCodeMap($this->jsonRequest($all, $codes), new Response());
        self::assertSame(200, $saved->getStatusCode());
        self::assertCount(2, $this->data($saved)['code_map']);
        // Úprava přepíše řádek zkratky (bez ohledu na velikost písmen), druhý nezaloží.
        $codes['codes'][0]['code'] = '7Int';
        self::assertSame(200, $action->saveCodeMap($this->jsonRequest($all, $codes), new Response())->getStatusCode());
        self::assertSame(2, $this->countWhere('payroll_posting_code_maps', '1 = 1'));

        $preview = $this->service->preview($this->supplierId, $xml);
        self::assertEqualsCanonicalizing(['7Int', '2Oz'], array_column($preview['code_map'], 'code'));
        $line = $preview['internal_documents'][0]['lines'][0];
        self::assertSame(['7Int', '521', '331'], [$line['code'], $line['debit'], $line['credit']]);
        // 331 ze zkratky a BEZ331000 jsou tentýž účet: kontrola zúčtování mezd sedí.
        self::assertSame(0.0, $preview['totals']['clearing']['331000']['difference']);
        self::assertSame('ok', array_column($preview['checks'], 'level', 'code')['clearing_balanced']);

        $result = $this->service->apply($this->supplierId, $xml, 'mzdy.xml', true, $this->audit());
        $doc = $this->row('SELECT journal_entry_id FROM internal_documents WHERE id = ? AND supplier_id = ?', [$result['internal_documents'][0]['id'], $this->supplierId]);
        self::assertSame(['331:credit:30000.00', '521:debit:30000.00'], $this->entryLines((int) $doc['journal_entry_id']));
        self::assertSame(['331:debit:3000.00', $liability . ':credit:3000.00'], $this->entryLines((int) $this->items()[0]['journal_entry_id']));

        // Mapování patří firmě: jiná firma tytéž zkratky přiřazené nemá.
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $this->supplierId);
        self::assertSame(['7Int', '2Oz'], array_column($this->unmappedCodes($xml), 'code'));
    }

    /**
     * Zkratky předkontací ve všech dokladech fixture: OSSZ (003) má dvě položky se dvěma
     * zkratkami (aOz za firmu, 8Oz za zaměstnance), zdravotní pojištění zůstává dvojicí účtů.
     */
    private function codedXml(): string
    {
        return strtr($this->xml, [
            '521000/331000' => '7Int', '331000/342000' => '2Oz',
            '524000/336001' => 'aOz', '331000/336001' => '8Oz',
        ]);
    }

    /** @param list<array{0:string,1:string,2:string}> $rows zkratka, MD, Dal */
    private function saveCodes(array $rows): void
    {
        $this->container->get(PamicaPostingCodeMap::class)->save($this->supplierId, PamicaPostingImportService::APPLICATION,
            array_map(static fn (array $r): array => ['code' => $r[0], 'debit_account_code' => $r[1], 'credit_account_code' => $r[2]], $rows), null);
    }

    private function analytic(string $code): void
    {
        $repo = $this->container->get(ChartOfAccountsRepository::class);
        if ($repo->findByCode($this->supplierId, $code) !== null) return;
        $parent = $repo->findByCode($this->supplierId, substr($code, 0, 3));
        self::assertNotNull($parent, 'Syntetika ' . substr($code, 0, 3) . ' v osnově chybí.');
        $repo->insert($this->supplierId, [
            'account_code' => $code, 'name' => 'Analytika ' . $code, 'account_type' => (string) $parent['account_type'],
            'normal_side' => $parent['normal_side'], 'is_synthetic' => false, 'parent_id' => (int) $parent['id'], 'is_active' => true,
        ], '2000-01-01', null);
    }

    public function testDifferentCreditAccountsNameCodesAndSyntheticWithAnalyticsIsRefused(): void
    {
        $xml = $this->codedXml();
        // Stav z praxe: 8Oz uložené na syntetiku 331, než osnova dostala analytiku 331.000.
        $this->saveCodes([['7Int', '521', '331'], ['2Oz', '331', '342.100'], ['8Oz', '524', '331']]);
        $this->analytic('331.000');
        $this->saveCodes([['aOz', '524', '331.000']]);

        try {
            $this->service->preview($this->supplierId, $xml);
            self::fail('Závazek s položkami na různých účtech Dal se nesmí načíst.');
        } catch (PamicaPostingException $e) {
            self::assertSame('invalid_accounting', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
            self::assertStringContainsString('Závazek 003 má položky na různých účtech Dal (331.000 ze zkratky aOz; 331 ze zkratky 8Oz)', $e->getMessage());
            self::assertStringContainsString('Upravte přiřazení zkratek.', $e->getMessage());
            self::assertSame(['aOz', '8Oz'], $e->context['codes']);
            self::assertSame(['331.000', '331'], $e->context['credits']);
        }

        // Syntetiku s aktivní analytikou už přiřazení nepřijme.
        try {
            $this->saveCodes([['8Oz', '331', '336']]);
            self::fail('Syntetika s analytikami se nesmí uložit.');
        } catch (PamicaPostingException $e) {
            self::assertSame('invalid_code_map', $e->errorCode);
            self::assertStringContainsString('Vyberte analytický účet (např. 331.000).', $e->getMessage());
        }
        $stored = array_column($this->container->get(PamicaPostingCodeMap::class)->rows($this->supplierId, PamicaPostingImportService::APPLICATION), null, 'code');
        self::assertSame(['524', '331'], [$stored['8Oz']['debit_account_code'], $stored['8Oz']['credit_account_code']]);
    }

    public function testCodeMapEndpointReturnsSavedRows(): void
    {
        $action = $this->container->get(PayrollPostingImportAction::class);
        $all = ['accounting' => 2, 'other_items' => 2, 'accounting.journal.write' => 2, 'accounting.journal.post' => 2];
        self::assertSame([], $this->data($action->codeMap($this->jsonRequest($all, []), new Response()))['code_map']);

        $this->saveCodes([['7Int', '521', '331'], ['2Oz', '331', '342.100']]);
        $response = $action->codeMap($this->jsonRequest($all, []), new Response());
        self::assertSame(200, $response->getStatusCode());
        $rows = $this->data($response)['code_map'];
        self::assertSame(['2Oz', '7Int'], array_column($rows, 'code'));
        self::assertSame(['331', '342.100'], [$rows[0]['debit_account_code'], $rows[0]['credit_account_code']]);

        // Čte ho jen ten, kdo ho smí uložit.
        self::assertSame(403, $action->codeMap($this->jsonRequest(['other_items' => 1] + $all, []), new Response())->getStatusCode());
        // Mapování patří firmě.
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $this->supplierId);
        self::assertSame([], $this->data($action->codeMap($this->jsonRequest($all, []), new Response()))['code_map']);
    }

    public function testCommitmentOnGrossWageAccountBlocksImport(): void
    {
        $xml = $this->codedXml();
        $this->analytic('342.000');
        // Prohozené účty u daně (342/331) a pojistné na Dal 331 místo 336.
        $this->saveCodes([['7Int', '521', '331'], ['2Oz', '342.000', '331'], ['8Oz', '524', '331'], ['aOz', '524', '331']]);

        $preview = $this->service->preview($this->supplierId, $xml);
        $checks = array_values(array_filter($preview['checks'], static fn (array $c): bool => $c['code'] === 'commitment_on_clearing_account'));
        self::assertSame(['002', '003'], array_column(array_column($checks, 'context'), 'item_id'));
        self::assertSame('error', $checks[0]['level']);
        self::assertSame('Závazek „Mzdy 2099/03, daň z příjmů, zálohová" by ležel na účtu 331, na který se účtují hrubé mzdy. '
            . 'Zkontrolujte zkratku 2Oz (srážka ze mzdy se účtuje obvykle MD 331 / Dal účet závazku, např. 342 nebo 336).', $checks[0]['message']);
        self::assertSame(['aOz', '8Oz'], $checks[1]['context']['codes']);
        self::assertFalse($preview['can_apply']);
        self::assertFalse($preview['can_apply_with_new_accounts']);

        $this->expectApplyErrorKeepingDataFor($xml, 'commitment_on_clearing_account');
    }

    public function testCorrectCodeMapWithAnalyticsPasses(): void
    {
        $xml = $this->codedXml();
        foreach (['521.000', '331.000', '342.000', '336.000', '524.000'] as $code) $this->analytic($code);
        $this->saveCodes([
            ['7Int', '521.000', '331.000'], ['2Oz', '331.000', '342.000'],
            ['8Oz', '331.000', '336.000'], ['aOz', '524.000', '336.000'],
        ]);

        $preview = $this->service->preview($this->supplierId, $xml);
        $levels = array_column($preview['checks'], 'level', 'code');
        self::assertArrayNotHasKey('commitment_on_clearing_account', $levels);
        self::assertSame('ok', $levels['clearing_balanced']);
        self::assertSame('ok', $levels['accounts_ok']);
        self::assertTrue($preview['can_apply'], json_encode($preview['checks'], JSON_UNESCAPED_UNICODE));

        $result = $this->service->apply($this->supplierId, $xml, 'mzdy.xml', false, $this->audit());
        self::assertSame([], $result['created_accounts']);
        $items = $this->items();
        self::assertSame(['331.000:debit:3000.00', '342.000:credit:3000.00'], $this->entryLines((int) $items[0]['journal_entry_id']));
        self::assertSame(['331.000:debit:2130.00', '336.000:credit:9570.00', '524.000:debit:7440.00'], $this->entryLines((int) $items[1]['journal_entry_id']));
        self::assertSame('23520.00', $this->balance('331.000'));
    }

    private function expectApplyErrorKeepingDataFor(string $xml, string $code): void
    {
        try {
            $this->service->apply($this->supplierId, $xml, null, true, $this->audit());
            self::fail('Načtení mělo být odmítnuto (' . $code . ').');
        } catch (PamicaPostingException $e) {
            self::assertSame($code, $e->errorCode);
        }
        self::assertSame(0, $this->rowCount('other_items'));
        self::assertSame(0, $this->rowCount('internal_documents'));
    }

    /** @return list<array<string,mixed>> */
    private function unmappedCodes(string $xml): array
    {
        try {
            $this->service->preview($this->supplierId, $xml);
            self::fail('Zkratky bez mapování musí náhled zastavit.');
        } catch (PamicaPostingException $e) {
            self::assertSame('unmapped_accounting_codes', $e->errorCode);
            return $e->context['unmapped_codes'];
        }
    }

    private function jsonRequest(array $permissions, array $body)
    {
        return (new ServerRequestFactory())->createServerRequest('PUT', '/api/accounting/payroll-posting-imports/code-map')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, $permissions, 'custom'));
    }

    private function expectApplyError(string $code, bool $createAccounts = true): void
    {
        try {
            $this->service->apply($this->supplierId, $this->xml, null, $createAccounts, $this->audit());
            self::fail('Načtení mělo být odmítnuto (' . $code . ').');
        } catch (PamicaPostingException $e) {
            self::assertSame($code, $e->errorCode);
        }
        self::assertSame(0, $this->rowCount('other_items'));
        self::assertSame(0, $this->rowCount('internal_documents'));
    }

    /** @return array{user_id:?int,posted_by:?int,ip:?string,user_agent:?string} */
    private function audit(): array
    {
        $uid = $this->userId > 0 ? $this->userId : null;
        return ['user_id' => $uid, 'posted_by' => $uid, 'ip' => '127.0.0.1', 'user_agent' => 'phpunit'];
    }

    /** @return list<array<string,mixed>> */
    private function items(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM other_items WHERE supplier_id = ? ORDER BY id');
        $stmt->execute([$this->supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<string> */
    private function entryLines(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT CONCAT(ca.account_code, ':', jl.side, ':', jl.amount)
               FROM journal_entry_lines jl JOIN chart_of_accounts ca ON ca.id = jl.account_id
              WHERE jl.entry_id = ? AND jl.supplier_id = ? ORDER BY ca.account_code, jl.side"
        );
        $stmt->execute([$entryId, $this->supplierId]);
        $lines = $stmt->fetchAll(PDO::FETCH_COLUMN);
        sort($lines);
        return $lines;
    }

    private function entryTotal(mixed $entryId): float
    {
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM journal_entry_lines WHERE entry_id = ? AND supplier_id = ? AND side = 'credit'");
        $stmt->execute([(int) $entryId, $this->supplierId]);
        return (float) $stmt->fetchColumn();
    }

    private function balance(string $code): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT FORMAT(SUM(CASE WHEN jl.side = 'credit' THEN jl.amount ELSE -jl.amount END), 2, 'en_US')
               FROM journal_entry_lines jl JOIN chart_of_accounts ca ON ca.id = jl.account_id
              WHERE jl.supplier_id = ? AND ca.account_code = ?"
        );
        $stmt->execute([$this->supplierId, $code]);
        return str_replace(',', '', (string) $stmt->fetchColumn());
    }

    /** @return array<string,mixed> */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private function rowCount(string $table): int
    {
        return $this->countWhere($table, '1 = 1');
    }

    private function countWhere(string $table, string $where): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$where}");
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function request(array $permissions, string $name, string $content, bool $createAccounts = false)
    {
        $stream = (new StreamFactory())->createStream($content);
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/accounting/payroll-posting-imports/preview')
            ->withUploadedFiles(['file' => new UploadedFile($stream, $name, 'application/xml', strlen($content), UPLOAD_ERR_OK)])
            ->withParsedBody($createAccounts ? ['create_missing_accounts' => '1'] : [])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, $permissions, 'custom'));
    }

    private function data(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        return $body['data'] ?? $body;
    }

    private function error(ResponseInterface $response): ?string
    {
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        return $body['error']['code'] ?? null;
    }
}
