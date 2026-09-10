<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Http;

use MyInvoice\Service\Http\OutboundRequestException;
use MyInvoice\Service\Http\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;

final class OutboundUrlGuardSizeLimitTest extends TestCase
{
    public function testCurlFileSizeFailureHasTypedPermanentReason(): void
    {
        $failure = OutboundUrlGuard::transferFailureException(
            false,
            false,
            CURLE_FILESIZE_EXCEEDED,
            'Maximum file size exceeded',
        );

        self::assertNotNull($failure);
        self::assertSame(OutboundRequestException::SIZE_LIMIT, $failure->reason);
    }

    public function testWriteCallbackOverflowHasTypedPermanentReason(): void
    {
        $failure = OutboundUrlGuard::transferFailureException(
            false,
            true,
            CURLE_WRITE_ERROR,
            'Failed writing body',
        );

        self::assertNotNull($failure);
        self::assertSame(OutboundRequestException::SIZE_LIMIT, $failure->reason);
    }
}
