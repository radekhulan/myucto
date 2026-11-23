<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Mail\Imap\ImapAuthentication;
use MyInvoice\Service\Mail\SentMailImapAppender;
use MyInvoice\Service\OAuth\OAuthTokenClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Support\FolderCollection;

final class SentMailImapAppenderTest extends TestCase
{
    public function testSkippedWhenNoProfileOrDisabled(): void
    {
        $appender = new SentMailImapAppender();

        self::assertSame(
            ['status' => 'skipped', 'folder' => null, 'error' => null],
            $appender->appendIfEnabled(null, "Subject: Test\r\n\r\nBody"),
        );

        self::assertSame(
            ['status' => 'skipped', 'folder' => null, 'error' => null],
            $appender->appendIfEnabled(['imap_sent_enabled' => false], "Subject: Test\r\n\r\nBody"),
        );
    }

    public function testEnabledProfileWithoutPasswordFailsBeforeConnecting(): void
    {
        $result = (new SentMailImapAppender())->appendIfEnabled([
            'imap_sent_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_username' => 'user@example.test',
            'imap_folder' => 'Sent',
        ], "Subject: Test\r\n\r\nBody");

        self::assertSame('failed', $result['status']);
        self::assertSame('Sent', $result['folder']);
        self::assertStringContainsString('imap_password', (string) $result['error']);
    }

    public function testAppendUsesExistingFolderWithSeenFlagAndConfiguredTimeout(): void
    {
        $folder = new FakeImapFolder('Sent/Items');
        $client = new FakeImapClient();
        $client->foldersByPath['Sent/Items'] = $folder;

        $capturedSettings = null;
        $appender = new SentMailImapAppender(function (array $settings) use ($client, &$capturedSettings): Client {
            $capturedSettings = $settings;
            return $client;
        });

        $result = $appender->appendIfEnabled($this->profile([
            'imap_folder' => 'Sent/Items',
            'imap_timeout' => 12,
        ]), "Subject: Test\r\n\r\nBody");

        self::assertSame(['status' => 'saved', 'folder' => 'Sent/Items', 'error' => null], $result);
        self::assertSame(12, $capturedSettings['timeout'] ?? null);
        self::assertSame(1, $client->connects);
        self::assertSame(1, $client->disconnects);
        self::assertCount(1, $folder->appends);
        self::assertSame("Subject: Test\r\n\r\nBody", $folder->appends[0]['message']);
        self::assertSame(['\Seen'], $folder->appends[0]['options']);
        self::assertIsString($folder->appends[0]['internal_date']);
    }

    public function testAppendCanStoreWithoutSeenFlag(): void
    {
        $folder = new FakeImapFolder('Sent');
        $client = new FakeImapClient();
        $client->foldersByPath['Sent'] = $folder;

        $result = (new SentMailImapAppender(static fn (): Client => $client))
            ->appendIfEnabled($this->profile(['imap_mark_seen' => false]), "Subject: Test\r\n\r\nBody");

        self::assertSame('saved', $result['status']);
        self::assertSame(null, $folder->appends[0]['options']);
    }

    public function testAppendCreatesMissingFolderWhenEnabled(): void
    {
        $createdFolder = new FakeImapFolder('New/Sent');
        $client = new FakeImapClient();
        $client->createdFolder = $createdFolder;

        $result = (new SentMailImapAppender(static fn (): Client => $client))
            ->appendIfEnabled($this->profile([
                'imap_folder' => 'New/Sent',
                'imap_create_folder' => true,
            ]), "Subject: Test\r\n\r\nBody");

        self::assertSame('saved', $result['status']);
        self::assertSame('New/Sent', $result['folder']);
        self::assertSame(['New/Sent'], $client->createdPaths);
        self::assertSame("Subject: Test\r\n\r\nBody", $createdFolder->appends[0]['message']);
    }

