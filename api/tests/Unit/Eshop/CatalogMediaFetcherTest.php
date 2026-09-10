<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Eshop;

use MyInvoice\Service\Eshop\Import\CatalogMediaFetchException;
use MyInvoice\Service\Eshop\Import\GuardedCatalogMediaFetcher;
use MyInvoice\Service\Http\OutboundRequestException;
use MyInvoice\Service\Http\OutboundResponse;
use MyInvoice\Service\Http\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;

final class CatalogMediaFetcherTest extends TestCase
{
    public function testEveryRedirectIsRevalidatedAndRelativeLocationIsResolved(): void
    {
        $requested = [];
        $fetcher = new GuardedCatalogMediaFetcher(new OutboundUrlGuard(), function (string $url) use (&$requested): OutboundResponse {
            $requested[] = $url;
            return count($requested) === 1
                ? new OutboundResponse(302, '', '', ['location' => '../assets/photo.png'])
                : new OutboundResponse(200, 'png-bytes', 'image/png');
        });

        self::assertSame(
            ['body' => 'png-bytes', 'original_name' => 'photo.png'],
            $fetcher->fetch('https://media.example.test/products/2026/item'),
        );
        self::assertSame([
            'https://media.example.test/products/2026/item',
            'https://media.example.test/products/assets/photo.png',
        ], $requested);
    }

    public function testQueryOnlyRedirectKeepsOriginalPath(): void
    {
        self::assertSame(
            'https://media.example.test/assets/photo.png?token=abc',
            GuardedCatalogMediaFetcher::redirectUrl(
                'https://media.example.test/assets/photo.png?old=1',
                '?token=abc',
            ),
        );
    }

    public function testRedirectToPrivateAddressIsRejectedBeforeSecondRequest(): void
    {
        $calls = 0;
        $fetcher = new GuardedCatalogMediaFetcher(new OutboundUrlGuard(), function () use (&$calls): OutboundResponse {
            $calls++;
            return new OutboundResponse(302, '', '', ['location' => 'https://127.0.0.1/secret.png']);
        });

        try {
            $fetcher->fetch('https://media.example.test/photo.png');
            self::fail('Private redirect must be rejected.');
        } catch (CatalogMediaFetchException $e) {
            self::assertSame('media_url_blocked', $e->errorCode);
            self::assertFalse($e->retryable);
            self::assertSame(1, $calls);
        }
    }

    public function testDnsFailureIsRetryable(): void
    {
        $fetcher = new GuardedCatalogMediaFetcher(new OutboundUrlGuard());

        try {
            $fetcher->fetch('https://media.invalid/photo.png');
            self::fail('Unresolvable DNS target must fail.');
        } catch (CatalogMediaFetchException $e) {
            self::assertTrue($e->retryable);
            self::assertSame('media_download_failed', $e->errorCode);
        }
    }

    public function testDnsResolvedPrivateTargetIsPermanent(): void
    {
        $fetcher = new GuardedCatalogMediaFetcher(
            new OutboundUrlGuard(),
            static fn (): never => throw new OutboundRequestException(
                'Blocked target.',
                OutboundRequestException::TARGET_BLOCKED,
            ),
        );

        try {
            $fetcher->fetch('https://media.example.test/photo.png');
            self::fail('A DNS-resolved private target must fail permanently.');
        } catch (CatalogMediaFetchException $e) {
            self::assertSame('media_url_blocked', $e->errorCode);
            self::assertFalse($e->retryable);
        }
    }

    public function testResponseOverLimitIsRejectedEvenByFakeTransport(): void
    {
        $fetcher = new GuardedCatalogMediaFetcher(
            new OutboundUrlGuard(),
            static fn (): OutboundResponse => new OutboundResponse(200, str_repeat('x', GuardedCatalogMediaFetcher::MAX_BYTES + 1), 'image/png'),
        );

        $this->expectExceptionObject(new CatalogMediaFetchException('media_file_too_large'));
        $fetcher->fetch('https://media.example.test/photo.png');
    }

    public function testTypedTransportSizeLimitIsPermanent(): void
    {
        $fetcher = new GuardedCatalogMediaFetcher(
            new OutboundUrlGuard(),
            static fn (): never => throw new OutboundRequestException(
                'Maximum file size exceeded',
                OutboundRequestException::SIZE_LIMIT,
            ),
        );

        try {
            $fetcher->fetch('https://media.example.test/photo.png');
            self::fail('A transport size limit must fail permanently.');
        } catch (CatalogMediaFetchException $e) {
            self::assertSame('media_file_too_large', $e->errorCode);
            self::assertFalse($e->retryable);
        }
    }

    public function testServerFailureIsRetryableWithoutLeakingUrlInError(): void
    {
        $fetcher = new GuardedCatalogMediaFetcher(
            new OutboundUrlGuard(),
            static fn (): OutboundResponse => new OutboundResponse(503, 'unavailable', 'text/plain'),
        );

        try {
            $fetcher->fetch('https://media.example.test/private-name.png');
            self::fail('503 must fail.');
        } catch (CatalogMediaFetchException $e) {
            self::assertTrue($e->retryable);
            self::assertSame('media_remote_unavailable', $e->getMessage());
            self::assertStringNotContainsString('private-name', $e->getMessage());
        }
    }
}
