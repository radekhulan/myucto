<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\EmailNotice;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;
use MyInvoice\Service\Bank\EmailNotice\WebklexImapMailboxClient;
use MyInvoice\Service\Mail\Imap\ImapAuthentication;
use MyInvoice\Service\OAuth\OAuthTokenClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use PHPUnit\Framework\Attributes\DataProvider;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\FolderCollection;
use Webklex\PHPIMAP\Support\MessageCollection;

final class WebklexImapMailboxClientTest extends TestCase
{
    #[DataProvider('postProcessCases')]
    public function testImportedNoticeIsMarkedBeforeConfiguredAction(bool $imported, bool $enabled, string $kind, string $action): void
    {
        $mark = ($imported && $enabled) || ($kind === 'success' && $action === 'mark_seen');
        $imapMessage = $this->createMock(Message::class);
        $marked = false;
        $imapMessage->expects($mark ? self::once() : self::never())->method('setFlag')->with('Seen')
            ->willReturnCallback(static function () use (&$marked): bool { $marked = true; return true; });
        $imapMessage->expects($mark ? self::once() : self::never())->method('hasFlag')->with('seen')->willReturn(true);
        $imapMessage->expects($action === 'move' ? self::once() : self::never())->method('move')->with('Archive')
            ->willReturnCallback(static function () use (&$marked, $mark): ?Message {
                self::assertSame($mark, $marked, 'Příznak se zapisuje před přesunem, který mění UID.');
                return null;
            });
        $client = $this->imapClient($imapMessage, $mark || $action === 'move');
        $result = $client->postProcess([
            'mark_seen_after_import' => $enabled,
            $kind . '_action' => $action,
            $kind . '_move_folder' => 'Archive',
        ], $this->notice(), $kind, $imported);

        self::assertNull($result);
    }

    public static function postProcessCases(): iterable
    {
        yield 'uložené nespárované avízo' => [true, true, 'failure', 'none'];
        yield 'uložené spárované avízo' => [true, true, 'success', 'none'];
        yield 'před přesunem úspěšného avíza' => [true, true, 'success', 'move'];
        yield 'před přesunem nespárovaného avíza' => [true, true, 'failure', 'move'];
        yield 'bez dvojího označení' => [true, true, 'success', 'mark_seen'];
        yield 'vypnutá volba' => [true, false, 'failure', 'none'];
        yield 'neuložená zpráva' => [false, true, 'failure', 'none'];
        yield 'PDF příloha bez avíza' => [false, true, 'success', 'none'];
        yield 'dosavadní označení po úspěchu' => [false, false, 'success', 'mark_seen'];
    }

    #[DataProvider('flagFailures')]
    public function testMarkSeenFailureIsReportedAndConnectionClosed(bool $stored, bool $seen): void
    {
        $message = $this->createMock(Message::class);
        $message->expects(self::once())->method('setFlag')->with('Seen')->willReturn($stored);
        $message->method('hasFlag')->willReturn($seen);
        $message->expects(self::never())->method('move');
        $error = $this->imapClient($message, true)->postProcess([
            'mark_seen_after_import' => true, 'failure_action' => 'move', 'failure_move_folder' => 'Archive',
        ], $this->notice(), 'failure', true);

        self::assertNotNull($error);
        self::assertStringContainsString('přečten', $error);
    }

    public static function flagFailures(): iterable
    {
        yield 'server nepotvrdil zápis' => [false, false];
        yield 'server nevrátil příznak' => [true, false];
    }