    public function testAppendFailsWhenFolderDoesNotExistAndCreationIsDisabled(): void
    {
        $client = new FakeImapClient();

        $result = (new SentMailImapAppender(static fn (): Client => $client))
            ->appendIfEnabled($this->profile(['imap_folder' => 'Missing']), "Subject: Test\r\n\r\nBody");

        self::assertSame('failed', $result['status']);
        self::assertSame('Missing', $result['folder']);
        self::assertSame([], $client->createdPaths);
        self::assertStringContainsString('IMAP složka nebyla nalezena', (string) $result['error']);
    }

    public function testAppendSanitizesBadCredentialsError(): void
    {
        $client = new FakeImapClient();
        $client->connectError = new \RuntimeException('AUTH failed for user@example.test password=s3cret-pass');

        $result = (new SentMailImapAppender(static fn (): Client => $client))
            ->appendIfEnabled($this->profile(), "Subject: Test\r\n\r\nBody");

        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('AUTH failed', (string) $result['error']);
        self::assertStringNotContainsString('user@example.test', (string) $result['error']);
        self::assertStringNotContainsString('s3cret-pass', (string) $result['error']);
    }

    public function testAppendSanitizesTimeoutErrorAndUsesConfiguredTimeout(): void
    {
        $client = new FakeImapClient();
        $client->connectError = new \RuntimeException('Connection timed out in /home/zdjur/web/myinvoice/api/vendor/imap.php');

        $capturedSettings = null;
        $appender = new SentMailImapAppender(function (array $settings) use ($client, &$capturedSettings): Client {
            $capturedSettings = $settings;
            return $client;
        });

        $result = $appender->appendIfEnabled($this->profile(['imap_timeout' => 7]), "Subject: Test\r\n\r\nBody");

        self::assertSame('failed', $result['status']);
        self::assertSame(7, $capturedSettings['timeout'] ?? null);
        self::assertStringContainsString('Connection timed out', (string) $result['error']);
        self::assertStringNotContainsString('/home/zdjur', (string) $result['error']);
    }

