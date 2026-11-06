<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollImportedJmhzProtocolRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzPartialProtocolOwnership;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolExplainer;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolImportService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\JmhzSignedProtocolFactory;
use MyInvoice\Tests\Unit\Payroll\Submission\JmhzTransportSample;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollImportedJmhzProtocolRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const ENVIRONMENT = 'test';
    private const OWN_ID_PPV = '4002787754995';
    private const FOREIGN_ID_PPV = '4002787754996';
    private const PROTOCOL_OIC = '1632728141';

    private Connection $db;
    private PayrollImportedJmhzProtocolRepository $repository;
    private PayrollSensitiveData $sensitive;
    private int $supplierId;
    private ?JmhzSignedProtocolFactory $factory = null;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $sensitive = $container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $this->sensitive = $sensitive;
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
        $this->factory?->cleanUp();
        $this->factory = null;
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
        $service = $this->service();

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
        $service = $this->service();
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
        $service = $this->service();
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
        $service = $this->service();
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
        $service = $this->service();
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

    /**
     * Dílčí protokol k JMHZ z datové schránky nese variabilní symbol jen
     * v nepodepsaném komentáři a v názvu přílohy. Uloží se, když pečeť
     * projde a všechna podepsaná ID PPV patří vztahům téhle firmy; VS
     * a období z nápovědy se pak uloží k dokladu.
     */
    public function testPartialProtocolIsStoredWhenEveryFormBelongsToTheCompany(): void
    {
        $this->givenOfficeVariableSymbol('9990000001');
        $this->givenEmploymentWithIdPpv(self::OWN_ID_PPV);

        $result = $this->service()->import(
            $this->supplierId,
            self::ENVIRONMENT,
            $this->partialProtocol(self::OWN_ID_PPV, '9990000001'),
            'JMH-DILCI-PROTOKOL-VS9990000001-2026-01-IDCSSZ-0000AAAA.xml',
            null,
        );

        self::assertTrue($result['created']);
        self::assertSame('partial_submission', $result['protocol']['protocol_kind']);
        self::assertSame('9990000001', $result['protocol']['variable_symbol']);
        self::assertSame(1, $result['protocol']['period_month']);
        self::assertSame(2026, $result['protocol']['period_year']);
    }

    /** ID PPV, které v evidenci firmy není, protokol neuloží. */
    public function testPartialProtocolWithUnknownEmploymentIsRefused(): void
    {
        $this->givenOfficeVariableSymbol('9990000001');
        $this->givenEmploymentWithIdPpv(self::OWN_ID_PPV);

        $this->assertRefused(
            'jmhz_protocol_tenant_unverifiable',
            $this->partialProtocol(self::FOREIGN_ID_PPV, '9990000001'),
            'JMH-DILCI-PROTOKOL-VS9990000001-2026-01-IDCSSZ-0000AAAA.xml',
        );
    }

    /**
     * Podepsaná ID PPV sedí, ale nápověda ukazuje na cizí variabilní symbol,
     * nebo si komentář a název souboru odporují.
     */
    public function testPartialProtocolWithForeignOrConflictingSymbolIsRefused(): void
    {
        $this->givenOfficeVariableSymbol('9990000001');
        $this->givenEmploymentWithIdPpv(self::OWN_ID_PPV);

        $this->assertRefused(
            'jmhz_protocol_tenant_mismatch',
            $this->partialProtocol(self::OWN_ID_PPV, '9990000009'),
            null,
        );
        $this->assertRefused(
            'jmhz_protocol_variable_symbol_conflict',
            $this->partialProtocol(self::OWN_ID_PPV, '9990000001'),
            'JMH-DILCI-PROTOKOL-VS9990000009-2026-01-IDCSSZ-0000AAAA.xml',
        );
    }

    /** Bez platné pečeti ČSSZ podepsaným identifikátorům nic nevěří. */
    public function testPartialProtocolChangedAfterSigningIsRefused(): void
    {
        $this->givenOfficeVariableSymbol('9990000001');
        $this->givenEmploymentWithIdPpv(self::OWN_ID_PPV);
        $tampered = str_replace(
            self::FOREIGN_ID_PPV,
            self::OWN_ID_PPV,
            $this->partialProtocol(self::FOREIGN_ID_PPV, '9990000001'),
        );

        $this->assertRefused(
            'jmhz_protocol_digest_mismatch',
            $tampered,
            'JMH-DILCI-PROTOKOL-VS9990000001-2026-01-IDCSSZ-0000AAAA.xml',
        );
    }

    private function assertRefused(string $code, string $xml, ?string $filename): void
    {
        try {
            $this->service()->import($this->supplierId, self::ENVIRONMENT, $xml, $filename, null);
            self::fail("Protokol se měl odmítnout ({$code}).");
        } catch (JmhzTransportException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
        self::assertSame(0, $this->service()->history($this->supplierId, self::ENVIRONMENT)['total']);
    }

    private function service(): JmhzProtocolImportService
    {
        return new JmhzProtocolImportService(
            $this->repository,
            new JmhzProtocolExplainer(),
            new JmhzPartialProtocolOwnership(
                new JmhzProtocolSignatureVerifier(trustAnchorPem: $this->protocols()->anchorPem()),
                new PayrollRegistrationIdentityRepository($this->db),
                $this->sensitive,
                $this->repository,
            ),
        );
    }

    private function partialProtocol(string $idPpv, string $commentSymbol): string
    {
        $signed = $this->protocols()->sign(JmhzTransportSample::partialProtocol(
            'OK',
            [[
                'guid' => JmhzTransportSample::FORM_GUID,
                'result' => 'OK',
                'identifier' => self::PROTOCOL_OIC . ';' . $idPpv,
            ]],
        ));

        // Komentář ČSSZ stojí před obálkou, mimo pečeť.
        return str_replace(
            '<?xml version="1.0" encoding="utf-8"?>',
            '<?xml version="1.0" encoding="utf-8"?><!--' . "\n"
                . "\tVariabilní symbol:           {$commentSymbol}\n"
                . "\tStav podání:                 Podání bylo přijato\n-->",
            $signed,
        );
    }

    private function givenEmploymentWithIdPpv(string $idPpv): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Jana Nováková", "employee", "hpp", 1, 1, 0, 30000, 0, 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, is_legacy_projection)
             VALUES (?, ?, "jmhz-dilci", "employment", "active", "2026-01-01", 0)'
        )->execute([$this->supplierId, $employeeId]);
        $employmentId = (int) $pdo->lastInsertId();
        (new PayrollRegistrationIdentityService(
            new PayrollRegistrationIdentityRepository($this->db),
            $this->sensitive,
            \MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry::defaults(),
        ))->assignEmploymentExternalId(
            $this->supplierId,
            $employmentId,
            self::ENVIRONMENT,
            $idPpv,
            '2026-01-01',
            'verified_manual_import',
            'ruční opis z portálu ČSSZ',
            null,
            null,
        );
    }

    private function protocols(): JmhzSignedProtocolFactory
    {
        return $this->factory ??= new JmhzSignedProtocolFactory();
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
