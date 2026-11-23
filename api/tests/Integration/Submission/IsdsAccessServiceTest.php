<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\IsdsAccessProfileRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\IsdsAccessService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class IsdsAccessServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PASSWORD = 'synthetic-isds-password';
    private Connection $db;
    private IsdsAccessService $service;
    private IsdsAccessProfileRepository $repository;
    private int $owner;
    private int $employee;
    private int $first;
    private int $second;
    private string $box;
    private int $lookups = 0;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('isds_access_profiles') || $container->get(SecretEncryption::class)->validateKey() !== null) {
            $this->markTestSkipped('Test vyžaduje migrace a šifrovací klíč.');
        }
        $this->box = substr(bin2hex(random_bytes(4)), 0, 7);
        $container->set(DirectIsdsInboxTransport::class, new DirectIsdsInboxTransport(
            function (string $url, string $body, ChannelContext $context): array {
                ++$this->lookups;
                self::assertStringEndsWith('/DS/DsManage', $url);
                self::assertStringContainsString('GetOwnerInfoFromLogin2', $body);
                self::assertStringContainsString('<isds:dbDummy', $body);
                self::assertSame('synthetic-username', $context->credentials->username?->reveal());
                self::assertSame(self::PASSWORD, $context->credentials->password?->reveal());
                return [
                    'status' => 200,
                    'body' => '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
                        . '<s:Body><GetOwnerInfoFromLogin2Response xmlns="http://isds.czechpoint.cz/v20">'
                        . '<dbOwnerInfo><dbID>' . $this->box . '</dbID></dbOwnerInfo>'
                        . '<dbStatus><dbStatusCode>0000</dbStatusCode></dbStatus>'
                        . '</GetOwnerInfoFromLogin2Response></s:Body></s:Envelope>',
                ];
            },
        ));
        $this->repository = $container->get(IsdsAccessProfileRepository::class);
        $this->service = $container->get(IsdsAccessService::class);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->owner = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $pdo->prepare("INSERT INTO users (email, password_hash, name, locale) VALUES (?, 'synthetic-disabled', 'Employee', 'cs')")
            ->execute([bin2hex(random_bytes(8)) . '@example.invalid']);
        $this->employee = (int) $pdo->lastInsertId();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->first = $this->createIsolatedSupplier($pdo, $source);
        $this->second = $this->createIsolatedSupplier($pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testPersonalSecretsNeverLeaveTheVaultAndAreScopedToOwnerCompanyAndEnvironment(): void
    {
        $saved = $this->service->remember($this->first, $this->owner, 'test', 'send', $this->login());
        self::assertSame(['id', 'label', 'box_id', 'method', 'scope'], array_keys($saved));
        self::assertSame($this->box, $saved['box_id']);
        $stored = $this->repository->owned($saved['id'], $this->owner, 'personal');
        self::assertNotNull($stored);
        self::assertStringStartsWith('enc:v2:', $stored['username_ciphertext']);
        self::assertStringStartsWith('enc:v2:', $stored['password_ciphertext']);
        self::assertStringNotContainsString(self::PASSWORD, json_encode($stored, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->service->choices($this->first, $this->employee, 'test', 'send'));
        self::assertSame([], $this->service->choices($this->second, $this->owner, 'test', 'send'));
        self::assertSame([], $this->service->choices($this->first, $this->owner, 'production', 'send'));
        $this->rejects('isds_access_unavailable', fn () => $this->service->use($saved['id'], $this->first, $this->employee, 'test', 'send'));
        self::assertSame(1, $this->lookups);

        $repeated = $this->service->remember($this->first, $this->owner, 'test', 'send', $this->login());
        self::assertSame($saved, $repeated);
        self::assertSame($this->box, $this->service->use($saved['id'], $this->first, $this->owner, 'test', 'send')['context']->credentials->boxId);
        $this->service->forget($saved['id'], $this->owner, 'personal', $this->first);
        self::assertSame([], $this->service->choices($this->first, $this->owner, 'test', 'send'));
    }

    public function testSharedGrantsAreIndependentAndMailboxChangesFailClosed(): void
    {
        $allowed = [$this->first, $this->second];
        $grants = [
            ['supplier_id' => $this->first, 'receive' => true],
            ['supplier_id' => $this->second, 'send' => true, 'receipts' => true],
        ];
        $saved = $this->service->saveShared($this->owner, $this->first, null, $allowed, $this->shared($grants));
        self::assertSame([], $this->service->adminItems($this->employee, $allowed));
        self::assertSame([], $this->service->choices($this->first, $this->employee, 'test', 'send'));
        self::assertSame([], $this->service->choices($this->first, $this->employee, 'test', 'receipts'));
        self::assertSame([], $this->service->choices($this->second, $this->employee, 'test', 'receive'));
        self::assertSame($saved['id'], $this->service->choices($this->second, $this->employee, 'test', 'send')[0]['id']);
        self::assertFalse($this->service->use($saved['id'], $this->first, $this->employee, 'test', 'receive')['can_receipts']);
        self::assertTrue($this->service->use($saved['id'], $this->second, $this->employee, 'test', 'receipts')['can_receipts']);
        $this->rejects('isds_inbox_assigned_elsewhere', fn () => $this->service->saveShared(
            $this->owner, $this->first, null, $allowed, $this->shared([['supplier_id' => $this->second, 'receive' => true]]),
        ));
        $this->rejects('isds_access_grants_not_editable', fn () => $this->service->forget($saved['id'], $this->owner, 'shared', $this->first, [$this->first]));
        $this->box = $this->box === 'abcdefg' ? 'gfedcba' : 'abcdefg';
        $this->rejects('isds_access_box_mismatch', fn () => $this->service->use($saved['id'], $this->second, $this->employee, 'test', 'send'));
        $this->service->forget($saved['id'], $this->owner, 'shared', $this->first, $allowed);
        self::assertSame([], $this->service->choices($this->second, $this->employee, 'test', 'send'));
    }

    private function login(): array
    {
        return ['username' => 'synthetic-username', 'password' => self::PASSWORD];
    }

    private function shared(array $grants): array
    {
        return ['label' => 'Synthetic access', 'environment' => 'test', 'method' => 'password', 'grants' => $grants] + $this->login();
    }

    private function rejects(string $code, callable $action): void
    {
        try {
            $action();
            self::fail('Použití profilu mělo být zamítnuto.');
        } catch (SubmissionChannelException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
