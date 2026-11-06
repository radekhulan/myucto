<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\RateLimit\MailRateLimiter;
use MyInvoice\Service\Mail\RateLimit\MailSendCounterInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Poděkování za úhradu zbrzděné limitem odesílání se v aktivitě faktury objevilo
 * jen jako „ve frontě". Skutečné doručení z fronty se k faktuře musí dopsat.
 */
final class DeferredPaymentThanksActivityTest extends TestCase
{
    public function testDeferredPaymentThanksDeliveryIsLoggedOnInvoice(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE email_templates (id INTEGER PRIMARY KEY, code TEXT, locale TEXT, subject TEXT, body_html TEXT, body_text TEXT, updated_at TEXT, updated_by INTEGER)');
        $pdo->exec('CREATE TABLE supplier_email_templates (id INTEGER PRIMARY KEY, supplier_id INTEGER, code TEXT, locale TEXT, subject TEXT, body_html TEXT, body_text TEXT, updated_at TEXT, updated_by INTEGER)');
        $pdo->exec('CREATE TABLE mail_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, not_before TEXT, status TEXT, attempts INTEGER, template TEXT, locale TEXT, recipients INTEGER, defer_reason TEXT, payload TEXT, sent_at TEXT, last_error TEXT)');
        $pdo->prepare('INSERT INTO email_templates (code, locale, subject, body_html, body_text, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['invoice_payment_thanks', 'cs', 'Děkujeme', '<p>Děkujeme {{ invoice.varsymbol }}</p>', 'Děkujeme {{ invoice.varsymbol }}', '2026-01-15 12:00:00']);
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $blocked = true;
        $counter = $this->createStub(MailSendCounterInterface::class);
        $counter->method('sentSince')->willReturnCallback(static function () use (&$blocked): int { return $blocked ? 1 : 0; });
        $counter->method('oldestSince')->willReturn(new \DateTimeImmutable('-30 minutes'));
        $config = new Config(['smtp' => ['from_email' => 'sender@example.test', 'from_name' => 'Synthetic', 'rate_limit' => ['enabled' => true, 'per_hour' => 1, 'per_day' => 100, 'drain_on_cli' => false]]]);
        $activity = new class ($db) extends ActivityLogger {
            /** @var list<array{action:string, entity_id:?int, payload:?array}> */
            public array $entries = [];
            public function log(string $action, ?int $userId = null, ?string $entityType = null, ?int $entityId = null, ?array $payload = null, ?string $ip = null, ?string $userAgent = null, ?int $supplierId = null): void
            {
                $this->entries[] = ['action' => $action, 'entity_id' => $entityId, 'payload' => $payload];
            }
        };
        $mailer = new Mailer($config, new NullLogger(), $db, new EmailTemplateRepository($db),
            rateLimiter: new MailRateLimiter($config, $counter, new NullLogger()), activity: $activity);
        (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, new DeferredThanksCapturingTransport());

        $result = $mailer->sendTemplateDetailed('invoice_payment_thanks', 'cs', ['recipient@example.test'],
            ['invoice' => ['id' => 42, 'varsymbol' => '2026000042']]);
        self::assertTrue($result['deferred']);

        $blocked = false;
        $mailer->flushDeferred(new \DateTimeImmutable('+2 hours'));

        self::assertCount(1, $activity->entries);
        self::assertSame('invoice.payment_thanks_sent', $activity->entries[0]['action']);
        self::assertSame(42, $activity->entries[0]['entity_id']);
        self::assertTrue($activity->entries[0]['payload']['deferred'] ?? null);
        self::assertSame(['recipient@example.test'], $activity->entries[0]['payload']['recipients'] ?? null);
    }
}

final class DeferredThanksCapturingTransport implements TransportInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }
    public function __toString(): string { return 'synthetic://test'; }
}
