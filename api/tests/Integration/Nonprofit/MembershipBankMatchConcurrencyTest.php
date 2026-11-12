<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBankMatchRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Nonprofit\Membership\MembershipBankMatchService;
use MyInvoice\Service\Nonprofit\Membership\MembershipBankPaymentGuard;
use MyInvoice\Service\Nonprofit\Membership\MembershipChargeService;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipMemberService;
use MyInvoice\Service\Tax\Nonprofit\NonprofitLegalProfileService;
use MyInvoice\Tests\Support\OwnedSandboxDatabase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Bankovní řádek spáruje v saldokontu jiný uživatel až poté, co si párování členské úhrady
 * vytvořilo snímek. Kontrola ve snímku obsazení nevidí; zápis nesmí skončit chybou 500 ani
 * druhým párováním téhož řádku. Druhé spojení commituje, proto vlastní kopie testovací DB.
 */
#[Group('integration')]
final class MembershipBankMatchConcurrencyTest extends TestCase
{
    use OwnedSandboxDatabase;

    private const SANDBOX = 'membership_bank_lock';

    /** @return array<string,array{bool}> */
    public static function isolation(): array
    {
        return ['duplicitní klíč (1062)' => [false], 'konflikt snímku a opakování' => [true]];
    }

    /** @return array<string,array{bool}> */
    public static function snapshotModes(): array
    {
        return ['bez izolace snímku' => [false], 'izolace snímku a opakování' => [true]];
    }

    #[DataProvider('isolation')]
    public function testConcurrentLedgerPairingOfTheBankLineIsAConflictNotAServerError(bool $snapshotIsolation): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $pdo = $db->pdo();
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation=' . ($snapshotIsolation ? '1' : '0'));
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $container = Bootstrap::buildContainer();
            $container->set(Config::class, self::sandboxConfig(self::SANDBOX));
            $container->set(Connection::class, $db);
            [$supplier, $actor, $claim, $transaction, $line] = $this->fixture($db, $container);

            $pdo->beginTransaction();
            $pdo->query('SELECT COUNT(*) FROM journal_line_pairing_items')->fetchColumn();
            $other = $this->secondConnection();
            $other->prepare("INSERT INTO journal_line_pairings(supplier_id,account_id,note,origin,created_by) VALUES(?,?,'Synthetic concurrent pairing','manual',?)")
                ->execute([$supplier, $line['account_id'], $actor]);
            $other->prepare('INSERT INTO journal_line_pairing_items(supplier_id,pairing_id,entry_id,line_no,account_id) VALUES(?,?,?,?,?)')
                ->execute([$supplier, (int) $other->lastInsertId(), $line['entry_id'], $line['line_no'], $line['account_id']]);

