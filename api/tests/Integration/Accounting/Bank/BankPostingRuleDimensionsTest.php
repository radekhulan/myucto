<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Accounting\Bank\BankPostingRuleAction;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Accounting\Dimension\BankRuleDimensions;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Automation\AutomationFeedService;
use MyInvoice\Service\Automation\FeedQuery;
use PHPUnit\Framework\Attributes\Group;

/**
 * Dimenze a podmínka „vlastní bankovní účet“ v pravidle účtování bankovních pohybů
 * (migrace 2234). Dimenze pravidla se k pohybu zapíší až při schválení návrhu nebo
 * automatickém zaúčtování. Dostane je jen řádek banky a u pohybu bez dokladu protiúčet
 * (ne saldokonto hrazeného dokladu ani 261), stejně jako výchozí dimenze účtu. Pořadí typ
 * po typu: ruční dimenze pohybu > hrazené doklady > pravidlo pohybů > výchozí hodnota
 * pravidla dimenzí podle účtu > výchozí dimenze účtu. Vše v transakci, tearDown rollbackne.
 */
#[Group('integration')]
final class BankPostingRuleDimensionsTest extends BankPostingTestCase
{
    private const SECOND_ACCOUNT = '3000000004';
    private const SECOND_BANK = '0100';

    private DimensionService $dimensions;
    private DimensionAssignmentRepository $assignments;
    private int $projectType = 0;
    private int $centerType = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dimensions = $this->container->get(DimensionService::class);
        $this->assignments = $this->container->get(DimensionAssignmentRepository::class);
        $this->dimensions->setEnabled($this->supplierId, true);
        $types = $this->dimensions->ensureDefaultTypes($this->supplierId, ['projekt', 'stredisko']);
        $this->projectType = $types['project'];
        $this->centerType = $types['cost_center'];
    }

    // ── schválení a zamítnutí návrhu ─────────────────────────────────────────

    public function testApprovedRuleWritesItsDimensionsToMovementAndEntry(): void
    {
        $center = $this->value($this->centerType, 'BR-A');
        $ruleId = $this->interestRule([$this->centerType => $center]);
        $tx = $this->interest($this->statement());

        $suggestionId = $this->suggest($tx);
        self::assertSame([], $this->movementDimensions($tx), 'Návrh sám k pohybu nic nezapíše.');

        $entryId = $this->service->approveSuggestion($this->supplierId, $suggestionId, $this->meta());

        self::assertSame([$this->centerType => $center], $this->movementDimensions($tx), 'Schválení zapíše dimenze pravidla k pohybu.');
        self::assertSame(
            [$this->centerType => ['origin' => BankRuleDimensions::ORIGIN, 'ref' => 'bank_posting_rule:' . $ruleId]],
            $this->assignments->documentOrigins($this->supplierId, 'bank_transaction', $tx)['header'],
        );
        $origins = $this->assignments->entryLineOrigins($this->supplierId, $entryId);
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            self::assertSame($center, $dims[$line['id']][$this->centerType] ?? null, 'Úrok i banka nesou středisko pravidla.');
            self::assertSame(
                ['origin' => BankRuleDimensions::ORIGIN, 'ref' => 'bank_posting_rule:' . $ruleId],
                $origins[$line['id']][$this->centerType] ?? null,
                'Původ hodnoty ukazuje na pravidlo bankovních pohybů.',
            );
        }
    }

    public function testRejectedSuggestionLeavesNoRuleDimensions(): void
    {
        $this->interestRule([$this->centerType => $this->value($this->centerType, 'BR-REJ')]);
        $tx = $this->interest($this->statement());
        $suggestionId = $this->suggest($tx);

        $this->service->rejectSuggestion($this->supplierId, $suggestionId, $this->meta());
        $res = $this->service->postManual($this->supplierId, $tx, [
            'debit_account_code' => '221', 'credit_account_code' => '668',
        ], $this->meta());

        self::assertSame([], $this->movementDimensions($tx), 'Zamítnuté pravidlo na pohybu nic nenechá.');
        self::assertSame([], $this->assignments->entryLineDimensions($this->supplierId, (int) $res['entry_id']));
    }

    public function testUnpostRemovesRuleDimensionsFromMovement(): void
    {
        $this->interestRule([$this->centerType => $this->value($this->centerType, 'BR-UN')]);
        $tx = $this->interest($this->statement());
        $this->service->approveSuggestion($this->supplierId, $this->suggest($tx), $this->meta());
        self::assertNotSame([], $this->movementDimensions($tx));

        $this->service->unpost($this->supplierId, $tx, $this->meta());

        self::assertSame([], $this->movementDimensions($tx), 'Po odúčtování dimenze pravidla na pohybu nezůstanou.');
    }

    public function testAnotherRuleReplacesEarlierRuleValuesButKeepsManualOnes(): void
    {
        $manual = $this->value($this->projectType, 'BR-KEEP');
        $first = $this->interestRule([$this->centerType => $this->value($this->centerType, 'BR-FIRST')]);
        $secondCenter = $this->value($this->centerType, 'BR-SECOND');
        $second = $this->interestRule([$this->centerType => $secondCenter, $this->projectType => $this->value($this->projectType, 'BR-NOPE')]);
        $tx = $this->interest($this->statement());
        $this->dimensions->saveDocument($this->supplierId, 'bank_transaction', $tx, [$this->projectType => $manual], null);

        $ruleDimensions = new BankRuleDimensions($this->db);
        $ruleDimensions->applyToTransaction($this->supplierId, $tx, $first);
        $ruleDimensions->applyToTransaction($this->supplierId, $tx, $second);

        self::assertEquals([$this->centerType => $secondCenter, $this->projectType => $manual], $this->movementDimensions($tx));
        self::assertSame('bank_posting_rule:' . $second, $this->assignments->documentOrigins($this->supplierId, 'bank_transaction', $tx)['header'][$this->centerType]['ref']);
    }

    public function testRuleWithoutDimensionsKeepsPostingUnchanged(): void
    {
        $this->interestRule([]);
        $tx = $this->interest($this->statement());
        $entryId = $this->service->approveSuggestion($this->supplierId, $this->suggest($tx), $this->meta());

        self::assertSame([], $this->movementDimensions($tx));
        self::assertSame([], $this->assignments->entryLineDimensions($this->supplierId, $entryId), 'Pravidlo bez dimenzí zápis nemění.');
    }

    // ── pořadí vrstev ────────────────────────────────────────────────────────

    public function testExplicitMovementDimensionBeatsRuleTypeByType(): void
    {
        $ruleCenter = $this->value($this->centerType, 'BR-RC');
        $ruleProject = $this->value($this->projectType, 'BR-RP');
        $ruleId = $this->interestRule([$this->centerType => $ruleCenter, $this->projectType => $ruleProject]);
        $tx = $this->interest($this->statement());
        $manual = $this->value($this->centerType, 'BR-MAN');
        $this->dimensions->saveDocument($this->supplierId, 'bank_transaction', $tx, [$this->centerType => $manual], null);

        $entryId = $this->service->approveSuggestion($this->supplierId, $this->suggest($tx), $this->meta());

        $origins = $this->assignments->documentOrigins($this->supplierId, 'bank_transaction', $tx)['header'];
        self::assertSame('explicit', $origins[$this->centerType]['origin'] ?? null, 'Ruční středisko pohybu zůstane.');
        self::assertSame('bank_posting_rule:' . $ruleId, $origins[$this->projectType]['ref'] ?? null, 'Pravidlo doplní jen chybějící typ.');
        foreach ($this->assignments->entryLineDimensions($this->supplierId, $entryId) as $lineDims) {
            ksort($lineDims);
            self::assertEquals([$this->projectType => $ruleProject, $this->centerType => $manual], $lineDims);
        }
    }

    public function testRuleBeatsBankAccountDefault(): void
    {
        $account = $this->ownAccount(self::ACCOUNT, self::BANK_CODE);
        $accountCenter = $this->value($this->centerType, 'BR-ACC');
        $accountProject = $this->value($this->projectType, 'BR-ACCP');
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [
            $this->centerType => $accountCenter, $this->projectType => $accountProject,
        ]);
        $ruleCenter = $this->value($this->centerType, 'BR-OVER');
        $this->interestRule([$this->centerType => $ruleCenter]);
        $tx = $this->interest($this->statement());

        $entryId = $this->service->approveSuggestion($this->supplierId, $this->suggest($tx), $this->meta());

        foreach ($this->assignments->entryLineDimensions($this->supplierId, $entryId) as $lineDims) {
            ksort($lineDims);
            self::assertEquals([$this->projectType => $accountProject, $this->centerType => $ruleCenter], $lineDims,
                'Středisko z pravidla přebije účet, projekt doplní účet.');
        }
    }

    public function testPaidDocumentBeatsRuleDimension(): void
    {
        $docCenter = $this->value($this->centerType, 'BR-DOC');
        $ruleCenter = $this->value($this->centerType, 'BR-RUL');
        $ruleProject = $this->value($this->projectType, 'BR-RPJ');
        $ruleId = $this->interestRule([$this->centerType => $ruleCenter, $this->projectType => $ruleProject]);
        $purchase = $this->advancePurchase('BR-PF', 500.00);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase, [$this->centerType => $docCenter], []);
        $tx = $this->transaction($this->statement(), -500.00);
        $this->paymentMatch($tx, $purchase, 500.00);
        // Pohyb nese hodnoty pravidla (zaúčtovaný podle pravidla dřív, než se spároval s dokladem).
        (new BankRuleDimensions($this->db))->applyToTransaction($this->supplierId, $tx, $ruleId);

        $entryId = (int) $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            ['account_code' => '314', 'side' => 'debit', 'amount' => 500.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 500.00],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId]);

        $lines = $this->linesByAccount($entryId);
        self::assertEquals([$this->projectType => $ruleProject, $this->centerType => $docCenter], $this->bankLine($lines),
            'Na řádku banky středisko hrazeného dokladu přebije pravidlo, projekt doplní pravidlo.');
        self::assertSame([$this->centerType => $docCenter], $lines['314|debit'],
            'Saldokonto hrazené zálohy nese jen dimenze dokladu, projekt pravidla ne.');
    }

    public function testRuleDimensionSkipsTransitAccountOfOwnTransfer(): void
    {
        $center = $this->value($this->centerType, 'BR-TRN');
        $ruleId = $this->rule([
            'name' => 'Převod na spořicí účet', 'direction' => 'outgoing', 'message_contains' => 'prevod na sporici',
            'debit_account_code' => '261', 'credit_account_code' => '221',
        ]);
        $this->ruleRepo->replaceDimensions($this->supplierId, $ruleId, [$this->centerType => $center]);
        $tx = $this->transaction($this->statement(), -1_000.00, ['description' => 'Převod na spořicí účet', 'counterparty_name' => null]);

        $lines = $this->linesByAccount($this->service->approveSuggestion($this->supplierId, $this->suggest($tx), $this->meta()));

        self::assertSame([$this->centerType => $center], $this->bankLine($lines), 'Řádek banky nese středisko pravidla.');
        self::assertSame([], $lines['261|debit'], 'Peníze na cestě středisko pravidla nedostanou, 261 se po dimenzi vyrovná.');
    }

    public function testRuleBeatsAccountRuleDefaultWhichBeatsBankAccountDefault(): void
    {
        $account = $this->ownAccount(self::ACCOUNT, self::BANK_CODE);
        $ruleCenter = $this->value($this->centerType, 'BR-RC');
        $maskCenter = $this->value($this->centerType, 'BR-MC');
        $maskProject = $this->value($this->projectType, 'BR-MP');
        $accountProject = $this->value($this->projectType, 'BR-AP');
        $rules = new DimensionRuleService($this->db);
        $rules->create($this->supplierId, [
            'dimension_type_id' => $this->centerType, 'account_mask' => '568', 'enforcement' => 'none', 'default_value_id' => $maskCenter,
        ]);
        $rules->create($this->supplierId, [
            'dimension_type_id' => $this->projectType, 'account_mask' => '568', 'enforcement' => 'none', 'default_value_id' => $maskProject,
        ]);
        $this->dimensions->saveEntityDefaults($this->supplierId, 'bank_account', $account, [$this->projectType => $accountProject]);
        $ruleId = $this->rule([
            'name' => 'Poplatek', 'direction' => 'outgoing', 'message_contains' => 'poplatek za vedeni',
            'debit_account_code' => '568', 'credit_account_code' => '221',
        ]);
        $this->ruleRepo->replaceDimensions($this->supplierId, $ruleId, [$this->centerType => $ruleCenter]);
        $tx = $this->transaction($this->statement(), -50.00, ['description' => 'Poplatek za vedení účtu', 'counterparty_name' => null]);

        $lines = $this->linesByAccount($this->service->approveSuggestion($this->supplierId, $this->suggest($tx), $this->meta()));

        self::assertEquals([$this->projectType => $maskProject, $this->centerType => $ruleCenter], $lines['568|debit'],
            'Na 568 středisko pravidla pohybů přebije výchozí hodnotu pravidla dimenzí, projekt dá pravidlo dimenzí přednostně před účtem.');
        self::assertEquals([$this->projectType => $accountProject, $this->centerType => $ruleCenter], $this->bankLine($lines),
            'Řádek banky: středisko pravidla pohybů, projekt účtu (pravidlo dimenzí pro 568 tu neplatí).');
    }

    public function testRuleDoesNotOverrideSplitBetweenPaidDocuments(): void
    {
        $first = $this->value($this->centerType, 'BR-D1');
        $second = $this->value($this->centerType, 'BR-D2');
        $ruleId = $this->interestRule([$this->centerType => $this->value($this->centerType, 'BR-DR')]);
        $a = $this->advancePurchase('BR-PA', 300.00);
        $b = $this->advancePurchase('BR-PB', 200.00);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $a, [$this->centerType => $first], []);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $b, [$this->centerType => $second], []);
        $tx = $this->transaction($this->statement(), -500.00);
        $this->paymentMatch($tx, $a, 300.00);
        $this->paymentMatch($tx, $b, 200.00);
        (new BankRuleDimensions($this->db))->applyToTransaction($this->supplierId, $tx, $ruleId);

        $entryId = (int) $this->posting->postDocument($this->supplierId, 'bank', $tx, [
            ['account_code' => '314', 'side' => 'debit', 'amount' => 300.00],
            ['account_code' => '314', 'side' => 'debit', 'amount' => 200.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 500.00],
        ], ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId]);

        $byAmount = [];
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $splits = $this->assignments->entryLineSplits($this->supplierId, $entryId);
        foreach ($this->journal->linesForEntry($entryId, $this->supplierId) as $line) {
            $byAmount[$line['side'] . '|' . number_format((float) $line['amount'], 2, '.', '')] = [
                'center' => $dims[$line['id']][$this->centerType] ?? null,
                'split' => array_keys($splits[$line['id']][$this->centerType] ?? []),
            ];
        }
        self::assertSame($first, $byAmount['debit|300.00']['center'], 'Řádek dokladu nese středisko dokladu, ne pravidla.');
        self::assertSame($second, $byAmount['debit|200.00']['center']);
        self::assertEqualsCanonicalizing([$first, $second], $byAmount['credit|500.00']['split'], 'Banka nese rozpad dokladů.');
    }

    public function testClosedRuleValueIsNotWrittenToMovementOrEntry(): void
    {
        $closed = $this->value($this->centerType, 'BR-CLO');
        $project = $this->value($this->projectType, 'BR-OPN');
        $this->interestRule([$this->centerType => $closed, $this->projectType => $project]);
        $this->db->pdo()->prepare('UPDATE dimension_values SET is_active = 0 WHERE id = ?')->execute([$closed]);
        $tx = $this->interest($this->statement());

        $entryId = $this->service->approveSuggestion($this->supplierId, $this->suggest($tx), $this->meta());

        self::assertSame([$this->projectType => $project], $this->movementDimensions($tx), 'Uzavřené středisko pravidla se k pohybu nezapíše.');
        foreach ($this->linesByAccount($entryId) as $key => $lineDims) {
            self::assertSame([$this->projectType => $project], $lineDims, "Řádek {$key} nese jen otevřenou hodnotu pravidla.");
        }
    }

    public function testRematchToDocumentDropsRuleDimensions(): void
    {
        $center = $this->value($this->centerType, 'BR-RM');
        $ruleId = $this->rule([
            'name' => 'Platba dodavateli', 'direction' => 'outgoing', 'message_contains' => 'platba dodavateli',
            'debit_account_code' => '518', 'credit_account_code' => '221',
        ]);
        $this->ruleRepo->replaceDimensions($this->supplierId, $ruleId, [$this->centerType => $center]);
        $tx = $this->transaction($this->statement(), -500.00, ['description' => 'Platba dodavateli', 'counterparty_name' => null]);
        $this->service->approveSuggestion($this->supplierId, $this->suggest($tx), $this->meta());
        self::assertSame([$this->centerType => $center], $this->movementDimensions($tx));

        // Pohyb se dodatečně spáruje se zálohou a zápis se přepíše na její úhradu.
        $purchase = $this->advancePurchase('BR-RMP', 500.00);
        $this->paymentMatch($tx, $purchase, 500.00);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")->execute([$tx]);
        $this->db->pdo()->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by) VALUES (?, 'bank.payment.matched', 'auto', ?)
             ON DUPLICATE KEY UPDATE level = VALUES(level), updated_by = VALUES(updated_by)"
        )->execute([$this->supplierId, $this->userId]);
        $entryId = $this->service->postMatched($this->supplierId, $tx, $this->userId);

        self::assertNotNull($entryId);
        $lines = $this->linesByAccount((int) $entryId);
        self::assertArrayHasKey('314|debit', $lines, 'Zápis je úhrada zálohy: ' . json_encode(array_keys($lines)));
        self::assertSame([], $this->movementDimensions($tx), 'Úhrada dokladu není použití pravidla, dimenze pravidla z pohybu zmizí.');
        foreach ($lines as $key => $lineDims) {
            self::assertSame([], $lineDims, "Řádek {$key} nenese středisko pravidla, které se už nepoužilo.");
        }
    }

    public function testAutoRuleWithoutDimensionsAndMissingRequiredDimensionEndsAsNeedsInput(): void
    {
        (new DimensionRuleService($this->db))->create($this->supplierId, [
            'dimension_type_id' => $this->projectType, 'account_mask' => '6', 'enforcement' => 'error',
        ]);
        $ruleId = $this->interestRule([]);
        $this->makeAuto($ruleId);
        $tx = $this->interest($this->statement());

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('suggested', $res['action'], 'I pravidlo bez dimenzí skončí při chybějící povinné dimenzi návrhem, ne chybou: ' . json_encode($res));
        self::assertSame('needs_input', $this->suggestionRow((int) $res['suggestion_id'])['status']);
        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testRestampOfRulePostedEntryKeepsRuleDimensions(): void
    {
        $center = $this->value($this->centerType, 'BR-RS');
        $this->interestRule([$this->centerType => $center]);
        $tx = $this->interest($this->statement());
        $entryId = $this->service->approveSuggestion($this->supplierId, $this->suggest($tx), $this->meta());

        $this->posting->restampDimensions($this->supplierId, 'bank', $tx);

        foreach ($this->linesByAccount($entryId) as $key => $lineDims) {
            self::assertSame([$this->centerType => $center], $lineDims, "Přerazítkování nechá řádku {$key} středisko pravidla.");
        }
    }

    public function testRuleWritesNothingWhenDimensionsAreOff(): void
    {
        $this->interestRule([$this->centerType => $this->value($this->centerType, 'BR-OFFW')]);
        $tx = $this->interest($this->statement());
        $suggestionId = $this->suggest($tx);
        $this->dimensions->setEnabled($this->supplierId, false);

        $this->service->approveSuggestion($this->supplierId, $suggestionId, $this->meta());

        self::assertSame([], $this->movementDimensions($tx), 'Bez zapnutých dimenzí se k pohybu nic nezapíše.');
    }

    // ── vlastní bankovní účet ────────────────────────────────────────────────

    public function testTwoAccountsWithSameMovementTypeEachGetTheirRule(): void
    {
        $first = $this->ownAccount(self::ACCOUNT, self::BANK_CODE);
        $second = $this->ownAccount(self::SECOND_ACCOUNT, self::SECOND_BANK);
        $firstCenter = $this->value($this->centerType, 'BR-1');
        $secondCenter = $this->value($this->centerType, 'BR-2');
        $general = $this->interestRule([$this->centerType => $this->value($this->centerType, 'BR-GEN')]);
        $firstRule = $this->interestRule([$this->centerType => $firstCenter], $first);
        $secondRule = $this->interestRule([$this->centerType => $secondCenter], $second);

        $onFirst = $this->interest($this->statement());
        $onSecond = $this->interest($this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK));
        $sugFirst = $this->suggestionRow($this->suggest($onFirst));
        $sugSecond = $this->suggestionRow($this->suggest($onSecond));

        self::assertSame($firstRule, (int) $sugFirst['rule_id'], 'Pravidlo omezené na účet přebije obecné bez souběhu.');
        self::assertNull($sugFirst['note'], 'Účet rozhodne, nejde o souběh pravidel.');
        self::assertSame($secondRule, (int) $sugSecond['rule_id']);
        $this->service->approveSuggestion($this->supplierId, (int) $sugFirst['id'], $this->meta());
        $this->service->approveSuggestion($this->supplierId, (int) $sugSecond['id'], $this->meta());
        self::assertSame([$this->centerType => $firstCenter], $this->movementDimensions($onFirst));
        self::assertSame([$this->centerType => $secondCenter], $this->movementDimensions($onSecond));

        // Pohyb na účtu bez vlastního pravidla dostane obecné.
        $this->db->pdo()->prepare('UPDATE bank_posting_rules SET is_active = 0 WHERE id = ?')->execute([$secondRule]);
        $later = $this->interest($this->statement(self::SECOND_ACCOUNT, self::SECOND_BANK));
        self::assertSame($general, (int) $this->suggestionRow($this->suggest($later))['rule_id'], 'Pravidlo jiného účtu se nepoužije.');
    }

    public function testTwoAccountRulesOfSameAccountStayInConflict(): void
    {
        $account = $this->ownAccount(self::ACCOUNT, self::BANK_CODE);
        $first = $this->interestRule([], $account);
        $second = $this->interestRule([], $account);
        $this->interestRule([], $this->ownAccount(self::SECOND_ACCOUNT, self::SECOND_BANK));
        $suggestionId = $this->suggest($this->interest($this->statement()));
        $sug = $this->suggestionRow($suggestionId);
        self::assertSame('rule_conflict', $sug['note'], 'Dvě pravidla téhož účtu se stejnou prioritou jsou souběh.');

        $feed = $this->container->get(AutomationFeedService::class);
        $item = null;
        foreach (['pending', 'needs_input'] as $tab) {
            foreach ($feed->feed(0, true, new FeedQuery($tab, [$this->supplierId]))['items'] as $candidate) {
                if ($candidate['id'] === 'bps:' . $suggestionId) $item = $candidate;
            }
        }
        self::assertNotNull($item, 'Návrh se souběhem je v Automatice.');
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $item['conflict_rules'] ?? []);
        sort($ids);
        self::assertSame([$first, $second], $ids, 'Pravidlo jiného vlastního účtu do souběhu nepatří.');
    }

    // ── automatické zaúčtování ───────────────────────────────────────────────

    public function testAutoRuleWithoutRequiredDimensionEndsAsNeedsInput(): void
    {
        (new DimensionRuleService($this->db))->create($this->supplierId, [
            'dimension_type_id' => $this->projectType, 'account_mask' => '6', 'enforcement' => 'error',
        ]);
        $center = $this->value($this->centerType, 'BR-AUTO');
        $ruleId = $this->interestRule([$this->centerType => $center]);
        $this->makeAuto($ruleId);
        $tx = $this->interest($this->statement());

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('suggested', $res['action'], 'Chybějící povinná dimenze není chyba automatiky.');
        $sug = $this->suggestionRow((int) $res['suggestion_id']);
        self::assertSame('needs_input', $sug['status']);
        self::assertSame('dimension_required', $sug['note']);
        self::assertSame(0, $this->entryCountForTx($tx));
        self::assertSame([], $this->movementDimensions($tx), 'Nezaúčtovaný pohyb nesmí nést středisko z pravidla.');

        $project = $this->value($this->projectType, 'BR-AUTOP');
        $this->ruleRepo->replaceDimensions($this->supplierId, $ruleId, [$this->centerType => $center, $this->projectType => $project]);
        $this->db->pdo()->prepare("UPDATE bank_posting_suggestions SET status = 'superseded' WHERE id = ?")->execute([(int) $sug['id']]);
        $next = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $next['action'], 'S dimenzí z pravidla se pohyb zaúčtuje sám.');
        self::assertEquals([$this->centerType => $center, $this->projectType => $project], $this->movementDimensions($tx));
    }

    public function testFailedApprovalInsideApplyRuleLeavesNoRuleDimensions(): void
    {
        (new DimensionRuleService($this->db))->create($this->supplierId, [
            'dimension_type_id' => $this->projectType, 'account_mask' => '6', 'enforcement' => 'error',
        ]);
        $ruleId = $this->interestRule([$this->centerType => $this->value($this->centerType, 'BR-APPLY')]);
        $tx = $this->interest($this->statement());

        $res = $this->service->applyRuleToTransaction($this->supplierId, $tx, $ruleId, $this->userId);

        self::assertSame('suggested', $res['status']);
        self::assertSame('dimension_required', $res['reason']);
        self::assertSame(0, $this->entryCountForTx($tx));
        self::assertSame([], $this->movementDimensions($tx), 'Nepovedené schválení nenechá dimenze pravidla na pohybu.');
    }

    public function testChangingOneValueInPanelKeepsOtherRuleValuesAsRuleValues(): void
    {
        $center = $this->value($this->centerType, 'BR-PC');
        $ruleId = $this->interestRule([$this->centerType => $center, $this->projectType => $this->value($this->projectType, 'BR-PP')]);
        $tx = $this->interest($this->statement());
        $this->service->approveSuggestion($this->supplierId, $this->suggest($tx), $this->meta());

        $manual = $this->value($this->projectType, 'BR-PMAN');
        $this->dimensions->saveDocument($this->supplierId, 'bank_transaction', $tx, [$this->centerType => $center, $this->projectType => $manual], null);

        $origins = $this->assignments->documentOrigins($this->supplierId, 'bank_transaction', $tx)['header'];
        self::assertSame(['origin' => BankRuleDimensions::ORIGIN, 'ref' => 'bank_posting_rule:' . $ruleId], $origins[$this->centerType], 'Nezměněná hodnota pravidla zůstane hodnotou pravidla.');
        self::assertSame('explicit', $origins[$this->projectType]['origin'], 'Změněná hodnota je ruční.');
        self::assertSame([$this->centerType], (new BankRuleDimensions($this->db))->typesFor($this->supplierId, [$tx])[$tx]);
    }

    // ── API pravidel ─────────────────────────────────────────────────────────

    public function testApiSavesValidatesAndListsRuleDimensionsAndAccount(): void
    {
        $action = $this->container->get(BankPostingRuleAction::class);
        $account = $this->ownAccount(self::ACCOUNT, self::BANK_CODE);
        $center = $this->value($this->centerType, 'BR-API');
        $body = [
            'name' => 'Úroky', 'direction' => 'incoming', 'message_contains' => 'úrok',
            'debit_account_code' => '221', 'credit_account_code' => '662',
            'bank_account_id' => $account, 'dimensions' => [$this->centerType => $center],
        ];

        $created = $this->callAction($action, 'create', 'POST', 'admin', $body);
        self::assertSame(201, $created['status']);
        $ruleId = (int) $created['body']['rule']['id'];
        self::assertSame($account, $created['body']['rule']['bank_account_id']);
        self::assertEquals([$this->centerType => $center], $created['body']['rule']['dimensions']);

        $foreignValue = $this->value($this->projectType, 'BR-WRONG');
        $invalid = $this->callAction($action, 'update', 'PUT', 'admin', ['dimensions' => [$this->centerType => $foreignValue]], ['id' => (string) $ruleId]);
        self::assertSame(422, $invalid['status'], 'Hodnota jiného typu je validační chyba.');
        self::assertSame('invalid_dimension', $invalid['body']['error']['code'] ?? $invalid['body']['code'] ?? null);

        $otherAccount = $this->callAction($action, 'create', 'POST', 'admin', ['bank_account_id' => 999999999] + $body);
        self::assertSame(422, $otherAccount['status'], 'Cizí nebo neexistující účet pravidlo nepřijme.');

        $cleared = $this->callAction($action, 'update', 'PUT', 'admin', ['dimensions' => [], 'bank_account_id' => null], ['id' => (string) $ruleId]);
        self::assertSame(200, $cleared['status']);
        self::assertNull($cleared['body']['bank_account_id']);
        self::assertEquals([], $cleared['body']['dimensions']);
        self::assertSame([], $this->ruleRepo->dimensions($this->supplierId, $ruleId));

        $list = $this->callAction($action, 'list', 'GET', 'admin');
        $listed = array_values(array_filter($list['body']['items'], static fn (array $r): bool => (int) $r['id'] === $ruleId));
        self::assertCount(1, $listed);
        self::assertArrayHasKey('dimensions', $listed[0]);
    }

    public function testApiRejectsDimensionsWhenCompanyHasThemOff(): void
    {
        $action = $this->container->get(BankPostingRuleAction::class);
        $center = $this->value($this->centerType, 'BR-OFF');
        $this->dimensions->setEnabled($this->supplierId, false);
        $res = $this->callAction($action, 'create', 'POST', 'admin', [
            'name' => 'Úroky', 'direction' => 'incoming', 'message_contains' => 'úrok',
            'debit_account_code' => '221', 'credit_account_code' => '662',
            'dimensions' => [$this->centerType => $center],
        ]);
        self::assertSame(409, $res['status']);
    }

    public function testApiKeepsUnchangedDimensionsAfterCompanyTurnedThemOff(): void
    {
        $action = $this->container->get(BankPostingRuleAction::class);
        $center = $this->value($this->centerType, 'BR-KEEPOFF');
        $ruleId = $this->interestRule([$this->centerType => $center]);
        $this->dimensions->setEnabled($this->supplierId, false);

        $renamed = $this->callAction($action, 'update', 'PUT', 'admin', ['name' => 'Úroky nově', 'dimensions' => [$this->centerType => $center]], ['id' => (string) $ruleId]);
        self::assertSame(200, $renamed['status'], 'Nezměněné dimenze úpravu pravidla nezablokují.');
        self::assertSame([$this->centerType => $center], $this->ruleRepo->dimensions($this->supplierId, $ruleId));

        $changed = $this->callAction($action, 'update', 'PUT', 'admin', ['dimensions' => [$this->centerType => $this->value($this->centerType, 'BR-NEWOFF')]], ['id' => (string) $ruleId]);
        self::assertSame(409, $changed['status'], 'Novou hodnotu bez zapnutých dimenzí uložit nejde.');
    }

    public function testDeletingRuleCascadesItsDimensions(): void
    {
        $ruleId = $this->interestRule([$this->centerType => $this->value($this->centerType, 'BR-DEL')]);
        $this->ruleRepo->delete($this->supplierId, $ruleId);
        $left = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_posting_rule_dimensions WHERE rule_id = ?');
        $left->execute([$ruleId]);
        self::assertSame(0, (int) $left->fetchColumn());
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /** @param array<int,int> $dimensions */
    /** @return array<string,array<int,int>> kód účtu|strana => dimenze */
    private function linesByAccount(int $entryId): array
    {
        $dims = $this->assignments->entryLineDimensions($this->supplierId, $entryId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.side, c.account_code FROM journal_entry_lines l JOIN chart_of_accounts c ON c.id = l.account_id
              WHERE l.supplier_id = ? AND l.entry_id = ?'
        );
        $stmt->execute([$this->supplierId, $entryId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $line) {
            $d = $dims[(int) $line['id']] ?? [];
            ksort($d);
            $out[$line['account_code'] . '|' . $line['side']] = $d;
        }
        return $out;
    }

    /**
     * Dimenze řádku banky (analytika 221.xxx účtu výpisu).
     *
     * @param array<string,array<int,int>> $lines
     * @return array<int,int>
     */
    private function bankLine(array $lines): array
    {
        $bank = array_values(array_filter($lines, static fn (string $key): bool => str_starts_with($key, '221'), ARRAY_FILTER_USE_KEY));
        self::assertCount(1, $bank, 'Zápis má jeden řádek banky: ' . json_encode(array_keys($lines)));
        return $bank[0];
    }

    private function interestRule(array $dimensions, ?int $bankAccountId = null): int
    {
        $ruleId = $this->rule([
            'name' => 'Úrok', 'direction' => 'incoming', 'message_contains' => BankMessageNormalizer::normalize('úrok'),
            'debit_account_code' => '221', 'credit_account_code' => '662',
        ]);
        if ($bankAccountId !== null) {
            $this->ruleRepo->update($this->supplierId, $ruleId, ['bank_account_id' => $bankAccountId]);
        }
        if ($dimensions !== []) {
            $this->ruleRepo->replaceDimensions($this->supplierId, $ruleId, $dimensions);
        }
        return $ruleId;
    }

    private function interest(int $statementId): int
    {
        return $this->transaction($statementId, 120.00, ['description' => 'Úrok za měsíc', 'counterparty_name' => null]);
    }

    private function suggest(int $txId): int
    {
        $res = $this->service->handleTransaction($txId, $this->userId);
        self::assertSame('suggested', $res['action'] ?? null, 'Pravidlo v režimu návrhu: ' . json_encode($res));
        return (int) $res['suggestion_id'];
    }

    private function makeAuto(int $ruleId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE bank_posting_rules SET mode = 'auto', mode_set_manually_at = NOW() WHERE id = ?")->execute([$ruleId]);
        $pdo->prepare(
            "INSERT INTO auto_posting_policy (supplier_id, operation_type, level, updated_by)
             VALUES (?, 'bank.rule.custom', 'auto', ?)
             ON DUPLICATE KEY UPDATE level = VALUES(level), updated_by = VALUES(updated_by)"
        )->execute([$this->supplierId, $this->userId]);
    }

    /** @return array<int,int> */
    private function movementDimensions(int $txId): array
    {
        return $this->assignments->documentDimensions($this->supplierId, 'bank_transaction', $txId)['header'];
    }

    private function ownAccount(string $number, string $bank): int
    {
        $existing = (new SupplierBankAccountRepository($this->db, new BankStatementOwnershipResolver($this->db)))
            ->matchCounterparty($this->supplierId, $number, $bank, true);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, ?, ?, ?, ?, 'CZK', ?, 'current', 'manual', 1)"
        )->execute([$this->supplierId, 'Účet ' . $number, $number, $bank, $bank, ltrim($number, '0')]);
        return (int) $pdo->lastInsertId();
    }

    private function value(int $typeId, string $code): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $typeId, ['code' => $code, 'name' => 'Hodnota ' . $code])['id'];
    }

    /** Zálohová přijatá faktura: úhrada jde na 314 bez zaúčtovaného předpisu. */
    private function advancePurchase(string $number, float $total): int
    {
        $pdo = $this->db->pdo();
        $vendorId = $this->client('Dodavatel ' . $number);
        $date = self::YEAR . '-06-01';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date,
                 received_at, currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, created_by)
             VALUES (?, ?, ?, "advance", ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, "received", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $date, $date, $date, $date, $this->currencyId, $total, $total, $this->userId]);
        return (int) $pdo->lastInsertId();
    }
}
