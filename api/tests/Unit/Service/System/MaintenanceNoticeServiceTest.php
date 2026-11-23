<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\MaintenanceNoticeService;
use MyInvoice\Service\System\ManagedModeGuard;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class MaintenanceNoticeServiceTest extends TestCase
{
    private PDO $sqlite;
    private string $localFile;

    protected function setUp(): void
    {
        $this->sqlite = new PDO('sqlite::memory:');
        $this->sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->sqlite->exec('CREATE TABLE app_meta (k TEXT PRIMARY KEY, v TEXT)');
        $this->localFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mn-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->localFile)) {
            unlink($this->localFile);
        }
    }

    public function testRefreshStoresNoticeAndShowsItOnlyInsideWindow(): void
    {
        $history = [];
        $service = $this->service([$this->feed([$this->notice()])], $history, managed: true);

        $result = $service->refresh($this->at('2026-11-10T10:00:00Z'));

        self::assertTrue($result['ok']);
        self::assertSame(1, $result['count']);
        self::assertStringContainsString('audience=managed', (string) $history[0]['request']->getUri());

        self::assertSame([], $service->active($this->at('2026-11-16T23:59:00Z')), 'před show_from se neukazuje');
        $visible = $service->active($this->at('2026-11-18T12:00:00Z'));
        self::assertCount(1, $visible);
        self::assertSame('remote:7@20261110090000', $visible[0]['key']);
        self::assertSame('2026-11-20T20:00:00+00:00', $visible[0]['starts_at']);
        self::assertArrayNotHasKey('show_from', $visible[0]);
        self::assertSame([], $service->active($this->at('2026-11-20T22:00:00Z')), 'po konci zmizí sama');
    }

    public function testMarkupIsStrippedAndInvalidItemsAreDropped(): void
    {
        $history = [];
        $service = $this->service([$this->feed([
            $this->notice(['message' => ['cs' => '<b>Odstávka</b><script>alert(1)</script>', 'en' => '']]),
            $this->notice(['id' => 8, 'ends_at' => '2026-11-20T19:00:00Z']),
            $this->notice(['id' => 9, 'starts_at' => 'zítra']),
            $this->notice(['id' => 10, 'message' => ['cs' => '', 'en' => '']]),
            $this->notice(['id' => 11, 'severity' => 'evil']),
        ])], $history);

        self::assertSame(2, $service->refresh($this->at('2026-11-18T00:00:00Z'))['count']);
        $visible = $service->active($this->at('2026-11-18T12:00:00Z'));

        self::assertSame('Odstávkaalert(1)', $visible[0]['message']['cs']);
        self::assertSame('Odstávkaalert(1)', $visible[0]['message']['en'], 'chybějící jazyk se doplní druhým');
        self::assertSame('warning', $visible[1]['severity']);
    }

    public function testNetworkErrorKeepsLastKnownNotice(): void
    {
        $history = [];
        $service = $this->service([
            $this->feed([$this->notice()]),
            new ConnectException('down', new Request('GET', 'x')),
            new Response(500, [], 'oops'),
        ], $history);

        self::assertTrue($service->refresh($this->at('2026-11-18T00:00:00Z'))['ok']);
        self::assertFalse($service->refresh($this->at('2026-11-18T00:15:00Z'))['ok']);
        self::assertFalse($service->refresh($this->at('2026-11-18T00:30:00Z'))['ok']);

        self::assertCount(1, $service->active($this->at('2026-11-18T12:00:00Z')));
    }

    public function testEmptyFeedClearsWithdrawnNotice(): void
    {
        $history = [];
        $service = $this->service([$this->feed([$this->notice()]), $this->feed([])], $history);

        $service->refresh($this->at('2026-11-18T00:00:00Z'));
        $service->refresh($this->at('2026-11-18T00:15:00Z'));

        self::assertSame([], $service->active($this->at('2026-11-18T12:00:00Z')));
    }

    public function testEditedNoticeGetsNewKeySoDismissalResets(): void
    {
        $history = [];
        $service = $this->service([
            $this->feed([$this->notice()]),
            $this->feed([$this->notice(['revision' => '20261112080000'])]),
        ], $history);

        $service->refresh($this->at('2026-11-18T00:00:00Z'));
        $before = $service->active($this->at('2026-11-18T12:00:00Z'))[0]['key'];
        $service->refresh($this->at('2026-11-18T00:15:00Z'));
        $after = $service->active($this->at('2026-11-18T12:00:00Z'))[0]['key'];

        self::assertNotSame($before, $after);
    }

    public function testLocalFileNoticeIsShownWithoutFeed(): void
    {
        file_put_contents($this->localFile, json_encode([
            'starts_at' => '2026-11-20T20:00:00+01:00',
            'ends_at'   => '2026-11-20T21:00:00+01:00',
            'message'   => ['cs' => 'Výměna disku'],
        ], JSON_THROW_ON_ERROR));
        $history = [];
        $service = $this->service([], $history);

        $visible = $service->active($this->at('2026-11-19T12:00:00Z'));

        self::assertCount(1, $visible);
        self::assertStringStartsWith('local:', $visible[0]['key']);
        self::assertSame('2026-11-20T19:00:00+00:00', $visible[0]['starts_at']);
        self::assertSame([], $service->active($this->at('2026-11-16T12:00:00Z')), 'bez show_from 3 dny předem');
    }

    public function testFeedUrlDefaultsToLicenseServer(): void
    {
        $history = [];
        self::assertSame('https://lic.example/api/maintenance-notice', $this->service([], $history)->feedUrl());
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param array<int,array<string,mixed>> $history
     */
    private function service(array $responses, array &$history, bool $managed = false): MaintenanceNoticeService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $config = new Config([
            'app' => ['managed' => $managed],
            'license' => ['server_url' => 'https://lic.example/'],
        ]);

        return new MaintenanceNoticeService(
            $config,
            $this->connection(),
            new ManagedModeGuard($config),
            new Client(['handler' => $stack]),
            $this->localFile,
        );
    }

    private function connection(): Connection
    {
        $sqlite = $this->sqlite;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(static fn (string $sql) => $sqlite->prepare(str_replace(
            'ON DUPLICATE KEY UPDATE v = VALUES(v)',
            'ON CONFLICT(k) DO UPDATE SET v = excluded.v',
            $sql,
        )));
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $db->method('hasTable')->willReturn(true);

        return $db;
    }

    /** @param list<array<string,mixed>> $notices */
    private function feed(array $notices): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => true, 'notices' => $notices], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function notice(array $override = []): array
    {
        return $override + [
            'id'        => 7,
            'revision'  => '20261110090000',
            'severity'  => 'warning',
            'show_from' => '2026-11-17T08:00:00Z',
            'starts_at' => '2026-11-20T20:00:00Z',
            'ends_at'   => '2026-11-20T22:00:00Z',
            'message'   => ['cs' => 'Plánovaná údržba databáze.', 'en' => 'Planned database maintenance.'],
        ];
    }

    private function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }
}
