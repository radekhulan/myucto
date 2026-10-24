<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\InstanceAdmin;

use MyInvoice\Service\InstanceAdmin\NeutralSmtpTestSender;
use MyInvoice\Service\InstanceAdmin\SmtpTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmtpTargetTest extends TestCase
{
    public function testApprovedTargetHasOnlyTypedDestinationAndIdentity(): void
    {
        $target = SmtpTarget::fromInput(self::validInput());
        self::assertSame('smtp.example.test', $target['host']);
        self::assertSame(587, $target['port']);
        self::assertSame('receiver@example.test', $target['test_recipient']);
        self::assertSame(array_keys(self::validInput()), array_keys($target));
    }

    #[DataProvider('invalidInputs')]
    public function testUnsafeTargetIsRejected(array $change): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SmtpTarget::fromInput(array_replace(self::validInput(), $change));
    }

    public static function invalidInputs(): iterable
    {
        yield 'URL místo hostitele' => [['host' => 'smtp://user:password@smtp.example.test']];
        yield 'URL fragment' => [['host' => 'smtp.example.test#evil.example.test']];
        yield 'SMTP injection' => [['from_email' => "sender@example.test\r\nBcc: other@example.test"]];
        yield 'TLS downgrade' => [['encryption' => 'none']];
        yield 'pole ověření TLS' => [['verify_peer' => false]];
        yield 'příkaz sendmail' => [['sendmail_command' => '/tmp/command']];
        yield 'podvržené ID' => [['id' => 1]];
        yield 'heslo mezi schváleními' => [['password' => 'synthetic-secret']];
        yield 'nulový port' => [['port' => 0]];
        yield 'port nad rozsah' => [['port' => 65536]];
        yield 'neplatný příjemce' => [['test_recipient' => 'not-an-email']];
        yield 'příliš dlouhé jméno' => [['name' => str_repeat('x', 101)]];
    }

    public function testNeutralMessageCannotIncludeCompanyOrCredentialData(): void
    {
        $message = NeutralSmtpTestSender::message([...self::validInput(),
            'password' => 'synthetic-secret', 'supplier' => ['company_name' => 'Synthetic Confidential Company'],
            'subject' => 'Synthetic hidden subject', 'attachments' => ['/tmp/synthetic.pdf']]);
        self::assertSame(['receiver@example.test'], array_map(static fn ($address) => $address->getAddress(), $message->getTo()));
        self::assertSame('sender@example.test', $message->getFrom()[0]->getAddress());
        self::assertSame([], $message->getAttachments());
        self::assertSame([], $message->getCc());
        self::assertSame([], $message->getBcc());
        self::assertNull($message->getHtmlBody());
        self::assertStringNotContainsString('synthetic-secret', $message->toString());
        self::assertStringNotContainsString('Synthetic Confidential Company', $message->toString());
        self::assertStringNotContainsString('Synthetic hidden subject', $message->toString());
    }

    public function testApprovedTransportRequiresTlsAndVerifiesCertificate(): void
    {
        $transport = NeutralSmtpTestSender::transport([...self::validInput(), 'password' => 'synthetic-secret']);
        self::assertTrue($transport->isAutoTls());
        self::assertTrue($transport->isTlsRequired());
        $options = $transport->getStream()->getStreamOptions();
        self::assertTrue($options['ssl']['verify_peer']);
        self::assertTrue($options['ssl']['verify_peer_name']);
        self::assertFalse($options['ssl']['allow_self_signed']);
    }

    private static function validInput(): array
    {
        return ['name' => 'Approved synthetic relay', 'host' => 'SMTP.EXAMPLE.TEST', 'port' => 587,
            'encryption' => 'tls', 'username' => 'smtp-user', 'from_email' => 'sender@example.test',
            'test_recipient' => 'receiver@example.test'];
    }
}
