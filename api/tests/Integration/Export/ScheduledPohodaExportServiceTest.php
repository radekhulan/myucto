<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Export;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\ExportPeriodResolver;
use MyInvoice\Service\Export\PohodaXmlExporter;
use MyInvoice\Service\Export\ScheduledPohodaExportService;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\SupplierEmailContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ScheduledPohodaExportServiceTest extends TestCase
{
    private Connection $db;
    private $container;
    private int $supplierId = 0;
    /** @var list<array{vars:array<string,mixed>,attachments:array<int,array<string,mixed>>}> */
    private array $sent = [];
    private ?\Throwable $mailFailure = null;
    private array $mailResult = ['smtp_response' => '250 ok'];
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->now = new \DateTimeImmutable('2026-11-10');
        $pdo = $this->db->pdo();
        $cur = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $cty = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        if (!$cur || !$vat || !$cty) {
            $this->markTestSkipped('Chybí základní data.');
        }
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                       VALUES ("Plán s.r.o.", "Testovaci 1", "Praha", "11000", ?, "p@example.com", ?, ?)')->execute([$cty, $cur, $vat]);
        $this->supplierId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    private function service(): ScheduledPohodaExportService
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendTemplateDetailed')->willReturnCallback(
            function (string $code, string $locale, array $to, array $vars, ?string $subject = null, array $cc = [], array $bcc = [], array $attachments = []): array {
                if ($this->mailFailure !== null) {
                    throw $this->mailFailure;
                }
                $this->sent[] = ['vars' => $vars, 'attachments' => $attachments, 'to' => $to];
                return $this->mailResult;
            }
        );
        return new ScheduledPohodaExportService(
            $this->db,
            $this->container->get(PohodaXmlExporter::class),
            $this->container->get(ExportPeriodResolver::class),
            $mailer,
            $this->container->get(SupplierEmailContext::class),
            $this->container->get(ActivityLogger::class),
        );
    }

    private function enable(ScheduledPohodaExportService $s, string $start = '2026-10', int $day = 5): void
    {
        $s->saveConfig($this->supplierId, ['enabled' => true, 'run_day' => $day, 'recipient_email' => 'ucetni@example.com'], null, new \DateTimeImmutable($start . '-15 +1 month'));
    }

    private function invoice(string $issue, string $status = 'issued', string $item = 'Zboží', string $vs = '', string $type = 'invoice'): int
    {
        $pdo = $this->db->pdo();
        $clientId = (int) $pdo->query('SELECT id FROM clients WHERE supplier_id = ' . $this->supplierId . ' LIMIT 1')->fetchColumn();
        if ($clientId === 0) {
            $pdo->prepare('INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
                           SELECT ?, "Odběratel", "Ulice 1", "Praha", "11000", country_id, default_currency_id FROM supplier WHERE id = ?')->execute([$this->supplierId, $this->supplierId]);
            $clientId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO invoices (supplier_id, invoice_type, client_id, varsymbol, issue_date, tax_date, due_date, currency_id, status, total_without_vat, total_vat, total_with_vat)
                       SELECT ?, ?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL 14 DAY), default_currency_id, ?, 1000, 210, 1210 FROM supplier WHERE id = ?')
            ->execute([$this->supplierId, $type, $clientId, $vs ?: ('T' . random_int(1000000, 9999999)), $issue, $issue, $issue, $status, $this->supplierId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat)
                       VALUES (?, ?, 1, 1000, 1, 21, 1000, 210, 1210)')->execute([$id, $item]);
        return $id;
    }

    public function testValidationAndDueLogic(): void
    {
        $s = $this->service();
        try {
            $s->saveConfig($this->supplierId, ['enabled' => true, 'run_day' => 5, 'recipient_email' => '']);
            $this->fail('Zapnutí bez e-mailu musí selhat.');
        } catch (\InvalidArgumentException) {
        }
        $this->enable($s, '2026-11', 10); // zapnuto v prosinci 2026 => start 2026-11? viz níže
        $this->assertSame('2026-11', $s->config($this->supplierId)['start_month']);

        $this->assertArrayNotHasKey($this->supplierId, $s->dueMonths(new \DateTimeImmutable('2026-12-09')));
        $this->assertSame('2026-11', $s->dueMonths(new \DateTimeImmutable('2026-12-10'))[$this->supplierId]);
    }

    public function testOnlyClosedMonthsCanBeRun(): void
    {
        $s = $this->service();
        $this->enable($s);
        foreach (['2026-11', '2026-12', '2027-03'] as $label) {
            try {
                $s->run($this->supplierId, $label, 'manual', false, null, $this->now);
                $this->fail("$label musí být odmítnut");
            } catch (\DomainException) {
            }
        }
        $this->assertSame([], $s->history($this->supplierId)['runs']);
    }

    public function testEmptyMonthIsDoneButLateInvoiceIsReportedAndRepeatResetsCounter(): void
    {
        $s = $this->service();
        $this->enable($s);
        $this->assertSame('ok', $s->run($this->supplierId, '2026-10', 'cron', false, null, $this->now)['status']);
        $this->assertSame([], $this->sent, 'Prázdný měsíc bez změn se neposílá.');
        $this->assertSame('exists', $s->run($this->supplierId, '2026-10', 'manual', false, null, $this->now)['status']);
        $this->assertArrayNotHasKey($this->supplierId, $s->dueMonths($this->now));

        // Nově vystavený doklad se starším datem v už vyřízeném měsíci.
        $this->invoice('2026-10-12');
        $this->assertSame(1, $s->changedMonths($this->supplierId, null, false, $this->now)['2026-10']['changed']);
        $this->assertSame(1, $s->history($this->supplierId)['runs'][0]['changed_after']);

        // Opakovaný export čítač vynuluje.
        $r = $s->run($this->supplierId, '2026-10', 'manual', true, null, $this->now);
        $this->assertSame(['ok', 1], [$r['status'], $r['doc_count']]);
        $this->assertCount(1, $this->sent);
        $this->assertSame('pohoda-2026-10.xml', $this->sent[0]['attachments'][0]['name']);
        $this->assertSame([], $s->changedMonths($this->supplierId, null, false, $this->now));
    }

    public function testCancelledAndEditedDocumentsAreReportedAndOnlyCancelledMonthIsNotAnError(): void
    {
        $s = $this->service();
        $this->enable($s, '2026-09');
        $a = $this->invoice('2026-09-03', 'issued', 'Zboží', 'A-0001');
        $b = $this->invoice('2026-09-04', 'issued', 'Zboží', 'B-0002');
        $c = $this->invoice('2026-09-05', 'cancelled'); // storno před exportem se neexportuje
        $r = $s->run($this->supplierId, '2026-09', 'cron', false, null, $this->now);
        $this->assertSame(['ok', 2], [$r['status'], $r['doc_count']]);
        $this->assertSame([], $s->changedMonths($this->supplierId, null, false, $this->now));

        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$a]);
        $pdo->prepare("UPDATE invoice_items SET description = 'Změněno' WHERE invoice_id = ?")->execute([$b]);
        $pdo->prepare("UPDATE invoices SET status = 'paid', sent_at = NOW() WHERE id = ?")->execute([$b]); // úhrada změnou není
        $m = $s->changedMonths($this->supplierId, null, false, $this->now)['2026-09'];
        $this->assertSame(2, $m['changed']);
        $kinds = array_column($m['items'], 'kind', 'vs');
        $this->assertSame(['A-0001' => 'cancelled', 'B-0002' => 'changed'], $kinds);

        // Měsíc jen se stornovanými doklady: žádná chyba, nic k odeslání.
        $this->invoice('2026-08-03', 'cancelled');
        $this->enable($s, '2026-08');
        $r = $s->run($this->supplierId, '2026-08', 'cron', false, null, $this->now);
        $this->assertSame(['ok', 0], [$r['status'], $r['doc_count']]);
        // Příloha není (v měsíci není co exportovat), e-mail nese jen upozornění na změny v 2026-09.
        $this->assertSame([], end($this->sent)['attachments']);
    }

    public function testWarningGoesIntoNextMonthlyMailOnceAndEmptyMonthSendsMailWithoutAttachment(): void
    {
        $s = $this->service();
        $this->enable($s, '2026-08');
        $s->run($this->supplierId, '2026-08', 'cron', false, null, $this->now);
        $this->invoice('2026-08-20'); // přibyl po odeslání
        $this->sent = [];

        $s->run($this->supplierId, '2026-09', 'cron', false, null, $this->now); // 0 dokladů, ale je co hlásit
        $this->assertCount(1, $this->sent);
        $this->assertSame([], $this->sent[0]['attachments']);
        $this->assertStringContainsString('Za 2026-08 se po odeslání změnil 1 doklad', $this->sent[0]['vars']['change_notes'][0]);

        $this->sent = [];
        $s->run($this->supplierId, '2026-10', 'cron', false, null, $this->now); // totéž se nehlásí podruhé
        $this->assertSame([], $this->sent);
        // Panel ale změnu ukazuje dál.
        $this->assertSame(1, $s->changedMonths($this->supplierId, null, false, $this->now)['2026-08']['changed']);
    }

    public function testMissedMonthsAreSentOneByOneAndNeverBeforeStart(): void
    {
        $s = $this->service();
        $this->enable($s, '2026-08');
        $this->assertSame('2026-08', $s->dueMonths($this->now)[$this->supplierId]);
        foreach (['2026-08', '2026-09', '2026-10'] as $expected) {
            $this->assertSame($expected, $s->dueMonths($this->now)[$this->supplierId]);
            $s->run($this->supplierId, $expected, 'cron', false, null, $this->now);
        }
        $this->assertArrayNotHasKey($this->supplierId, $s->dueMonths($this->now));
        $this->assertSame(['2026-10', '2026-09', '2026-08'], array_column($s->history($this->supplierId)['runs'], 'period_label'));
    }

    public function testFailureIsSafeCollapsedAndRetriedUntilSent(): void
    {
        $s = $this->service();
        $this->enable($s);
        $this->invoice('2026-10-02');
        $this->mailFailure = new \RuntimeException('SMTP 535 login=tajne heslo=xyz');
        for ($i = 0; $i < 3; $i++) {
            $r = $s->run($this->supplierId, '2026-10', 'cron', false, null, $this->now);
            $this->assertSame('error', $r['status']);
            $this->assertStringNotContainsString('tajne', (string) $r['error']);
        }
        $h = $s->history($this->supplierId);
        $this->assertCount(1, $h['runs'], 'Opakované chyby téhož měsíce tvoří jeden řádek.');
        $this->assertSame(3, $h['runs'][0]['attempts']);
        $this->assertSame('2026-10', $h['problem']['period_label']);
        $this->assertSame('2026-10', $s->dueMonths($this->now)[$this->supplierId], 'Selhaný měsíc se zkouší znovu.');

        $this->mailFailure = null;
        $this->assertSame('ok', $s->run($this->supplierId, '2026-10', 'cron', false, null, $this->now)['status']);
        $this->assertNull($s->history($this->supplierId)['problem']);
    }

    public function testDeferredMailIsQueuedAndReconciledWithOutbox(): void
    {
        $s = $this->service();
        $this->enable($s);
        $this->invoice('2026-10-02');
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO mail_outbox (created_at, not_before, status, attempts, template, locale, recipients, defer_reason, payload)
                    VALUES (NOW(3), NOW(3), 'pending', 0, 'scheduled_export', 'cs', 1, 'test', '{}')");
        $outboxId = (int) $pdo->lastInsertId();
        $this->mailResult = ['smtp_response' => 'deferred', 'deferred' => true, 'outbox_id' => $outboxId];

        $r = $s->run($this->supplierId, '2026-10', 'cron', false, null, $this->now);
        $this->assertSame('queued', $r['status']);
        $this->assertArrayNotHasKey($this->supplierId, $s->dueMonths($this->now), 'Zpráva ve frontě se neposílá znovu.');
        $this->assertSame('queued', $s->history($this->supplierId)['runs'][0]['status']);

        // Fronta zprávu posunula pod nové id a to doručila.
        $pdo->exec("INSERT INTO mail_outbox (created_at, not_before, status, attempts, template, locale, recipients, defer_reason, payload, sent_at)
                    VALUES (NOW(3), NOW(3), 'sent', 1, 'scheduled_export', 'cs', 1, 'test', '{}', NOW(3))");
        $newId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE mail_outbox SET status = 'requeued', last_error = ? WHERE id = ?")->execute(['znovu odloženo brzdou, pokračuje jako #' . $newId, $outboxId]);
        $this->assertSame('ok', $s->history($this->supplierId)['runs'][0]['status']);

        // Vzdaná zpráva => chyba a měsíc se zkusí znovu.
        $this->invoice('2026-09-02');
        $this->enable($s, '2026-09');
        $pdo->exec("INSERT INTO mail_outbox (created_at, not_before, status, attempts, template, locale, recipients, defer_reason, payload, last_error)
                    VALUES (NOW(3), NOW(3), 'failed', 5, 'scheduled_export', 'cs', 1, 'test', '{}', 'SMTP detail')");
        $this->mailResult = ['smtp_response' => 'deferred', 'deferred' => true, 'outbox_id' => (int) $pdo->lastInsertId()];
        $s->run($this->supplierId, '2026-09', 'cron', false, null, $this->now);
        $h = $s->history($this->supplierId);
        $this->assertSame(['2026-09', 'error'], [$h['runs'][0]['period_label'], $h['runs'][0]['status']]);
        $this->assertStringNotContainsString('SMTP detail', (string) $h['runs'][0]['error']);
    }

    public function testConcurrentRunForSameMonthIsRefused(): void
    {
        $s = $this->service();
        $this->enable($s);
        $cfg = Bootstrap::buildContainer()->get(\MyInvoice\Infrastructure\Config\Config::class)->get('db');
        $other = new \PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', $cfg['host'], $cfg['port'] ?? 3306, (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn()), $cfg['user'], $cfg['pass']);
        $name = NamedLockName::for($this->db, 'pohoda-export', $this->supplierId . ':2026-10');
        $other->prepare('SELECT GET_LOCK(?, 0)')->execute([$name]);
        try {
            $this->assertSame('busy', $s->run($this->supplierId, '2026-10', 'cron', false, null, $this->now)['status']);
        } finally {
            $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
        $this->assertSame('ok', $s->run($this->supplierId, '2026-10', 'cron', false, null, $this->now)['status']);
    }

    public function testRecipientChangeIsLoggedAndRunKeepsRecipient(): void
    {
        $s = $this->service();
        $s->saveConfig($this->supplierId, ['enabled' => true, 'run_day' => 5, 'recipient_email' => 'stara@example.com']);
        $s->saveConfig($this->supplierId, ['enabled' => true, 'run_day' => 5, 'recipient_email' => 'nova@example.com']);
        $st = $this->db->pdo()->prepare("SELECT payload FROM activity_log WHERE action = 'pohoda_export.config_changed' AND entity_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$this->supplierId]);
        $payload = json_decode((string) $st->fetchColumn(), true);
        $this->assertSame('stara@example.com', $payload['old']['recipient_email']);
        $this->assertSame('nova@example.com', $payload['new']['recipient_email']);

        $s->run($this->supplierId, '2026-10', 'manual', false, null, $this->now);
        $this->assertSame('nova@example.com', $s->history($this->supplierId)['runs'][0]['recipient_email']);
    }

    public function testReEnablingRestartsFromPreviousMonthAndDeletingSupplierRemovesEverything(): void
    {
        $s = $this->service();
        $s->saveConfig($this->supplierId, ['enabled' => true, 'run_day' => 1, 'recipient_email' => 'u@example.com'], null, new \DateTimeImmutable('2026-05-10'));
        $this->assertSame('2026-04', $s->config($this->supplierId)['start_month']);
        $s->saveConfig($this->supplierId, ['enabled' => false, 'run_day' => 1, 'recipient_email' => 'u@example.com'], null, new \DateTimeImmutable('2026-06-10'));
        $s->saveConfig($this->supplierId, ['enabled' => true, 'run_day' => 1, 'recipient_email' => 'u@example.com'], null, new \DateTimeImmutable('2026-09-10'));
        $this->assertSame('2026-08', $s->config($this->supplierId)['start_month']);

        $this->invoice('2026-08-02');
        $s->run($this->supplierId, '2026-08', 'manual', false, null, $this->now);
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);

        $this->assertArrayNotHasKey($this->supplierId, $s->dueMonths(new \DateTimeImmutable('2026-12-20')));
        foreach (['scheduled_pohoda_export', 'scheduled_pohoda_export_run'] as $t) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE supplier_id = ?");
            $st->execute([$this->supplierId]);
            $this->assertSame(0, (int) $st->fetchColumn(), $t);
        }
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM scheduled_pohoda_export_doc d LEFT JOIN scheduled_pohoda_export_run r ON r.id = d.run_id WHERE r.id IS NULL')->fetchColumn());
    }

    public function testMailListsDocumentNumbersMarksCancelledAndCapsTheList(): void
    {
        $s = $this->service();
        $this->enable($s, '2026-08');
        $keep = $this->invoice('2026-08-03', 'issued', 'Zboží', 'STORNO-1');
        $s->run($this->supplierId, '2026-08', 'cron', false, null, $this->now);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$keep]);
        for ($i = 1; $i <= 52; $i++) {
            $this->invoice('2026-08-10', 'issued', 'Zboží', sprintf('NOVA-%03d', $i));
        }
        $this->sent = [];
        $s->run($this->supplierId, '2026-09', 'cron', false, null, $this->now);
        $note = $this->sent[0]['vars']['change_notes'][0];
        $this->assertStringContainsString('STORNO-1 (stornován, v Pohodě je potřeba zrušit)', $note);
        $this->assertStringContainsString('a dalších 3', $note, '53 změn, vypsáno 50');
        $this->assertStringContainsString('v části Export', $note);
        $this->assertStringNotContainsString('odeslat export znovu.', $note);
    }

    public function testTogglingCreditNotesAfterSendingIsNotAChange(): void
    {
        $s = $this->service();
        $this->enable($s, '2026-08');
        $this->invoice('2026-08-03');
        $this->invoice('2026-08-04', 'issued', 'Dobropis', 'CN-1', 'credit_note');
        $s->run($this->supplierId, '2026-08', 'cron', false, null, $this->now); // s dobropisy
        $s->saveConfig($this->supplierId, ['enabled' => true, 'run_day' => 5, 'include_credit_notes' => false, 'recipient_email' => 'ucetni@example.com'], null, $this->now);
        $this->assertSame([], $s->changedMonths($this->supplierId, null, false, $this->now));

        $this->invoice('2026-09-03');
        $this->invoice('2026-09-04', 'issued', 'Dobropis', 'CN-2', 'credit_note');
        $s->run($this->supplierId, '2026-09', 'cron', false, null, $this->now); // bez dobropisů
        $s->saveConfig($this->supplierId, ['enabled' => true, 'run_day' => 5, 'include_credit_notes' => true, 'recipient_email' => 'ucetni@example.com'], null, $this->now);
        $this->assertSame([], $s->changedMonths($this->supplierId, null, false, $this->now));
        // Nový dobropis po běhu s dobropisy se ale hlásí.
        $this->invoice('2026-08-20', 'issued', 'Dobropis', 'CN-3', 'credit_note');
        $this->assertSame(1, $s->changedMonths($this->supplierId, null, false, $this->now)['2026-08']['changed']);
    }

    public function testChangeAtTheEndOfLongInvoiceIsDetected(): void
    {
        $s = $this->service();
        $this->enable($s, '2026-08');
        $id = $this->invoice('2026-08-03');
        $pdo = $this->db->pdo();
        $pdo->exec('SET SESSION group_concat_max_len = 1024'); // výchozí MySQL; služba si limit zvedne sama
        for ($i = 0; $i < 60; $i++) {
            $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat)
                           VALUES (?, ?, 1, 10, 1, 21, 10, 2.1, 12.1)')->execute([$id, 'Dlouhá položka číslo ' . $i . str_repeat('x', 30)]);
        }
        $s->run($this->supplierId, '2026-08', 'cron', false, null, $this->now);
        $this->assertSame([], $s->changedMonths($this->supplierId, null, false, $this->now));
        $pdo->prepare('UPDATE invoice_items SET unit_price_without_vat = 11 WHERE invoice_id = ? ORDER BY id DESC LIMIT 1')->execute([$id]);
        $this->assertSame(1, $s->changedMonths($this->supplierId, null, false, $this->now)['2026-08']['changed']);
    }
}