            try {
                $container->get(MembershipBankMatchService::class)->match($supplier, $transaction, [$claim], $actor, [], true);
                self::fail('Bankovní řádek obsazený souběžným párováním se nesmí spárovat podruhé.');
            } catch (MembershipException $e) {
                self::assertSame('nonprofit_membership_payment_already_matched', $e->errorCode, $e->getMessage());
                self::assertSame(409, $e->httpStatus);
            } catch (\PDOException $e) {
                // Při izolaci snímku konflikt odvolá transakci; opakuje ji vlastník (TransactionFailure::retrying),
                // který po novém čtení vrátí 409 výše. Služba ho nesmí převést na jinou chybu.
                self::assertTrue($snapshotIsolation && TransactionFailure::aborted($e), $e->getMessage());
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db->close();
        }
    }

    /**
     * Uživatel uloží na řádek úhrady ručně tutéž hodnotu, kterou tam dřív dala výchozí vrstva, až poté,
     * co si párování vytvořilo snímek. O uvolnění rozhoduje původ přečtený pod zámkem: ruční hodnota
     * se neztratí a párování se odmítne jako u každé ruční dimenze odlišné od předpisu. Při izolaci snímku
     * zamykací čtení změněného řádku transakci odvolá a opakování ji přečte znovu.
     */
    #[DataProvider('snapshotModes')]
    public function testManualValueSavedAfterSnapshotIsNotReleasedAsDefault(bool $snapshotIsolation): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $pdo = $db->pdo();
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation=' . ($snapshotIsolation ? '1' : '0'));
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $container = Bootstrap::buildContainer();
            $container->set(Config::class, self::sandboxConfig(self::SANDBOX));
            $container->set(Connection::class, $db);
            [$supplier, $actor, $claim, $transaction, $ref] = $this->fixture($db, $container);
            $dimensions = $container->get(DimensionService::class);
            $type = (int) $dimensions->ensureDefaultTypes($supplier, ['stredisko'])['cost_center'];
            $center = (int) $dimensions->createValue($supplier, $type, ['code' => 'SYN-RACE', 'name' => 'Synthetic race center'])['id'];
            $line = (int) $pdo->query('SELECT id FROM journal_entry_lines WHERE entry_id=' . $ref['entry_id'] . ' AND line_no=' . $ref['line_no'])->fetchColumn();
            (new DimensionAssignmentRepository($db))->insertLineDimensions($supplier, $line, [$type => $center],
                [$type => ['origin' => 'entity_default', 'ref' => 'bank_account:1']]);

            $pdo->beginTransaction();
            $pdo->query('SELECT COUNT(*) FROM journal_entry_line_dimensions WHERE line_id=' . $line)->fetchColumn();
            $this->secondConnection()->prepare("UPDATE journal_entry_line_dimensions SET assignment_origin='explicit', assignment_origin_ref=NULL
                WHERE supplier_id=? AND line_id=? AND dimension_type_id=?")->execute([$supplier, $line, $type]);

            try {
                $container->get(MembershipBankMatchService::class)->match($supplier, $transaction, [$claim], $actor, [], true);
                self::fail('Ruční hodnota uložená souběžně se nesmí uvolnit jako výchozí.');
            } catch (MembershipException $e) {
                self::assertSame('nonprofit_membership_payment_dimensions_own', $e->errorCode, $e->getMessage());
            } catch (\PDOException $e) {
                self::assertTrue($snapshotIsolation && TransactionFailure::aborted($e), $e->getMessage());
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db->close();
        }
    }

    /**
     * Platbu s předpisem spáruje jiný uživatel až poté, co si storno vytvořilo snímek. Storno pak nesmí
     * odebrat pohledávku z okruhu (platba by zůstala zamčená bez protějšku): zamykací čtení okruh vidí.
     */
    #[DataProvider('snapshotModes')]
    public function testMatchCommittedAfterReversalSnapshotRejectsTheReversal(bool $snapshotIsolation): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $pdo = $db->pdo();
        $other = self::sandboxConnection(self::SANDBOX);
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation=' . ($snapshotIsolation ? '1' : '0'));
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $container = $this->container($db);
            [$supplier, $actor, $claim, $transaction] = $this->fixture($db, $container);
            $charges = $this->charges($db, $container);
            $reverse = ['case_id' => $this->caseOf($pdo, $claim), 'accounting_date' => '2026-12-31', 'reason' => 'Synthetic concurrent cancellation'];
            $preview = $charges->previewReverse($supplier, $reverse);

            $pdo->beginTransaction();
            $pdo->query('SELECT COUNT(*) FROM journal_line_pairing_items')->fetchColumn();
            $this->container($other)->get(MembershipBankMatchService::class)->match($supplier, $transaction, [$claim], $actor, [], true);

            try {
                $charges->confirmReverse($supplier, $reverse, $preview['expected_case_version'], $preview['preview_hash'], 'reverse-concurrent', $actor);
                self::fail('Storno spárovaného předpisu prošlo, platba by zůstala v okruhu bez pohledávky.');
            } catch (MembershipException $e) {
                self::assertSame('nonprofit_membership_claim_paired', $e->errorCode, $e->getMessage());
                self::assertSame(409, $e->httpStatus);
            } catch (\PDOException $e) {
                self::assertTrue($snapshotIsolation && TransactionFailure::aborted($e), $e->getMessage());
            }
            if ($pdo->inTransaction()) $pdo->rollBack();
            self::assertSame(0, (int) $other->pdo()->query("SELECT COUNT(*) FROM nonprofit_membership_charge_operations WHERE supplier_id=$supplier AND operation='reverse'")->fetchColumn());
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db->close();
            $other->close();
        }
    }

    /** Opačné pořadí: storno potvrdí jiný uživatel po snímku párování; párování vrátí 409, ne 500. */
    #[DataProvider('snapshotModes')]
    public function testReversalCommittedAfterMatchSnapshotMakesTheClaimNotPayable(bool $snapshotIsolation): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $pdo = $db->pdo();
        $other = self::sandboxConnection(self::SANDBOX);
        try {
            $pdo->exec('SET SESSION innodb_snapshot_isolation=' . ($snapshotIsolation ? '1' : '0'));
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $container = $this->container($db);
            [$supplier, $actor, $claim, $transaction] = $this->fixture($db, $container);
            $reverse = ['case_id' => $this->caseOf($pdo, $claim), 'accounting_date' => '2026-12-31', 'reason' => 'Synthetic concurrent cancellation'];

            $pdo->beginTransaction();
            $pdo->query('SELECT COUNT(*) FROM nonprofit_membership_charge_claims')->fetchColumn();
            $charges = $this->charges($other, $this->container($other));
            $preview = $charges->previewReverse($supplier, $reverse);
            $charges->confirmReverse($supplier, $reverse, $preview['expected_case_version'], $preview['preview_hash'], 'reverse-before-match', $actor);

            try {
                $container->get(MembershipBankMatchService::class)->match($supplier, $transaction, [$claim], $actor, [], true);
                self::fail('Párování přijalo předpis stornovaný souběžným uživatelem.');
            } catch (MembershipException $e) {
                self::assertSame('nonprofit_membership_claim_not_payable', $e->errorCode, $e->getMessage());
            } catch (\PDOException $e) {
                self::assertTrue($snapshotIsolation && TransactionFailure::aborted($e), $e->getMessage());
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db->close();
            $other->close();
        }
    }

    /**
     * Kontrola „má firma předpisy“ běží u každé bankovní změny ještě před PaymentMutationContext::acquire().
     * Nesmí zamykat: sdílený zámek by zamkl i mezeru indexu sousední firmy a zakládání jejích předpisů
     * by čekalo na cizí bankovní transakci (a u prvního předpisu obrátilo pořadí zámků).
     */
    public function testGuardInOpenTransactionDoesNotBlockClaimsOfAnotherSupplier(): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $pdo = $db->pdo();
        try {
            [$supplier] = $this->fixture($db, $this->container($db));
            $pdo->beginTransaction();
            self::assertTrue((new MembershipBankPaymentGuard($db))->supplierHasClaims($supplier));
            $other = $this->secondConnection();
            $other->exec('SET SESSION innodb_lock_wait_timeout=1');
            $other->exec('SET SESSION FOREIGN_KEY_CHECKS=0');
            $other->beginTransaction();
            try {
                // Sousední firma s nižším ID: její nový předpis padne do mezery indexu před předpisy firmy.
                $other->prepare("INSERT INTO nonprofit_membership_charge_claims(supplier_id,case_id,operation_id,member_id,client_id,kind,amount_cents,record_hash)
                    VALUES(?,1,1,1,1,'issue',100,?)")->execute([$supplier - 1, str_repeat('0', 64)]);
            } catch (\PDOException $e) {
                self::fail('Předpis jiné firmy čekal na ochranu bankovního pohybu: ' . $e->getMessage());
            } finally {
                $other->rollBack();
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $db->close();
        }
    }

    /**
     * Dvě párování předpisů se stejným účtem pohledávky se řadí už při výběru předpisů. Přerazítkování dimenzí
     * po párování zamyká osnovu výhradně (SystemDimensionWritePolicy); kdyby výběr četl účet jen sdíleným
     * zámkem, obě párování by ho získala a při přerazítkování by čekala jedno na druhé (deadlock 1213).
     */
    public function testSecondPairingOnTheSameReceivableAccountWaitsAtClaimSelection(): void
    {
        $db = self::sandboxConnection(self::SANDBOX);
        $second = self::sandboxConnection(self::SANDBOX);
        $pdo = $db->pdo();
        try {
            [$supplier, , $claim, , , $extra] = $this->fixture($db, $this->container($db), true);
            self::assertNotNull($extra);
            self::assertNotSame($claim, $extra);
            $pdo->beginTransaction();
            $selected = $this->container($db)->get(MembershipBankMatchRepository::class)->selected($supplier, [$claim]);
            self::assertSame([$claim], array_column($selected, 'claim_id'));
            $second->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');
            $second->pdo()->beginTransaction();
            // Řádek účtu pohledávky je zamčený výhradně: ani sdílené čtení (jako výběr se sdíleným zámkem) neprojde.
            $account = (int) $pdo->query("SELECT id FROM chart_of_accounts WHERE supplier_id=$supplier AND account_code='315.100'")->fetchColumn();
            try {
                $q = $second->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE id=? LOCK IN SHARE MODE');
                $q->execute([$account]);
                self::fail('Účet pohledávky vybraného předpisu nebyl zamčený výhradně.');
            } catch (\PDOException $e) {
                self::assertSame(1205, (int) ($e->errorInfo[1] ?? 0), $e->getMessage());
            }
            try {
                $this->container($second)->get(MembershipBankMatchRepository::class)->selected($supplier, [$extra]);
                self::fail('Výběr druhého předpisu na stejném účtu pohledávky nečekal na rozpracované párování.');
            } catch (\PDOException $e) {
                self::assertSame(1205, (int) ($e->errorInfo[1] ?? 0), $e->getMessage());
            }
        } finally {
            if ($second->pdo()->inTransaction()) $second->pdo()->rollBack();
            if ($pdo->inTransaction()) $pdo->rollBack();
            $second->close();
            $db->close();
        }
    }

    private function container(Connection $db): \Psr\Container\ContainerInterface
    {
        $container = Bootstrap::buildContainer();
        $container->set(Config::class, self::sandboxConfig(self::SANDBOX));
        $container->set(Connection::class, $db);
        return $container;
    }

    private function charges(Connection $db, \Psr\Container\ContainerInterface $container): MembershipChargeService
    {
        return new MembershipChargeService($db, new MembershipRepository($db), new MembershipChargeRepository($db), $container->get(PostingService::class));
    }

    private function caseOf(PDO $pdo, int $claim): int
    {
        return (int) $pdo->query('SELECT case_id FROM nonprofit_membership_charge_claims WHERE id=' . $claim)->fetchColumn();
    }

    /** @return array{int,int,int,int,array{entry_id:int,line_no:int,account_id:int},?int} poslední je druhý předpis téhož účtu */
    private function fixture(Connection $db, \Psr\Container\ContainerInterface $container, bool $secondClaim = false): array
    {
        $pdo = $db->pdo();
        $key = bin2hex(random_bytes(6));
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->prepare("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode) VALUES('Synthetic concurrent association','Synthetic street','Synthetic city','10000',?,203,1,?,'double_entry')")
            ->execute(["association-$key@example.test", $vat]);
        $supplier = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES(?,'CZK','Synthetic CZK','Kč','Koruna','Crown',2,1,1,'1000000005','0100')")->execute([$supplier]);
        $pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([(int) $pdo->lastInsertId(), $supplier]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES(?,?,'Synthetic concurrent actor','admin','cs',1)")
            ->execute(["actor-$key@example.test", password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $actor = (int) $pdo->lastInsertId();
        $period = $container->get(AccountingPeriodRepository::class)->create($supplier, 2026, '2026-01-01', '2026-12-31');
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES(?,'2026-01-01','cz_504')")->execute([$supplier]);
        $axes = (new SystemDimensionCatalog($db))->ensureForFramework($supplier, '2026-01-01', $actor);
        $pdo->prepare("INSERT INTO dimension_values(type_id,supplier_id,code,name) VALUES(?,?,'MEMBERSHIP','Synthetic membership activity')")
            ->execute([$axes['activity_kind']['id'], $supplier]);
        $classification = ['activity_value_id' => $axes['activity']['values']['main'], 'activity_kind_value_id' => (int) $pdo->lastInsertId(),
            'tax_regime_value_id' => $axes['tax_regime']['values']['subject']];
        foreach (['221' => 'asset', '315.100' => 'asset', '684.100' => 'revenue'] as $code => $type) {
            $pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES(?,?,?,?)')
                ->execute([$supplier, $code, 'Synthetic ' . $code, $type]);
        }
        (new NonprofitLegalProfileService($db))->confirm($supplier, $period, ['expected_revision' => 0, 'idempotency_key' => 'membership-legal',
            'reason' => 'Synthetic reviewed association statutes', 'profile' => ['kind' => 'narrow_vpp', 'entity_type' => 'association',
                'main_activity_nonbusiness' => true, 'legal_basis' => 'zdp_17a', 'evidence' => 'Synthetic signed association statutes']], $actor);
        $repository = new MembershipRepository($db);
        $posting = $container->get(PostingService::class);
        $basis = ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership',
            'reference' => 'Synthetic association statutes article 5', 'evidence' => 'Synthetic member evidence'];
        $currency = (int) $pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,'Synthetic member','Synthetic street','Synthetic city','10000',203,?,'cs',?,1,0)")
            ->execute([$supplier, "member-$key@example.test", $currency]);
        $members = new MembershipMemberService($db, $repository);
        $member = $members->create($supplier, (int) $pdo->lastInsertId(), 'SYN-CONCURRENT', $actor);
        $members->appendRevision($supplier, $member['id'], ['status' => 'active', 'effective_from' => '2026-01-01', 'expected_revision' => 0,
            'idempotency_key' => 'member-concurrent', 'legal_basis' => $basis], $actor);
        $charges = new MembershipChargeService($db, $repository, new MembershipChargeRepository($db), $posting);
        $request = ['member_id' => $member['id'], 'title_key' => 'annual-2026', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'accounting_date' => '2026-01-01', 'due_date' => '2026-01-31', 'amount' => '20000.00',
            'receivable_account' => '315.100', 'revenue_account' => '684.100', 'title_reference' => 'Synthetic statutes article 5',
            'evidence' => 'Synthetic approved annual membership resolution', 'nonreciprocal' => true, 'reason' => 'Synthetic annual membership charge',
            'classification' => $classification];
        $preview = $charges->previewIssue($supplier, $request);
        $issued = $charges->confirmIssue($supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'issue-concurrent', $actor);
        $claim = (int) $pdo->query('SELECT id FROM nonprofit_membership_charge_claims WHERE operation_id=' . (int) $issued['operation']['id'])->fetchColumn();
        $extra = null;
        if ($secondClaim) {
            $request = ['title_key' => 'extra-2026', 'reason' => 'Synthetic extra membership charge'] + $request;
            $preview = $charges->previewIssue($supplier, $request);
            $issuedExtra = $charges->confirmIssue($supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'issue-concurrent-extra', $actor);
            $extra = (int) $pdo->query('SELECT id FROM nonprofit_membership_charge_claims WHERE operation_id=' . (int) $issuedExtra['operation']['id'])->fetchColumn();
        }
        $pdo->prepare("INSERT INTO bank_statements(supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date,imported_by)
            VALUES(?,'synthetic-concurrent.gpc',?,'1000000005','0100','CZK','2026-02-01',?)")->execute([$supplier, hash('sha256', $key), $actor]);
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,description,match_status)
            VALUES(?,'2026-02-01','20000.00','CZK','Synthetic concurrent payment','unmatched')")->execute([(int) $pdo->lastInsertId()]);
        $transaction = (int) $pdo->lastInsertId();
        $entry = $posting->postDocument($supplier, 'bank', $transaction, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => '20000.00'],
            ['account_code' => '315.100', 'side' => 'credit', 'amount' => '20000.00'],
        ], ['entry_date' => '2026-02-01', 'description' => 'Synthetic concurrent payment', 'posted' => true, 'posted_by' => $actor]);
        $q = $pdo->prepare("SELECT entry_id,line_no,account_id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? AND side='credit'");
        $q->execute([$supplier, $entry]);
        return [$supplier, $actor, $claim, $transaction, array_map('intval', $q->fetch(PDO::FETCH_ASSOC)), $extra];
    }

    private function secondConnection(): PDO
    {
        $config = self::sandboxConfig(self::SANDBOX);
        return new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306)
            . ';dbname=' . $config->get('db.name') . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
