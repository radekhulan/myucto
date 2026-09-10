<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EmailProfileRepository;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\RateLimit\MailRateLimiter;
use MyInvoice\Service\Mail\RateLimit\MailSendCounterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class MailerManagedIdentityTest extends TestCase
{
    public static function identities(): iterable
    {
        foreach (['password_reset', 'user_invite', 'login_otp'] as $code) {
            yield $code . ' managed' => [true, $code, true];
            yield $code . ' managed string' => ['true', $code, true];
            yield $code . ' self hosted' => [false, $code, false];
            yield $code . ' false string' => ['false', $code, false];
        }
        yield 'managed invoice' => [true, 'invoice_send', false];
        yield 'managed profile test' => [true, 'email_profile_test', false];
        yield 'managed system ignores explicit profile' => [true, 'password_reset', true, true];
        yield 'self hosted respects explicit profile' => [false, 'password_reset', false, true];
        foreach (['password_reset', 'user_invite', 'login_otp'] as $code) {
            foreach (['cs', 'en'] as $locale) {
                yield $code . ' file ' . $locale => [true, $code, true, false, $locale];
            }
        }
    }

    #[DataProvider('identities')]
    public function testSenderIdentity(bool|string $managed, string $code, bool $system, bool $override = false, ?string $fileLocale = null): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($this->createStub(\PDO::class));
        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('find')->willReturn($fileLocale !== null ? null : [
            'subject' => 'Test',
            'body_html' => '<p>{{ supplier.display_name|default("System") }}</p>',
            'body_text' => '{{ supplier.display_name|default("System") }}',
        ]);
        $profile = [
            'id' => 10, 'supplier_id' => 1, 'code' => 'company',
            'from_email' => 'sender@company.example', 'from_name' => 'Company profile',
            'reply_to_enabled' => true, 'reply_to_email' => 'reply@company.example',
            'reply_to_name' => 'Company reply', 'transport_type' => 'global',
        ];
        $profiles = $this->createMock(EmailProfileRepository::class);
        $profiles->expects($system || $override ? self::never() : self::once())
            ->method('defaultProfile')->willReturn($profile);
        $config = new Config([
            'app' => ['managed' => $managed, 'url' => 'https://tenant.host.example'],
            'smtp' => [
                'from_email' => 'noreply@host.example', 'from_name' => 'Hosted application',
                'reply_to_email' => 'support@host.example', 'reply_to_name' => 'Support',
                'rate_limit' => ['enabled' => false],
            ],
        ]);
        $mailer = new Mailer($config, new NullLogger(), $db, $templates, null, $profiles, null,
            new MailRateLimiter($config, $this->createStub(MailSendCounterInterface::class), new NullLogger()));
        $transport = new class implements TransportInterface {
            public ?Email $message = null;
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->message = $message instanceof Email ? $message : null;
                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }
            public function __toString(): string { return 'capture://'; }
        };
        (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, $transport);
        $vars = [
            'supplier' => ['id' => 1, 'display_name' => 'Company branding', 'email' => 'owner@company.example'],
            'name' => 'Test user', 'resetLink' => 'https://tenant.host.example/reset?token=synthetic',
            'code' => '123456', 'expiresIn' => '10 minutes',
        ];
        if ($fileLocale !== null) {
            (new \ReflectionProperty(Mailer::class, 'supplierFooter'))->setValue($mailer, $vars['supplier']);
            unset($vars['supplier']);
        }
        $mailer->sendTemplate($code, $fileLocale ?? 'cs', ['recipient@example.test'], $vars,
            emailProfileOverride: $override ? $profile : null);
        $email = $transport->message;
        self::assertInstanceOf(Email::class, $email);
        self::assertSame($system ? 'noreply@host.example' : 'sender@company.example', $email->getFrom()[0]->getAddress());
        self::assertSame($system ? 'Hosted application' : 'Company profile', $email->getFrom()[0]->getName());
        if ($system) {
            self::assertSame([], $email->getReplyTo());
        } else {
            self::assertSame('reply@company.example', $email->getReplyTo()[0]->getAddress());
        }
        if ($fileLocale === null) {
            self::assertSame($system ? 'System' : 'Company branding', $email->getTextBody());
        } else {
            self::assertStringNotContainsString('Company branding', $email->getTextBody());
            self::assertStringNotContainsString('owner@company.example', $email->getHtmlBody());
            self::assertStringContainsString($code === 'login_otp' ? '123456' : $vars['resetLink'], $email->getTextBody());
        }
        self::assertSame(filter_var($managed, FILTER_VALIDATE_BOOLEAN), $email->getHeaders()->has('X-MyUcto-Instance'));
    }
}