    public function testMicrosoft365ListsFetchesAndPostProcessesWithXoauth2(): void
    {
        $message = $this->createMock(Message::class);
        $message->expects(self::once())->method('setFlag')->with('Seen')->willReturn(true);
        $message->expects(self::once())->method('hasFlag')->with('seen')->willReturn(true);
        $query = $this->createMock(WhereQuery::class);
        $query->expects(self::once())->method('__call')->with('all', [])->willReturnSelf();
        $query->method('leaveUnread')->willReturnSelf();
        $query->method('fetchOrderDesc')->willReturnSelf();
        $query->method('limit')->willReturnSelf();
        $query->expects(self::once())->method('get')->willReturn(MessageCollection::make([]));
        $query->expects(self::once())->method('getMessageByUid')->with(42)->willReturn($message);
        $folder = $this->createMock(Folder::class);
        $folder->path = 'INBOX';
        $folder->expects(self::exactly(2))->method('query')->willReturn($query);
        $client = $this->createMock(PostProcessImapClientStub::class);
        $client->expects(self::exactly(3))->method('connect')->willReturnSelf();
        $client->expects(self::exactly(3))->method('disconnect')->willReturnSelf();
        $client->method('getFolders')->willReturn(FolderCollection::make([$folder]));
        $client->method('getFolderByPath')->willReturn($folder);
        $http = new HttpClient(['handler' => new MockHandler([
            new Response(200, [], '{"access_token":"synthetic-bearer","expires_in":3600}'),
        ])]);
        $authentication = new ImapAuthentication(
            new OAuthTokenClient($http),
            new SecretEncryption(new Config([])),
            new MockClock(),
        );
        $mailbox = new WebklexImapMailboxClient(new EmailNoticeTextNormalizer(), static function (array $settings) use ($client): Client {
            self::assertSame('oauth', $settings['authentication']);
            self::assertSame('synthetic-bearer', $settings['password']);
            self::assertSame('shared@example.test', $settings['username']);
            return $client;
        }, $authentication);
        $settings = [
            'auth_mode' => 'microsoft365',
            'username' => 'shared@example.test',
            'oauth_tenant_id' => '11111111-1111-4111-8111-111111111111',
            'oauth_client_id' => '22222222-2222-4222-8222-222222222222',
            'oauth_client_secret' => 'synthetic-client-secret',
            'mark_seen_after_import' => true,
        ];
        self::assertSame(['INBOX'], $mailbox->test($settings)['folders']);
        self::assertSame([], $mailbox->latest($settings, 10));
        self::assertNull($mailbox->postProcess($settings, $this->notice(), 'success', true));
    }

    private function imapClient(Message $message, bool $connect): WebklexImapMailboxClient
    {
        $query = $this->createMock(WhereQuery::class);
        $query->method('leaveUnread')->willReturnSelf();
        $query->expects($connect ? self::once() : self::never())->method('getMessageByUid')->with(42)->willReturn($message);
        $folder = $this->createMock(Folder::class);
        $folder->expects($connect ? self::once() : self::never())->method('query')->willReturn($query);
        $client = $this->createMock(PostProcessImapClientStub::class);
        $client->expects($connect ? self::once() : self::never())->method('connect')->willReturnSelf();
        $client->expects($connect ? self::once() : self::never())->method('disconnect')->willReturnSelf();
        $client->method('getFolderByPath')->willReturn($folder);
        return new WebklexImapMailboxClient(new EmailNoticeTextNormalizer(), static fn (): Client => $client);
    }

    private function notice(): BankEmailNoticeMessage
    {
        return new BankEmailNoticeMessage(42, '<notice@example.test>', null, 'bank@example.test', 'Avízo', '', '');
    }

    /**
     * Regrese: ve Webklexu je getUid() magická metoda (jen @method + __call), takže
     * method_exists($msg, 'getUid') === false. Guard `method_exists(...) ? ... : null`
     * proto zapisoval uid = null pro každou zprávu → postProcess se hned vrátil a
     * „označit jako přečtené" (a add_flag/move) se nikdy nespustilo.
     */
    public function testUidIsReadFromMagicGetUidAccessor(): void
    {
        $message = new MagicWebklexMessageStub([
            'getUid' => 42,
            'getMessageId' => '<mid@bank.cz>',
            'getFrom' => 'noreply@bank.cz',
            'getSubject' => 'Avízo o platbě',
            'getDate' => '2026-01-15 10:00:00',
            'getHTMLBody' => '',
            'getTextBody' => 'Přijatá platba 1000 CZK',
        ]);

        // Přesně vlastnost, která bug způsobila: magickou getUid() method_exists nevidí.
        self::assertFalse(method_exists($message, 'getUid'));

        $client = new WebklexImapMailboxClient(new EmailNoticeTextNormalizer());
        $toNoticeMessage = new \ReflectionMethod(WebklexImapMailboxClient::class, 'toNoticeMessage');

        /** @var BankEmailNoticeMessage $notice */
        $notice = $toNoticeMessage->invoke($client, $message, ['allow_forwarded' => false, 'forwarded_from' => '']);

        self::assertSame(42, $notice->uid);
        self::assertSame('<mid@bank.cz>', $notice->messageId);
    }
}

/** Destruktor Webklexu znovu volá disconnect; test počítá jen explicitní odpojení. */
class PostProcessImapClientStub extends Client
{
    public function __destruct() {}
}

/**
 * Napodobuje Webklex\PHPIMAP\Message: accessory (getUid, getFrom, …) existují jen
 * přes magické __call, takže method_exists() je na ně false — přesně jako v knihovně.
 */
final class MagicWebklexMessageStub
{
    /** @param array<string,mixed> $values */
    public function __construct(private readonly array $values) {}

    public function __call(string $method, array $arguments): mixed
    {
        return $this->values[$method] ?? '';
    }
}
