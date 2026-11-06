<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eet;

use DI\Container;
use MyInvoice\Action\Auth\Tokens\TokenEetExternalAction;
use MyInvoice\Action\Eet\EetRecordAction;
use MyInvoice\Action\Invoice\CreatePaymentAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Eet\EetCommandException;
use MyInvoice\Service\Eet\EetCorrectionService;
use MyInvoice\Service\Eet\EetCredentialProvider;
use MyInvoice\Service\Eet\EetDispatchService;
use MyInvoice\Service\Eet\EetPaymentCommand;
use MyInvoice\Service\Eet\EetQueueService;
use MyInvoice\Service\Eet\Transport\EetTransportInterface;
use MyInvoice\Tests\Support\ScriptedEetTransport;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Symfony\Component\Clock\MockClock;

/**
 * E-shop (jiný systém), který tržby eviduje sám: úhrada přes API se zapíše účetně,
 * MyÚčto tržbu jen zaznamená jako `external` a nikdy ji neodešle (žádné dvojí hlášení).
 */
#[Group('integration')]
final class EetExternalRecordingTest extends TestCase
{
    private const DAY = '2026-11-02';
    private const POK = 'b3a09b52-7c87-4014-a496-4c7a53cf9120-ff';

    private Container $container;
    private Connection $db;
    private MockClock $clock;
    private ScriptedEetTransport $transport;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $czk = 0;
    private int $userId = 0;
    private int $deviceId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->clock = new MockClock(new \DateTimeImmutable(self::DAY . ' 10:00:00', new \DateTimeZone('Europe/Prague')));
        $this->transport = new ScriptedEetTransport();
        $this->container->set(EetTransportInterface::class, $this->transport);
        $this->container->set(EetCredentialProvider::class, ScriptedEetTransport::credentials());
        $this->container->set(ClockInterface::class, $this->clock);
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->czk = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $this->czk, $vat, $this->userId));

        $pdo->prepare(
            "INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode, allow_refund_invoices)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'tax_evidence', 1)"
        )->execute(['Synthetic EET e-shop s.r.o.', 'Test 1', 'Praha', '11000', $country, uniqid('eetext-') . '@example.com', $this->czk, $vat]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Zákazník s.r.o.", "Test 1", "Praha", "11000", ?, "test@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $country, $this->czk]);
        $this->clientId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO eet_units (supplier_id, unit_number, name, kind, valid_from) VALUES (?, 7, 'Prodejna', 'premises', '2026-01-01')")
            ->execute([$this->supplierId]);
        $unitId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO eet_devices (supplier_id, unit_id, code, name) VALUES (?, ?, ?, 'Kasa')")
            ->execute([$this->supplierId, $unitId, 'X' . substr(bin2hex(random_bytes(6)), 0, 10)]);
        $this->deviceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO eet_settings (supplier_id, enabled, environment, eic, active_from, regime, default_device_id)
             VALUES (?, 1, 'playground', 'CZ00000019', '2026-01-01', 'standard', ?)"
        )->execute([$this->supplierId, $this->deviceId]);
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
            $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = NULL, bank_transaction_id = NULL WHERE supplier_id = ?')
                ->execute([$this->supplierId]);
            $pdo->prepare('UPDATE invoices SET parent_invoice_id = NULL WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM api_tokens WHERE supplier_id = ?')->execute([$this->supplierId]);
            self::deleteRows($pdo, 'supplier', $this->supplierId, 0);
        }
        $this->db->close();
    }

    public function testExternalContextOnInvoicePaymentRecordsWithoutSending(): void
    {
        $invoiceId = $this->invoice(1210);
        $response = $this->pay($invoiceId, $this->externalBody(1210));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $json = $this->json($response);
        self::assertTrue($json['became_paid']);
        self::assertSame('external', $json['eet']['eet_decision']['decision']);
        self::assertSame('external', $json['eet']['eet']['status']);

        self::assertSame([], $this->transport->calls, 'Externí tržba se nesmí odeslat.');
        $record = $this->row('SELECT status, eic, unit_id, device_id, unit_number, device_code, sequence_number, pok, total_amount, sale_at FROM eet_records WHERE supplier_id = ?');
        self::assertSame(['external', 'CZ00000019', null, null, 25, 'ESHOP-1', '2026/000042', self::POK, '1210.00', '2026-11-02 09:15:00'], array_values($record));
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]));
        self::assertSame('external', $this->scalar('SELECT eet_decision FROM payment_events WHERE supplier_id = ?', [$this->supplierId]));

        // Evidence, souhrn faktury i export záznam ukážou.
        $list = $this->json($this->container->get(EetRecordAction::class)->list($this->request('GET', []), new Psr7Response()));
        self::assertSame([true], array_column($list['items'], 'external'));
        $box = $this->json($this->container->get(EetRecordAction::class)->invoice($this->request('GET', []), new Psr7Response(), ['id' => (string) $invoiceId]));
        self::assertSame('external', $box['items'][0]['eet_record']['status']);
        $csv = (string) $this->container->get(EetRecordAction::class)->export($this->request('GET', []), new Psr7Response())->getBody();
        self::assertStringContainsString(';external;', $csv);
    }

    public function testSameExternalTripleReplaysAndDifferentContentConflicts(): void
    {
        $invoiceId = $this->invoice(2000);
        self::assertSame(200, $this->pay($invoiceId, $this->externalBody(1000))->getStatusCode());
        $again = $this->pay($invoiceId, $this->externalBody(1000));
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        self::assertTrue($this->json($again)['eet']['idempotent_replay']);
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]), 'Opakované převzetí nezaloží druhou úhradu.');

        $conflict = $this->pay($invoiceId, $this->externalBody(900));
        self::assertSame(409, $conflict->getStatusCode(), (string) $conflict->getBody());
        self::assertSame('eet_external_duplicate', $this->json($conflict)['error']['code']);
        self::assertSame('1', $this->scalar('SELECT COUNT(*) FROM eet_records WHERE supplier_id = ?', [$this->supplierId]));
    }

    public function testTokenWithExternalDefaultTreatsMissingContextAsExternal(): void
    {
        $invoiceId = $this->invoice(500);
        $plain = $this->pay($invoiceId, ['amount' => '500'], bearer: ['eet_external_by_default' => false]);
        self::assertSame(422, $plain->getStatusCode());
        self::assertSame('eet_context_required', $this->json($plain)['error']['code']);

        $session = $this->pay($invoiceId, ['amount' => '500']);
        self::assertSame(422, $session->getStatusCode(), 'Webová relace volbu tokenu nedědí.');

        $token = $this->pay($invoiceId, ['amount' => '500'], bearer: ['eet_external_by_default' => true]);
        self::assertSame(200, $token->getStatusCode(), (string) $token->getBody());
        self::assertSame('external', $this->json($token)['eet']['eet']['status']);
        self::assertSame([], $this->transport->calls);
    }

    public function testTokenSettingIsToggledByOwnerAndReadByAuthentication(): void
    {
        $tokens = $this->container->get(ApiTokenService::class);
        $created = $tokens->generate($this->userId, $this->supplierId, 'E-shop', 'read_write');
        self::assertFalse($tokens->validate($created['plaintext'])['eet_external_by_default']);
        $response = $this->container->get(TokenEetExternalAction::class)(
            $this->request('PUT', ['enabled' => true]), new Psr7Response(), ['id' => (string) $created['id']]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($tokens->validate($created['plaintext'])['eet_external_by_default']);
        $listed = array_values(array_filter($tokens->listForUser($this->userId), static fn (array $t): bool => $t['id'] === $created['id']));
        self::assertTrue($listed[0]['eet_external_by_default']);
        $bad = $this->container->get(TokenEetExternalAction::class)($this->request('PUT', ['enabled' => 'ano']), new Psr7Response(), ['id' => (string) $created['id']]);
        self::assertSame(400, $bad->getStatusCode());
    }

    public function testWorkerRetryAndQueueIgnoreExternalRecords(): void
    {
        $invoiceId = $this->invoice(800);
        self::assertSame(200, $this->pay($invoiceId, $this->externalBody(800))->getStatusCode());
        $recordId = (int) $this->scalar('SELECT id FROM eet_records WHERE supplier_id = ?', [$this->supplierId]);
        // Ani záznam s „prošlým" next_attempt_at worker nepřevezme.
        $this->db->pdo()->prepare('UPDATE eet_records SET next_attempt_at = ? WHERE id = ?')->execute(['2026-11-01 00:00:00', $recordId]);
        $this->clock->modify('+3 days');

        $dispatch = $this->container->get(EetDispatchService::class);
        $dispatch->processDue(500);
        self::assertSame([], $this->transport->callsFor('ESHOP-1'));
        self::assertSame('external', $this->scalar('SELECT status FROM eet_records WHERE id = ?', [$recordId]));
        self::assertSame('0', (string) $this->scalar('SELECT overdue FROM eet_records WHERE id = ?', [$recordId]));
        try {
            $dispatch->retryNow($this->supplierId, $recordId);
            self::fail('Ruční opakování externího záznamu prošlo.');
        } catch (EetCommandException $e) {
            self::assertSame('eet_state_conflict', $e->errorCode);
        }
        self::assertSame([], $this->transport->calls);
        $queue = $this->container->get(EetQueueService::class)->summary($this->supplierId);
        self::assertSame([0, 0, []], [$queue['open_total'], $queue['overdue'], $queue['action_required_by_code']]);
    }

    /** Vratka externí tržby přes MyÚčto: peníze vrací pokladna MyÚčta, odešle se záporná tržba. */
    public function testRefundOfExternalSaleIsSentAsNegativeSale(): void
    {
        $invoiceId = $this->invoice(1210);
        self::assertSame(200, $this->pay($invoiceId, $this->externalBody(1210))->getStatusCode());
        $recordId = (int) $this->scalar('SELECT id FROM eet_records WHERE supplier_id = ?', [$this->supplierId]);
        $this->clock->modify('+1 day');
        $result = $this->container->get(EetCorrectionService::class)->create($this->supplierId, $this->userId, $recordId, 'ext-refund-0001',
            ['amount' => '210', 'reason' => 'refund']);

        self::assertSame(['-210.00', 'confirmed', $recordId], [$result['correction']['total_amount'], $result['correction']['status'], $result['correction']['corrects_record_id']]);
        self::assertSame($this->deviceId, $result['correction']['device_id'], 'Oprava jde ze zařízení MyÚčta.');
        self::assertCount(1, $this->transport->calls);
        self::assertSame(-21000, $this->transport->calls[0]['data']->totalCents);
        self::assertSame('1000.00', $result['original_remaining']);
    }

    public function testExternalPaymentThroughEetPaymentsAndValidation(): void
    {
        $result = $this->container->get(EetPaymentCommand::class)->accept($this->supplierId, $this->userId, 'ext-quick-0001', [
            'eet_context' => 'external', 'amount' => '99.90', 'channel' => 'card_online', 'contact_context' => 'remote',
        ]);
        self::assertSame(['external', 'external', 'quick_sale'], [$result['eet_decision']['decision'], $result['eet']['status'], $result['origin']]);
        self::assertSame([], $this->transport->calls);

        foreach ([
            [['pok' => 'abc'], 'external_eet.pok'],
            [['id_pokl' => 'Kasa č. 1', 'porad_cis' => '1'], 'external_eet.id_pokl'],
            [['id_pokl' => 'KASA1'], 'external_eet.porad_cis'],
            [['dat_trzby' => '2026-11-02 09:00'], 'external_eet.dat_trzby'],
            [['eic' => 'CZ1212121218'], 'external_eet.eic'],
            [['id_jednotky' => 0], 'external_eet.id_jednotky'],
        ] as [$external, $field]) {
            try {
                $this->container->get(EetPaymentCommand::class)->accept($this->supplierId, $this->userId, 'ext-bad-' . substr(md5($field), 0, 8), [
                    'eet_context' => 'external', 'amount' => '10', 'external_eet' => $external,
                ]);
                self::fail('Neplatná externí evidence prošla: ' . $field);
            } catch (EetCommandException $e) {
                self::assertSame(['eet_validation', $field], [$e->errorCode, $e->extra['field'] ?? null]);
            }
        }
    }

    public function testCompanyWithoutEetKeepsPaymentUnchanged(): void
    {
        $this->db->pdo()->prepare('UPDATE eet_settings SET enabled = 0 WHERE supplier_id = ?')->execute([$this->supplierId]);
        $invoiceId = $this->invoice(300);
        $response = $this->pay($invoiceId, $this->externalBody(300) + ['paid_on' => '2026-10-30']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $json = $this->json($response);
        self::assertArrayNotHasKey('eet', $json);
        self::assertSame('2026-10-30', $json['payment']['paid_on'] ?? null);
        self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM payment_events WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM eet_records WHERE supplier_id = ?', [$this->supplierId]));
    }

    // ── pomocníci ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function externalBody(float $amount): array
    {
        return [
            'amount' => number_format($amount, 2, '.', ''),
            'eet_context' => 'external',
            'external_eet' => [
                'pok' => self::POK, 'eic' => 'CZ00000019', 'id_jednotky' => 25, 'id_pokl' => 'ESHOP-1',
                'porad_cis' => '2026/000042', 'dat_trzby' => '2026-11-02T09:15:00+01:00', 'celk_trzba' => number_format($amount, 2, '.', ''),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $bearer řádek API tokenu (null = webová relace)
     */
    private function pay(int $invoiceId, array $body, ?array $bearer = null): ResponseInterface
    {
        $request = $this->request('POST', $body);
        if ($bearer !== null) {
            $request = $request->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
                ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['id' => 1, 'scope' => 'read_write'] + $bearer);
        }
        return $this->container->get(CreatePaymentAction::class)($request, new Psr7Response(), ['id' => (string) $invoiceId]);
    }

    private function invoice(float $total): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id, reverse_charge,
                 total_without_vat, total_vat, total_with_vat, advance_paid_amount, bank_snapshot, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, "2026-11-01", "2026-11-01", "2026-11-15", ?, 0, ?, 0, ?, 0, ?, "issued", "1", ?)'
        )->execute([$this->supplierId, (string) random_int(1000000, 9999999), $this->clientId, $this->czk, $total, $total,
            json_encode(['account_number' => '1000000005', 'bank_code' => '0100']), $this->userId]);
        $invoiceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                                        total_without_vat, total_vat, total_with_vat)
             SELECT ?, "Synthetic item", 1, ?, id, 0, ?, 0, ? FROM vat_rates ORDER BY id LIMIT 1'
        )->execute([$invoiceId, $total, $total, $total]);
        return $invoiceId;
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        return $body !== [] ? $request->withParsedBody($body) : $request;
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return (array) json_decode((string) $response->getBody(), true);
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $value : (string) $value;
    }

    /** @return array<string,mixed> */
    private function row(string $sql): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$this->supplierId]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private static function deleteRows(PDO $pdo, string $table, int $supplierId, int $depth): void
    {
        for ($attempt = 0; $attempt < 64; $attempt++) {
            try {
                $pdo->prepare('DELETE FROM `' . $table . '` WHERE ' . ($table === 'supplier' ? 'id' : 'supplier_id') . ' = ?')->execute([$supplierId]);
                return;
            } catch (\PDOException $e) {
                if ($depth > 16 || (int) ($e->errorInfo[1] ?? 0) !== 1451
                    || preg_match('/`[^`]+`\.`([^`]+)`, CONSTRAINT/', (string) $e->getMessage(), $m) !== 1) {
                    throw $e;
                }
                self::deleteRows($pdo, $m[1], $supplierId, $depth + 1);
            }
        }
    }
}
