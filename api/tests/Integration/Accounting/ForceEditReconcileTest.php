<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Force-edit zaúčtovaného dokladu v uzavřeném období (audit 2026-07 B11).
 *
 *   - bez force_mode → 422 (force_mode_required)
 *   - force_mode='notes_only' + změna účetního pole → 422 (financial_change_not_allowed)
 *   - force_mode='notes_only' + jen poznámka → whitelist projde (rozhodovací funkce)
 *   - reconcile (DocumentJournalSync) → původní zápis stornován + přeúčtování do
 *     otevřeného období; deník sedí na nový doklad
 *
 * Vzor DocumentJournalSyncTest: úspěšný end-to-end edit přes celou Action naráží v
 * jedné rollback transakci na StatsRecomputer (vlastní tx), proto se 422 brány testují
 * přes Action (vrací se PŘED editací) a reconcile jádro přímo na službě.
 */
#[Group('integration')]
final class ForceEditReconcileTest extends TestCase
{
    private Connection $db;
    private UpdateInvoiceAction $updateInvoice;
    private DocumentJournalSync $sync;
    private PostingService $posting;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;
    private InvoiceRepository $invoices;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $closedYear = 0;
    private int $openYear = 0;
    private int $closedPeriodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db            = $container->get(Connection::class);
            $this->updateInvoice = $container->get(UpdateInvoiceAction::class);
            $this->sync          = $container->get(DocumentJournalSync::class);
            $this->posting       = $container->get(PostingService::class);
            $this->journal       = $container->get(JournalEntryRepository::class);
            $this->periods       = $container->get(AccountingPeriodRepository::class);
            $this->invoices      = $container->get(InvoiceRepository::class);
            $seeder              = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        // Uzavřený rok = dva roky zpět (jistě minulý), otevřený rok = aktuální (kryje dnešek,
        // kam reconcile přeúčtuje). Uzavřený rok navíc posuneme dolů přes všechna EXISTUJÍCÍ
        // období firmy — jinak by create() narazil na UNIQUE (supplier, fiscal_year) a vrátil
        // reálné approved období, do kterého už §35 účtovat nedovolí (posting spadne dřív,
        // než ho test stihne uzavřít). Tím je test nezávislý na tom, které roky už firma má.
        $this->openYear   = (int) date('Y');
        $closedYear = $this->openYear - 2;
        while ($this->periods->findByYear($this->supplierId, $closedYear) !== null) {
            $closedYear--;
        }
        $this->closedYear = $closedYear;

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        $this->closedPeriodId = $this->periods->create($this->supplierId, $this->closedYear, $this->closedYear . '-01-01', $this->closedYear . '-12-31');
        $this->periods->create($this->supplierId, $this->openYear, $this->openYear . '-01-01', $this->openYear . '-12-31');

        // Čistý výchozí stav: bez měkkého zámku k datu (nezávisle na případné committed
        // hodnotě locked_until na devu, např. po archivaci DPH) — jinak by force-edit do
        // uzavřeného roku spadl na date_locked místo testované force_mode brány. V transakci,
        // takže rollback obnoví reálnou hodnotu.
        $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = NULL WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── brána: force-edit zaúčtovaného dokladu v uzavřeném období vyžaduje force_mode ──

    public function testForceEditWithoutForceModeReturns422(): void
    {
        $invoiceId = $this->postedInvoiceInClosedPeriod('FV-B11-A1');

        $body = $this->fullBody('FV-B11-A1', 1200.00, 252.00, 21.00); // změna částky
        $res = $this->invoke($invoiceId, $body, ['force' => '1']);

        self::assertSame(422, $res['status'], 'Chybí force_mode → 422.');
        self::assertSame('force_mode_required', $res['body']['error']['code'] ?? null);
    }

    public function testNotesOnlyWithFinancialChangeReturns422(): void
    {
        $invoiceId = $this->postedInvoiceInClosedPeriod('FV-B11-A2');

        $body = $this->fullBody('FV-B11-A2', 1200.00, 252.00, 21.00); // změna položek/částky
        $res = $this->invoke($invoiceId, $body, ['force' => '1', 'force_mode' => 'notes_only']);

        self::assertSame(422, $res['status'], 'notes_only se změnou částky → 422.');
        self::assertSame('financial_change_not_allowed', $res['body']['error']['code'] ?? null);
    }