    public function testFolderBrowseWithoutPasswordFailsBeforeConnecting(): void
    {
        $result = (new SentMailImapAppender())->folders([
            'imap_sent_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_username' => 'user@example.test',
            'imap_folder' => 'Sent',
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('imap_password', $result['message']);
    }

    public function testConnectionTestWithoutPasswordFailsBeforeConnecting(): void
    {
        $result = (new SentMailImapAppender())->test([
            'imap_sent_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_username' => 'user@example.test',
            'imap_folder' => 'Sent',
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('imap_password', $result['message']);
    }

    public function testConnectionTestPerformsAppendProbeAndReturnsFolderMetadata(): void
    {
        $folder = new FakeImapFolder('Sent');
        $client = new FakeImapClient();
        $client->foldersByPath['Sent'] = $folder;
        $client->folders = FolderCollection::make([new FakeImapFolder('Sent')]);

        $result = (new SentMailImapAppender(static fn (): Client => $client))->test($this->profile());

        self::assertTrue($result['ok']);
        self::assertStringContainsString('zápis testovací zprávy', $result['message']);
        self::assertStringContainsString('X-MyInvoice-IMAP-Test: 1', $folder->appends[0]['message']);
        self::assertSame(['\Seen'], $folder->appends[0]['options']);
        self::assertSame('Sent', $result['folders'][0]['path'] ?? null);
        self::assertTrue($result['folders'][0]['writable'] ?? false);
        self::assertTrue($result['folders'][0]['sent'] ?? false);
    }

    public function testFolderBrowseReturnsDelimiterAndNonWritableMetadata(): void
    {
        $client = new FakeImapClient();
        $client->folders = FolderCollection::make([
            new FakeImapFolder('INBOX', '/', []),
            new FakeImapFolder('Sent Items', '/', []),
            new FakeImapFolder('Archive', '/', ['\NoSelect', '\HasChildren']),
        ]);

        $result = (new SentMailImapAppender(static fn (): Client => $client))->folders($this->profile());

        self::assertTrue($result['ok']);
        self::assertSame('Archive', $result['folders'][0]['path'] ?? null);
        self::assertSame('/', $result['folders'][0]['delimiter'] ?? null);
        self::assertFalse($result['folders'][0]['writable'] ?? true);
        self::assertTrue($result['folders'][0]['no_select'] ?? false);
        self::assertTrue($result['folders'][0]['has_children'] ?? false);
        self::assertSame('Sent Items', $result['folders'][2]['path'] ?? null);
        self::assertTrue($result['folders'][2]['sent'] ?? false);
        self::assertTrue($result['folders'][2]['system'] ?? false);
    }

    public function testMicrosoft365ListsFoldersAndArchivesWithXoauth2(): void
    {
        $folder = new FakeImapFolder('Sent');
        $client = new FakeImapClient();
        $client->foldersByPath['Sent'] = $folder;
        $client->folders = FolderCollection::make([$folder]);
        $http = new HttpClient(['handler' => new MockHandler([
            new Response(200, [], '{"access_token":"synthetic-bearer","expires_in":3600}'),
        ])]);
        $authentication = new ImapAuthentication(
            new OAuthTokenClient($http),
            new SecretEncryption(new Config([])),
            new MockClock(),
        );
        $appender = new SentMailImapAppender(static function (array $settings) use ($client): Client {
            self::assertSame('outlook.office365.com', $settings['host']);
            self::assertTrue($settings['validate_cert']);
            self::assertSame('oauth', $settings['authentication']);
            self::assertSame('synthetic-bearer', $settings['password']);
            self::assertSame('shared@example.test', $settings['username']);
            return $client;
        }, $authentication);
        $profile = $this->profile([
            'imap_auth_mode' => 'microsoft365',
            'imap_username' => 'shared@example.test',
            'imap_password' => null,
            'imap_oauth_tenant_id' => '11111111-1111-4111-8111-111111111111',
            'imap_oauth_client_id' => '22222222-2222-4222-8222-222222222222',
            'imap_oauth_client_secret' => 'synthetic-client-secret',
        ]);

        self::assertSame('Sent', $appender->folders($profile)['folders'][0]['path']);
        self::assertTrue($appender->test($profile)['ok']);
        self::assertSame('saved', $appender->appendIfEnabled($profile, 'Syntetická zpráva')['status']);
        self::assertSame('Syntetická zpráva', $folder->appends[1]['message']);

        $encoded = base64_encode("user=shared@example.test\x01auth=Bearer synthetic-bearer\x01\x01");
        $client->connectError = new \RuntimeException('NO AUTHENTICATE failed. XOAUTH2 ' . $encoded . ' synthetic-bearer');
        $result = $appender->appendIfEnabled($profile, 'Syntetická zpráva');
        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('[imap_auth_rejected]', $result['error']);
        self::assertStringNotContainsString($encoded, $result['error']);
        self::assertStringNotContainsString('synthetic-bearer', $result['error']);
        $client->connectError = null;
        $folder->appendError = new \RuntimeException('synthetic-client-secret');
        $result = $appender->appendIfEnabled($profile, 'Syntetická zpráva');
        self::assertStringContainsString('[imap_write_failed]', $result['error']);
        self::assertStringNotContainsString('synthetic-client-secret', $result['error']);
    }

    public function testErrorMessageSanitizationRedactsCredentialsAndLocalPaths(): void
    {
        $method = new \ReflectionMethod(SentMailImapAppender::class, 'sanitizedErrorMessage');
        $message = $method->invoke(
            new SentMailImapAppender(),
            new \RuntimeException(
                'Cannot open imap://user@example.test:s3cret-pass@imap.example.test/Sent '
                . 'password=s3cret-pass username=user@example.test in /home/zdjur/web/myinvoice/api/vendor/lib.php '
                . 'and C:\\inetpub\\wwwroot\\myinvoice\\api\\vendor\\lib.php',
            ),
            [
                'username' => 'user@example.test',
                'password' => 's3cret-pass',
            ],
            null,
        );

        self::assertIsString($message);
        self::assertStringNotContainsString('s3cret-pass', $message);
        self::assertStringNotContainsString('user@example.test', $message);
        self::assertStringNotContainsString('/home/zdjur', $message);
        self::assertStringNotContainsString('C:\\inetpub', $message);
        self::assertStringContainsString('[redacted]', $message);
        self::assertStringContainsString('[path]', $message);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function profile(array $overrides = []): array
    {
        return array_replace([
            'imap_sent_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_validate_cert' => true,
            'imap_username' => 'user@example.test',
            'imap_password' => 's3cret-pass',
            'imap_folder' => 'Sent',
            'imap_create_folder' => false,
            'imap_mark_seen' => true,
            'imap_timeout' => 30,
            'imap_on_failure' => 'log_only',
        ], $overrides);
    }
}

final class FakeImapClient extends Client
{
    public int $connects = 0;
    public int $disconnects = 0;
    public ?\Throwable $connectError = null;
    /** @var array<string,Folder|null> */
    public array $foldersByPath = [];
    public FolderCollection $folders;
    public ?Folder $createdFolder = null;
    /** @var list<string> */
    public array $createdPaths = [];

    public function __construct()
    {
        $this->folders = FolderCollection::make([]);
    }

    public function __destruct() {}

    public function connect(): Client
    {
        $this->connects++;
        if ($this->connectError !== null) {
            throw $this->connectError;
        }

        return $this;
    }

    public function disconnect(): Client
    {
        $this->disconnects++;
        return $this;
    }

    public function getFolderByPath($folder_path, bool $utf7 = false, bool $soft_fail = false): ?Folder
    {
        return $this->foldersByPath[(string) $folder_path] ?? null;
    }

    public function getFolders(bool $hierarchical = true, ?string $parent_folder = null, bool $soft_fail = false): FolderCollection
    {
        return $this->folders;
    }

    public function createFolder(string $folder_path, bool $expunge = true, bool $utf7 = false): Folder
    {
        $this->createdPaths[] = $folder_path;
        if ($this->createdFolder === null) {
            $this->createdFolder = new FakeImapFolder($folder_path);
        }

        return $this->createdFolder;
    }

    public function getDefaultEvents($section): array
    {
        return [];
    }
}

final class FakeImapFolder extends Folder
{
    /** @var list<array{message:string,options:?list<string>,internal_date:mixed}> */
    public array $appends = [];
    public ?\Throwable $appendError = null;

    /**
     * @param list<string> $attributes
     */
    public function __construct(string $path = 'Sent', string $delimiter = '/', array $attributes = [])
    {
        $this->path = $path;
        $this->full_name = $path;
        $parts = $delimiter !== '' ? explode($delimiter, $path) : [$path];
        $this->name = (string) end($parts);
        $this->delimiter = $delimiter;
        $this->children = FolderCollection::make([]);
        $this->no_inferiors = in_array('\NoInferiors', $attributes, true) || in_array('\Noinferiors', $attributes, true);
        $this->no_select = in_array('\NoSelect', $attributes, true) || in_array('\Noselect', $attributes, true);
        $this->marked = in_array('\Marked', $attributes, true);
        $this->referral = in_array('\Referral', $attributes, true);
        $this->has_children = in_array('\HasChildren', $attributes, true);
        $this->status = [];
    }

    public function appendMessage(string $message, ?array $options = null, \Carbon\Carbon|string|null $internal_date = null): array
    {
        if ($this->appendError !== null) {
            throw $this->appendError;
        }
        $this->appends[] = [
            'message' => $message,
            'options' => $options,
            'internal_date' => $internal_date,
        ];

        return [];
    }
}
