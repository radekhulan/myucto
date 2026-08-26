<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollImportedJmhzProtocolRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolExplainer;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolImportService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollImportedJmhzProtocolRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const ENVIRONMENT = 'test';

    private Connection $db;
    private PayrollImportedJmhzProtocolRepository $repository;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $this->repository = new PayrollImportedJmhzProtocolRepository($db);
        if (!$this->repository->isAvailable()) {
            $this->markTestSkipped('Migrace 1375 neproběhla.');
        }
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testImportedProtocolIsStoredAndListed(): void
    {
        $stored = $this->repository->store(
            $this->supplierId,
            self::ENVIRONMENT,
            $this->payload(),
            null,
        );

        self::assertTrue($stored['created']);
        self::assertSame(1, $stored['row']['row_version']);
        self::assertSame('9990000001', $stored['row']['variable_symbol']);
        self::assertSame(6, $stored['row']['period_month']);

        $listed = $this->repository->listRecent($this->supplierId, self::ENVIRONMENT);
        self::assertCount(1, $listed);
        // Syrový doklad se ven neposílá, dokud si o něj někdo neřekne.
        self::assertArrayNotHasKey('payload_xml', $listed[0]);

        $withPayload = $this->repository->listRecent(
            $this->supplierId,
            self::ENVIRONMENT,
            100,
            true,
        );
        self::assertArrayHasKey('payload_xml', $withPayload[0]);
    }

    /**
     * Druhé načtení téhož protokolu je pořád jeden doklad. Zdvojený řádek by
     * v přehledu vypadal jako druhé podání za totéž období.
     */
    public function testReimportUpdatesInsteadOfDuplicating(): void
    {
        $this->repository->store($this->supplierId, self::ENVIRONMENT, $this->payload(), null);
        $again = $this->repository->store(
            $this->supplierId,
            self::ENVIRONMENT,
            $this->payload(['status_code' => 3, 'status_name' => 'Rejected', 'error_count' => 2]),
            null,
        );

        self::assertFalse($again['created']);
        self::assertSame(2, $again['row']['row_version']);
        self::assertSame(3, $again['row']['status_code']);
        self::assertCount(
            1,
            $this->repository->listRecent($this->supplierId, self::ENVIRONMENT),
        );
    }

    /**
     * Celá cesta „soubor z datové schránky → řádek v evidenci".
     *
     * Ověřuje to, co u téhle funkce rozhoduje: protokol vlastní firmy projde,
     * protokol vystavený na cizí variabilní symbol se NEULOŽÍ, a druhé načtení
     * téhož souboru nezaloží druhý doklad.
     */
    public function testImportServiceStoresOwnProtocolAndRefusesForeignOne(): void
    {
        $this->givenOfficeVariableSymbol('9990000001');
        $service = new JmhzProtocolImportService(
            $this->repository,
            new JmhzProtocolExplainer(),
        );

        $result = $service->import(
            $this->supplierId,
            self::ENVIRONMENT,
            self::protocolXml('9990000001'),
            'PROTOKOL.xml',
            null,
        );

        self::assertTrue($result['created']);
        self::assertSame('ProcessedAndComplete', $result['protocol']['status_name']);
        self::assertSame(6, $result['protocol']['period_month']);
        self::assertSame(2026, $result['protocol']['period_year']);
        self::assertSame(
            '0195AAAA-1111-7222-8333-BBBBCCCCDDDD',
            $result['protocol']['submission_guid'],
        );
        self::assertSame([], $result['errors']);

        $again = $service->import(
            $this->supplierId,
            self::ENVIRONMENT,
            self::protocolXml('9990000001'),
            'PROTOKOL.xml',
            null,
        );
        self::assertFalse($again['created']);
        self::assertCount(
            1,
            $service->history($this->supplierId, self::ENVIRONMENT)['items'],
        );

        try {
            $service->import(
                $this->supplierId,
                self::ENVIRONMENT,
                self::protocolXml('9990000009'),
                'CIZI.xml',
                null,
            );
            self::fail('Cizí protokol se nesmí uložit.');
        } catch (JmhzTransportException $exception) {
            self::assertSame('jmhz_protocol_tenant_mismatch', $exception->errorCode);
        }
        self::assertCount(
            1,
            $service->history($this->supplierId, self::ENVIRONMENT)['items'],
        );
    }

    /**
     * idPodani označuje celý řetězec řádného, opravného a stornovacího podání,
     * ne jeden konkrétní doručený protokol. Dva různé doklady se stejným
     * idPodani se proto nesmí navzájem přepsat.
     */
    public function testDifferentProtocolsWithSameSubmissionGuidRemainSeparate(): void
    {
        $this->givenOfficeVariableSymbol('9990000001');
        $service = new JmhzProtocolImportService(
            $this->repository,
            new JmhzProtocolExplainer(),
        );
        $firstXml = self::protocolXml('9990000001');
        $secondXml = str_replace(
            [
                '2026-07-02T16:20:20.382+02:00',
                'AAAA1111BBBB2222CCCC3333DDDD4444',
            ],
            [
                '2026-07-03T09:10:11.123+02:00',
                'EEEE5555FFFF6666AAAA7777BBBB8888',
            ],
            $firstXml,
        );

        $first = $service->import(
            $this->supplierId,
            self::ENVIRONMENT,
            $firstXml,
            'PROTOKOL-R.xml',
            null,
        );
        $second = $service->import(
            $this->supplierId,
            self::ENVIRONMENT,
            $secondXml,
            'PROTOKOL-O.xml',
            null,
        );

        self::assertTrue($first['created']);
        self::assertTrue($second['created']);
        self::assertNotSame($first['protocol']['id'], $second['protocol']['id']);
        self::assertSame(
            2,
            $service->history($this->supplierId, self::ENVIRONMENT)['total'],
        );

        $replayed = $service->import(
            $this->supplierId,
            self::ENVIRONMENT,
            $secondXml,
            'PROTOKOL-O-kopie.xml',
            null,
        );
        self::assertFalse($replayed['created']);
        self::assertSame($second['protocol']['id'], $replayed['protocol']['id']);
    }

    /** Chyby se počítají z uloženého originálu, ne z uložené interpretace. */
    public function testHistoryExplainsErrorsFromTheStoredOriginal(): void
    {
        $this->givenOfficeVariableSymbol('9990000001');
        $service = new JmhzProtocolImportService(
            $this->repository,
            new JmhzProtocolExplainer(),
        );
        $service->import(
            $this->supplierId,
            self::ENVIRONMENT,
            self::protocolXml('9990000001', withFailure: true),
            null,
            null,
        );

        $history = $service->history($this->supplierId, self::ENVIRONMENT);
        self::assertCount(1, $history['items']);
        self::assertSame(1, $history['total']);
        self::assertTrue($history['items'][0]['detail_available']);
        self::assertSame('Rejected', $history['items'][0]['status_name']);
        self::assertSame(1, $history['items'][0]['error_count']);
        // Seznam už chyby nenese — dotahují se pro jeden protokol na vyžádání,
        // a pořád z uloženého ORIGINÁLU, ne ze zamrazené interpretace.
        self::assertArrayNotHasKey('errors', $history['items'][0]);

        $detail = $service->explain(
            $this->supplierId,
            self::ENVIRONMENT,
            (int) $history['items'][0]['id'],
        );
        self::assertTrue($detail['detail_available']);
        self::assertCount(1, $detail['errors']);
        self::assertSame(20301, $detail['errors'][0]['code']);
    }

    /** Cizí protokol se přes detail chyb nedá přečíst ani při znalosti ID. */
    public function testExplainRefusesProtocolOfAnotherCompany(): void
    {
        $this->givenOfficeVariableSymbol('9990000001');
        $service = new JmhzProtocolImportService(
            $this->repository,
            new JmhzProtocolExplainer(),
        );
        $stored = $service->import(
            $this->supplierId,
            self::ENVIRONMENT,
            self::protocolXml('9990000001', withFailure: true),
            null,
            null,
        );

        $foreign = $service->explain(
            $this->supplierId + 1,
            self::ENVIRONMENT,
            (int) $stored['protocol']['id'],
        );
        self::assertFalse($foreign['detail_available']);
        self::assertSame([], $foreign['errors']);
    }

    /** Stránkování: strop nejde zvednout a uživatel se dostane i za něj. */
    public function testHistoryPagesThroughProtocols(): void
    {
        $this->givenOfficeVariableSymbol('9990000001');
        $service = new JmhzProtocolImportService(
            $this->repository,
            new JmhzProtocolExplainer(),
        );
        for ($month = 1; $month <= 4; ++$month) {
            $service->import(
                $this->supplierId,
                self::ENVIRONMENT,
                self::protocolXml('9990000001', month: $month),
                null,
                null,
            );
        }

        $first = $service->history($this->supplierId, self::ENVIRONMENT, 2, 0);
        self::assertCount(2, $first['items']);
        self::assertSame(4, $first['total']);

        $second = $service->history($this->supplierId, self::ENVIRONMENT, 2, 2);
        self::assertCount(2, $second['items']);
        self::assertSame(4, $second['total']);
        self::assertSame(
            [],
            array_intersect(
                array_column($first['items'], 'id'),
                array_column($second['items'], 'id'),
            ),
        );

        // Strop je tvrdý: vyžádaný nesmysl se osekne, seznam se nezvětší.
        $greedy = $service->history($this->supplierId, self::ENVIRONMENT, 99999, 0);
        self::assertCount(4, $greedy['items']);
    }

    private function givenOfficeVariableSymbol(string $symbol): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, ?, ?, ?, 1)',
        )->execute([$this->supplierId, 'MAIN', 'Hlavní účtárna', $symbol]);
    }

    private static function protocolXml(
        string $variableSymbol,
        bool $withFailure = false,
        int $month = 6,
    ): string {
        $failure = $withFailure
            ? '<chybySeznam><chyba><id>1</id><typChyby>zpracovani</typChyby>'
                . '<castPodani>form</castPodani>'
                . '<idFormulare>AAAABBBB-1111-7222-8333-CCCCDDDDEEEE</idFormulare>'
                . '<kod>20301</kod><popis>Pojistné neodpovídá vyměřovacímu základu.</popis>'
                . '</chyba></chybySeznam>'
            : '';
        $status = $withFailure
            ? '<kod>3</kod><nazev>Hlášení je zamítnuto</nazev>'
            : '<kod>1</kod><nazev>Hlášení je zpracováno a je úplné</nazev>';

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<ProtokolOZpracovani'
            . ' xmlns="http://schemas.cssz.cz/JMHZ/ProtokolOZpracovani/2026">'
            . '<datumProtokolu>2026-07-02T16:20:20.382+02:00</datumProtokolu>'
            . '<variabilniSymbol>' . $variableSymbol . '</variabilniSymbol>'
            . '<idKonkretnihoPodani>AAAA1111BBBB2222CCCC3333DDDD4444</idKonkretnihoPodani>'
            . '<datumPodani>2026-07-02T16:15:36+02:00</datumPodani>'
            . '<idPodani>0195AAAA-1111-7222-8333-BBBBCCCCDDDD</idPodani>'
            . '<mesic>' . $month . '</mesic><rok>2026</rok>'
            . '<stavMH>' . $status . '</stavMH>'
            . $failure
            . '</ProtokolOZpracovani>';
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'protocol_kind' => 'processing',
            'variable_symbol' => '9990000001',
            'period_month' => 6,
            'period_year' => 2026,
            'submission_guid' => '0195AAAA-1111-7222-8333-BBBBCCCCDDDD',
            'correlation_reference' => 'AAAA1111BBBB2222CCCC3333DDDD4444',
            'status_code' => 1,
            'status_name' => 'ProcessedAndComplete',
            'error_count' => 0,
            'protocol_dated_at' => '2026-07-02T16:20:20.382+02:00',
            'submitted_at' => '2026-07-02T16:15:36+02:00',
            'source_filename' => 'protokol.xml',
            'payload_sha256' => str_repeat('a', 64),
            'payload_xml' => '<ProtokolOZpracovani/>',
            'dedupe_key' => str_repeat('b', 64),
        ], $overrides);
    }
}
