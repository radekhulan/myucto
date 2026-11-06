<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Avízo, které parser nepřečetl (`parse_failed`), nezaloží pohyb — chybí tedy nejen
 * v párování, ale i v kontrole „platba bez dokladu" a v součtech výpisu. Dosud o tom
 * aplikace sama neřekla, uživatel to viděl jen jako červený řádek v seznamu avíz.
 *
 * Test hlídá, že se takové avízo objeví v „Akcích pro tebe", že se počítají jen
 * nepřečtené zprávy (ne všechny neúspěšné stavy) a že odložení jedné zprávy neschová
 * další, která propadne později.
 *
 * Izolace: zprávy se značkou v `fallback_hash`; vše se v tearDown maže.
 */
#[Group('integration')]
final class CrmUnprocessedBankNoticeTest extends TestCase
{
    private const MARKER = '__unprocessed_notice_test__';

    private Connection $db;
    private CrmAggregationService $crm;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->crm = $c->get(CrmAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()
            ->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->db->pdo()
            ->prepare('DELETE FROM bank_email_processed_messages WHERE fallback_hash LIKE ?')
            ->execute([self::MARKER . '%']);
        $this->db->pdo()
            ->prepare("DELETE FROM crm_action_item_dismissals WHERE supplier_id = ? AND item_type = 'bank_notice_unprocessed'")
            ->execute([$this->supplierId]);
    }

    /** Vloží zprávu daného stavu a vrátí její id. */
    private function notice(string $status, string $when = '-3 days'): int
    {
        $pdo = $this->db->pdo();
        $hash = self::MARKER . substr(bin2hex(random_bytes(16)), 0, 64 - strlen(self::MARKER));
        $pdo->prepare(
            'INSERT INTO bank_email_processed_messages
                 (supplier_id, fallback_hash, message_date, sender, subject, status, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $hash,
            (new \DateTimeImmutable($when))->format('Y-m-d H:i:s'),
            'test@example.com',
            'Pohyb na účtě',
            $status,
            $status === 'parse_failed' ? 'Pro e-mail nebyl nalezen žádný aktivní parser provider.' : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    private function item(?int $userId = null, bool $canManageBankConnections = true): ?array
    {
        $result = $this->crm->actionItems($this->supplierId, $userId, null, $canManageBankConnections);
        foreach ($result['items'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'bank_notice_unprocessed') {
                return $item;
            }
        }

        return null;
    }

    public function testNeprecteneAvizoSeObjeviVAkcich(): void
    {
        self::assertNull($this->item(), 'bez nepřečteného avíza se položka nenabízí');

        $this->notice('parse_failed');
        $item = $this->item();

        self::assertNotNull($item, 'nepřečtené avízo musí být vidět v Akcích pro tebe');
        self::assertSame(1, $item['count']);
        self::assertSame('high', $item['severity']);
        self::assertSame('/bank?tab=email', $item['link'], 'odkaz míří na seznam avíz');
        self::assertSame('crm.action_items.bank_notice_unprocessed_title', $item['title_key']);
    }

    public function testPocitajiSeJenNeprectenaAvizaAVsechnaDohromady(): void
    {
        // `match_failed` znamená, že se pohyb založil a jen se nenašla faktura — to
        // řeší párování, ne tahle položka. `processed_success` je hotová zpráva.
        $this->notice('match_failed');
        $this->notice('processed_success');
        self::assertNull($this->item(), 'úspěšně zpracovaná ani nespárovaná zpráva položku nevyvolá');

        $this->notice('parse_failed');
        $this->notice('parse_failed');
        $item = $this->item();

        self::assertNotNull($item);
        self::assertSame(2, $item['count'], 'počítají se všechna nepřečtená avíza');
    }

    public function testStarsiNezPulRokuJizNeotravuje(): void
    {
        $this->notice('parse_failed', '-200 days');

        self::assertNull($this->item(), 'avízo starší 180 dnů už se nenabízí');
    }

    /** Seznam avíz je záložka správy bankovních účtů; bez toho práva by odkaz nic neukázal. */
    public function testBezSpravyBankovnichUctuSePolozkaNenabizi(): void
    {
        $this->notice('parse_failed');

        self::assertNull($this->item(null, false));
        self::assertNotNull($this->item(null, true));
    }

    /**
     * Odložení „historická" schová jen avíza, která už propadla; avízo, které propadne
     * později, se ozve znovu. Typ položky musí jít odložit vůbec.
     */
    public function testKlientskaPolozkaPwaInstallJdeOdlozitAVraciSeVDismissedTypes(): void
    {
        $userId = (int) ($this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($userId === 0) {
            $this->markTestSkipped('Chybí uživatel.');
        }
        $this->crm->restoreActionItem($this->supplierId, $userId, 'pwa_install');
        self::assertNotContains('pwa_install', $this->crm->actionItems($this->supplierId, $userId)['dismissed_types']);

        $this->crm->dismissActionItem($this->supplierId, $userId, 'pwa_install', 'forever');
        try {
            self::assertContains('pwa_install', $this->crm->actionItems($this->supplierId, $userId)['dismissed_types']);
        } finally {
            $this->crm->restoreActionItem($this->supplierId, $userId, 'pwa_install');
        }
    }

    public function testOdlozeniSchovaJenStavajiciAviza(): void
    {
        $userId = (int) ($this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($userId === 0) {
            $this->markTestSkipped('Chybí uživatel.');
        }
        $this->notice('parse_failed');
        $this->crm->dismissActionItem($this->supplierId, $userId, 'bank_notice_unprocessed', 'historical');
        self::assertNull($this->item($userId), 'odložené avízo se už nenabízí');

        $this->notice('parse_failed');
        $item = $this->item($userId);
        self::assertNotNull($item, 'nově propadlé avízo se ozve i po odložení starších');
        self::assertSame(1, $item['count']);
    }
}