    /**
     * Změna JEN země spotřeby na OSS řádku je účetní změna, i když se částka nehnula:
     * plnění se přesune do jiného řádku OSS podání a DPH se zaúčtuje jinam (345.100,
     * migrace 1295). Otisk položek to musí zachytit
     * ({@see \MyInvoice\Service\Invoice\DocumentItemsPayload}) — jinak by notes_only
     * doklad rozešel s deníkem i s hlášením a nikdo by se to nedozvěděl.
     */
    public function testNotesOnlyWithOssCountryChangeReturns422(): void
    {
        if (!$this->db->hasColumn('invoice_items', 'oss_applicable')) {
            $this->markTestSkipped('Instance bez OSS sloupců (migrace 0137).');
        }
        $invoiceId = $this->postedOssInvoiceInClosedPeriod('FV-B11-OSS1');
        $existing = $this->invoices->find($invoiceId);
        self::assertNotNull($existing);

        // Tělo je s uloženým řádkem shodné ve VŠEM kromě země spotřeby — kdyby otisk OSS
        // pole neznal, prošlo by to jako čistě poznámková editace.
        $moved = ['items' => [$this->ossItemPayload('AT')]];
        $same  = ['items' => [$this->ossItemPayload('DE')]];

        // Rozhodovací funkce nad SKUTEČNĚ uloženým dokladem — bez šumu schéma validace.
        $ref = new \ReflectionMethod(UpdateInvoiceAction::class, 'financialFieldsChanged');
        self::assertContains('items', $ref->invoke(null, $moved, $existing),
            'Změna země spotřeby na OSS řádku je účetní změna (jiný řádek podání, jiné zaúčtování).');
        self::assertNotContains('items', $ref->invoke(null, $same, $existing),
            'Týž řádek jen z formuláře (stringy) není změna — jinak by notes_only nešel použít nikdy.');

        $res = $this->invoke($invoiceId, $moved, ['force' => '1', 'force_mode' => 'notes_only']);

        self::assertSame(422, $res['status'], 'notes_only se změnou země spotřeby → 422.');
        self::assertSame('financial_change_not_allowed', $res['body']['error']['code'] ?? null);
        self::assertStringContainsString('items', (string) ($res['body']['error']['message'] ?? ''));
    }

    /**
     * „Netisknout odběratele" (migrace 2037) mění tištěný daňový doklad, takže v uzavřeném
     * období neprojde jako čistě poznámková úprava.
     */
    public function testNotesOnlyWithHiddenCustomerChangeReturns422(): void
    {
        $invoiceId = $this->postedInvoiceInClosedPeriod('FV-B11-HC1');

        $res = $this->invoke($invoiceId, ['hide_customer_on_pdf' => true], ['force' => '1', 'force_mode' => 'notes_only']);

        self::assertSame(422, $res['status'], 'notes_only se změnou tisku odběratele → 422.');
        self::assertSame('financial_change_not_allowed', $res['body']['error']['code'] ?? null);
        self::assertStringContainsString('hide_customer_on_pdf', (string) ($res['body']['error']['message'] ?? ''));
    }

