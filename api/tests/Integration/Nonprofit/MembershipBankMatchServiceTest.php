<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Action\Bank\ClientBankAccess;
use MyInvoice\Action\Nonprofit\MembershipBankMatchAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\ClientBankAccountRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalLinePairingRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\Dimension\DimensionAccountMapService;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\OtherItemBankMatchService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\OtherItemPaymentMatchReader;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Nonprofit\Membership\MembershipBankMatchService;
use MyInvoice\Service\Nonprofit\Membership\MembershipBankPaymentGuard;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipMemberService;
use MyInvoice\Service\Nonprofit\Membership\MembershipPublicCatalog;
use MyInvoice\Service\Nonprofit\Membership\MembershipReconciliationService;
use MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MembershipBankMatchServiceTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private PostingService $posting;
    private MembershipMemberService $members;
    private MembershipChargeService $charges;
    private MembershipBalanceRepository $balances;
    private int $supplier;
    private int $period;
    private int $actor;
    private array $classification;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $vat = (int) $this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) VALUES('Synthetic membership association','Synthetic street','Synthetic city','10000','membership-association@example.test',203,1,?,'double_entry')")->execute([$vat]);
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")->execute([$this->supplier]);
        $currency = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$currency, $this->supplier]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES(? ,?,'Synthetic membership actor','admin','cs',1)")
            ->execute(['membership-actor@example.test', password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->actor = (int) $this->pdo->lastInsertId();
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$this->supplier]);
        $axes = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2026-01-01', $this->actor);
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'MEMBERSHIP','Synthetic membership activity')")
            ->execute([$axes['activity_kind']['id'], $this->supplier]);
        $this->classification = ['activity_value_id' => $axes['activity']['values']['main'],
            'activity_kind_value_id' => (int) $this->pdo->lastInsertId(),
            'tax_regime_value_id' => $axes['tax_regime']['values']['subject']];
        foreach (['221' => 'asset', '315.100' => 'asset', '684.100' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')
                ->execute([$this->supplier, $code, 'Synthetic ' . $code, $type]);
        }
        (new NonprofitLegalProfileService($this->db))->confirm($this->supplier, $this->period, [
            'expected_revision' => 0,
            'idempotency_key' => 'membership-legal',
            'reason' => 'Synthetic reviewed association statutes',
            'profile' => ['kind' => 'narrow_vpp', 'entity_type' => 'association', 'main_activity_nonbusiness' => true,
                'legal_basis' => 'zdp_17a', 'evidence' => 'Synthetic signed association statutes'],
        ], $this->actor);
        $repository = new MembershipRepository($this->db);
        $this->members = new MembershipMemberService($this->db, $repository);
        $this->posting = $container->get(PostingService::class);
        $this->charges = new MembershipChargeService($this->db, $repository, new MembershipChargeRepository($this->db), $this->posting);
        $this->balances = new MembershipBalanceRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testExactCollectiveMatchPostsOnceAndReplaysWithoutWrites(): void
    {
        $a = $this->newClaim('bank-a');
        $b = $this->newClaim('bank-b');
        $tx = $this->payment('40000.00');
        $service = $this->bankService();
        $result = $service->match($this->supplier, $tx, [$b, $a], $this->actor, [], true);
        self::assertSame([$a, $b], $result['claim_ids']);
        self::assertSame($result, $service->match($this->supplier, $tx, [$a, $b], $this->actor, [], false));
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='bank' AND source_id=?");
        $q->execute([$this->supplier, $tx]);
        self::assertSame(1, (int) $q->fetchColumn());
        foreach ([$a, $b] as $id) self::assertSame('settled', (new MembershipReconciliationService($this->balances))->claimSettlement($this->supplier, $id)['settlement']['status']);
        $read = OtherItemPaymentMatchReader::membershipByTransactions($this->pdo, $this->supplier, [$tx]);
        self::assertSame([$a, $b], array_column($read[$tx], 'claim_id'));
        self::assertSame('2000000', $read[$tx][0]['amount_cents']);
        self::assertSame([], $service->candidates($this->supplier, $tx)['candidates']);
        $this->expectException(MembershipException::class);
        $service->match($this->supplier, $tx, [$a], $this->actor, [], true);
    }

    public function testSinglePartialPaymentsShareOneNativePairing(): void
    {
        $id = $this->newClaim('bank-partial', '123456');
        $a = $this->payment('5000.00');
        $b = $this->payment('15000.00', '123456');
        $first = $this->bankService()->match($this->supplier, $a, [$id], $this->actor, [], true);
        self::assertSame('partially_settled', (new MembershipReconciliationService($this->balances))->claimSettlement($this->supplier, $id)['settlement']['status']);
        $candidates = $this->bankService()->candidates($this->supplier, $b);
        self::assertSame([$id], array_column($candidates['candidates'], 'id'));
        self::assertSame('1500000', $candidates['candidates'][0]['amount_cents']);
        $last = $this->bankService()->match($this->supplier, $b, [$id], $this->actor, [], true);
        self::assertSame($first['pairing_id'], $last['pairing_id']);
        self::assertSame('settled', (new MembershipReconciliationService($this->balances))->claimSettlement($this->supplier, $id)['settlement']['status']);
    }

    public function testCandidatesPageAllDuplicatesAndManualSearchIgnoresVsAndAmount(): void
    {
        $ids = [];
        for ($i = 0; $i < 4; $i++) $ids[] = $this->newClaim('bank-duplicate-' . $i, '000123');
        $tx = $this->payment('40000.00', '123');
        $first = $this->bankService()->candidates($this->supplier, $tx, '0', 2);
        self::assertSame([$ids[3], $ids[2]], array_column($first['candidates'], 'id'), 'Nejnovější předpisy jsou první.');
        self::assertSame('vs:' . $ids[2], $first['next_cursor']);
        $second = $this->bankService()->candidates($this->supplier, $tx, $first['next_cursor'], 2);
        self::assertSame([$ids[1], $ids[0]], array_column($second['candidates'], 'id'));
        self::assertSame('amount:' . PHP_INT_MAX, $second['next_cursor'], 'Po shodách VS následují předpisy shodné jen částkou.');
        $third = $this->bankService()->candidates($this->supplier, $tx, $second['next_cursor'], 2);
        self::assertSame([], $third['candidates']);
        self::assertNull($third['next_cursor']);
        $other = $this->newClaim('bank-search', null);
        self::assertSame([$other], array_column($this->bankService()->candidates($this->supplier, $tx, '0', 50, 'bank-search')['candidates'], 'id'));
        // Pozice jiné fáze stránkování se odmítne, nepokračuje od cizího ID.
        foreach ([['search:' . $ids[2], ''], ['vs:' . $ids[2], 'bank-search'], ['amount:' . $ids[2], 'bank-search']] as [$cursor, $search]) {
            try {
                $this->bankService()->candidates($this->supplier, $tx, $cursor, 2, $search);
                self::fail("Pozice $cursor nepatří k hledání '$search'.");
            } catch (MembershipException $e) {
                self::assertSame(422, $e->httpStatus);
            }
        }
        $plain = $this->payment('40000.00');
        try {
            $this->bankService()->candidates($this->supplier, $plain, 'vs:' . $ids[2], 2);
            self::fail('Pohyb bez VS nemá fázi shody VS.');
        } catch (MembershipException $e) {
            self::assertSame(422, $e->httpStatus);
        }
        $this->expectException(MembershipException::class);
        $this->bankService()->candidates($this->supplier + 900000, $tx);
    }

    public function testPaidHistoryIsSkippedWithoutLosingOpenClaimsAcrossPages(): void
    {
        // Střídavě uhrazené a otevřené předpisy stejné částky i VS: okno větve se plní i uhrazenými,
        // stránky musí přesto vrátit každý otevřený předpis právě jednou.
        $open = [];
        for ($i = 0; $i < 7; $i++) {
            $claim = $this->newClaim('bank-history-' . $i, '4242');
            if ($i % 2 === 0) {
                $this->bankService()->match($this->supplier, $this->payment('20000.00', '4242'), [$claim], $this->actor, [], true);
            } else {
                $open[] = $claim;
            }
        }
        $tx = $this->payment('20000.00', '4242');
        foreach (['vs' => $tx, 'amount' => $this->payment('20000.00', '999')] as $label => $payment) {
            $seen = [];
            $after = '0';
            for ($page = 0; $page < 10; $page++) {
                $result = $this->bankService()->candidates($this->supplier, $payment, $after, 2);
                $ids = array_column($result['candidates'], 'id');
                self::assertLessThanOrEqual(2, count($ids));
                $seen = [...$seen, ...$ids];
                if ($result['next_cursor'] === null) break;
                self::assertNotSame($after, $result['next_cursor']);
                $after = $result['next_cursor'];
            }
            self::assertSame(array_reverse($open), $seen, $label);
        }
    }

    public function testVariableSymbolMatchComesFirstAmongManyClaimsOfTheSameAmount(): void
    {
        // Po hromadném předpisu má stejnou částku mnoho členů; platba člena podle VS nesmí zapadnout.
        $own = $this->newClaim('bank-priority-own', '5151');
        $others = [];
        for ($i = 0; $i < 4; $i++) $others[] = $this->newClaim('bank-priority-' . $i, (string) (6000 + $i));
        $tx = $this->payment('20000.00', '5151');
        $first = $this->bankService()->candidates($this->supplier, $tx, '0', 2);
        self::assertSame([$own], array_column($first['candidates'], 'id'));
        // Stejný slovník důvodů a skóre jako u ostatní položky (OtherItemPaymentCandidateRanker, #275).
        self::assertSame(['variable_symbol', 'remaining_amount'], $first['candidates'][0]['match_reasons']);
        self::assertSame(85, $first['candidates'][0]['match_score']);
        $rest = $this->bankService()->candidates($this->supplier, $tx, $first['next_cursor'], 50);
        self::assertSame(array_reverse($others), array_column($rest['candidates'], 'id'), 'Shoda VS se ve fázi částky neopakuje.');
        self::assertSame(['remaining_amount'], $rest['candidates'][0]['match_reasons']);
    }

    public function testSameMemberClaimsWithTheSameSymbolAndAmountAreAllOffered(): void
    {
        // Issue #321, krok 6: víc otevřených předpisů téhož člena se shodným VS a částkou se nabídne
        // všechny a nic se nevybere automaticky.
        $member = $this->member('SYN-SAME-MEMBER', 'same-member');
        $claims = [];
        foreach (['annual-2026', 'camp-2026'] as $title) {
            $request = ['title_key' => $title, 'variable_symbol' => '4242'] + $this->request($member['id']);
            $preview = $this->charges->previewIssue($this->supplier, $request);
            $issued = $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'same-member-' . $title, $this->actor);
            $claims[] = (int) $this->pdo->query('SELECT id FROM nonprofit_membership_charge_claims WHERE operation_id=' . (int) $issued['operation']['id'])->fetchColumn();
        }
        $tx = $this->payment('20000.00', '4242');
        $candidates = $this->bankService()->candidates($this->supplier, $tx)['candidates'];
        self::assertEqualsCanonicalizing($claims, array_column($candidates, 'id'));
        self::assertSame([$member['id'], $member['id']], array_column($candidates, 'member_id'));
        foreach ($candidates as $candidate) self::assertSame(['variable_symbol', 'remaining_amount'], $candidate['match_reasons']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM journal_entries WHERE source_type='bank' AND source_id=" . $tx)->fetchColumn(),
            'Nabídka kandidátů nic nepárovala ani nezaúčtovala.');
    }

    public function testRankerUsesTheSameSymbolKeyAsCandidateSelectionAndExactCents(): void
    {
        // VS z banky s mezerou: kandidát přijde z fáze VS (matchKey), musí nést i důvod VS.
        $claim = $this->newClaim('bank-symbol-key', '1234');
        $spaced = $this->payment('20000.00', '12 34');
        $candidate = $this->bankService()->candidates($this->supplier, $spaced)['candidates'][0];
        self::assertSame($claim, $candidate['id']);
        self::assertSame(['variable_symbol', 'remaining_amount'], $candidate['match_reasons']);
        // O haléř vyšší platba: párování by ji odmítlo jako přeplatek, proto ne „celý zbytek“.
        $over = $this->payment('20000.01', '1234');
        $candidate = $this->bankService()->candidates($this->supplier, $over)['candidates'][0];
        self::assertSame($claim, $candidate['id']);
        self::assertSame(['variable_symbol'], $candidate['match_reasons']);
        self::assertSame(80, $candidate['match_score']);
    }

    public function testPayerAccountOfMemberIsAnAuxiliaryReasonAndRanksTheClaimFirst(): void
    {
        // Platba bez VS po hromadném předpisu: stejnou částku má víc členů. Účet plátce známý u kontaktu
        // člena kandidáta jen zvýrazní a posune dopředu; ostatní předpisy zůstanou nabídnuté.
        $others = [$this->newClaim('bank-payer-a'), $this->newClaim('bank-payer-b')];
        $own = $this->newClaim('bank-payer-own');
        $first = $this->newClaim('bank-payer-c');
        $client = (int) $this->pdo->query('SELECT client_id FROM nonprofit_membership_charge_claims WHERE id=' . $own)->fetchColumn();
        Bootstrap::buildContainer()->get(ClientBankAccountRepository::class)->addManual($client, $this->supplier, ['account_number' => '1000000005', 'bank_code' => '0300']);
        $tx = $this->payment('20000.00');
        $this->pdo->prepare("UPDATE bank_transactions SET counterparty_account='1000000005',counterparty_bank='0300' WHERE id=?")->execute([$tx]);
        $candidates = $this->bankService()->candidates($this->supplier, $tx)['candidates'];
        self::assertSame($own, $candidates[0]['id']);
        self::assertSame(['counterparty_account', 'remaining_amount'], $candidates[0]['match_reasons']);
        self::assertSame(55, $candidates[0]['match_score']);
        self::assertEqualsCanonicalizing([...$others, $first], array_column(array_slice($candidates, 1), 'id'));
        self::assertSame(['remaining_amount'], $candidates[1]['match_reasons']);
    }

    public function testFullBranchWindowDoesNotSkipOlderClaimOfTheOtherBranch(): void
    {
        // Okno fáze VS obsadí tři uhrazené předpisy; částečně uhrazený přijde na další stránce
        // a teprve po vyčerpání VS předpisy shodné jen částkou.
        $first = $this->newClaim('bank-window-a', '7');
        $partial = $this->newClaim('bank-window-partial', '4343');
        $this->bankService()->match($this->supplier, $this->payment('5000.00', '4343'), [$partial], $this->actor, [], true);
        $second = $this->newClaim('bank-window-b', '8');
        for ($i = 0; $i < 3; $i++) {
            $paid = $this->newClaim('bank-window-paid-' . $i, '4343');
            $this->bankService()->match($this->supplier, $this->payment('20000.00', '4343'), [$paid], $this->actor, [], true);
        }
        $tx = $this->payment('20000.00', '4343');
        $seen = [];
        $after = '0';
        for ($page = 0; $page < 10; $page++) {
            $result = $this->bankService()->candidates($this->supplier, $tx, $after, 2);
            $seen = [...$seen, ...array_column($result['candidates'], 'id')];
            if ($result['next_cursor'] === null) break;
            $after = $result['next_cursor'];
        }
        self::assertSame([$partial, $second, $first], $seen);
    }

    public function testSearchWindowBoundaryDoesNotSkipClaimOfTheOtherBranch(): void
    {
        // Hledání „okno“ najde členy podle čísla i předpisy podle označení. Okno větve členů obsadí tři
        // uhrazené předpisy; bez hranice by stránka vrátila oba předpisy podle označení a částečně
        // uhrazený předpis člena by se už nikdy nenabídl.
        $titled = function (string $key): int {
            $member = $this->member('SYN-TITLED-' . $key, $key);
            $issued = $this->issueTitle($member['id'], 'okno-' . $key, $key);
            return (int) $this->pdo->query('SELECT id FROM nonprofit_membership_charge_claims WHERE operation_id=' . (int) $issued['operation']['id'])->fetchColumn();
        };
        $titleA = $titled('title-a');
        $partial = $this->newClaim('okno-partial');
        $this->bankService()->match($this->supplier, $this->payment('5000.00'), [$partial], $this->actor, [], true);
        $titleB = $titled('title-b');
        for ($i = 0; $i < 3; $i++) {
            $paid = $this->newClaim('okno-paid-' . $i);
            $this->bankService()->match($this->supplier, $this->payment('20000.00'), [$paid], $this->actor, [], true);
        }
        $tx = $this->payment('1.00');
        $seen = [];
        $after = '0';
        for ($page = 0; $page < 10; $page++) {
            $result = $this->bankService()->candidates($this->supplier, $tx, $after, 2, 'okno');
            $seen = [...$seen, ...array_column($result['candidates'], 'id')];
            if ($result['next_cursor'] === null) break;
            $after = $result['next_cursor'];
        }
        self::assertSame([$titleB, $partial, $titleA], $seen);
    }

    public function testPaymentLineCarriesClaimDimensionsSoTheReceivableBalancesPerDimension(): void
    {
        $claim = $this->newClaim('bank-dimensions', '4646');
        $tx = $this->payment('20000.00', '4646');
        $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
        $receivable = (int) $this->pdo->query('SELECT o.receivable_line_id FROM nonprofit_membership_charge_claims c
            JOIN nonprofit_membership_charge_operations o ON o.supplier_id=c.supplier_id AND o.id=c.operation_id WHERE c.id=' . $claim)->fetchColumn();
        $q = $this->pdo->prepare("SELECT l.id FROM journal_entries e JOIN journal_entry_lines l ON l.supplier_id=e.supplier_id AND l.entry_id=e.id
            JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.supplier_id=? AND e.source_type='bank' AND e.source_id=? AND a.account_code='315.100'");
        $q->execute([$this->supplier, $tx]);
        $credit = (int) $q->fetchColumn();
        $dims = (new DimensionAssignmentRepository($this->db))->lineDimensions($this->supplier, [$receivable, $credit]);
        self::assertNotSame([], $dims[$receivable] ?? [], 'Fixture: pohledávka nese dimenze klasifikace.');
        self::assertEquals($dims[$receivable], $dims[$credit] ?? [], 'Úhrada musí pohledávku vyrovnat i po dimenzi.');
    }

    public function testPaymentWithOwnDifferentDimensionIsRejectedInsteadOfLeavingReceivableUnbalanced(): void
    {
        $claim = $this->newClaim('bank-own-dimension', '4747');
        $type = (int) $this->pdo->query('SELECT type_id FROM dimension_values WHERE id=' . (int) $this->classification['activity_kind_value_id'])->fetchColumn();
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name)
            SELECT type_id,supplier_id,'MEMBERSHIP-OWN','Synthetic own payment activity' FROM dimension_values WHERE id=?")
            ->execute([$this->classification['activity_kind_value_id']]);
        $own = (int) $this->pdo->lastInsertId();
        $tx = $this->payment('20000.00', '4747');
        (new DimensionAssignmentRepository($this->db))->replaceDocumentDimensions($this->supplier, 'bank_transaction', $tx, [$type => $own], []);
        try {
            $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
            self::fail('Vlastní dimenze pohybu by nechala pohledávku po dimenzi nevyrovnanou.');
        } catch (MembershipException $e) {
            self::assertSame('nonprofit_membership_payment_dimensions_own', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    /**
     * Pohyb se zaúčtuje dřív, než o předpisu ví (nehradí doklad), takže výchozí dimenze vlastního
     * účtu padne i na 315. Po spárování ji 315 nesmí podržet: nezadal ji uživatel a pohledávka
     * by po dimenzi nebyla vyrovnaná. Řádek banky si ji ponechá.
     */
    public function testBankAccountDefaultDimensionDoesNotBlockMatchAndStaysOnlyOnBankLine(): void
    {
        $claim = $this->newClaim('bank-account-default', '4848');
        [$type, $center] = $this->centerValue('ACCOUNT');
        $account = $this->bankAccount();
        Bootstrap::buildContainer()->get(DimensionService::class)
            ->saveEntityDefaults($this->supplier, 'bank_account', $account, [$type => $center]);
        $tx = $this->payment('20000.00', '4848');
        $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
        [$receivable, $credit, $bank] = $this->paymentLines($claim, $tx);
        self::assertSame($receivable, $credit, 'Úhrada nese jen dimenze předpisu.');
        self::assertSame($center, $bank[$type] ?? null, 'Řádek banky si středisko účtu ponechá.');
    }

    /**
     * Pravidlo dimenzí na účtu pohledávky dostane při zaúčtování jen úhrada (předpis mezi zdroji
     * pravidel není). Párování hodnotu pravidla z řádku úhrady uvolní a přerazítkování ji nevrátí,
     * takže se pohledávka po dimenzi vyrovná; řádek banky pravidlo 315 netýká.
     */
    public function testReceivableAccountRuleDefaultIsReleasedFromThePaymentLine(): void
    {
        [$type, $center] = $this->centerValue('RULE');
        (new DimensionRuleService($this->db))->create($this->supplier, [
            'dimension_type_id' => $type, 'account_mask' => '315', 'enforcement' => 'none', 'default_value_id' => $center,
        ]);
        $claim = $this->newClaim('bank-rule-default', '4949');
        $tx = $this->payment('20000.00', '4949');
        self::assertArrayNotHasKey($type, $this->paymentLines($claim, null)[0], 'Fixture: předpis pravidlo nepřebírá.');
        Bootstrap::buildContainer()->get(BankPostingService::class)->postManual($this->supplier, $tx, [
            'debit_account_code' => '221', 'credit_account_code' => '315.100',
        ], ['user_id' => $this->actor, 'posted_by' => $this->actor]);
        self::assertSame($center, $this->paymentLines($claim, $tx)[1][$type] ?? null, 'Fixture: zaúčtování dá úhradě hodnotu pravidla.');
        $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
        [$receivable, $credit] = $this->paymentLines($claim, $tx);
        self::assertSame($receivable, $credit, 'Úhrada nese jen dimenze předpisu, hodnota pravidla se uvolní.');
    }

    /**
     * Povinné pravidlo na účtu pohledávky pro typ, který předpis nese (zde druh činnosti, i systémové dimenze):
     * párování zaúčtuje nezaúčtovaný pohyb už s dimenzemi předpisu, takže pravidlo bez výchozí hodnoty projde.
     */
    public function testRequiredReceivableRuleForClaimDimensionPassesWhenMatchPostsThePayment(): void
    {
        $kind = (int) $this->pdo->query('SELECT type_id FROM dimension_values WHERE id=' . (int) $this->classification['activity_kind_value_id'])->fetchColumn();
        (new DimensionRuleService($this->db))->create($this->supplier, ['dimension_type_id' => $kind, 'account_mask' => '315', 'enforcement' => 'error']);
        $claim = $this->newClaim('bank-required-claim-type', '4952');
        $tx = $this->payment('20000.00', '4952');
        $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
        [$receivable, $credit] = $this->paymentLines($claim, $tx);
        self::assertSame($receivable, $credit);
    }

    /**
     * Povinné pravidlo pro typ, který předpis nenese: bez výchozí hodnoty zaúčtování odmítne, s výchozí hodnotou
     * podle rady v hlášce a manuálu párování projde a hodnota se z řádku úhrady uvolní.
     */
    public function testRequiredReceivableRuleForOtherTypeNeedsDefaultValueForMatch(): void
    {
        [$type, $center] = $this->centerValue('REQUIRED');
        $rules = new DimensionRuleService($this->db);
        $rule = $rules->create($this->supplier, ['dimension_type_id' => $type, 'account_mask' => '315', 'enforcement' => 'error']);
        $claim = $this->newClaim('bank-required-rule', '4951');
        $tx = $this->payment('20000.00', '4951');
        $this->pdo->exec('SAVEPOINT required_rule');
        try {
            $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
            self::fail('Povinné pravidlo bez výchozí hodnoty pro typ mimo předpis zaúčtování odmítne.');
        } catch (PostingException $e) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT required_rule');
            self::assertSame('dimension_required', $e->errorCode);
        }
        $rules->update($this->supplier, (int) $rule['id'], ['dimension_type_id' => $type, 'account_mask' => '315', 'enforcement' => 'error', 'default_value_id' => $center]);
        $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
        [$receivable, $credit] = $this->paymentLines($claim, $tx);
        self::assertSame($receivable, $credit);
    }

    /**
     * Uvolnění výchozích hodnot patří jen řádku úhrady 315. Řádek banky si hodnotu ponechá i tehdy,
     * když ji výchozí vrstva účtu po zaúčtování už nedává (správce výchozí hodnotu smazal).
     */
    public function testReleasingDefaultsTouchesOnlyThePaymentLineNotTheBankLine(): void
    {
        $claim = $this->newClaim('bank-account-removed', '5353');
        [$type, $center] = $this->centerValue('REMOVED');
        $account = $this->bankAccount();
        $dimensions = Bootstrap::buildContainer()->get(DimensionService::class);
        $dimensions->saveEntityDefaults($this->supplier, 'bank_account', $account, [$type => $center]);
        $tx = $this->payment('20000.00', '5353');
        Bootstrap::buildContainer()->get(BankPostingService::class)->postManual($this->supplier, $tx,
            ['debit_account_code' => '221', 'credit_account_code' => '315.100'], ['user_id' => $this->actor, 'posted_by' => $this->actor]);
        $dimensions->saveEntityDefaults($this->supplier, 'bank_account', $account, []);
        $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
        [$receivable, $credit, $bank] = $this->paymentLines($claim, $tx);
        self::assertSame($receivable, $credit);
        self::assertSame($center, $bank[$type] ?? null, 'Párování nesmí odebrat dimenzi řádku banky.');
    }

    /** Pravidlo bankovního pohybu dalo hodnotu oběma řádkům; po spárování zůstane jen na řádku banky. */
    public function testBankPostingRuleDimensionDoesNotBlockMatchAndStaysOnBankLine(): void
    {
        $claim = $this->newClaim('bank-posting-rule', '5454');
        [$type, $center] = $this->centerValue('BANK-RULE');
        $container = Bootstrap::buildContainer();
        $rules = $container->get(BankPostingRuleRepository::class);
        $rule = $rules->insert($this->supplier, ['name' => 'Synthetic membership rule', 'direction' => 'incoming',
            'message_contains' => BankMessageNormalizer::normalize('Synthetic membership bank payment'),
            'debit_account_code' => '221', 'credit_account_code' => '315.100', 'mode' => 'suggest', 'is_active' => 1,
            'priority' => 100, 'applies_currency' => 'CZK'], $this->actor);
        $rules->replaceDimensions($this->supplier, $rule, [$type => $center]);
        // Vlastní účet jen této firmy: pravidla hledají firmu podle účtu výpisu a sdílený vzorový účet je nejednoznačný.
        $this->bankAccount('0000000019');
        $tx = $this->payment('20000.00', '5454', '0000000019');
        $bank = $container->get(BankPostingService::class);
        $handled = $bank->handleTransaction($tx, $this->actor);
        self::assertSame('suggested', $handled['action'] ?? null, 'Fixture: pravidlo navrhne zaúčtování: ' . json_encode($handled));
        $bank->approveSuggestion($this->supplier, (int) $handled['suggestion_id'], ['user_id' => $this->actor, 'posted_by' => $this->actor]);
        $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
        [$receivable, $credit, $bankLine] = $this->paymentLines($claim, $tx);
        self::assertSame($receivable, $credit);
        self::assertSame($center, $bankLine[$type] ?? null);
    }

    /** Pravidlo jen na účtu banky: úhrada si jeho hodnotu z doby zaúčtování neponese. */
    public function testBankLineRuleDefaultDoesNotStayOnPaidReceivable(): void
    {
        $claim = $this->newClaim('bank-rule-221', '5050');
        [$type, $center] = $this->centerValue('RULE-221');
        (new DimensionRuleService($this->db))->create($this->supplier, [
            'dimension_type_id' => $type, 'account_mask' => '221', 'enforcement' => 'none', 'default_value_id' => $center,
        ]);
        $tx = $this->payment('20000.00', '5050');
        $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
        [$receivable, $credit, $bank] = $this->paymentLines($claim, $tx);
        self::assertSame($receivable, $credit);
        self::assertSame($center, $bank[$type] ?? null);
    }

    /**
     * Pravidla se vynechají jen na řádku úhrady: pravidlo pro účet banky založené až po zaúčtování pohybu
     * dá řádku 221 hodnotu při přerazítkování v párování (vynechání pravidel pro celý zápis by ji nedalo).
     */
    public function testRuleCreatedAfterPostingStillReachesTheBankLineOnMatch(): void
    {
        $claim = $this->newClaim('bank-rule-221-late', '5051');
        $tx = $this->payment('20000.00', '5051');
        Bootstrap::buildContainer()->get(BankPostingService::class)->postManual($this->supplier, $tx,
            ['debit_account_code' => '221', 'credit_account_code' => '315.100'], ['user_id' => $this->actor, 'posted_by' => $this->actor]);
        [$type, $center] = $this->centerValue('RULE-221-LATE');
        (new DimensionRuleService($this->db))->create($this->supplier, [
            'dimension_type_id' => $type, 'account_mask' => '221', 'enforcement' => 'none', 'default_value_id' => $center,
        ]);
        self::assertArrayNotHasKey($type, $this->paymentLines($claim, $tx)[2], 'Fixture: pravidlo vzniklo po zaúčtování.');
        $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
        [$receivable, $credit, $bank] = $this->paymentLines($claim, $tx);
        self::assertSame($receivable, $credit);
        self::assertSame($center, $bank[$type] ?? null);
    }

    /** @return array<string,array{string}> */
    public static function manualOrigins(): array
    {
        return ['ruční hodnota' => ['explicit'], 'starší zápis bez původu' => ['unknown']];
    }

    /** Ruční dimenze na řádku 315 zaúčtovaného pohybu se neuvolní jako výchozí hodnota. */
    #[DataProvider('manualOrigins')]
    public function testManualDimensionOnPostedReceivableLineStillBlocksMatch(string $origin): void
    {
        $claim = $this->newClaim('bank-manual-line-' . $origin, '5252');
        [$type, $center] = $this->centerValue('MANUAL-' . $origin);
        $tx = $this->payment('20000.00', '5252');
        Bootstrap::buildContainer()->get(BankPostingService::class)->postManual($this->supplier, $tx,
            ['debit_account_code' => '221', 'credit_account_code' => '315.100'], ['user_id' => $this->actor, 'posted_by' => $this->actor]);
        $line = (int) $this->pdo->query("SELECT l.id FROM journal_entries e JOIN journal_entry_lines l ON l.supplier_id=e.supplier_id AND l.entry_id=e.id
            JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.source_type='bank' AND e.source_id=" . $tx . " AND a.account_code='315.100'")->fetchColumn();
        (new DimensionAssignmentRepository($this->db))->replaceLineDimensions($this->supplier, $line, [$type => $center],
            [$type => ['origin' => $origin, 'ref' => null]]);
        $this->pdo->exec('SAVEPOINT dimensions_own');
        try {
            $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
            self::fail('Ruční dimenze řádku úhrady by nechala pohledávku po dimenzi nevyrovnanou.');
        } catch (MembershipException $e) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT dimensions_own');
            self::assertSame('nonprofit_membership_payment_dimensions_own', $e->errorCode);
        }
        // Rada z hlášky: nové zaúčtování párováním ruční ani starší hodnotu řádku nenese.
        Bootstrap::buildContainer()->get(BankPostingService::class)->unpost($this->supplier, $tx, ['user_id' => $this->actor]);
        $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
        [$receivable, $credit] = $this->paymentLines($claim, $tx);
        self::assertSame($receivable, $credit);
    }

    /**
     * Rozpad na řádku úhrady u typu, který předpis neurčuje, přerazítkování ponechá. Dimenze bez rozpadu
     * se pak s předpisem shodují, ale pohledávka by po dimenzi zůstala nevyrovnaná, proto se párování odmítne.
     */
    public function testSplitOnPaymentLineBlocksMatchEvenWhenPlainDimensionsEqualTheClaim(): void
    {
        $claim = $this->newClaim('bank-split-line', '5555');
        [$type, $first] = $this->centerValue('SPLIT-A');
        $dimensions = Bootstrap::buildContainer()->get(DimensionService::class);
        $second = (int) $dimensions->createValue($this->supplier, $type, ['code' => 'SYN-SPLIT-B', 'name' => 'Synthetic SPLIT-B'])['id'];
        $tx = $this->payment('20000.00', '5555');
        Bootstrap::buildContainer()->get(BankPostingService::class)->postManual($this->supplier, $tx,
            ['debit_account_code' => '221', 'credit_account_code' => '315.100'], ['user_id' => $this->actor, 'posted_by' => $this->actor]);
        $line = (int) $this->pdo->query("SELECT l.id FROM journal_entries e JOIN journal_entry_lines l ON l.supplier_id=e.supplier_id AND l.entry_id=e.id
            JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.source_type='bank' AND e.source_id=" . $tx . " AND a.account_code='315.100'")->fetchColumn();
        $repository = new DimensionAssignmentRepository($this->db);
        $repository->replaceLineSplits($this->supplier, $line, [$type => [$first => 0.6, $second => 0.4]]);
        self::assertNotSame([], $repository->lineSplits($this->supplier, [$line]), 'Fixture: řádek úhrady nese rozpad.');
        try {
            $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
            self::fail('Rozpad řádku úhrady by nechal pohledávku po dimenzi nevyrovnanou.');
        } catch (MembershipException $e) {
            self::assertSame('nonprofit_membership_payment_dimensions_own', $e->errorCode);
        }
    }

    /**
     * Rozúčtovaný pohyb s výsledkovými řádky na analytice podle druhu činnosti pohybu: druh předpisu je
     * mapováním účtotvorné dimenze přesune na jinou analytiku. Restamp je nepřerazítkuje a párování žádá
     * přeúčtování pohybu místo obecného odmítnutí kvůli odlišným dimenzím.
     */
    public function testClaimDimensionMovingAnotherLineToMappedAnalyticAsksForRepost(): void
    {
        $claim = $this->newClaim('bank-driving-map', '5656');
        $container = Bootstrap::buildContainer();
        $kind = (int) $this->pdo->query('SELECT type_id FROM dimension_values WHERE id=' . (int) $this->classification['activity_kind_value_id'])->fetchColumn();
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'MEMBERSHIP-OTHER','Synthetic other activity')")
            ->execute([$kind, $this->supplier]);
        $other = (int) $this->pdo->lastInsertId();
        // Systémové dimenze výsledkových řádků dá pohybu výchozí vrstva účtu; jiný druh činnosti než předpis.
        $types = [];
        foreach ($this->classification as $value) {
            $types[(int) $this->pdo->query('SELECT type_id FROM dimension_values WHERE id=' . (int) $value)->fetchColumn()] = (int) $value;
        }
        $types[$kind] = $other;
        $container->get(DimensionService::class)
            ->saveEntityDefaults($this->supplier, 'bank_account', $this->bankAccount(), $types);
        $tx = $this->payment('20000.00', '5656');
        $insert = $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type,parent_id,is_synthetic) VALUES(?,?,?,?,?,?)');
        $insert->execute([$this->supplier, '548', 'Synthetic 548', 'expense', null, 1]);
        $parent = (int) $this->pdo->lastInsertId();
        $insert->execute([$this->supplier, '548.100', 'Synthetic 548.100', 'expense', $parent, 0]);
        $insert->execute([$this->supplier, '548.200', 'Synthetic 548.200', 'expense', $parent, 0]);
        // Účtotvorný druh činnosti: druh pohybu → 548.100, druh předpisu → 548.200.
        $container->get(DimensionService::class)->updateType($this->supplier, $kind, ['drives_accounts' => true]);
        $map = $container->get(DimensionAccountMapService::class);
        $map->saveForValue($this->supplier, $other, [['synthetic_code' => '548', 'analytic_code' => '548.100']], $this->actor);
        $map->saveForValue($this->supplier, (int) $this->classification['activity_kind_value_id'], [['synthetic_code' => '548', 'analytic_code' => '548.200']], $this->actor);
        Bootstrap::buildContainer()->get(BankPostingService::class)->postManual($this->supplier, $tx, ['lines' => [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 20000.00],
            ['account_code' => '315.100', 'side' => 'credit', 'amount' => 20000.00],
            ['account_code' => '548', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '548', 'side' => 'credit', 'amount' => 100.00],
        ]], ['user_id' => $this->actor, 'posted_by' => $this->actor]);
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM journal_entries e JOIN journal_entry_lines l ON l.supplier_id=e.supplier_id AND l.entry_id=e.id
            JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.source_type='bank' AND e.source_id=" . $tx . " AND a.account_code='548.100'")->fetchColumn(),
            'Fixture: výsledkové řádky leží na analytice druhu pohybu.');
        // Akce párování vlastní transakci a po odmítnutí ji vrátí; test běží ve vnější transakci, proto savepoint.
        $this->pdo->exec('SAVEPOINT repost_advice');
        try {
            $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
            self::fail('Dimenze předpisu přesouvající řádek na jinou analytiku vyžaduje přeúčtování.');
        } catch (MembershipException $e) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT repost_advice');
            self::assertSame('nonprofit_membership_payment_dimension_account', $e->errorCode, $e->getMessage());
            self::assertSame(409, $e->httpStatus);
        }
        // Rada z hlášky musí jít provést: po odúčtování párování zaúčtuje pohyb samo jen řádkem úhrady.
        Bootstrap::buildContainer()->get(BankPostingService::class)->unpost($this->supplier, $tx, ['user_id' => $this->actor]);
        $result = $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
        self::assertSame([$claim], $result['claim_ids']);
        [$receivable, $credit, $bank] = $this->paymentLines($claim, $tx);
        self::assertSame($receivable, $credit, 'Úhrada po novém zaúčtování nese dimenze předpisu.');
        // Typ, který určuje předpis, platí jako u úhrady faktury pro celý bankovní zápis, i když
        // bankovní účet má pro tentýž typ jinou výchozí hodnotu (manuál kap. 120).
        self::assertSame((int) $this->classification['activity_kind_value_id'], $bank[$kind] ?? null);
    }

    /** Duplicitní klíč mimo zápis párování (zde auditní stopa) je chyba serveru, ne obsazené párování. */
    public function testDuplicateKeyOutsidePairingWriteIsNotReportedAsAlreadyMatched(): void
    {
        $claim = $this->newClaim('bank-other-duplicate', '5151');
        $tx = $this->payment('20000.00', '5151');
        $container = Bootstrap::buildContainer();
        $logger = new class($this->db) extends ActivityLogger {
            public function log(string $action, ?int $userId = null, ?string $entityType = null, ?int $entityId = null,
                ?array $payload = null, ?string $ip = null, ?string $userAgent = null, ?int $supplierId = null): void
            {
                $e = new \PDOException('Synthetic duplicate entry', 23000);
                $e->errorInfo = ['23000', 1062, 'Synthetic duplicate entry'];
                throw $e;
            }
        };
        $service = new MembershipBankMatchService($this->db,
            $container->get(OtherItemBankMatchService::class),
            $container->get(BankPostingService::class), $logger, $this->posting);
        try {
            $service->match($this->supplier, $tx, [$claim], $this->actor, [], true);
            self::fail('Chyba auditní stopy se nesmí ztratit.');
        } catch (\PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1] ?? null);
        }
    }

    public function testCollectivePaymentRejectsClaimsWithDifferentDimensions(): void
    {
        $first = $this->newClaim('bank-dim-first');
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name)
            SELECT type_id,supplier_id,'MEMBERSHIP-B','Synthetic second membership activity' FROM dimension_values WHERE id=?")
            ->execute([$this->classification['activity_kind_value_id']]);
        $original = $this->classification;
        $this->classification['activity_kind_value_id'] = (int) $this->pdo->lastInsertId();
        try { $second = $this->newClaim('bank-dim-second'); } finally { $this->classification = $original; }
        $tx = $this->payment('40000.00');
        try {
            $this->bankService()->match($this->supplier, $tx, [$first, $second], $this->actor, [], true);
            self::fail('Jeden řádek platby nevyrovná po dimenzi předpisy s rozdílnými dimenzemi.');
        } catch (MembershipException $e) {
            self::assertSame('nonprofit_membership_payment_dimensions_differ', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM journal_entries WHERE source_type='bank' AND source_id=" . $tx)->fetchColumn());
    }

    /**
     * Zrušení párování v saldokontu a spárování téhož pohybu s předpisem jiných dimenzí: řádek úhrady si po
     * zrušení ponechá dimenze předpisu A (původ modul), druhé párování je uvolní a nahradí dimenzemi předpisu B.
     */
    public function testDissolvedPairingCanBeRematchedToClaimWithOtherDimensions(): void
    {
        $first = $this->newClaim('bank-rematch-a');
        $this->pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name)
            SELECT type_id,supplier_id,'MEMBERSHIP-REMATCH','Synthetic rematch activity' FROM dimension_values WHERE id=?")
            ->execute([$this->classification['activity_kind_value_id']]);
        $original = $this->classification;
        $this->classification['activity_kind_value_id'] = (int) $this->pdo->lastInsertId();
        try { $second = $this->newClaim('bank-rematch-b'); } finally { $this->classification = $original; }
        $tx = $this->payment('20000.00');
        $matched = $this->bankService()->match($this->supplier, $tx, [$first], $this->actor, [], true);
        [$a, $paidA] = $this->paymentLines($first, $tx);
        self::assertSame($a, $paidA, 'Fixture: úhrada nese dimenze předpisu A.');
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\OpenItemsService::class)
            ->delete($this->supplier, [(int) $matched['pairing_id']], ['user_id' => $this->actor]);
        $again = $this->bankService()->match($this->supplier, $tx, [$second], $this->actor, [], true);
        self::assertSame((int) $matched['entry_id'], (int) $again['entry_id'], 'Pohyb se znovu nezaúčtoval.');
        [$b, $paidB] = $this->paymentLines($second, $tx);
        self::assertNotSame($a, $b, 'Fixture: předpisy mají různé dimenze.');
        self::assertSame($b, $paidB, 'Úhrada po přepárování nese dimenze předpisu B.');
    }

    public function testRematchDoesNotSpendMembershipPaymentOnInvoiceWithSameSymbol(): void
    {
        $claim = $this->newClaim('bank-rematch', '4545');
        $tx = $this->payment('20000.00', '4545');
        $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
        $currency = (int) $this->pdo->query('SELECT default_currency_id FROM supplier WHERE id=' . $this->supplier)->fetchColumn();
        $client = (int) $this->pdo->query('SELECT client_id FROM nonprofit_membership_charge_claims WHERE id=' . $claim)->fetchColumn();
        $this->pdo->prepare("INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, paid_total, status, vat_classification_code)
             VALUES (?, '4545', 'invoice', ?, '2026-01-15', '2026-01-15', '2026-02-15', ?, 0, 20000, 0, 20000, 0, 'issued', '1')")
            ->execute([$this->supplier, $client, $currency]);
        $result = Bootstrap::buildContainer()->get(StatementMatcher::class)->matchBatch([$tx])[$tx];
        self::assertSame('unmatched', $result['status']);
        self::assertSame('membership_settled', $result['reason']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id=' . $tx)->fetchColumn());
    }

    /**
     * Hledání čte nejdřív odpovídající členy a jejich předpisy po indexu člena; nad SEARCH_MEMBER_LIMIT shod
     * prochází předpisy od nejnovějších s celým výběrem. Člen za limitem (vyšší ID) se nesmí ztratit oříznutím.
     * Hledání bez shody nevrátí nic a označení příspěvku se najde přes seznam odlišných označení.
     */
    public function testWideMemberSearchDoesNotTruncateMatchedMembers(): void
    {
        $currency = (int) $this->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor)
            SELECT ?,CONCAT('Widesearch filler ',seq),'Synthetic street','Synthetic city','10000',203,CONCAT('widesearch-',seq,'@example.test'),'cs',?,1,0 FROM seq_1_to_210")
            ->execute([$this->supplier, $currency]);
        $this->pdo->prepare("INSERT INTO nonprofit_members(supplier_id,client_id,member_number,created_by,created_at,recorded_at_utc)
            SELECT supplier_id,id,CONCAT('WIDE-',id),?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) FROM clients WHERE supplier_id=? AND company_name LIKE 'Widesearch filler %'")
            ->execute([$this->actor, $this->supplier]);
        $claim = $this->newClaim('widesearch-target');
        $memberId = (int) $this->pdo->query('SELECT member_id FROM nonprofit_membership_charge_claims WHERE id=' . $claim)->fetchColumn();
        $this->pdo->prepare('UPDATE clients SET company_name=? WHERE supplier_id=? AND id=(SELECT client_id FROM nonprofit_members WHERE supplier_id=? AND id=?)')
            ->execute(['Widesearch target', $this->supplier, $this->supplier, $memberId]);
        $tx = $this->payment('1.00', '999');
        self::assertSame([$claim], array_column($this->bankService()->candidates($this->supplier, $tx, '0', 50, 'Widesearch')['candidates'], 'id'));
        self::assertSame([$claim], array_column($this->bankService()->candidates($this->supplier, $tx, '0', 50, 'Widesearch target')['candidates'], 'id'));
        self::assertSame([], $this->bankService()->candidates($this->supplier, $tx, '0', 50, 'Widesearch nobody')['candidates']);
        self::assertContains($claim, array_column($this->bankService()->candidates($this->supplier, $tx, '0', 50, 'annual-20')['candidates'], 'id'));
    }

    public function testManualSearchFindsPersonByFirstOrLastNameAndShowsTheirName(): void
    {
        $person = $this->newClaim('bank-person', null);
        $memberId = (int) $this->pdo->query('SELECT member_id FROM nonprofit_membership_charge_claims WHERE id=' . $person)->fetchColumn();
        $this->pdo->prepare('UPDATE clients SET company_name=?,first_name=?,last_name=? WHERE supplier_id=? AND id=(SELECT client_id FROM nonprofit_members WHERE supplier_id=? AND id=?)')
            ->execute(['', 'Synthetic', 'Rowanova', $this->supplier, $this->supplier, $memberId]);
        $company = $this->newClaim('bank-company', null);
        $tx = $this->payment('1.00', '999');
        foreach (['Rowanova', 'Synthetic'] as $term) {
            $found = $this->bankService()->candidates($this->supplier, $tx, '0', 50, $term)['candidates'];
            self::assertContains($person, array_column($found, 'id'), $term);
        }
        // Hledání po slovech jako seznam členů: celé jméno v libovolném pořadí i s pevnou mezerou.
        foreach (['Synthetic Rowanova', 'Rowanova  Synthetic', "Synthetic\u{00A0}Rowanova", 'bank-person Rowanova'] as $term) {
            $found = $this->bankService()->candidates($this->supplier, $tx, '0', 50, $term)['candidates'];
            self::assertSame([$person], array_column($found, 'id'), $term);
        }
        self::assertSame([], $this->bankService()->candidates($this->supplier, $tx, '0', 50, 'Synthetic Nobody')['candidates']);
        // Znaky LIKE platí doslova (jako seznam členů), nenajdou tedy všechny předpisy.
        foreach (['_', '%', '='] as $term) {
            self::assertSame([], $this->bankService()->candidates($this->supplier, $tx, '0', 50, $term)['candidates'], $term);
        }
        // Diakritika: titul je ASCII klíč, hledání jména s diakritikou nesmí skončit chybou kolace.
        $this->pdo->prepare('UPDATE clients SET last_name=? WHERE supplier_id=? AND id=(SELECT client_id FROM nonprofit_members WHERE supplier_id=? AND id=?)')
            ->execute(['Rowanová', $this->supplier, $this->supplier, $memberId]);
        foreach (['Rowanová', 'Synthetic Rowanová'] as $term) {
            self::assertSame([$person], array_column($this->bankService()->candidates($this->supplier, $tx, '0', 50, $term)['candidates'], 'id'), $term);
        }
        $this->pdo->prepare('UPDATE clients SET last_name=? WHERE supplier_id=? AND id=(SELECT client_id FROM nonprofit_members WHERE supplier_id=? AND id=?)')
            ->execute(['Rowanova', $this->supplier, $this->supplier, $memberId]);
        $found = array_column($this->bankService()->candidates($this->supplier, $tx, '0', 50, 'Rowanova')['candidates'], null, 'id');
        self::assertSame('Synthetic Rowanova', $found[$person]['party']);
        self::assertArrayNotHasKey($company, $found);
        $company = array_column($this->bankService()->candidates($this->supplier, $tx, '0', 50, 'bank-company')['candidates'], null, 'id')[$company];
        self::assertSame('Synthetic member', $company['party']);
    }

    public function testCollectiveAmountMismatchDoesNotPost(): void
    {
        $a = $this->newClaim('bank-mismatch-a');
        $b = $this->newClaim('bank-mismatch-b');
        $tx = $this->payment('30000.00');
        try {
            $this->bankService()->match($this->supplier, $tx, [$a, $b], $this->actor, [], true);
            self::fail('Expected amount mismatch');
        } catch (MembershipException $e) {
            self::assertSame('nonprofit_membership_payment_amount_mismatch', $e->errorCode);
        }
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM journal_entries WHERE source_type='bank' AND source_id=" . $tx)->fetchColumn());
    }

    public function testClaimWithInvalidReceiptIsNeitherOfferedNorMatched(): void
    {
        $claim = $this->newClaim('bank-damaged-receipt', '7301');
        $line = (int) $this->pdo->query('SELECT o.receivable_line_id FROM nonprofit_membership_charge_claims c
            JOIN nonprofit_membership_charge_operations o ON o.supplier_id=c.supplier_id AND o.id=c.operation_id WHERE c.id=' . $claim)->fetchColumn();
        // Úmyslně poškozené syntetické úložiště jako v MembershipNativeTest: seznam i detail předpis hlásí „k prověření“.
        $this->pdo->prepare("UPDATE journal_entry_lines SET account_id=(SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code='221') WHERE supplier_id=? AND id=?")
            ->execute([$this->supplier, $this->supplier, $line]);
        $tx = $this->payment('20000.00', '7301');
        self::assertSame([], array_column($this->bankService()->candidates($this->supplier, $tx)['candidates'], 'id'));
        self::assertSame([], $this->bankService()->candidates($this->supplier, $tx, '0', 50, 'bank-damaged-receipt')['candidates']);
        try {
            $this->bankService()->match($this->supplier, $tx, [$claim], $this->actor, [], true);
            self::fail('Párování přijalo předpis s neplatným dokladem.');
        } catch (MembershipException $e) {
            self::assertSame('nonprofit_membership_claim_not_payable', $e->errorCode);
        }
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM journal_entries WHERE source_type='bank' AND source_id=" . $tx)->fetchColumn());
    }

    public function testUsedPaymentCannotBeMutatedOrAllocatedElsewhere(): void
    {
        $id = $this->newClaim('bank-guard');
        $tx = $this->payment('20000.00');
        $free = fn (): int => (int) $this->pdo->query('SELECT COUNT(*) FROM bank_transactions bt
            JOIN bank_statements bs ON bs.id=bt.statement_id AND bs.supplier_id=' . $this->supplier . '
            LEFT JOIN (SELECT NULL bank_transaction_id, 0 used_amount) a ON 0=1
            WHERE bt.id=' . $tx . ' AND ' . OtherItemService::freeBankPaymentSql())->fetchColumn();
        self::assertSame(1, $free(), 'Fixture: nespárovaný pohyb je volný i pro ostatní položky.');
        $matched = $this->bankService()->match($this->supplier, $tx, [$id], $this->actor, [], true);
        // Kandidáti „Připojit úhradu“ i párování z banky sdílí s potvrzením podmínku volného pohybu.
        self::assertSame(0, $free(), 'Pohyb uhrazující členský předpis se ostatní položce nenabízí.');
        $guard = new PaymentMutationContext($this->db);
        $line = (int) $this->pdo->query('SELECT id FROM journal_entry_lines WHERE entry_id=' . (int) $matched['entry_id'] . ' ORDER BY line_no LIMIT 1')->fetchColumn();
        // Dorovnání dimenzí smí jen probíhající párování; po něm ani pro jiný zápis výjimka neplatí.
        foreach ([fn () => $guard->assertSourceMutable($this->supplier, 'bank', $tx),
            fn () => $guard->assertEntriesMutable($this->supplier, [$matched['entry_id']]),
            fn () => $guard->assertLinesMutable($this->supplier, [$line]),
            fn () => MembershipBankPaymentGuard::restampingMatchedPayment($this->supplier, (int) $matched['entry_id'] + 1,
                fn () => $guard->assertLinesMutable($this->supplier, [$line]))] as $check) {
            try { $check(); self::fail('Expected used bank source guard'); }
            catch (PostingException $e) { self::assertSame('nonprofit_membership_payment_in_use', $e->errorCode); }
        }
    }

    /**
     * Platba spárovaná s předpisem ručně v saldokontu (postup kroku 5, bez dialogu banky) je po nasazení
     * zamčená stejně jako párování z banky: zámek čte nativní párování, ne cestu, kterou vzniklo. Zamčení
     * dokládají veřejné vstupy bankovních akcí (ignorovat, výpis, odúčtovat); po zrušení párování je pohyb volný.
     * Platí i pro předpis, který krok 5 dovolil stornovat se spárovanou úhradou: zámek ukazuje cestu ven
     * (zrušit párování v saldokontu), ne tiché uvolnění pohybu, jehož okruh je třeba prověřit. Fixture stornuje
     * před spárováním, protože storno spárovaného předpisu krok 6 odmítá; storno okruh nemění, stav je stejný.
     */
    #[DataProvider('legacyPairings')]
    public function testLedgerPairedPaymentFromBeforeBankMatchingIsLockedUntilThePairingIsDissolved(bool $reversed): void
    {
        $claim = $this->newClaim('bank-legacy-ledger-' . (int) $reversed, '5353');
        if ($reversed) {
            $case = (int) $this->pdo->query('SELECT case_id FROM nonprofit_membership_charge_claims WHERE id=' . $claim)->fetchColumn();
            $reverse = ['case_id' => $case, 'accounting_date' => '2026-02-01', 'reason' => 'Synthetic cancellation before bank matching'];
            $preview = $this->charges->previewReverse($this->supplier, $reverse);
            $this->charges->confirmReverse($this->supplier, $reverse, $preview['expected_case_version'], $preview['preview_hash'], 'legacy-reverse', $this->actor);
        }
        $tx = $this->payment('20000.00', '5353');
        $posting = Bootstrap::buildContainer()->get(BankPostingService::class);
        $posting->postManual($this->supplier, $tx, ['debit_account_code' => '221', 'credit_account_code' => '315.100'],
            ['user_id' => $this->actor, 'posted_by' => $this->actor]);
        $ref = static fn (array $r): array => ['entry_id' => (int) $r['entry_id'], 'line_no' => (int) $r['line_no'], 'account_id' => (int) $r['account_id']];
        $receivable = $this->pdo->query('SELECT l.entry_id, l.line_no, l.account_id FROM nonprofit_membership_charge_claims c
            JOIN nonprofit_membership_charge_operations o ON o.supplier_id=c.supplier_id AND o.id=c.operation_id
            JOIN journal_entry_lines l ON l.supplier_id=o.supplier_id AND l.id=o.receivable_line_id WHERE c.id=' . $claim)->fetch(PDO::FETCH_ASSOC);
        $payment = $this->pdo->query("SELECT l.entry_id, l.line_no, l.account_id FROM journal_entries e JOIN journal_entry_lines l ON l.supplier_id=e.supplier_id AND l.entry_id=e.id
            JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.source_type='bank' AND e.source_id=" . $tx . " AND a.account_code='315.100'")->fetch(PDO::FETCH_ASSOC);
        $pairings = new JournalLinePairingRepository($this->db);
        $pairing = $pairings->create($this->supplier, (int) $receivable['account_id'], [$ref($receivable), $ref($payment)],
            'Synthetic ledger pairing', 'manual', $this->actor);
        $statement = (int) $this->pdo->query('SELECT statement_id FROM bank_transactions WHERE id=' . $tx)->fetchColumn();
        foreach (['transakce (ignorovat, zaúčtovat jinak)' => fn () => $posting->lockTransaction($this->supplier, $tx),
            'výpis (smazat, přepárovat)' => fn () => $posting->lockStatement($this->supplier, $statement),
            'odúčtovat' => fn () => $posting->unpost($this->supplier, $tx, ['user_id' => $this->actor])] as $label => $mutation) {
            try { $mutation(); self::fail('Ručně spárovaná platba musí být zamčená: ' . $label); }
            catch (PostingException $e) { self::assertSame(['nonprofit_membership_payment_in_use', 409], [$e->errorCode, $e->httpStatus], $label); }
        }
        Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\OpenItemsService::class)
            ->delete($this->supplier, [$pairing], ['user_id' => $this->actor]);
        $posting->unpost($this->supplier, $tx, ['user_id' => $this->actor]);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM journal_entries WHERE source_type='bank' AND source_id=" . $tx . ' AND reversed_by IS NULL')->fetchColumn(),
            'Po zrušení párování jde pohyb odúčtovat.');
    }

    public static function legacyPairings(): array
    {
        return ['platný předpis' => [false], 'předpis stornovaný v kroku 5' => [true]];
    }

    /**
     * Strážní test plánu (měření 15. 11.): fáze VS začíná případy firmy po indexu VS, ne osnovou účtů ani skenem
     * případů instance; bez pevného pořadí se plán při mnoha shodách zhroutil na desítky sekund.
     */
    public function testVariableSymbolPhaseStartsWithCasesOfTheSupplierByIndex(): void
    {
        $this->newClaim('bank-plan-vs', '6161');
        [$sql, $params] = (new \MyInvoice\Repository\Nonprofit\Membership\MembershipBankMatchRepository($this->db))->candidateQuery($this->supplier, '6161', 2000000, 0, 51, '', 'vs');
        $explain = $this->pdo->prepare('EXPLAIN ' . $sql);
        $explain->execute($params);
        $plan = $explain->fetchAll(PDO::FETCH_ASSOC);
        // Větev VS je odvozená tabulka, jejíž první řádek plánu jsou případy (cc také čte souhrn párování po PK).
        $first = [];
        foreach ($plan as $row) $first[$row['id']] ??= $row;
        // CTE předpisů se materializuje pro výsledek i pro souhrn párování; instanci větve poznáme podle členů
        // (tabulka m je jen v SQL předpisů) a každá musí začít případy po indexu VS, ať je první tabulka jakákoli.
        $branchIds = array_unique(array_column(array_filter($plan, static fn (array $r): bool => $r['table'] === 'm'), 'id'));
        self::assertCount(2, $branchIds, json_encode($plan));
        foreach ($branchIds as $id) {
            self::assertSame(['cc', 'ix_nmcc_variable_symbol', 'ref'], [$first[$id]['table'], $first[$id]['key'], $first[$id]['type']], json_encode($plan));
        }
    }

    /**
     * Strážní test plánu: členská podmínka nevyřešených pohybů je korelovaný poddotaz po pohybu
     * (idx_je_supplier_source). Semi-join z EXISTS procházel ve stránce výpisu všechna párování firmy.
     */
    public function testMembershipConditionIsProbedPerTransaction(): void
    {
        $tx = $this->payment('20000.00', '6262');
        $statement = (int) $this->pdo->query('SELECT statement_id FROM bank_transactions WHERE id=' . $tx)->fetchColumn();
        $explain = $this->pdo->prepare('EXPLAIN SELECT bt.id FROM bank_transactions bt WHERE bt.statement_id=? AND NOT '
            . \MyInvoice\Service\Bank\NonInvoiceBankTransactionScope::membershipSql($this->supplier, 'bt.id') . ' LIMIT 50');
        $explain->execute([$statement]);
        $plan = $explain->fetchAll(PDO::FETCH_ASSOC);
        $probe = array_values(array_filter($plan, static fn (array $r): bool => $r['table'] === 'non_invoice_membership'));
        self::assertCount(1, $probe, json_encode($plan));
        self::assertSame('DEPENDENT SUBQUERY', $probe[0]['select_type'], json_encode($plan));
        self::assertSame('idx_je_supplier_source', $probe[0]['key'], json_encode($plan));
    }

    /** Kontext hrazených předpisů pro zaúčtování v párování platí jen pro svůj pohyb a jen uvnitř volání. */
    public function testPendingClaimsApplyOnlyToTheirTransactionAndOnlyInsideTheCall(): void
    {
        $claim = $this->newClaim('bank-pending-scope', '6363');
        $own = $this->payment('20000.00', '6363');
        $other = $this->payment('20000.00', '6363');
        $defaults = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Dimension\DimensionDefaults::class);
        $claims = fn (int $tx): array => array_values(array_filter($defaults->bankDocuments($this->supplier, $tx)['documents'],
            static fn (array $d): bool => $d['doc_type'] === 'membership_claim'));
        $foreign = fn (): array => array_values(array_filter($defaults->bankDocuments($this->supplier + 1000000, $own)['documents'],
            static fn (array $d): bool => $d['doc_type'] === 'membership_claim'));
        $inside = \MyInvoice\Service\Accounting\Dimension\DimensionDefaults::withPendingMembershipClaims($this->supplier, $own, [$claim],
            fn (): array => [$claims($own), $claims($other), $foreign(),
                \MyInvoice\Service\Accounting\Dimension\DimensionDefaults::withPendingMembershipClaims($this->supplier, $other, [$claim], fn (): array => $claims($own)),
                $claims($own)]);
        self::assertSame([$claim], array_column($inside[0], 'doc_id'), 'Vlastní pohyb vidí hrazený předpis.');
        self::assertSame([], $inside[1], 'Jiný pohyb předpis nevidí.');
        self::assertSame([], $inside[2], 'Jiná firma se stejným číslem pohybu předpis nevidí.');
        self::assertSame([], $inside[3], 'Vnořený kontext jiného pohybu vnější překryje.');
        self::assertSame([$claim], array_column($inside[4], 'doc_id'), 'Po vnořeném volání platí vnější kontext.');
        self::assertSame([], $claims($own), 'Po návratu kontext neplatí.');
        try {
            \MyInvoice\Service\Accounting\Dimension\DimensionDefaults::withPendingMembershipClaims($this->supplier, $own, [$claim],
                static fn () => throw new \RuntimeException('Synthetic failure'));
            self::fail('Výjimka se musí propsat.');
        } catch (\RuntimeException) {
        }
        self::assertSame([], $claims($own), 'Ani po výjimce kontext neplatí.');
    }

    public function testPaymentAccountConflictAndMissingPostingPermissionAreRejected(): void
    {
        $id = $this->newClaim('bank-posting-guard');
        $tx = $this->payment('20000.00');
        try { $this->bankService()->match($this->supplier, $tx, [$id], $this->actor, [], false); self::fail('Expected posting permission'); }
        catch (MembershipException $e) { self::assertSame('posting_forbidden', $e->errorCode); }
        $this->pdo->prepare("INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,'315.200','Synthetic other receivable','asset')")->execute([$this->supplier]);
        $this->posting->postDocument($this->supplier, 'bank', $tx, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => '20000.00'],
            ['account_code' => '315.200', 'side' => 'credit', 'amount' => '20000.00'],
        ], ['entry_date' => '2026-02-01', 'description' => 'Synthetic wrong payment account', 'posted' => true, 'posted_by' => $this->actor]);
        $this->pdo->exec('SAVEPOINT posting_mismatch');
        try {
            $this->bankService()->match($this->supplier, $tx, [$id], $this->actor, [], true);
            self::fail('Pohyb zaúčtovaný proti jinému účtu se nesmí spárovat.');
        } catch (MembershipException $e) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT posting_mismatch');
            self::assertSame('nonprofit_membership_payment_posting_mismatch', $e->errorCode);
        }
        // Rada z hlášky: po odúčtování párování zaúčtuje pohyb samo proti účtu pohledávky předpisu.
        Bootstrap::buildContainer()->get(BankPostingService::class)->unpost($this->supplier, $tx, ['user_id' => $this->actor]);
        self::assertSame([$id], $this->bankService()->match($this->supplier, $tx, [$id], $this->actor, [], true)['claim_ids']);
    }

    public function testFullyPaidClaimSelectedAgainIsRejectedWithoutPosting(): void
    {
        $id = $this->newClaim('bank-paid-again');
        $this->bankService()->match($this->supplier, $this->payment('20000.00'), [$id], $this->actor, [], true);
        $tx = $this->payment('5000.00');
        $this->assertMatchRejected('nonprofit_membership_payment_ambiguous', $tx, [$id]);
    }

    public function testCollectivePaymentWithPartiallyPaidClaimIsRejectedWithoutPosting(): void
    {
        $a = $this->newClaim('bank-collective-partial-a');
        $b = $this->newClaim('bank-collective-partial-b');
        $this->bankService()->match($this->supplier, $this->payment('5000.00'), [$a], $this->actor, [], true);
        // Zbytek A a celé B: částka sedí, ale A už má okruh; připojení do něj by B nechalo mimo okruh.
        $this->assertMatchRejected('nonprofit_membership_payment_ambiguous', $this->payment('35000.00'), [$a, $b], false);
    }

    public function testCollectivePaymentRejectsClaimsWithDifferentReceivableAccounts(): void
    {
        $this->pdo->prepare("INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,'315.200','Synthetic other receivable','asset')")->execute([$this->supplier]);
        $a = $this->newClaim('bank-accounts-a');
        $b = $this->newClaim('bank-accounts-b', null, '315.200');
        $this->assertMatchRejected('nonprofit_membership_payment_accounts_differ', $this->payment('40000.00'), [$a, $b]);
    }

    public function testSinglePaymentAboveOpenAmountIsRejectedWithoutPosting(): void
    {
        $id = $this->newClaim('bank-overpay');
        $this->assertMatchRejected('nonprofit_membership_payment_amount_mismatch', $this->payment('25000.00'), [$id]);
    }

    /**
     * Kontroly před zaúčtováním nic nezapíšou ani uvnitř cizí transakce. Kontrola až po zaúčtování nezaúčtovaného
     * pohybu ($beforePosting=false) spoléhá na odvolání transakce vlastníkem; test běží ve vnější transakci, proto
     * ověřuje jen, že nevzniklo párování.
     *
     * @param list<int> $claims
     */
    private function assertMatchRejected(string $code, int $tx, array $claims, bool $beforePosting = true): void
    {
        $items = (int) $this->pdo->query('SELECT COUNT(*) FROM journal_line_pairing_items WHERE supplier_id=' . $this->supplier)->fetchColumn();
        try {
            $this->bankService()->match($this->supplier, $tx, $claims, $this->actor, [], true);
            self::fail('Očekáváno odmítnutí ' . $code);
        } catch (MembershipException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
            self::assertSame(409, $e->httpStatus);
        }
        if ($beforePosting) {
            self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM journal_entries WHERE source_type='bank' AND source_id=" . $tx)->fetchColumn());
        }
        self::assertSame($items, (int) $this->pdo->query('SELECT COUNT(*) FROM journal_line_pairing_items WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    public function testEndpointRequiresSessionAndBothBankAndAccountingPermissions(): void
    {
        $id = $this->newClaim('bank-auth');
        $tx = $this->payment('20000.00');
        $action = new MembershipBankMatchAction($this->bankService(), new IpMatcher());
        $permissions = ['accounting' => 1, 'bank' => 1, 'bank.match' => 2, 'accounting.journal.post' => 2];
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/accounting/nonprofit-membership/bank-match')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'accountant'])
            ->withParsedBody(['transaction_id' => $tx, 'claim_ids' => [$id]]);
        foreach (['bank', 'accounting', 'bank.match', 'accounting.journal.post'] as $missing) {
            $reduced = $permissions;
            unset($reduced[$missing]);
            $r = $request->withAttribute('auth.effective_role', new EffectiveRole(501, 'Synthetic bank membership role', 'staff', true, $reduced));
            self::assertSame(403, $action->match($r, new \Slim\Psr7\Response())->getStatusCode(), $missing);
        }
        $request = $request->withAttribute('auth.effective_role', new EffectiveRole(501, 'Synthetic bank membership role', 'staff', true, $permissions));
        self::assertSame(403, $action->match($request->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'), new \Slim\Psr7\Response())->getStatusCode());
        $get = $request->withQueryParams(['transaction_id' => (string) $tx, 'limit' => '50']);
        self::assertSame(200, $action->candidates($get, new \Slim\Psr7\Response())->getStatusCode());
        $invalid = $request->withQueryParams(['transaction_id' => (string) $tx, 'search' => "Nov\xC3"]);
        self::assertSame(422, $action->candidates($invalid, new \Slim\Psr7\Response())->getStatusCode(), 'Neplatné UTF-8 v hledání');
        $bearerGet = $get->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer');
        self::assertSame(403, $action->candidates($bearerGet, new \Slim\Psr7\Response())->getStatusCode());
        // Token s plným zápisem (system_write) jedná jako relace (RequestAuthorization::actsAsSession).
        $systemWrite = $bearerGet->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['system_write' => true]);
        self::assertSame(200, $action->candidates($systemWrite, new \Slim\Psr7\Response())->getStatusCode());
        self::assertSame(403, $action->match($request, new \Slim\Psr7\Response())->getStatusCode(), 'Nezaúčtovaný pohyb navíc vyžaduje bank.post a accounting write');
        $full = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/accounting/nonprofit-membership/bank-match', ['REMOTE_ADDR' => '192.0.2.15'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(501, 'Synthetic bank membership role', 'staff', true,
                ['bank.post' => 2, 'accounting' => 2] + $permissions))
            ->withParsedBody(['transaction_id' => $tx, 'claim_ids' => [$id]]);
        self::assertSame(200, $action->match($full, new \Slim\Psr7\Response())->getStatusCode());
        // Token s plným zápisem (system_write) páruje jako relace; autor je uživatel tokenu.
        $tokenClaim = $this->newClaim('bank-auth-token');
        $tokenTx = $this->payment('20000.00');
        $viaToken = $full->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['system_write' => true])
            ->withParsedBody(['transaction_id' => $tokenTx, 'claim_ids' => [$tokenClaim]]);
        self::assertSame(403, $action->match($viaToken->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['system_write' => false]), new \Slim\Psr7\Response())->getStatusCode());
        self::assertSame(200, $action->match($viaToken, new \Slim\Psr7\Response())->getStatusCode());
        $q = $this->pdo->prepare("SELECT user_id FROM activity_log WHERE action='nonprofit_membership.bank_matched' AND supplier_id=? ORDER BY id DESC LIMIT 1");
        $q->execute([$this->supplier]);
        self::assertSame($this->actor, (int) $q->fetchColumn());
        // Audit párování nese adresu klienta jako ostatní účetní akce (AccountingActionSupport::auditMeta).
        self::assertSame('192.0.2.15', $this->pdo->query("SELECT INET6_NTOA(ip) FROM activity_log WHERE action='nonprofit_membership.bank_matched'
            AND supplier_id=" . $this->supplier . ' ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    /**
     * Uzavřené období platby nepovolí přerazítkovat dimenze úhrady (DimensionPeriodGuard). Akce vrátí 409 se
     * strojovým kódem jako ostatní účetní chyby, ne 500.
     */
    public function testMatchInClosedPeriodIsConflictNotServerError(): void
    {
        $id = $this->newClaim('bank-closed', '5151');
        $tx = $this->payment('20000.00', '5151');
        // Pohyb zaúčtovaný ještě v otevřeném období; párování pak přerazítkovává dimenze jeho řádku 315.
        Bootstrap::buildContainer()->get(BankPostingService::class)->postManual($this->supplier, $tx,
            ['debit_account_code' => '221', 'credit_account_code' => '315.100'], ['user_id' => $this->actor, 'posted_by' => $this->actor]);
        $this->pdo->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=? AND id=?")->execute([$this->supplier, $this->period]);
        $action = new MembershipBankMatchAction($this->bankService(), new IpMatcher());
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/accounting/nonprofit-membership/bank-match')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(501, 'Synthetic bank membership role', 'staff', true,
                ['accounting' => 2, 'bank' => 2, 'bank.match' => 2, 'bank.post' => 2, 'accounting.journal.post' => 2]))
            ->withParsedBody(['transaction_id' => $tx, 'claim_ids' => [$id]]);
        $response = $action->match($request, new \Slim\Psr7\Response());
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('period_not_open', json_decode((string) $response->getBody(), true)['error']['code']);
        // Odvolání rozpracovaného párování dělá vlastník transakce (TransactionFailure::retrying v match());
        // test běží ve vnější transakci, proto zápisy neověřuje.
    }

    public function testLegacyCaseWithoutSymbolGetsDefaultSymbolAndStaysFindableByMemberSearch(): void
    {
        $member = $this->member('SYN-bank-old-null', 'bank-old-null');
        $request = $this->request($member['id']);
        $repository = new MembershipChargeRepository($this->db);
        // Historický případ má NULL VS už při založení; neměnnost se v testu neobchází.
        $repository->insertCase($this->supplier, $member['id'], $request['title_key'], $request['period_from'], $request['period_to'], $this->actor);
        $preview = $this->charges->previewIssue($this->supplier, $request);
        $issued = $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'bank-old-null', $this->actor);
        // Případ z doby před VS dostane při vystavení výchozí VS (nečíselné členské číslo → vygenerovaný).
        self::assertMatchesRegularExpression('/^[1-9][0-9]{9}$/D', (string) $issued['case']['variable_symbol']);
        $id = $repository->claimForOperation($this->supplier, $issued['operation']['id'])['id'];
        $q = $this->pdo->prepare('SELECT case_id,record_hash FROM nonprofit_membership_charge_operations WHERE id=(SELECT operation_id FROM nonprofit_membership_charge_claims WHERE supplier_id=? AND id=?)');
        $q->execute([$this->supplier, $id]);
        $operation = $q->fetch(PDO::FETCH_ASSOC);
        $tx = $this->payment('40000.00', '987654321');
        self::assertSame([$id], array_column($this->bankService()->candidates($this->supplier, $tx, '0', 50, 'bank-old-null')['candidates'], 'id'));
        $q->execute([$this->supplier, $id]);
        self::assertSame($operation['record_hash'], $q->fetch(PDO::FETCH_ASSOC)['record_hash']);
    }

    #[DataProvider('orphanPaymentPositions')]
    public function testOrphanBankPairingCanBeReused(bool $continuation): void
    {
        $claim = $this->newClaim('orphan-bank');
        if ($continuation) {
            $first = $this->payment('5000.00');
            $this->bankService()->match($this->supplier, $first, [$claim], $this->actor, [], true);
        }
        $amount = $continuation ? '15000.00' : '20000.00';
        $transaction = $this->payment($amount);
        $entry = $this->posting->postDocument($this->supplier, 'bank', $transaction, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => $amount],
            ['account_code' => '315.100', 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => '2026-02-01', 'description' => 'Synthetic orphan payment', 'posted' => true, 'posted_by' => $this->actor]);
        $q = $this->pdo->prepare("SELECT entry_id,line_no,account_id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? AND side='credit'");
        $q->execute([$this->supplier, $entry]);
        $line = array_map('intval', $q->fetch(PDO::FETCH_ASSOC));
        // Stav po zániku protějšího zápisu: samotný bankovní řádek stále zabírá unikátní vazbu.
        $orphan = (new JournalLinePairingRepository($this->db))->create($this->supplier, $line['account_id'], [$line], 'Synthetic orphan', 'manual', $this->actor);
        $matched = $this->bankService()->match($this->supplier, $transaction, [$claim], $this->actor, [], false);
        self::assertSame($entry, $matched['entry_id']);
        self::assertSame('settled', (new MembershipReconciliationService($this->balances))->claimSettlement($this->supplier, $claim)['settlement']['status']);
        self::assertNull((new JournalLinePairingRepository($this->db))->find($this->supplier, $orphan));
    }

    public static function orphanPaymentPositions(): iterable
    {
        yield 'první úhrada' => [false];
        yield 'doplatek' => [true];
    }

    public function testRefundPairedInLedgerIsReportedAsMembershipLikeTheGuardBlocksIt(): void
    {
        $claim = $this->newClaim('bank-refund');
        $refund = $this->payment('-5000.00');
        $entry = $this->posting->postDocument($this->supplier, 'bank', $refund, [
            ['account_code' => '315.100', 'side' => 'debit', 'amount' => '5000.00'],
            ['account_code' => '221', 'side' => 'credit', 'amount' => '5000.00'],
        ], ['entry_date' => '2026-02-01', 'description' => 'Synthetic membership refund', 'posted' => true, 'posted_by' => $this->actor]);
        $ref = fn (string $where): array => array_map('intval', $this->pdo->query('SELECT l.entry_id,l.line_no,l.account_id FROM journal_entry_lines l ' . $where)->fetch(PDO::FETCH_ASSOC));
        $receivable = $ref('JOIN nonprofit_membership_charge_operations o ON o.supplier_id=l.supplier_id AND o.receivable_line_id=l.id
            JOIN nonprofit_membership_charge_claims c ON c.supplier_id=o.supplier_id AND c.operation_id=o.id WHERE c.id=' . $claim);
        $debit = $ref("WHERE l.entry_id=$entry AND l.side='debit'");
        // Ruční párování v saldokontu (vratka přeplatku); strážce pohyb blokuje, banka ho musí ukázat jako členský.
        (new JournalLinePairingRepository($this->db))->create($this->supplier, $debit['account_id'], [$receivable, $debit], 'Synthetic refund', 'manual', $this->actor);
        try {
            (new PaymentMutationContext($this->db))->assertSourceMutable($this->supplier, 'bank', $refund);
            self::fail('Fixture: strážce blokuje pohyb spárovaný s členským předpisem.');
        } catch (PostingException $e) {
            self::assertSame('nonprofit_membership_payment_in_use', $e->errorCode);
        }
        $matches = OtherItemPaymentMatchReader::membershipByTransactions($this->pdo, $this->supplier, [$refund]);
        self::assertSame([$claim], array_column($matches[$refund] ?? [], 'claim_id'), 'Výpis banky musí pohyb nabídnout jako vyřešený členstvím, ne k párování.');
    }

    public function testBankMetadataRequiresAccountingReadAndPreservesUsedResolution(): void
    {
        $claim = $this->newClaim('bank-metadata');
        $transaction = $this->payment('20000.00');
        $this->bankService()->match($this->supplier, $transaction, [$claim], $this->actor, [], true);
        $matches = OtherItemPaymentMatchReader::membershipByTransactions($this->pdo, $this->supplier, [$transaction]);
        $access = new ClientBankAccess($this->db);
        foreach ([['staff', ['bank' => 1], false, 'session'], ['staff', ['bank' => 1, 'accounting' => 1], true, 'session'],
            ['client', ['bank' => 1, 'accounting' => 1], false, 'session'],
            // Veřejné API přes token: členská čísla a předpisy nevydá, stav vyřízení ano.
            ['staff', ['bank' => 1, 'accounting' => 1], false, 'bearer'],
            // Token s plným zápisem jedná jako relace.
            ['staff', ['bank' => 1, 'accounting' => 1], true, 'system_write']] as [$type, $permissions, $visible, $method]) {
            $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/bank-transactions')
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
                ->withAttribute(AuthMiddleware::ATTR_METHOD, $method === 'system_write' ? 'bearer' : $method)
                ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, $method === 'system_write' ? ['system_write' => true] : null)
                ->withAttribute('auth.effective_role', new EffectiveRole(501, 'Synthetic bank role', $type, true, $permissions));
            $row = $access->redactTransactions($request, [['id' => $transaction, 'match_status' => 'unmatched',
                'posting' => null, 'matched_membership_claims' => $matches[$transaction]]])[0];
            self::assertSame($visible ? $matches[$transaction] : [], $row['matched_membership_claims']);
            self::assertSame('membership', $row['resolution']);
            self::assertFalse($row['match_available']);
            self::assertFalse($row['ignore_available']);
        }
    }

    private function bankAccount(string $number = '1000000005'): int
    {
        $this->pdo->prepare("INSERT INTO supplier_bank_accounts
                (supplier_id,label,account_number,bank_code,bank_code_norm,currency,account_canonical,kind,source,is_active)
             VALUES (?,'Synthetic membership account',?,'0100','0100','CZK',?,'current','manual',1)")
            ->execute([$this->supplier, $number, ltrim($number, '0')]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{0:int,1:int} typ střediska => nová hodnota */
    private function centerValue(string $code): array
    {
        $dimensions = Bootstrap::buildContainer()->get(DimensionService::class);
        $type = (int) $dimensions->ensureDefaultTypes($this->supplier, ['stredisko'])['cost_center'];
        return [$type, (int) $dimensions->createValue($this->supplier, $type, ['code' => 'SYN-' . $code, 'name' => 'Synthetic ' . $code])['id']];
    }

    /** @return array{0:array<int,int>,1:array<int,int>,2:array<int,int>} dimenze pohledávky, úhrady 315 a řádku banky 221 */
    private function paymentLines(int $claim, ?int $tx): array
    {
        $receivable = (int) $this->pdo->query('SELECT o.receivable_line_id FROM nonprofit_membership_charge_claims c
            JOIN nonprofit_membership_charge_operations o ON o.supplier_id=c.supplier_id AND o.id=c.operation_id WHERE c.id=' . $claim)->fetchColumn();
        $q = $this->pdo->prepare("SELECT a.account_code, l.id FROM journal_entries e JOIN journal_entry_lines l ON l.supplier_id=e.supplier_id AND l.entry_id=e.id
            JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.supplier_id=? AND e.source_type='bank' AND e.source_id=? AND e.reversed_by IS NULL");
        $q->execute([$this->supplier, $tx ?? 0]);
        $lines = [];
        foreach ($q->fetchAll(PDO::FETCH_KEY_PAIR) as $code => $line) $lines[str_starts_with((string) $code, '221') ? '221' : (string) $code] = $line;
        $dims = (new DimensionAssignmentRepository($this->db))->lineDimensions($this->supplier, [$receivable, ...array_values($lines)]);
        $out = [];
        foreach ([$receivable, (int) ($lines['315.100'] ?? 0), (int) ($lines['221'] ?? 0)] as $line) {
            $one = $dims[$line] ?? [];
            ksort($one);
            $out[] = $one;
        }
        self::assertNotSame([], $out[0], 'Fixture: pohledávka nese dimenze klasifikace.');
        return $out;
    }

    private function bankService(): MembershipBankMatchService
    {
        return Bootstrap::buildContainer()->get(MembershipBankMatchService::class);
    }

    private function newClaim(string $key, ?string $vs = null, string $receivable = '315.100'): int
    {
        $member = $this->member('SYN-' . $key, $key);
        $request = ['receivable_account' => $receivable] + $this->request($member['id']);
        if ($vs !== null) $request['variable_symbol'] = $vs;
        $preview = $this->charges->previewIssue($this->supplier, $request);
        $issued = $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], $key, $this->actor);
        return (int) $this->pdo->query('SELECT id FROM nonprofit_membership_charge_claims WHERE operation_id=' . (int) $issued['operation']['id'])->fetchColumn();
    }

    private function payment(string $amount, ?string $vs = null, string $account = '1000000005'): int
    {
        $key = bin2hex(random_bytes(8));
        $this->pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by)
            VALUES(?,'synthetic-bank-membership.gpc',?,?,'0100','CZK','2026-02-01',?)")
            ->execute([$this->supplier, hash('sha256', $key), $account, $this->actor]);
        $statement = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,description,match_status,variable_symbol)
            VALUES(?,'2026-02-01',?,'CZK','Synthetic membership bank payment','unmatched',?)")->execute([$statement, $amount, $vs]);
        return (int) $this->pdo->lastInsertId();
    }

    private function member(string $number, string $key): array
    {
        $currency = (int) $this->pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic member','Synthetic street','Synthetic city','10000',203,?,'cs',?,1,0)")
            ->execute([$this->supplier, $key . '@example.test', $currency]);
        $member = $this->members->create($this->supplier, (int) $this->pdo->lastInsertId(), $number, $this->actor);
        $this->members->appendRevision($this->supplier, $member['id'], [
            'status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0,
            'idempotency_key' => 'member-' . $key, 'legal_basis' => $this->basis('Synthetic member evidence ' . $key),
        ], $this->actor);
        return $member;
    }

    private function catalog(): MembershipPublicCatalog
    {
        return new MembershipPublicCatalog($this->db, new MembershipRepository($this->db), new MembershipChargeRepository($this->db), $this->balances);
    }

    private function basis(string $evidence): array
    {
        return ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership',
            'reference' => 'Synthetic association statutes article 5', 'evidence' => $evidence];
    }

    private function request(int $member): array
    {
        return ['member_id' => $member, 'title_key' => 'annual-2026', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'accounting_date' => '2026-01-01', 'due_date' => '2026-01-31', 'amount' => '20000.00',
            'receivable_account' => '315.100', 'revenue_account' => '684.100', 'title_reference' => 'Synthetic statutes article 5',
            'evidence' => 'Synthetic approved annual membership resolution', 'nonreciprocal' => true, 'reason' => 'Synthetic annual membership charge',
            'classification' => $this->classification];
    }

    private function issue(int $member, string $key): array
    {
        $request = $this->request($member);
        $preview = $this->charges->previewIssue($this->supplier, $request);
        return $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'issue-' . $key, $this->actor)
            + ['preview_hash' => $preview['preview_hash']];
    }

    private function issueTitle(int $member, string $title, string $key): array
    {
        $request = $this->request($member);
        $request['title_key'] = $title;
        $preview = $this->charges->previewIssue($this->supplier, $request);
        return $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], $key, $this->actor);
    }

    private function selectQueries(callable $work): int
    {
        $before = (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        $work();
        return (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1] - $before;
    }
}
