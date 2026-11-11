<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankEmailNoticeAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ImapMailboxClientInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Uložené heslo k bankovní schránce nesmí odejít na jiný server. Kdo smí účet jen
 * upravit (bank.import zápis), by jinak přepsal host na vlastní server a heslo,
 * které nikdy neviděl, by si tam při testu, procházení složek nebo cronu odchytil.
 */
#[Group('integration')]
final class BankEmailImapPasswordTargetTest extends TestCase
{
    private BankEmailNoticeRepository $repository;
    private BankEmailNoticeAction $action;
    /** @var list<array<string,mixed>> */
    private array $probes = [];
    private int $supplierId = 0;
    private int $accountId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $db = $container->get(Connection::class);
            $probes = &$this->probes;
            $container->set(ImapMailboxClientInterface::class, new class ($probes) implements ImapMailboxClientInterface {
                /** @param list<array<string,mixed>> $probes */
                public function __construct(private array &$probes) {}

                public function latest(array $settings, int $limit): array
                {
                    return [];
                }

                public function test(array $settings): array
                {
                    $this->probes[] = $settings;
                    return ['ok' => true, 'folders' => ['INBOX']];
                }

                public function postProcess(array $settings, BankEmailNoticeMessage $message, string $kind, bool $noticeImported = false): ?string
                {
                    return null;
                }
            });
            $this->repository = $container->get(BankEmailNoticeRepository::class);
            $this->action = $container->get(BankEmailNoticeAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
        $account = $this->repository->saveImapAccount($this->supplierId, [
            'name' => 'Syntetická avíza',
            'host' => 'imap.example.invalid',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'avizo@example.invalid',
            'password' => 'synthetic-stored-password',
            'folder' => 'INBOX',
        ]);
        $this->accountId = (int) $account['id'];
    }

    protected function tearDown(): void
    {
        if ($this->accountId > 0) {
            $this->repository->deleteImapAccount($this->supplierId, $this->accountId);
        }
    }

    /** @return array<string,mixed> */
    private function form(array $changes): array
    {
        return array_replace([
            'name' => 'Syntetická avíza',
            'auth_mode' => 'password',
            'host' => 'imap.example.invalid',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'avizo@example.invalid',
            'password' => '',
            'folder' => 'INBOX',
        ], $changes);
    }

    private function call(string $method, array $body): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method === 'updateImapAccount' ? 'PUT' : 'POST', '/api/settings/bank-email-notices/imap-accounts')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 0, 'role' => 'admin'])
            ->withParsedBody($body);
        return $this->action->{$method}($request, new Response(), ['id' => (string) $this->accountId]);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    private function storedPassword(): ?string
    {
        return $this->repository->imapAccount($this->supplierId, $this->accountId, true)['password'] ?? null;
    }

    public function testChangingHostWithoutPasswordIsRefused(): void
    {
        $response = $this->call('updateImapAccount', $this->form(['host' => 'attacker.example.invalid']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('imap_password_required', $this->json($response)['error']['code'] ?? null);
        self::assertSame('imap.example.invalid', $this->repository->imapAccount($this->supplierId, $this->accountId)['host']);
    }

    public function testChangingPortEncryptionOrUsernameWithoutPasswordIsRefused(): void
    {
        foreach ([['port' => 143], ['encryption' => 'none'], ['username' => 'jiny@example.invalid']] as $change) {
            $response = $this->call('updateImapAccount', $this->form($change));
            self::assertSame(422, $response->getStatusCode(), json_encode($change));
        }
    }

    public function testChangingHostWithNewPasswordAndUnrelatedEditsAreAllowed(): void
    {
        $renamed = $this->call('updateImapAccount', $this->form(['name' => 'Přejmenováno', 'host' => 'IMAP.example.invalid ']));
        self::assertSame(200, $renamed->getStatusCode());
        self::assertSame('synthetic-stored-password', $this->storedPassword());

        $moved = $this->call('updateImapAccount', $this->form(['host' => 'novy.example.invalid', 'password' => 'synthetic-new-password']));
        self::assertSame(200, $moved->getStatusCode());
        self::assertSame('novy.example.invalid', $this->repository->imapAccount($this->supplierId, $this->accountId)['host']);
        self::assertSame('synthetic-new-password', $this->storedPassword());
    }

    public function testBrowsingFoldersOnAnotherHostWithStoredPasswordIsRefused(): void
    {
        $response = $this->call('browseImapFolders', $this->form(['host' => 'attacker.example.invalid']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->probes, 'Uložené heslo nesmí odejít na cizí server.');

        $same = $this->call('browseImapFolders', $this->form([]));
        self::assertSame(200, $same->getStatusCode());
        self::assertCount(1, $this->probes);
        self::assertSame('synthetic-stored-password', $this->probes[0]['password']);

        $typed = $this->call('browseImapFolders', $this->form(['host' => 'jiny.example.invalid', 'password' => 'synthetic-typed']));
        self::assertSame(200, $typed->getStatusCode());
        self::assertSame('synthetic-typed', $this->probes[1]['password']);
    }
}
