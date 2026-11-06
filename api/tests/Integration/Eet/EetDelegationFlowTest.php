<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eet;

use DI\Container;
use MyInvoice\Action\Eet\EetDevicesAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Eet\EetCommandException;
use MyInvoice\Service\Eet\EetSettingsService;
use MyInvoice\Service\Eet\EetCorrectionService;
use MyInvoice\Service\Eet\EetCredentialProvider;
use MyInvoice\Service\Eet\EetDeviceRepository;
use MyInvoice\Service\Eet\EetException;
use MyInvoice\Service\Eet\EetPaymentCommand;
use MyInvoice\Service\Eet\Transport\EetMessageBuilder;
use MyInvoice\Service\Eet\Transport\EetMessageHeader;
use MyInvoice\Service\Eet\Transport\EetSaleData;
use MyInvoice\Service\Eet\Transport\EetTransportInterface;
use MyInvoice\Service\Eet\Transport\EetXmlValidator;
use MyInvoice\Tests\Support\ScriptedEetTransport;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Symfony\Component\Clock\MockClock;

/**
 * Evidence tržby na základě pověření (situace 4 a 5 dokumentu FS „Popis položek
 * datové zprávy s příklady jejich vyplnění“ v1.0): nastavení zařízení, snapshot
 * v záznamu, odeslaná Data, oprava se stejným pověřením a ověřovací mód proti XSD.
 */
#[Group('integration')]
final class EetDelegationFlowTest extends TestCase
{
    private const TAXPAYER = 'CZ00000019';
    private const DELEGATOR = 'CZ683555118';

