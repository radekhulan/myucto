<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemBankMatchService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemPostingEvent;
use MyInvoice\Service\Accounting\OtherItemPostingProfileService;
use MyInvoice\Service\Accounting\OtherItemPostingResolver;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Účetní profily druhů ostatních položek a vlastní kontace položky (migrace 2238):
 * přednost položka > druh > výchozí, zaúčtování vzniku i úhrady z banky podle profilu,
 * beze změny pro položky bez profilu, ochrana saldokonta a daňová evidence.
 * Syntetická firma, syntetický účet 1000000005/0100, období 2099.
 */
#[Group('integration')]
final class OtherItemPostingProfileTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private OtherItemService $items;
    private OtherItemPostingProfileService $profiles;
    private OtherItemPostingResolver $resolver;
    private OtherItemBankMatchService $bankMatch;
    private int $supplierId;
    private int $statementId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->items = $container->get(OtherItemService::class);
        $this->profiles = $container->get(OtherItemPostingProfileService::class);
        $this->resolver = $container->get(OtherItemPostingResolver::class);
        $this->bankMatch = $container->get(OtherItemBankMatchService::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
        $this->pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, statement_date, currency)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'synteticky-vypis', hash('sha256', uniqid('', true)),
            '1000000005', '0100', '2099-01-20', 'CZK']);
        $this->statementId = (int) $this->pdo->lastInsertId();
        $chart = $container->get(ChartOfAccountsRepository::class);
        $chart->insert($this->supplierId, ['account_code' => '379.500', 'name' => 'Syntetické půjčky',
            'account_type' => 'liability', 'normal_side' => 'credit', 'is_synthetic' => false,
            'parent_id' => (int) $chart->findByCode($this->supplierId, '379')['id']]);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testItemsWithoutProfilePostAndPayExactlyAsBefore(): void
    {
        $payable = $this->posted(['counter_account_code' => '518']);
        self::assertSame(['325:credit:1200.00', '518:debit:1200.00'], $this->entryLines($payable));
        $receivable = $this->posted(['side' => 'receivable', 'kind' => 'claim', 'counter_account_code' => '602']);
        self::assertSame(['315:debit:1200.00', '602:credit:1200.00'], $this->entryLines($receivable));

        $item = $this->items->get($this->supplierId, $payable);
        self::assertNull($item['account_code'], 'Výchozí účet se do položky nepropisuje, jako dřív.');
        self::assertSame('item', $item['posting_rules']->origin['source']);
        self::assertSame('default', $item['posting_rules']->payment_bank['source']);

        $txId = $this->transaction(-1200);
        $this->bankMatch->match($this->supplierId, $txId, $payable, null, [], true);
        self::assertSame(['325:debit:1200.00', '221:credit:1200.00'], $this->bankLines($txId));
        self::assertStringStartsWith('Banka 1000000005/0100', $this->bankDescription($txId), 'Výchozí popis pohybu.');
        self::assertSame(0, $this->provenanceCount($payable), 'Výchozí kontace se do auditu jako profil nezapisuje.');
    }

    public function testKindProfileFillsEmptyItemAccountAndCounterAndIsRecordedOnTheItem(): void
    {
        $this->profiles->saveForKind($this->supplierId, null, 'payable', 'loan', [
            'origin' => ['debit_account_code' => '261', 'credit_account_code' => '379.500',
                'description_template' => 'Půjčka {document_no}: {partner}'],
        ], null);

        $draft = $this->items->create($this->supplierId, $this->input(['kind' => 'loan', 'counter_account_code' => null,
            'partner_name' => 'Syntetický věřitel']), null);
        $rules = $this->items->get($this->supplierId, (int) $draft['id'])['posting_rules'];
        self::assertSame('kind', $rules->origin['source']);
        self::assertSame(['379.500:credit:1200', '261:debit:1200'], array_map(
            static fn (array $l): string => $l['account_code'] . ':' . $l['side'] . ':' . $l['amount'], $rules->origin['lines']));

        $posted = $this->items->post($this->supplierId, (int) $draft['id'], null);
        self::assertSame(['379.500:credit:1200.00', '261:debit:1200.00'], $this->entryLines((int) $draft['id']));
        self::assertSame('379.500', $posted['account_code'], 'Zaúčtovaný účet si položka nese pro přeúčtování a saldokonto.');
        self::assertSame('261', $posted['counter_account_code']);
        self::assertSame('Půjčka ' . $posted['document_no'] . ': Syntetický věřitel', $this->entryDescription((int) $posted['journal_entry_id']));
        self::assertSame(1, $this->provenanceCount((int) $draft['id']));

        // Profil platí jen pro budoucí zaúčtování: změna nechá zaúčtovaný zápis být.
        $this->profiles->saveForKind($this->supplierId, null, 'payable', 'loan', ['origin' => null], null);
        self::assertSame(['379.500:credit:1200.00', '261:debit:1200.00'], $this->entryLines((int) $draft['id']));
    }

    public function testItemOwnAccountsBeatKindProfilePartByPart(): void
    {
        $this->profiles->saveForKind($this->supplierId, null, 'payable', 'rent', [
            'origin' => ['debit_account_code' => '548', 'credit_account_code' => '379.500'],
        ], null);
        $own = $this->posted(['account_code' => '325', 'counter_account_code' => '518']);
        self::assertSame(['325:credit:1200.00', '518:debit:1200.00'], $this->entryLines($own));

        // Vlastní protiúčet a účet položky z druhu.
        $mixed = $this->items->create($this->supplierId, $this->input(['counter_account_code' => '518']), null);
        $origin = $this->resolver->origin($this->supplierId, $this->items->get($this->supplierId, (int) $mixed['id']));
        self::assertSame(['kind', 'item', '379.500'], [$origin['account_source'], $origin['counter_source'], $origin['account_code']]);
        $this->items->post($this->supplierId, (int) $mixed['id'], null);
        self::assertSame(['379.500:credit:1200.00', '518:debit:1200.00'], $this->entryLines((int) $mixed['id']));
    }

    public function testPaymentRulePrecedenceItemOverKindOverDefault(): void
    {
        $item = $this->items->get($this->supplierId, $this->posted(['kind' => 'rent']));
        self::assertSame('default', $this->resolver->payment($this->supplierId, $item, OtherItemPostingEvent::PaymentBank, '325')['source']);
        self::assertSame(['211', '325'], $this->pair($this->resolver->payment($this->supplierId, $item, OtherItemPostingEvent::PaymentCash, '325')), 'Závazek: MD položka, D pokladna.');

        $this->profiles->saveForKind($this->supplierId, null, 'payable', 'rent', [
            'payment_bank' => ['credit_account_code' => '221', 'description_template' => 'Nájem {title}'],
            'payment_cash' => ['credit_account_code' => '211'],
        ], null);
        $kindRule = $this->resolver->payment($this->supplierId, $item, OtherItemPostingEvent::PaymentBank, '325');
        self::assertSame(['kind', 'Nájem Syntetické nájemné'], [$kindRule['source'], $kindRule['description']]);

        $this->items->setPostingOverrides($this->supplierId, (int) $item['id'], [
            'payment_bank' => ['description_template' => 'Vlastní {variable_symbol}'],
        ], null);
        $own = $this->resolver->payment($this->supplierId, $item, OtherItemPostingEvent::PaymentBank, '325');
        self::assertSame(['item', 'Vlastní', '325', '221'], [$own['source'], $own['description'], $own['debit_account_code'], $own['credit_account_code']]);
        self::assertSame('kind', $this->resolver->payment($this->supplierId, $item, OtherItemPostingEvent::PaymentCash, '325')['source'],
            'Vlastní kontace jedné události nemění ostatní.');

        $this->items->setPostingOverrides($this->supplierId, (int) $item['id'], ['payment_bank' => null], null);
        self::assertSame('kind', $this->resolver->payment($this->supplierId, $item, OtherItemPostingEvent::PaymentBank, '325')['source']);
    }

    public function testBankPaymentIsPostedByKindProfileAndOverrideWithProvenance(): void
    {
        $this->profiles->saveForKind($this->supplierId, null, 'receivable', 'claim', [
            'payment_bank' => ['debit_account_code' => '221', 'description_template' => 'Úhrada {document_no}'],
        ], null);
        $item = $this->posted(['side' => 'receivable', 'kind' => 'claim', 'counter_account_code' => '602']);
        $txId = $this->transaction(1200);
        $this->bankMatch->match($this->supplierId, $txId, $item, null, [], true);
        self::assertSame(['221:debit:1200.00', '315:credit:1200.00'], $this->bankLines($txId), 'Strana položky zůstává účet položky.');
        $documentNo = (string) $this->items->get($this->supplierId, $item)['document_no'];
        self::assertSame('Úhrada ' . $documentNo, $this->bankDescription($txId));
        self::assertSame(1, $this->provenanceCount($item));
        self::assertEqualsWithDelta(0.0, (float) $this->items->get($this->supplierId, $item)['remaining_amount'], 0.001);

        $second = $this->posted(['side' => 'receivable', 'kind' => 'claim', 'counter_account_code' => '602', 'title' => 'Druhá']);
        $this->items->setPostingOverrides($this->supplierId, $second, ['payment_bank' => ['description_template' => 'Položka {title}']], null);
        $tx2 = $this->transaction(1200);
        $this->bankMatch->match($this->supplierId, $tx2, $second, null, [], true);
        self::assertSame('Položka Druhá', $this->bankDescription($tx2));
    }

    public function testPaymentProfileThatWouldMissTheItemAccountIsRejectedAtSave(): void
    {
        $cases = [
            'profile_breaks_saldo' => ['payment_bank' => ['debit_account_code' => '379.500']],
            'invalid_payment_account' => ['payment_bank' => ['credit_account_code' => '518']],
            'invalid_payment_account ' => ['payment_cash' => ['credit_account_code' => '221']],
            'invalid_account_side' => ['origin' => ['credit_account_code' => '315']],
            'same_accounts' => ['origin' => ['debit_account_code' => '325']],
            'invalid_account' => ['origin' => ['debit_account_code' => '999999']],
            'invalid_description_template' => ['origin' => ['description_template' => '{neznamy}']],
            'invalid_posting_event' => ['interest' => ['debit_account_code' => '562']],
        ];
        foreach ($cases as $code => $input) {
            try {
                $this->profiles->saveForKind($this->supplierId, null, 'payable', 'rent', $input, null);
                self::fail('Profil ' . $code . ' musí být odmítnut.');
            } catch (OtherItemException $e) {
                self::assertSame(trim($code), $e->errorCode, json_encode($input));
            }
        }
        self::assertSame(['origin' => null, 'payment_bank' => null, 'payment_cash' => null],
            $this->profiles->profilesForKind($this->supplierId, $this->rentKindId()));
    }

    public function testSyntheticAccountWithSeveralAnalyticsIsNotPostingLevel(): void
    {
        $chart = Bootstrap::buildContainer()->get(ChartOfAccountsRepository::class);
        $parent = $chart->findByCode($this->supplierId, '518');
        foreach (['518.100', '518.200'] as $code) {
            $chart->insert($this->supplierId, ['account_code' => $code, 'name' => 'Syntetická analytika ' . $code,
                'account_type' => 'expense', 'normal_side' => 'debit', 'is_synthetic' => false, 'parent_id' => (int) $parent['id']]);
        }
        try {
            $this->profiles->saveForKind($this->supplierId, null, 'payable', 'rent', ['origin' => ['debit_account_code' => '518']], null);
            self::fail('Syntetika s analytikami se účtovat nedá.');
        } catch (OtherItemException $e) {
            self::assertSame('account_not_posting_level', $e->errorCode);
        }
        $saved = $this->profiles->saveForKind($this->supplierId, null, 'payable', 'rent', ['origin' => ['debit_account_code' => '518.200']], null);
        self::assertSame('518.200', $saved['after']['origin']['debit_account_code']);
    }

    public function testOriginCannotBeOverriddenOutsideItemColumnsAndPaidItemCannotBeReposted(): void
    {
        $item = $this->posted();
        try {
            $this->items->setPostingOverrides($this->supplierId, $item, ['origin' => ['credit_account_code' => '379.500']], null);
            self::fail('Vznik nese položka ve vlastních sloupcích.');
        } catch (OtherItemException $e) {
            self::assertSame('invalid_posting_override', $e->errorCode);
        }
        $this->bankMatch->match($this->supplierId, $this->transaction(-1200), $item, null, [], true);
        try {
            $this->items->repost($this->supplierId, $item, ['reason' => 'Jiný účet', 'entry_date' => '2099-02-01',
                'account_code' => '379.500'], null);
            self::fail('Účet položky s úhradou se měnit nesmí.');
        } catch (OtherItemException $e) {
            self::assertSame('has_payments', $e->errorCode);
        }
        try {
            $this->items->update($this->supplierId, $item, ['account_code' => '379.500'], null);
            self::fail('Zaúčtovaná položka se upravit nedá.');
        } catch (OtherItemException $e) {
            self::assertSame('not_draft', $e->errorCode);
        }
    }

    public function testDraftOverridesFollowSideChangeAndRecurringCopiesThem(): void
    {
        $draft = $this->items->create($this->supplierId, $this->input([
            'posting_overrides' => ['payment_bank' => ['credit_account_code' => '221', 'description_template' => 'Splátka {title}']],
        ]), null);
        $stored = $this->items->get($this->supplierId, (int) $draft['id'])['posting_overrides']->payment_bank;
        self::assertSame([null, '221'], [$stored['debit_account_code'], $stored['credit_account_code']]);

        $this->items->update($this->supplierId, (int) $draft['id'], ['side' => 'receivable', 'kind' => 'claim',
            'counter_account_code' => '602'], null);
        $flipped = $this->items->get($this->supplierId, (int) $draft['id'])['posting_overrides']->payment_bank;
        self::assertSame(['221', null], [$flipped['debit_account_code'], $flipped['credit_account_code']],
            'Peněžní účet přejde se stranou položky, strana položky zůstane prázdná.');

        $schedules = Bootstrap::buildContainer()->get(OtherItemScheduleService::class);
        $schedule = $schedules->create($this->supplierId, (int) $draft['id'], ['frequency' => 'monthly', 'auto_post' => false], null);
        $generated = $schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-01', null);
        self::assertCount(1, $generated['created_ids']);
        $copy = $this->items->get($this->supplierId, $generated['created_ids'][0])['posting_overrides']->payment_bank;
        self::assertSame(['221', null, 'Splátka {title}'], [$copy['debit_account_code'], $copy['credit_account_code'], $copy['description_template']]);
    }

    public function testTaxEvidenceIgnoresProfilesAndOverrides(): void
    {
        $this->profiles->saveForKind($this->supplierId, null, 'payable', 'rent', [
            'origin' => ['debit_account_code' => '548', 'credit_account_code' => '379.500'],
        ], null);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);

        $draft = $this->items->create($this->supplierId, $this->input([
            'counter_account_code' => null, 'posting_overrides' => ['payment_bank' => ['credit_account_code' => '221']],
        ]), null);
        $posted = $this->items->post($this->supplierId, (int) $draft['id'], null);
        self::assertSame('confirmed', $posted['status']);
        self::assertNull($posted['journal_entry_id']);
        self::assertSame([], (array) $posted['posting_rules']);
        self::assertSame([], (array) $posted['posting_overrides']);
        self::assertNull($posted['account_code']);
        try {
            $this->profiles->saveForKind($this->supplierId, null, 'payable', 'rent', ['origin' => null], null);
            self::fail('Daňová evidence profily nemá.');
        } catch (OtherItemException $e) {
            self::assertSame('not_double_entry', $e->errorCode);
        }
        $kinds = $this->profiles->attachToKinds($this->supplierId, [['id' => null, 'side' => 'payable', 'builtin' => true, 'builtin_code' => 'rent']]);
        self::assertArrayNotHasKey('posting_profiles', $kinds[0]);
    }

    public function testSuggestionsAreOfferedButNeverSaved(): void
    {
        $kinds = $this->profiles->attachToKinds($this->supplierId, [
            ['id' => null, 'side' => 'payable', 'builtin' => true, 'builtin_code' => 'rent'],
            ['id' => null, 'side' => 'receivable', 'builtin' => true, 'builtin_code' => 'loan'],
        ]);
        self::assertSame(['518', '325', true], array_values($kinds[0]['posting_suggestion']));
        self::assertSame(['378', '261', true], array_values($kinds[1]['posting_suggestion']));
        self::assertNull($kinds[0]['posting_profiles']['origin']);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM other_item_posting_profiles WHERE supplier_id = ?');
        $count->execute([$this->supplierId]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    private function rentKindId(): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM other_item_kinds WHERE supplier_id = ? AND side = 'payable' AND builtin_code = 'rent'");
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $rule
     * @return array{0:string,1:string} [D, MD]
     */
    private function pair(array $rule): array
    {
        return [(string) $rule['credit_account_code'], (string) $rule['debit_account_code']];
    }

    /** @param array<string,mixed> $changes */
    private function input(array $changes = []): array
    {
        return array_replace([
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetické nájemné',
            'issued_on' => '2099-01-01', 'accounting_on' => '2099-01-01',
            'due_on' => '2099-01-20', 'currency' => 'CZK', 'amount' => 1200,
            'counter_account_code' => '518',
        ], $changes);
    }

    /** @param array<string,mixed> $changes */
    private function posted(array $changes = []): int
    {
        $draft = $this->items->create($this->supplierId, $this->input($changes), null);
        $this->items->post($this->supplierId, (int) $draft['id'], null);
        return (int) $draft['id'];
    }

    private function transaction(float $amount): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, match_status, counterparty_name, description)
             VALUES (?, ?, ?, ?, "unmatched", "Syntetický partner", "Syntetická platba")'
        )->execute([$this->statementId, '2099-01-20', $amount, 'CZK']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<string> řádky zápisu položky jako „účet:strana:částka" */
    private function entryLines(int $itemId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT CONCAT(a.account_code, ":", l.side, ":", CAST(l.amount AS CHAR))
               FROM other_items oi
               JOIN journal_entry_lines l ON l.entry_id = oi.journal_entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE oi.id = ? AND oi.supplier_id = ? ORDER BY l.id'
        );
        $stmt->execute([$itemId, $this->supplierId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function entryDescription(int $entryId): string
    {
        $stmt = $this->pdo->prepare('SELECT description FROM journal_entries WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$entryId, $this->supplierId]);
        return (string) $stmt->fetchColumn();
    }

    /** @return list<string> řádky živého bankovního zápisu (bez analytiky) */
    private function bankLines(int $txId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT CONCAT(SUBSTRING_INDEX(a.account_code, '.', 1), ':', l.side, ':', CAST(l.amount AS CHAR))
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.source_type = 'bank' AND e.source_id = ? AND e.reversed_by IS NULL
              ORDER BY l.id"
        );
        $stmt->execute([$this->supplierId, $txId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function bankDescription(int $txId): string
    {
        $stmt = $this->pdo->prepare("SELECT description FROM journal_entries
            WHERE supplier_id = ? AND source_type = 'bank' AND source_id = ? AND reversed_by IS NULL");
        $stmt->execute([$this->supplierId, $txId]);
        return (string) $stmt->fetchColumn();
    }

    private function provenanceCount(int $itemId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM activity_log
            WHERE supplier_id = ? AND action = 'other_item.posting_profile_applied' AND entity_type = 'other_item' AND entity_id = ?");
        $stmt->execute([$this->supplierId, $itemId]);
        return (int) $stmt->fetchColumn();
    }
}