    public function testFinancialFieldsChangedDecision(): void
    {
        $ref = new \ReflectionMethod(UpdateInvoiceAction::class, 'financialFieldsChanged');

        $existing = [
            'note_above_items' => 'původní', 'note_below_items' => null, 'language' => 'cs',
            'total_with_vat' => 1210.00, 'vat_classification_code' => 'A1', 'exchange_rate' => 25.0,
            'items' => [['description' => 'X', 'quantity' => '1', 'unit' => 'ks', 'unit_price_without_vat' => '1000', 'vat_rate_id' => (string) $this->vatRateId]],
        ];

        // Jen poznámka → žádná účetní změna.
        $noteOnly = $ref->invoke(null, ['note_above_items' => 'nová interní poznámka'], $existing);
        self::assertSame([], $noteOnly, 'Změna poznámky není účetní změna.');

        // Změna položek → 'items'.
        $itemsChanged = $ref->invoke(null, [
            'items' => [['description' => 'Y', 'quantity' => '2', 'unit' => 'ks', 'unit_price_without_vat' => '999', 'vat_rate_id' => (string) $this->vatRateId]],
        ], $existing);
        self::assertContains('items', $itemsChanged, 'Změna položek je účetní změna.');

        // Změna klasifikace DPH → 'vat_classification_code'.
        $vatChanged = $ref->invoke(null, ['vat_classification_code' => 'A2'], $existing);
        self::assertContains('vat_classification_code', $vatChanged);

        // B11 fix: reálná změna kurzu (ruční override přepíše CZK hodnotu) → účetní změna.
        $rateChanged = $ref->invoke(null, ['exchange_rate' => 30.0, 'note_above_items' => 'x'], $existing);
        self::assertContains('exchange_rate', $rateChanged, 'Změna kurzu 25 → 30 je účetní změna.');

        // Formátová neshoda kurzu (25.00 vs 25) NENÍ změna — numerické porovnání, ne string.
        $rateSame = $ref->invoke(null, ['exchange_rate' => '25.00', 'note_above_items' => 'x'], $existing);
        self::assertNotContains('exchange_rate', $rateSame, 'Formátová neshoda kurzu neblokuje notes_only.');

        // Kurz v payloadu vůbec není → žádná změna.
        $rateAbsent = $ref->invoke(null, ['note_above_items' => 'x'], $existing);
        self::assertNotContains('exchange_rate', $rateAbsent);
    }

    // ── reconcile jádro: storno + přeúčtování do otevřeného období ──

    public function testReconcileReversesAndRepostsIntoOpenPeriod(): void
    {
        $invoiceId = $this->postedInvoiceInClosedPeriod('FV-B11-R1');
        $originalEntry = $this->journal->findBySource($this->supplierId, 'invoice', $invoiceId);
        self::assertNotNull($originalEntry);
        $originalId = (int) $originalEntry['id'];

        // Uzavři rok dokladu — v něm už zápis přepsat nejde (§35).
        $this->periods->setStatus($this->closedPeriodId, $this->supplierId, 'closed');

        $result = $this->sync->reconcileForceEdit($this->supplierId, 'invoice', $invoiceId, [
            'user_id' => $this->userId, 'posted_by' => $this->userId,
        ]);

        self::assertNotNull($result, 'Reconcile proběhl (doklad měl aktivní zápis).');
        self::assertSame($originalId, $result['reversed_entry_id']);
        self::assertNotSame($originalId, $result['new_entry_id'], 'Vznikl NOVÝ zápis.');

        // Původní zápis stornován a odpojen od zdroje.
        $orig = $this->entryRow($originalId);
        self::assertNotNull($orig['reversed_by'], 'Původní zápis má reversed_by.');
        self::assertNull($orig['source_id'], 'Původní zápis odpojen (detachSource).');

        // Přesně jeden AKTIVNÍ zápis pro doklad = nový, v aktuálním (otevřeném) roce.
        self::assertSame(1, $this->activeEntryCount('invoice', $invoiceId), 'Deník sedí na jeden aktivní zápis.');
        $newRow = $this->entryRow((int) $result['new_entry_id']);
        self::assertSame($invoiceId, (int) $newRow['source_id'], 'Nový zápis váže na doklad.');
        self::assertSame($this->openYear . '-' . date('m-d'), (string) $newRow['entry_date'], 'Přeúčtováno k dnešku (otevřený rok).');
    }

    public function testOpenPeriodForceEditRepostsExistingEntryInPlace(): void
    {
        $client = $this->client('Odběratel open s.r.o.');
        $invoiceId = $this->sale('FV-OPEN-R1', $client, 1000.00, 210.00, 21.00, $this->openYear . '-06-15');
        $oldLines = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $oldLines, [
            'entry_date' => $this->openYear . '-06-15',
            'posted_by' => $this->userId,
        ]);

        $this->db->pdo()->prepare(
            'UPDATE invoices SET total_without_vat = 2000, total_vat = 420, total_with_vat = 2420 WHERE id = ?'
        )->execute([$invoiceId]);
        $this->db->pdo()->prepare(
            'UPDATE invoice_items SET unit_price_without_vat = 2000, total_without_vat = 2000, total_vat = 420, total_with_vat = 2420 WHERE invoice_id = ?'
        )->execute([$invoiceId]);

