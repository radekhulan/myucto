<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Repository\Submission\SubmissionChannelCredentialRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use PHPUnit\Framework\TestCase;

/**
 * `hasDataBox()` je doklad, na kterém stojí dostupnost Mobilního klíče v
 * {@see \MyInvoice\Service\Submission\Channel\Isds\IsdsTransportAvailabilityResolver}
 * — nesmí vyžadovat dešifrovací klíč (čte jen veřejnou projekci) a musí
 * rozlišit prostředí.
 */
final class SubmissionCredentialServiceHasDataBoxTest extends TestCase
{
    public function testTrueWhenACompanyBoxIsOnFile(): void
    {
        $repository = $this->createStub(SubmissionChannelCredentialRepository::class);
        $repository->method('findPublic')->willReturn(['id' => 1]);

        $service = new SubmissionCredentialService($repository, $this->createStub(SecretEncryption::class));

        self::assertTrue($service->hasDataBox(7, 'production'));
    }

    public function testFalseWhenNothingIsSaved(): void
    {
        $repository = $this->createStub(SubmissionChannelCredentialRepository::class);
        $repository->method('findPublic')->willReturn(null);

        $service = new SubmissionCredentialService($repository, $this->createStub(SecretEncryption::class));

        self::assertFalse($service->hasDataBox(7, 'test'));
    }

    public function testUnknownEnvironmentIsRejected(): void
    {
        $repository = $this->createStub(SubmissionChannelCredentialRepository::class);
        $service = new SubmissionCredentialService($repository, $this->createStub(SecretEncryption::class));

        $this->expectException(SubmissionChannelException::class);
        $service->hasDataBox(7, 'staging');
    }
}
