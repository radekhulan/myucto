<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Settings\BankAccountChangeNotifier;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Změna bankovního spojení měnového účtu API tokenem s výslovně udělenou
 * schopností `allow_bank_account_write`: jen správce, jen bankovní pole,
 * validace, audit a e-mail vlastníkovi tokenu. Bez schopnosti platí původní
 * ochrana — token měnový účet nezmění.
 */
#[Group('integration')]
final class TokenBankAccountWriteTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private SettingsAction $action;
    private CapturingBankNoticeTransport $transport;
    private int $supplierId;
    private int $userId;
    private int $currencyId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('Test vyžaduje nakonfigurovanou testovací DB.');
        }
        $c = Bootstrap::buildContainer();
        $this->db = $c->get(Connection::class);
        $pdo = $this->db->pdo();
        $sourceId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $sourceId);
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceId);
        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active,
                                     account_number, bank_code, iban)
             VALUES (?, 'CZK', '__TEST CZK účet', 'Kč', 'koruna', 'koruna', 2, 1, '17256712', '3030', NULL)"
        )->execute([$this->supplierId]);
        $this->currencyId = (int) $pdo->lastInsertId();

        $config = new Config(array_replace_recursive($c->get(Config::class)->all(), [
            'smtp' => [
                'from_email' => 'sender@example.test',
                'from_name'  => 'Synthetic',
                'dkim'       => ['enabled' => false],
                'rate_limit' => ['enabled' => false, 'drain_on_cli' => false],
            ],
        ]));
        $mailer = new Mailer($config, new NullLogger(), $this->db, $c->get(EmailTemplateRepository::class));
        $this->transport = new CapturingBankNoticeTransport();
        (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, $this->transport);
        $this->action = self::withDependency(
            $c->get(SettingsAction::class),
            'bankAccountNotifier',
            new BankAccountChangeNotifier($mailer, new NullLogger()),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testGrantedAdminTokenChangesBankFieldsAuditsAndNotifies(): void
    {
        $response = $this->put(['account_number' => '3153567028', 'iban' => 'CZ0930300000003153567028'], $this->grantedToken());

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['3153567028', '3030', 'CZ0930300000003153567028'], array_values($this->bank()));

        $audit = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log WHERE action = 'currency.bank_account_changed_via_token' AND entity_id = ?
              ORDER BY id DESC LIMIT 1"
        );
        $audit->execute([$this->currencyId]);
        $payload = json_decode((string) $audit->fetchColumn(), true);
        self::assertIsArray($payload, 'Změna tokenem musí mít auditní záznam.');
        $changes = array_column($payload['changes'] ?? [], null, 'field');
        self::assertSame(['account_number', 'iban'], array_keys($changes));
        self::assertSame('…6712', $changes['account_number']['before_tail']);
        self::assertSame('…7028', $changes['account_number']['after_tail']);
        self::assertStringNotContainsString('3153567028', json_encode($payload, JSON_THROW_ON_ERROR),
            'Plné číslo účtu do auditu nepatří (ActivityLogger ho rediguje).');
        self::assertSame(77, $payload['token_id'] ?? null);
        self::assertTrue($payload['notified'] ?? false);

        self::assertCount(1, $this->transport->messages, 'Vlastník tokenu musí dostat upozornění e-mailem.');
        $mail = $this->transport->messages[0];
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('owner@example.test', $mail->getTo()[0]->getAddress());
        self::assertStringContainsString('3153567028', (string) $mail->getTextBody());
        self::assertStringContainsString('17256712', (string) $mail->getTextBody());
    }

    public function testTokenWithoutGrantStaysReadOnly(): void
    {
        $token = $this->grantedToken();
        $token['allow_bank_account_write'] = false;
        $response = $this->put(['account_number' => '3153567028'], $token);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('token_write_forbidden', $this->json($response)['error']['code'] ?? null);
        self::assertSame('17256712', $this->bank()['account_number']);
        self::assertSame([], $this->transport->messages);
    }

    public function testReadScopeTokenCannotUseGrant(): void
    {
        $token = $this->grantedToken();
        $token['scope'] = 'read';
        $response = $this->put(['account_number' => '3153567028'], $token);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('17256712', $this->bank()['account_number']);
    }

    public function testNonAdminTokenIsRejected(): void
    {
        $response = $this->put(['account_number' => '3153567028'], $this->grantedToken(), 'accountant');

        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('17256712', $this->bank()['account_number']);
        self::assertSame([], $this->transport->messages);
    }

    public function testTokenCannotChangeNonBankFields(): void
    {
        $response = $this->put(['account_number' => '3153567028', 'is_default' => true, 'label' => 'x'], $this->grantedToken());

        self::assertSame(422, $response->getStatusCode());
        $fields = $this->json($response)['error']['fields'] ?? [];
        self::assertArrayHasKey('is_default', $fields);
        self::assertArrayHasKey('label', $fields);
        self::assertSame('17256712', $this->bank()['account_number']);
    }

    public function testInvalidBankValuesAreRejected(): void
    {
        $response = $this->put([
            'account_number' => '12ab',
            'bank_code'      => '30',
            'iban'           => 'CZ0030300000003153567028',
            'bic'            => 'X',
        ], $this->grantedToken());

        self::assertSame(422, $response->getStatusCode());
        $fields = $this->json($response)['error']['fields'] ?? [];
        foreach (['account_number', 'bank_code', 'iban', 'bic'] as $f) {
            self::assertArrayHasKey($f, $fields, $f);
        }
        self::assertSame('17256712', $this->bank()['account_number']);
        self::assertSame([], $this->transport->messages);
    }

    public function testSessionEditIsUnchangedAndSendsNoTokenNotice(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/currencies/' . $this->currencyId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody(['account_number' => '3153567028', 'label' => '__TEST přejmenovaný']);
        $response = $this->action->updateCurrency($request, new Response(), ['id' => (string) $this->currencyId]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('3153567028', $this->bank()['account_number']);
        self::assertSame([], $this->transport->messages);
    }

    /** @return array<string,mixed> */
    private function grantedToken(): array
    {
        return [
            'id'                       => 77,
            'name'                     => 'Integrace účetní',
            'prefix'                   => 'mi_testpref',
            'scope'                    => 'read_write',
            'allow_bank_account_write' => true,
            'user_email'               => 'owner@example.test',
            'user_name'                => 'Vlastník Tokenu',
            'user_locale'              => 'cs',
        ];
    }

    /** @param array<string,mixed> $body @param array<string,mixed> $token */
    private function put(array $body, array $token, string $role = 'admin'): ResponseInterface
    {
        $request = $this->bearerRequest($body, $token, $role);
        return $this->action->updateCurrency($request, new Response(), ['id' => (string) $this->currencyId]);
    }

    /** @param array<string,mixed> $body @param array<string,mixed> $token */
    private function bearerRequest(array $body, array $token, string $role): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('PUT', '/api/settings/currencies/' . $this->currencyId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, $token)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role,
                'email' => 'owner@example.test', 'name' => 'Vlastník Tokenu'])
            ->withParsedBody($body);
    }

    /** @return array{account_number:?string,bank_code:?string,iban:?string} */
    private function bank(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_number, bank_code, iban FROM currencies WHERE id = ?');
        $stmt->execute([$this->currencyId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function withDependency(object $original, string $name, object $replacement): object
    {
        $class = new \ReflectionClass($original);
        $args = [];
        foreach ($class->getConstructor()->getParameters() as $parameter) {
            $args[] = $parameter->getName() === $name
                ? $replacement
                : $class->getProperty($parameter->getName())->getValue($original);
        }
        return $class->newInstanceArgs($args);
    }
}

final class CapturingBankNoticeTransport implements TransportInterface
{
    /** @var list<RawMessage> */
    public array $messages = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->messages[] = $message;
        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'synthetic://bank-notice';
    }
}