        $repostedId = $this->sync->repostForceEdit($this->supplierId, 'invoice', $invoiceId, [
            'entry_date' => $this->openYear . '-06-15',
            'posted_by' => $this->userId,
        ]);

        self::assertSame($entryId, $repostedId, 'Otevřené období přepisuje tentýž zápis in-place.');
        $entry = $this->journal->find($entryId, $this->supplierId);
        $receivable = array_values(array_filter(
            $entry['lines'],
            fn(array $line): bool => $this->accountCode((int) $line['account_id']) === '311',
        ));
        self::assertCount(1, $receivable);
        self::assertEqualsWithDelta(2420.00, (float) $receivable[0]['amount'], 0.001, 'Deník nese opravenou částku.');
        self::assertSame(1, $this->activeEntryCount('invoice', $invoiceId));
    }

    /**
     * Role s právem úpravy vystavené faktury, ale bez práva zaúčtovat, nesmí finanční
     * změnou zaúčtovaného dokladu přepsat zápis v deníku. Odmítnutí přijde dřív, než se
     * doklad nebo deník změní; neúčetní změna projde a role se zaúčtováním projde i s částkou.
     */
    public function testEditIssuedRoleNeedsJournalPostToRepostFinancialChange(): void
    {
        $client = $this->client('Odběratel role s.r.o.');
        $invoiceId = $this->sale('FV-ROLE-R1', $client, 1000.00, 210.00, 21.00, $this->openYear . '-06-15');
        $lines = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $entryId = $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, [
            'entry_date' => $this->openYear . '-06-15', 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        $editor = new EffectiveRole(90, 'Úprava vystavených', 'staff', true, [
            'invoices' => AccessLevel::WRITE->value,
            'invoices.edit_issued' => AccessLevel::WRITE->value,
        ]);
        $financial = ['items' => [[
            'description' => 'Změněná položka', 'quantity' => 1, 'unit' => 'ks',
            'unit_price_without_vat' => 2000.00, 'vat_rate_id' => $this->vatRateId,
        ]]];

        $res = $this->invoke($invoiceId, $financial, ['force' => '1'], $editor);
        self::assertSame(403, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('journal_post_forbidden', $res['body']['error']['code'] ?? null);
        self::assertEqualsWithDelta(1210.00, (float) $this->invoices->find($invoiceId)['total_with_vat'], 0.001, 'Doklad se nezměnil.');
        self::assertSame(1, $this->activeEntryCount('invoice', $invoiceId));
        self::assertSame($entryId, (int) $this->journal->findBySource($this->supplierId, 'invoice', $invoiceId)['id']);

        $res = $this->invoke($invoiceId, ['note_below_items' => 'Opravená poznámka'], ['force' => '1'], $editor);
        self::assertSame(200, $res['status'], 'Neúčetní změna projde: ' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $poster = new EffectiveRole(91, 'Úprava a zaúčtování', 'staff', true, $editor->permissions + [
            'accounting.journal.post' => AccessLevel::WRITE->value,
        ]);
        $res = $this->invoke($invoiceId, $financial, ['force' => '1'], $poster);
        self::assertNotSame('journal_post_forbidden', $res['body']['error']['code'] ?? null,
            'Role s právem zaúčtovat přeúčtování smí: ' . json_encode($res['body'], JSON_UNESCAPED_UNICODE));
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function postedInvoiceInClosedPeriod(string $varsymbol): int
    {
        $client = $this->client('Odběratel s.r.o.');
        $invoiceId = $this->sale($varsymbol, $client, 1000.00, 210.00, 21.00, $this->closedYear . '-06-15');
        $lines = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, [
            'entry_date' => $this->closedYear . '-06-15', 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        // Zamkni rok dokladu — pro Action brány (posted zápis v closed období).
        $this->periods->setStatus($this->closedPeriodId, $this->supplierId, 'closed');
        return $invoiceId;
    }

    /** Týž doklad, ale s OSS řádkem do Německa — pro bránu nad otiskem OSS polí. */
    private function postedOssInvoiceInClosedPeriod(string $varsymbol): int
    {
        $client = $this->client('OSS odběratel ' . $varsymbol);
        $invoiceId = $this->sale($varsymbol, $client, 1000.00, 210.00, 21.00, $this->closedYear . '-06-15');
        // OSS řádek nemá tuzemskou klasifikaci — plnění se vykazuje v podání, ne v přiznání
        // ({@see \MyInvoice\Repository\InvoiceRepository::replaceItems()}).
        $this->db->pdo()->prepare(
            'UPDATE invoice_items
                SET oss_applicable = 1, oss_consumer_country = ?, oss_rate_type = ?,
                    oss_supply_type = ?, vat_classification_code = NULL
              WHERE invoice_id = ?'
        )->execute(['DE', 'standard', 'services', $invoiceId]);

        $lines = $this->posting->buildFromInvoice($this->supplierId, $invoiceId);
        $this->posting->postDocument($this->supplierId, 'invoice', $invoiceId, $lines, [
            'entry_date' => $this->closedYear . '-06-15', 'posted_by' => $this->userId, 'user_id' => $this->userId,
        ]);
        $this->periods->setStatus($this->closedPeriodId, $this->supplierId, 'closed');
        return $invoiceId;
    }

    /**
     * Řádek tak, jak ho pošle editor: stringy z formuláře a všechna OSS pole, protože
     * `replaceItems()` je DELETE + INSERT. Liší se jedině země spotřeby.
     *
     * @return array<string,mixed>
     */
    private function ossItemPayload(string $country): array
    {
        return [
            'description' => 'Test položka', 'quantity' => '1', 'unit' => 'ks',
            'unit_price_without_vat' => '1000.00', 'vat_rate_id' => (string) $this->vatRateId,
            'oss_applicable' => true,
            'oss_consumer_country' => $country,
            'oss_rate_type' => 'standard',
            'oss_supply_type' => 'services',
            'oss_exchange_rate' => null,
            'oss_exchange_rate_date' => null,
            'oss_taxable_amount_return' => null,
            'oss_vat_amount_return' => null,
            'oss_original_period' => null,
            'oss_needs_manual_review' => false,
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $query
     * @return array{status:int, body:array<mixed>}
     */
    private function invoke(int $invoiceId, array $body, array $query, ?EffectiveRole $role = null): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/invoices/' . $invoiceId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body)
            ->withQueryParams($query);
        if ($role !== null) {
            $req = $req->withAttribute('auth.effective_role', $role);
        }
        $resp = ($this->updateInvoice)($req, new Psr7Response(), ['id' => (string) $invoiceId]);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @return array<string,mixed> */
    private function fullBody(string $varsymbol, float $base, float $vat, float $rate): array
    {
        return [
            'client_id' => null, // doplní se? ne — jen změna položek stačí pro test brány
            'issue_date' => $this->closedYear . '-06-15',
            'tax_date' => $this->closedYear . '-06-15',
            'due_date' => $this->closedYear . '-06-30',
            'currency_id' => $this->currencyId,
            'invoice_type' => 'invoice',
            'items' => [[
                'description' => 'Změněná položka', 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => $base, 'vat_rate_id' => $this->vatRateId,
            ]],
        ];
    }

    private function activeEntryCount(string $sourceType, int $sourceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ?
                AND posted_at IS NOT NULL AND reversed_by IS NULL"
        );
        $stmt->execute([$this->supplierId, $sourceType, $sourceId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function entryRow(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, source_type, source_id, entry_date, posted_at, reversed_by FROM journal_entries WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? [] : $row;
    }

    private function accountCode(int $accountId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_code FROM chart_of_accounts WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$accountId, $this->supplierId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    private function client(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function sale(string $varsymbol, int $clientId, float $base, float $vat, float $rate, string $issue): int
    {
        $with = $base + $vat;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, language, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, "cs", 0, ?, ?, ?, "issued", ?, ?)'
        );
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $base, $vat, $with, '1', $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Test položka', 1, 'ks', ?, ?, ?, ?, ?, ?, 0)"
        );
        $stmt->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $base + $vat]);
        return $id;
    }
}