    private Container $container;
    private Connection $db;
    private ScriptedEetTransport $transport;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $unitId = 0;
    private int $defaultDeviceId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->transport = new ScriptedEetTransport();
        $this->container->set(EetTransportInterface::class, $this->transport);
        $this->container->set(EetCredentialProvider::class, ScriptedEetTransport::credentials());
        $this->container->set(ClockInterface::class, new MockClock(new \DateTimeImmutable('2026-11-02 10:00:00', new \DateTimeZone('Europe/Prague'))));
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();

        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(['Synthetic pověřený s.r.o.', 'Test 1', 'Praha', '11000', $country, uniqid('eet-pov-') . '@example.com', $currency, $vat]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO eet_units (supplier_id, unit_number, name, kind, valid_from) VALUES (?, 31, 'Čerpací stanice', 'premises', '2026-01-01')")
            ->execute([$this->supplierId]);
        $this->unitId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO eet_devices (supplier_id, unit_id, code, name) VALUES (?, ?, ?, 'Vlastní zboží')")
            ->execute([$this->supplierId, $this->unitId, $this->code()]);
        $this->defaultDeviceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO eet_settings (supplier_id, enabled, environment, eic, active_from, regime, default_device_id)
             VALUES (?, 1, 'playground', ?, '2026-01-01', 'standard', ?)"
        )->execute([$this->supplierId, self::TAXPAYER, $this->defaultDeviceId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($this->supplierId !== 0) {
            foreach ([
                'DELETE FROM eet_attempts WHERE supplier_id = ?',
                'DELETE FROM eet_records WHERE supplier_id = ?',
                'DELETE FROM payment_event_allocations WHERE supplier_id = ?',
                'DELETE FROM payment_events WHERE supplier_id = ?',
                'DELETE FROM eet_settings WHERE supplier_id = ?',
                'DELETE FROM eet_devices WHERE supplier_id = ?',
                'DELETE FROM eet_units WHERE supplier_id = ?',
                'DELETE FROM eet_settings_history WHERE supplier_id = ?',
                'DELETE FROM activity_log WHERE supplier_id = ?',
                'DELETE FROM supplier WHERE id = ?',
            ] as $sql) {
                $pdo->prepare($sql)->execute([$this->supplierId]);
            }
        }
        $this->db->close();
    }

    public function testSingleDelegatorIsSnapshottedAndSentWithoutMultipleFlag(): void
    {
        $device = $this->devices()->create($this->supplierId, [
            'unit_id' => $this->unitId, 'code' => $this->code(), 'name' => 'Pohonné hmoty v pověření',
            'delegation' => 'single', 'delegating_eic' => ' cz683555118 ',
        ]);
        self::assertSame(['single', self::DELEGATOR], [$device['delegation'], $device['delegating_eic']]);

        $result = $this->command()->accept($this->supplierId, $this->userId, 'pov-single-0001', $this->sale('860', (int) $device['id']));
        self::assertSame(['required', 'confirmed'], [$result['eet_decision']['decision'], $result['eet']['status']]);

        $record = $this->record((int) $result['eet']['record_id']);
        self::assertSame([self::DELEGATOR, 0], [$record['delegating_eic'], (int) $record['multiple_delegators']]);

        $call = $this->transport->callsFor((string) $device['code'])[0]['data'];
        self::assertSame([self::TAXPAYER, self::DELEGATOR, null], [$call->eic, $call->delegatingEic, $call->multipleDelegators]);

        $xml = $this->verificationMessage($call);
        self::assertStringContainsString('eic_poverujiciho="' . self::DELEGATOR . '"', $xml);
        self::assertStringNotContainsString('povereni_vice_popl', $xml);
        self::assertStringContainsString('overeni="true"', $xml);
    }

    public function testMultipleDelegatorsAndCorrectionKeepsTheOriginalDelegation(): void
    {
        $device = $this->devices()->create($this->supplierId, [
            'unit_id' => $this->unitId, 'code' => $this->code(), 'name' => 'Společná zakázka', 'delegation' => 'multiple',
        ]);
        self::assertNull($device['delegating_eic']);

        $sale = $this->command()->accept($this->supplierId, $this->userId, 'pov-multi-0001', $this->sale('25000', (int) $device['id']));
        $call = $this->transport->callsFor((string) $device['code'])[0]['data'];
        self::assertSame([null, true], [$call->delegatingEic, $call->multipleDelegators]);
        self::assertStringContainsString('povereni_vice_popl="true"', $this->verificationMessage($call));

        // Oprava jde přes výchozí zařízení bez pověření, ale patří k tržbě v pověření.
        $correction = $this->container->get(EetCorrectionService::class)->create(
            $this->supplierId, $this->userId, (int) $sale['eet']['record_id'], 'pov-multi-corr-0001',
            ['amount' => '1000', 'reason' => 'refund', 'device_id' => $this->defaultDeviceId],
        );
        $correctionRecord = $this->row(
            "SELECT delegating_eic, multiple_delegators, device_id, total_amount FROM eet_records WHERE supplier_id = ? AND kind = 'correction'",
            [$this->supplierId],
        );
        self::assertSame([null, 1, $this->defaultDeviceId, '-1000.00'], [
            $correctionRecord['delegating_eic'], (int) $correctionRecord['multiple_delegators'],
            (int) $correctionRecord['device_id'], $correctionRecord['total_amount'],
        ]);
        self::assertNotEmpty($correction);
        $correctionCall = $this->transport->callsFor((string) $this->scalar('SELECT code FROM eet_devices WHERE id = ?', [$this->defaultDeviceId]))[0]['data'];
        self::assertTrue($correctionCall->multipleDelegators);
    }

    public function testOwnSalesStayWithoutDelegationItems(): void
    {
        $result = $this->command()->accept($this->supplierId, $this->userId, 'pov-own-0001', $this->sale('185', null));
        $record = $this->record((int) $result['eet']['record_id']);
        self::assertSame([null, 0], [$record['delegating_eic'], (int) $record['multiple_delegators']]);
        $xml = $this->verificationMessage($this->transport->calls[0]['data']);
        self::assertStringNotContainsString('eic_poverujiciho', $xml);
        self::assertStringNotContainsString('povereni_vice_popl', $xml);
    }

    public function testDeviceRejectsInvalidDelegationAndClearsEicWithoutDelegation(): void
    {
        foreach ([
            ['delegation' => 'single', 'delegating_eic' => self::TAXPAYER],
            ['delegation' => 'single'],
            ['delegation' => 'single', 'delegating_eic' => 'CZ12'],
            ['delegation' => 'joint'],
        ] as $invalid) {
            try {
                $this->devices()->create($this->supplierId, ['unit_id' => $this->unitId, 'code' => $this->code(), 'name' => 'Chybné'] + $invalid);
                self::fail('Neplatné pověření prošlo: ' . json_encode($invalid));
            } catch (EetException $e) {
                self::assertContains($e->details['field'] ?? null, ['delegating_eic', 'delegation']);
            }
        }

        $device = $this->devices()->create($this->supplierId, [
            'unit_id' => $this->unitId, 'code' => $this->code(), 'name' => 'Parkoviště', 'delegation' => 'multiple', 'delegating_eic' => 'CZ8551015704',
        ]);
        $updated = $this->devices()->update($this->supplierId, (int) $device['id'], ['delegation' => 'none']);
        self::assertSame(['none', null], [$updated['delegation'], $updated['delegating_eic']]);
    }

    public function testDelegationInPaymentBodyIsRejected(): void
    {
        try {
            $this->command()->accept($this->supplierId, $this->userId, 'pov-body-0001', $this->sale('100', null) + ['delegating_eic' => self::DELEGATOR]);
            self::fail('Pověření z těla platby prošlo.');
        } catch (EetCommandException $e) {
            self::assertSame(422, $e->httpStatus);
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM payment_events WHERE supplier_id = ?', [$this->supplierId]));
    }

    public function testTaxpayerEicChangedToTheDelegatorBlocksThePayment(): void
    {
        $device = $this->devices()->create($this->supplierId, [
            'unit_id' => $this->unitId, 'code' => $this->code(), 'name' => 'Pověření', 'delegation' => 'single', 'delegating_eic' => self::DELEGATOR,
        ]);
        $this->db->pdo()->prepare('UPDATE eet_settings SET eic = ? WHERE supplier_id = ?')->execute([self::DELEGATOR, $this->supplierId]);

        $preview = $this->command()->preview($this->supplierId, $this->sale('100', (int) $device['id']));
        self::assertSame(['unknown', 'delegation_invalid'], [$preview['eet']['decision'], $preview['eet']['reason_code']]);
        self::assertTrue($preview['blocks_acceptance']);

        $this->expectException(EetCommandException::class);
        $this->command()->accept($this->supplierId, $this->userId, 'pov-same-0001', $this->sale('100', (int) $device['id']));
    }

    public function testDelegatedDeviceIsNeverDefaultNorMappedToCashRegister(): void
    {
        $device = $this->delegatedDevice();
        try {
            $this->container->get(EetSettingsService::class)->update($this->supplierId, ['default_device_id' => (int) $device['id']], $this->userId);
            self::fail('Zařízení v pověření prošlo jako výchozí.');
        } catch (EetException $e) {
            self::assertSame('default_device_id', $e->details['field'] ?? null);
        }

        $registerId = $this->register();
        try {
            $this->devices()->update($this->supplierId, (int) $device['id'], ['cash_register_id' => $registerId]);
            self::fail('Zařízení v pověření šlo přiřadit pokladně.');
        } catch (EetException $e) {
            self::assertSame('cash_register_id', $e->details['field'] ?? null);
        }

        try {
            $this->devices()->update($this->supplierId, $this->defaultDeviceId, ['delegation' => 'single', 'delegating_eic' => self::DELEGATOR]);
            self::fail('Výchozí zařízení šlo přepnout na pověření.');
        } catch (EetException $e) {
            self::assertSame('delegation', $e->details['field'] ?? null);
        }
    }

    public function testDelegatedDefaultDeviceIsNotUsedWithoutExplicitChoice(): void
    {
        $device = $this->delegatedDevice();
        // Stav mimo validaci (starší data, souběh): výchozí zařízení eviduje v pověření.
        $this->db->pdo()->prepare('UPDATE eet_settings SET default_device_id = ? WHERE supplier_id = ?')
            ->execute([(int) $device['id'], $this->supplierId]);

        try {
            $this->command()->accept($this->supplierId, $this->userId, 'pov-default-0001', $this->sale('100', null));
            self::fail('Vlastní tržba odešla přes výchozí zařízení v pověření.');
        } catch (EetCommandException $e) {
            self::assertSame('device_id', $e->extra['field'] ?? null);
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM eet_records WHERE supplier_id = ?', [$this->supplierId]));

        $explicit = $this->command()->accept($this->supplierId, $this->userId, 'pov-default-0002', $this->sale('100', (int) $device['id']));
        self::assertSame(self::DELEGATOR, $this->record((int) $explicit['eet']['record_id'])['delegating_eic']);
    }

    public function testTaxpayerEicCannotBecomeTheDelegatorOfADevice(): void
    {
        $device = $this->delegatedDevice();
        try {
            $this->container->get(EetSettingsService::class)->update($this->supplierId, ['eic' => strtolower(self::DELEGATOR)], $this->userId);
            self::fail('EIČ firmy šlo změnit na EIČ pověřujícího.');
        } catch (EetException $e) {
            self::assertSame(422, $e->httpStatus);
            self::assertSame(['eic', [(string) $device['code']]], [$e->details['field'] ?? null, $e->details['devices'] ?? null]);
        }
        self::assertSame(self::TAXPAYER, $this->scalar('SELECT eic FROM eet_settings WHERE supplier_id = ?', [$this->supplierId]));
    }

    public function testDeviceAuditKeepsPreviousAndNewDelegation(): void
    {
        $device = $this->delegatedDevice();
        $request = (new ServerRequestFactory())->createServerRequest('PATCH', '/api/eet/devices/' . $device['id'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody(['delegation' => 'multiple', 'delegating_eic' => 'CZ8551015704']);
        $response = $this->container->get(EetDevicesAction::class)->update($request, new Psr7Response(), ['id' => (string) $device['id']]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $payload = json_decode((string) $this->scalar(
            "SELECT payload FROM activity_log WHERE supplier_id = ? AND action = 'eet_device_update' ORDER BY id DESC LIMIT 1",
            [$this->supplierId],
        ), true);
        self::assertSame(['single', self::DELEGATOR], [$payload['old']['delegation'] ?? null, $payload['old']['delegating_eic'] ?? null]);
        self::assertSame(['multiple', 'CZ8551015704'], [$payload['new']['delegation'] ?? null, $payload['new']['delegating_eic'] ?? null]);
    }

    public function testDatabaseRejectsMalformedDelegationValues(): void
    {
        $pdo = $this->db->pdo();
        foreach ([
            ["UPDATE eet_devices SET delegation = 'multiple', delegating_eic = '' WHERE id = ?", [$this->defaultDeviceId]],
            ["UPDATE eet_devices SET delegation = 'single', delegating_eic = 'cz00000019' WHERE id = ?", [$this->defaultDeviceId]],
        ] as [$sql, $params]) {
            try {
                $pdo->prepare($sql)->execute($params);
                self::fail('Databáze přijala neplatné EIČ pověřujícího: ' . $sql);
            } catch (\PDOException $e) {
                self::assertStringContainsString('chk_eet_device_delegating_eic', $e->getMessage());
            }
        }
        $result = $this->command()->accept($this->supplierId, $this->userId, 'pov-db-0001', $this->sale('100', null));
        try {
            $pdo->prepare('UPDATE eet_records SET multiple_delegators = 2 WHERE id = ?')->execute([(int) $result['eet']['record_id']]);
            self::fail('Databáze přijala multiple_delegators = 2.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('chk_eet_records_multiple_delegators', $e->getMessage());
        }
    }

    public function testExternalRecordKeepsDelegationAndItsCorrectionToo(): void
    {
        $body = [
            'amount' => '200.00', 'eet_context' => 'external',
            'external_eet' => [
                'eic' => self::TAXPAYER, 'id_jednotky' => 555002, 'id_pokl' => '5/5583', 'porad_cis' => '22',
                'dat_trzby' => '2026-11-02T09:32:01+01:00', 'celk_trzba' => '200.00',
                'eic_poverujiciho' => 'cz8551015704', 'povereni_vice_popl' => 'true',
            ],
        ];
        $result = $this->command()->accept($this->supplierId, $this->userId, 'pov-ext-0001', $body);
        $record = $this->row("SELECT id, delegating_eic, multiple_delegators FROM eet_records WHERE supplier_id = ? AND status = 'external'", [$this->supplierId]);
        self::assertSame(['CZ8551015704', 1], [$record['delegating_eic'], (int) $record['multiple_delegators']]);
        self::assertNotEmpty($result);

        $this->container->get(EetCorrectionService::class)->create(
            $this->supplierId, $this->userId, (int) $record['id'], 'pov-ext-corr-0001', ['amount' => '50', 'reason' => 'refund'],
        );
        $correction = $this->row("SELECT delegating_eic, multiple_delegators FROM eet_records WHERE supplier_id = ? AND kind = 'correction'", [$this->supplierId]);
        self::assertSame(['CZ8551015704', 1], [$correction['delegating_eic'], (int) $correction['multiple_delegators']]);

        $body['external_eet']['porad_cis'] = '23';
        $body['external_eet']['eic_poverujiciho'] = self::TAXPAYER;
        try {
            $this->command()->accept($this->supplierId, $this->userId, 'pov-ext-0002', $body);
            self::fail('Externí tržba s pověřujícím shodným s poplatníkem prošla.');
        } catch (EetCommandException $e) {
            self::assertSame('external_eet.eic_poverujiciho', $e->extra['field'] ?? null);
        }
    }

    // ── pomocníci ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function delegatedDevice(): array
    {
        return $this->devices()->create($this->supplierId, [
            'unit_id' => $this->unitId, 'code' => $this->code(), 'name' => 'Pověření', 'delegation' => 'single', 'delegating_eic' => self::DELEGATOR,
        ]);
    }

    private function register(): int
    {
        if ((int) $this->scalar('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = ?', [$this->supplierId]) === 0) {
            $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        }
        return $this->container->get(CashRegisterService::class)->create($this->supplierId, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);
    }

    private function command(): EetPaymentCommand
    {
        return $this->container->get(EetPaymentCommand::class);
    }

    private function devices(): EetDeviceRepository
    {
        return $this->container->get(EetDeviceRepository::class);
    }

    private function code(): string
    {
        return 'P' . substr(bin2hex(random_bytes(6)), 0, 10);
    }

    /** @return array<string,mixed> */
    private function sale(string $amount, ?int $deviceId): array
    {
        return ['amount' => $amount, 'channel' => 'cash', 'contact_context' => 'in_person'] + ($deviceId !== null ? ['device_id' => $deviceId] : []);
    }

    /** Odeslaná Data jako zpráva v ověřovacím módu, ověřená proti XSD 4.1. */
    private function verificationMessage(EetSaleData $data): string
    {
        $document = (new EetMessageBuilder())->build($data, EetMessageHeader::create(true, true));
        (new EetXmlValidator())->validate($document);
        return (string) $document->saveXML($document->documentElement);
    }

    /** @return array<string,mixed> */
    private function record(int $id): array
    {
        return $this->row('SELECT * FROM eet_records WHERE id = ? AND supplier_id = ?', [$id, $this->supplierId]);
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @param list<mixed> $params @return array<string,mixed> */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
