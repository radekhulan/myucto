<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Infrastructure;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\{Connection,LoggingPdo,TransactionSavepointToken};
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class TransactionSavepointTrackingNativeTest extends TestCase
{
    private LoggingPdo $pdo;
    private string $table;
    private ?string $permanent = null;

    protected function setUp(): void
    {
        $pdo = Bootstrap::buildContainer()->get(Connection::class)->pdo();
        self::assertInstanceOf(LoggingPdo::class, $pdo);
        $this->pdo = $pdo;
        self::assertSame('mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertStringContainsString('MariaDB', (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
        self::assertSame((string) getenv('MYINVOICE_DB_NAME'), (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        self::assertFalse($pdo->inTransaction());
        $this->table = 'epoch_native_' . bin2hex(random_bytes(8));
        self::assertSame(0, $pdo->exec('CREATE TEMPORARY TABLE `' . $this->table . '` (id INT PRIMARY KEY) ENGINE=InnoDB'));
        self::assertTrue($pdo->beginTransaction());
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) return;
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        $this->pdo->exec('SET SESSION autocommit=1');
        if (isset($this->table)) $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS `' . $this->table . '`');
        if ($this->permanent !== null) $this->pdo->exec('DROP TABLE IF EXISTS `' . $this->permanent . '`');
    }

    private function runSql(string $via, string $sql): int
    {
        if ($via === 'exec') {
            $count = $this->pdo->exec($sql);
            self::assertNotFalse($count);
            return $count;
        }
        if ($via === 'query') {
            $statement = $this->pdo->query($sql);
            self::assertNotFalse($statement);
        } else {
            $statement = $this->pdo->prepare($sql);
            self::assertNotFalse($statement);
            self::assertTrue($statement->execute());
        }
        $count = $statement->rowCount();
        $statement->closeCursor();
        return $count;
    }

    private function counter(string $name): int
    {
        $row = $this->pdo->query('SHOW SESSION STATUS LIKE ' . $this->pdo->quote($name))->fetch(PDO::FETCH_NUM);
        self::assertIsArray($row);
        return (int) $row[1];
    }

    private function rows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $this->table . '`')->fetchColumn();
    }

    private function missing(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
            self::fail('Native engine accepted an absent savepoint.');
        } catch (PDOException $error) {
            self::assertSame(1305, (int) ($error->errorInfo[1] ?? 0));
        }
    }

    #[TestWith(['exec', 'ROLLBACK TO newer'])]
    #[TestWith(['query', 'ROLLBACK WORK TO SAVEPOINT newer'])]
    #[TestWith(['prepared', 'rOlLbAcK /* safe */ To SaVePoInT newer'])]
    public function testNativeRollbackToNewerSavepointKeepsOlderMarkers(string $via, string $rollback): void
    {
        self::assertSame(1, $this->pdo->exec('INSERT INTO `' . $this->table . '` VALUES (1)'));
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT older'));
        $older = TransactionSavepointToken::capture($this->pdo, 'older');
        self::assertSame(1, $this->pdo->exec('INSERT INTO `' . $this->table . '` VALUES (2)'));
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT newer'));
        $newer = TransactionSavepointToken::capture($this->pdo, 'newer');
        self::assertSame(1, $this->pdo->exec('INSERT INTO `' . $this->table . '` VALUES (3)'));
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT tail'));
        $tail = TransactionSavepointToken::capture($this->pdo, 'tail');
        $before = $this->counter('Com_rollback_to_savepoint');
        self::assertSame(0, $this->runSql($via, $rollback));
        self::assertSame($before + 1, $this->counter('Com_rollback_to_savepoint'));
        self::assertSame(2, $this->rows());
        self::assertTrue($older->current($this->pdo));
        self::assertTrue($newer->current($this->pdo));
        self::assertFalse($tail->current($this->pdo));
        $this->missing('RELEASE SAVEPOINT tail');
        self::assertTrue($newer->current($this->pdo));
    }

    #[TestWith(['exec'])]
    #[TestWith(['query'])]
    #[TestWith(['prepared'])]
    public function testNativeReleaseInvalidatesNamedAndAllNewerMarkers(string $via): void
    {
        foreach (['older','middle','tail'] as $name) self::assertSame(0, $this->runSql($via, 'SAVEPOINT ' . $name));
        $older = TransactionSavepointToken::capture($this->pdo, 'older');
        $middle = TransactionSavepointToken::capture($this->pdo, 'middle');
        $tail = TransactionSavepointToken::capture($this->pdo, 'tail');
        $before = $this->counter('Com_release_savepoint');
        self::assertSame(0, $this->runSql($via, 'RELEASE /* safe */ SAVEPOINT middle'));
        self::assertSame($before + 1, $this->counter('Com_release_savepoint'));
        self::assertTrue($older->current($this->pdo));
        self::assertFalse($middle->current($this->pdo));
        self::assertFalse($tail->current($this->pdo));
        $this->missing('RELEASE SAVEPOINT tail');
        self::assertTrue($older->current($this->pdo));
    }

    #[TestWith(['exec'])]
    #[TestWith(['query'])]
    #[TestWith(['prepared'])]
    public function testNativeQuotedNumericSavepointKeepsAuthorityAfterRollback(string $via): void
    {
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT older'));
        $older = TransactionSavepointToken::capture($this->pdo, 'older');
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT `123`'));
        $numeric = TransactionSavepointToken::capture($this->pdo, '123');
        self::assertSame(1, $this->pdo->exec('INSERT INTO `' . $this->table . '` VALUES (1)'));
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT tail'));
        $tail = TransactionSavepointToken::capture($this->pdo, 'tail');
        self::assertSame(0, $this->runSql($via, 'ROLLBACK TO SAVEPOINT `123`'));
        self::assertSame(0, $this->rows());
        self::assertSame(0, $this->runSql($via, 'ROLLBACK TO SAVEPOINT `123`'));
        self::assertTrue($older->current($this->pdo));
        self::assertTrue($numeric->current($this->pdo));
        self::assertFalse($tail->current($this->pdo));
        self::assertSame(0, $this->runSql($via, 'RELEASE SAVEPOINT `123`'));
        self::assertTrue($older->current($this->pdo));
        self::assertFalse($numeric->current($this->pdo));
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT `123`'));
        $replacement = TransactionSavepointToken::capture($this->pdo, '123');
        self::assertFalse($numeric->current($this->pdo));
        self::assertTrue($replacement->current($this->pdo));
    }

    #[TestWith(['exec'])]
    #[TestWith(['query'])]
    #[TestWith(['prepared'])]
    public function testNativeSameNameReplacementPreservesNewerMarkersAndMovesNameToTop(string $via): void
    {
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT MiXeD'));
        $old = TransactionSavepointToken::capture($this->pdo, 'mixed');
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT tail'));
        $tail = TransactionSavepointToken::capture($this->pdo, 'tail');
        $before = $this->counter('Com_savepoint');
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT `mixed`'));
        self::assertSame($before + 1, $this->counter('Com_savepoint'));
        $replacement = TransactionSavepointToken::capture($this->pdo, 'MIXED');
        self::assertFalse($old->current($this->pdo));
        self::assertTrue($tail->current($this->pdo));
        self::assertTrue($replacement->current($this->pdo));
        self::assertSame(0, $this->runSql($via, 'RELEASE SAVEPOINT tail'));
        self::assertFalse($tail->current($this->pdo));
        self::assertFalse($replacement->current($this->pdo));
    }

    #[TestWith(['exec'])]
    #[TestWith(['query'])]
    #[TestWith(['prepared'])]
    public function testFailedNativeSqlDoesNotAdvanceTracker(string $via): void
    {
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT live'));
        $live = TransactionSavepointToken::capture($this->pdo, 'live');
        try {
            $this->runSql($via, 'RELEASE SAVEPOINT absent_marker');
            self::fail('Missing savepoint succeeded.');
        } catch (PDOException $error) {
            self::assertSame(1305, (int) ($error->errorInfo[1] ?? 0));
        }
        self::assertTrue($live->current($this->pdo));
    }

    #[TestWith(['exec'])]
    #[TestWith(['query'])]
    #[TestWith(['prepared'])]
    public function testCommentsAndRepeatedPassiveAssertionsDoNotExecuteSavepointSql(string $via): void
    {
        self::assertSame(0, $this->runSql($via, "/* leading */ SaVePoInT /* middle */ `Mixed_Name` -- trailing\n"));
        $token = TransactionSavepointToken::capture($this->pdo, 'mixed_name');
        $saved = $this->counter('Com_savepoint');
        $released = $this->counter('Com_release_savepoint');
        for ($index = 0; $index < 5; $index++) self::assertTrue($token->current($this->pdo));
        self::assertSame($saved, $this->counter('Com_savepoint'));
        self::assertSame($released, $this->counter('Com_release_savepoint'));
        self::assertSame("COMMIT; RELEASE SAVEPOINT mixed_name", $this->pdo->query("SELECT 'COMMIT; RELEASE SAVEPOINT mixed_name'")->fetchColumn());
        self::assertSame(0, $this->runSql($via, 'SET SESSION time_zone=@@session.time_zone'));
        self::assertTrue($token->current($this->pdo));
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testNativePdoCommitAndFullRollbackExpireTokens(bool $commit): void
    {
        self::assertSame(1, $this->pdo->exec('INSERT INTO `' . $this->table . '` VALUES (1)'));
        self::assertSame(0, $this->pdo->exec('SAVEPOINT live'));
        $token = TransactionSavepointToken::capture($this->pdo, 'live');
        $before = $this->counter($commit ? 'Com_commit' : 'Com_rollback');
        self::assertTrue($commit ? $this->pdo->commit() : $this->pdo->rollBack());
        self::assertSame($before + 1, $this->counter($commit ? 'Com_commit' : 'Com_rollback'));
        self::assertFalse($this->pdo->inTransaction());
        self::assertFalse($token->current($this->pdo));
        self::assertSame($commit ? 1 : 0, $this->rows());
        self::assertTrue($this->pdo->beginTransaction());
        self::assertFalse($token->current($this->pdo));
    }

    #[TestWith(['exec', true])]
    #[TestWith(['query', true])]
    #[TestWith(['prepared', true])]
    #[TestWith(['exec', false])]
    #[TestWith(['query', false])]
    #[TestWith(['prepared', false])]
    public function testNativeSqlChainExpiresOldEpochWhileTransactionStaysActive(string $via, bool $commit): void
    {
        self::assertSame(1, $this->pdo->exec('INSERT INTO `' . $this->table . '` VALUES (1)'));
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT live'));
        $token = TransactionSavepointToken::capture($this->pdo, 'live');
        $before = $this->counter($commit ? 'Com_commit' : 'Com_rollback');
        self::assertSame(0, $this->runSql($via, $commit ? 'COMMIT AND CHAIN' : 'ROLLBACK AND CHAIN'));
        self::assertSame($before + 1, $this->counter($commit ? 'Com_commit' : 'Com_rollback'));
        self::assertTrue($this->pdo->inTransaction());
        self::assertFalse($token->current($this->pdo));
        self::assertSame($commit ? 1 : 0, $this->rows());
    }

    #[TestWith(['exec', 'START TRANSACTION'])]
    #[TestWith(['query', 'BEGIN WORK'])]
    #[TestWith(['prepared', 'START TRANSACTION'])]
    public function testNativeSqlStartReplacesTransactionAndCommitsItsRows(string $via, string $sql): void
    {
        self::assertSame(1, $this->pdo->exec('INSERT INTO `' . $this->table . '` VALUES (1)'));
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT live'));
        $token = TransactionSavepointToken::capture($this->pdo, 'live');
        $before = $this->counter('Com_begin');
        self::assertSame(0, $this->runSql($via, $sql));
        self::assertSame($before + 1, $this->counter('Com_begin'));
        self::assertTrue($this->pdo->inTransaction());
        self::assertFalse($token->current($this->pdo));
        self::assertSame(1, $this->rows());
    }

    #[TestWith(['exec'])]
    #[TestWith(['query'])]
    #[TestWith(['prepared'])]
    public function testNativeAutocommitImplicitCommitExpiresToken(string $via): void
    {
        self::assertSame(0, $this->runSql($via, 'SET SESSION autocommit=0'));
        self::assertSame(1, $this->pdo->exec('INSERT INTO `' . $this->table . '` VALUES (1)'));
        self::assertSame(0, $this->runSql($via, 'SAVEPOINT live'));
        $token = TransactionSavepointToken::capture($this->pdo, 'live');
        self::assertSame(0, $this->runSql($via, 'SET SESSION autocommit=1'));
        self::assertFalse($this->pdo->inTransaction());
        self::assertFalse($token->current($this->pdo));
        self::assertSame(1, $this->rows());
    }

    public function testNativeDdlImplicitCommitIsObserved(): void
    {
        self::assertSame(1, $this->pdo->exec('INSERT INTO `' . $this->table . '` VALUES (1)'));
        self::assertSame(0, $this->pdo->exec('SAVEPOINT live'));
        $token = TransactionSavepointToken::capture($this->pdo, 'live');
        $this->permanent = 'epoch_ddl_' . bin2hex(random_bytes(8));
        self::assertSame(0, $this->pdo->exec('CREATE TABLE `' . $this->permanent . '` (id INT PRIMARY KEY) ENGINE=InnoDB'));
        self::assertFalse($this->pdo->inTransaction());
        self::assertFalse($token->current($this->pdo));
        self::assertSame(1, $this->rows());
    }

    public function testNativeTemporaryDdlKeepsExistingToken(): void
    {
        self::assertSame(0, $this->pdo->exec('SAVEPOINT live'));
        $token = TransactionSavepointToken::capture($this->pdo, 'live');
        $temporary = 'epoch_extra_' . bin2hex(random_bytes(8));
        self::assertSame(0, $this->pdo->exec('CREATE TEMPORARY TABLE `' . $temporary . '` (id INT) ENGINE=InnoDB'));
        self::assertTrue($this->pdo->inTransaction());
        self::assertTrue($token->current($this->pdo));
        self::assertSame(0, $this->pdo->exec('DROP TEMPORARY TABLE `' . $temporary . '`'));
        self::assertTrue($token->current($this->pdo));
    }

    public function testExecutableCommentIsFailClosedAfterActualSuccessfulSql(): void
    {
        self::assertSame(0, $this->pdo->exec('SAVEPOINT live'));
        $token = TransactionSavepointToken::capture($this->pdo, 'live');
        self::assertSame(0, $this->pdo->exec('/*! SET @epoch_native_executable=73 */'));
        self::assertSame(73, (int) $this->pdo->query('SELECT @epoch_native_executable')->fetchColumn());
        self::assertTrue($this->pdo->inTransaction());
        self::assertFalse($token->current($this->pdo));
    }

    public function testMultipleSuccessfulStatementsInvalidateInsteadOfInventingLifecycle(): void
    {
        self::assertSame(0, $this->pdo->exec('SAVEPOINT live'));
        $token = TransactionSavepointToken::capture($this->pdo, 'live');
        $before = $this->counter('Com_savepoint');
        self::assertSame(0, $this->pdo->exec('SAVEPOINT opaque_tail; RELEASE SAVEPOINT opaque_tail'));
        self::assertSame($before + 1, $this->counter('Com_savepoint'));
        self::assertTrue($this->pdo->inTransaction());
        self::assertFalse($token->current($this->pdo));
    }

    #[TestWith(['exec'])]
    #[TestWith(['query'])]
    #[TestWith(['prepared'])]
    public function testSilentFailureIsNotReportedAsSuccessfulTransactionControl(string $via): void
    {
        self::assertSame(0, $this->pdo->exec('SAVEPOINT live'));
        $token = TransactionSavepointToken::capture($this->pdo, 'live');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        try {
            if ($via === 'exec') $result = $this->pdo->exec('RELEASE SAVEPOINT absent_marker');
            elseif ($via === 'query') $result = $this->pdo->query('RELEASE SAVEPOINT absent_marker');
            else {
                $statement = $this->pdo->prepare('RELEASE SAVEPOINT absent_marker');
                self::assertNotFalse($statement);
                $result = $statement->execute();
            }
            self::assertFalse($result);
        } finally {
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        self::assertTrue($token->current($this->pdo));
    }

    public function testFailedDdlActualImplicitCommitCannotReviveToken(): void
    {
        $this->permanent = 'epoch_failed_ddl_' . bin2hex(random_bytes(8));
        self::assertSame(0, $this->pdo->exec('CREATE TABLE `' . $this->permanent . '` (id INT PRIMARY KEY) ENGINE=InnoDB'));
        self::assertFalse($this->pdo->inTransaction());
        self::assertTrue($this->pdo->beginTransaction());
        self::assertSame(1, $this->pdo->exec('INSERT INTO `' . $this->table . '` VALUES (1)'));
        self::assertSame(0, $this->pdo->exec('SAVEPOINT live'));
        $token = TransactionSavepointToken::capture($this->pdo, 'live');
        try {
            $this->pdo->exec('ALTER TABLE `' . $this->permanent . '` ADD COLUMN id INT');
            self::fail('Duplicate column DDL succeeded.');
        } catch (PDOException $error) {
            self::assertSame(1060, (int) ($error->errorInfo[1] ?? 0));
        }
        self::assertFalse($token->current($this->pdo));
        self::assertSame(0, (int) $this->pdo->query('SELECT @@in_transaction')->fetchColumn());
        self::assertFalse($this->pdo->inTransaction());
        $this->missing('RELEASE SAVEPOINT live');
        self::assertTrue($this->pdo->beginTransaction());
        self::assertTrue($this->pdo->rollBack());
        self::assertSame(1, $this->rows());
        self::assertFalse($token->current($this->pdo));
        self::assertTrue($this->pdo->beginTransaction());
        self::assertFalse($token->current($this->pdo));
    }

    public function testNativePdoAutocommitAttributeImplicitCommitExpiresToken(): void
    {
        self::assertTrue($this->pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, false));
        self::assertSame(1, $this->pdo->exec('INSERT INTO `' . $this->table . '` VALUES (1)'));
        self::assertSame(0, $this->pdo->exec('SAVEPOINT live'));
        $token = TransactionSavepointToken::capture($this->pdo, 'live');
        self::assertTrue($this->pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true));
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(1, $this->rows());
        self::assertFalse($token->current($this->pdo));
        self::assertTrue($this->pdo->beginTransaction());
        self::assertFalse($token->current($this->pdo));
    }

    public function testFailedBatchPartialCommitIsFailClosedEvenWithNewActiveTransaction(): void
    {
        self::assertSame(1, $this->pdo->exec('INSERT INTO `' . $this->table . '` VALUES (1)'));
        self::assertSame(0, $this->pdo->exec('SAVEPOINT before_partial_batch'));
        $token = TransactionSavepointToken::capture($this->pdo, 'before_partial_batch');
        $commits = $this->counter('Com_commit');
        $begins = $this->counter('Com_begin');
        try {
            $this->pdo->exec('COMMIT; START TRANSACTION; SELECT * FROM definitely_missing_epoch_table');
            self::fail('Missing table in partial batch succeeded.');
        } catch (PDOException $error) {
            self::assertSame(1146, (int) ($error->errorInfo[1] ?? 0));
        }
        self::assertSame($commits + 1, $this->counter('Com_commit'));
        self::assertSame($begins + 1, $this->counter('Com_begin'));
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame(1, $this->rows());
        self::assertFalse($token->current($this->pdo));
        $this->missing('RELEASE SAVEPOINT before_partial_batch');
    }

    public function testOrdinaryFailedSelectAndDmlKeepActualLiveEpoch(): void
    {
        self::assertSame(0, $this->pdo->exec('SAVEPOINT live'));
        $token = TransactionSavepointToken::capture($this->pdo, 'live');
        foreach (['exec', 'query', 'prepared'] as $via) {
            try {
                $this->runSql($via, $via === 'exec' ? 'INSERT INTO definitely_missing_epoch_table VALUES (1)' : 'SELECT * FROM definitely_missing_epoch_table');
                self::fail('Missing table SQL succeeded.');
            } catch (PDOException $error) {
                self::assertSame(1146, (int) ($error->errorInfo[1] ?? 0));
            }
            self::assertTrue($this->pdo->inTransaction());
            self::assertTrue($token->current($this->pdo));
        }
    }

    public function testPlainNativePdoCannotMintTrackedToken(): void
    {
        $config = Config::load(Bootstrap::rootDir());
        $plain = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port',3306) . ';dbname=' . $config->get('db.name') . ';charset=utf8mb4', $config->get('db.user'), $config->get('db.pass',''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        self::assertTrue($plain->beginTransaction());
        try {
            self::assertSame(0, $plain->exec('SAVEPOINT plain_marker'));
            try {
                TransactionSavepointToken::capture($plain, 'plain_marker');
                self::fail('Plain PDO minted tracked authority.');
            } catch (\LogicException $error) {
                self::assertSame('Savepoint tokens require tracked PDO.', $error->getMessage());
            }
        } finally {
            self::assertTrue($plain->rollBack());
        }
    }

    public function testCheckMissingTableReturnsErrorRowsAndActuallyCommits(): void
    {
        self::assertSame(1, $this->pdo->exec('INSERT INTO `' . $this->table . '` VALUES (1)'));
        self::assertSame(0, $this->pdo->exec('SAVEPOINT before_check'));
        $token = TransactionSavepointToken::capture($this->pdo, 'before_check');
        $result = $this->pdo->query('CHECK TABLE definitely_missing_epoch_table')->fetchAll(PDO::FETCH_ASSOC);
        $current = $token->current($this->pdo);
        self::assertSame(['Error', 'status'], array_column($result, 'Msg_type'));
        self::assertSame('Operation failed', $result[1]['Msg_text']);
        self::assertFalse($current);
        $this->missing('RELEASE SAVEPOINT before_check');
        self::assertSame(0, (int) $this->pdo->query('SELECT @@in_transaction')->fetchColumn());
        self::assertSame(1, $this->rows());
    }

    #[TestWith(['exec'])]
    #[TestWith(['query'])]
    #[TestWith(['prepared'])]
    public function testUnknownFailedHeadUsesSameConservativePolicyAsSuccess(string $via): void
    {
        self::assertSame(0, $this->pdo->exec('SAVEPOINT before_unknown'));
        $token = TransactionSavepointToken::capture($this->pdo, 'before_unknown');
        try {
            $this->runSql($via, 'CACHE INDEX definitely_missing_epoch_table IN definitely_missing_keycache');
            self::fail('Unknown key cache SQL succeeded.');
        } catch (PDOException $error) {
            self::assertSame(1284, (int) ($error->errorInfo[1] ?? 0));
        }
        $current = $token->current($this->pdo);
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame(0, $this->pdo->exec('ROLLBACK TO SAVEPOINT before_unknown'));
        self::assertFalse($current, 'Unknown failed head is conservatively invalid even though this engine error preserves its actual marker.');
    }

    public function testHealthyFailedSetAndTemporaryDdlKeepLiveEpoch(): void
    {
        self::assertSame(0, $this->pdo->exec('SAVEPOINT healthy_failure'));
        $token = TransactionSavepointToken::capture($this->pdo, 'healthy_failure');
        foreach (['SET SESSION time_zone = definitely_unknown_epoch_timezone', 'CREATE TEMPORARY TABLE `' . $this->table . '` (id INT)', 'DROP TEMPORARY TABLE definitely_missing_epoch_table'] as $sql) {
            try {
                $this->pdo->exec($sql);
                self::fail('Expected ordinary SQL failure.');
            } catch (PDOException $error) {
                self::assertContains((int) ($error->errorInfo[1] ?? 0), [1298, 1050, 1051]);
            }
            self::assertTrue($token->current($this->pdo));
            self::assertSame(0, $this->pdo->exec('ROLLBACK TO SAVEPOINT healthy_failure'));
        }
    }

    #[TestWith(['exec'])]
    #[TestWith(['prepared'])]
    public function testNativeDeadlockExpiresTokenBeforeAnyFollowingSql(string $via): void
    {
        self::assertTrue($this->pdo->rollBack());
        $this->permanent = 'epoch_deadlock_' . bin2hex(random_bytes(8));
        self::assertSame(0, $this->pdo->exec('CREATE TABLE `' . $this->permanent . '` (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB'));
        self::assertSame(20, $this->pdo->exec('INSERT INTO `' . $this->permanent . '` VALUES ' . implode(',', array_map(static fn (int $id): string => '(' . $id . ',0)', range(1,20)))));
        self::assertTrue($this->pdo->beginTransaction());
        self::assertSame(1, $this->pdo->exec('UPDATE `' . $this->permanent . '` SET value=value+1 WHERE id=1'));
        self::assertSame(0, $this->pdo->exec('SAVEPOINT before_deadlock'));
        $token = TransactionSavepointToken::capture($this->pdo, 'before_deadlock');
        $worker = <<<'PHP'
require $argv[1].'/api/vendor/autoload.php';
$config=MyInvoice\Infrastructure\Config\Config::load($argv[1]);
$pdo=new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';dbname='.$config->get('db.name').';charset=utf8mb4',$config->get('db.user'),$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=5');$pdo->beginTransaction();
try {
$count=$pdo->exec('UPDATE `'.$argv[2].'` SET value=value+1 WHERE id BETWEEN 2 AND 20');
if($count!==19)throw new RuntimeException('Worker did not own19 rows.');
fwrite(STDOUT,"locked\n");fflush(STDOUT);
if(trim((string)fgets(STDIN))!=='go')throw new RuntimeException('Worker handoff absent.');
$count=$pdo->exec('UPDATE `'.$argv[2].'` SET value=value+1 WHERE id=1');
if($count!==1)throw new RuntimeException('Worker final row missing.');
$pdo->commit();fwrite(STDOUT,"committed\n");
} finally {if($pdo->inTransaction())$pdo->rollBack();}
PHP;
        $process = proc_open([PHP_BINARY, '-r', $worker, Bootstrap::rootDir(), $this->permanent], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        self::assertIsResource($process);
        $timeout = (int) $this->pdo->query('SELECT @@innodb_lock_wait_timeout')->fetchColumn();
        try {
            self::assertSame(0, $this->pdo->exec('SET SESSION innodb_lock_wait_timeout=5'));
            $ready = fgets($pipes[1]);
            self::assertSame("locked\n", $ready);
            self::assertSame(3, fwrite($pipes[0], "go\n"));
            fflush($pipes[0]);
            try {
                $this->runSql($via, 'UPDATE `' . $this->permanent . '` SET value=value+1 WHERE id=2');
                self::fail('Native cycle did not select smaller transaction as deadlock victim.');
            } catch (PDOException $error) {
                self::assertSame(1213, (int) ($error->errorInfo[1] ?? 0));
                self::assertSame('40001', (string) ($error->errorInfo[0] ?? ''));
            }
            $currentBeforeAnySql = $token->current($this->pdo);
            $cachedStateBeforeAnySql = $this->pdo->inTransaction();
            $this->missing('RELEASE SAVEPOINT before_deadlock');
            self::assertSame(0, (int) $this->pdo->query('SELECT @@in_transaction')->fetchColumn());
            stream_set_blocking($pipes[1], true);
            self::assertSame("committed\n", stream_get_contents($pipes[1]));
            self::assertSame('', stream_get_contents($pipes[2]));
            self::assertFalse($currentBeforeAnySql, '1213 lost native marker; cached PDO state=' . (int) $cachedStateBeforeAnySql);
            self::assertSame(1, (int) $this->pdo->query('SELECT value FROM `' . $this->permanent . '` WHERE id=1')->fetchColumn());
            self::assertSame(20, (int) $this->pdo->query('SELECT SUM(value) FROM `' . $this->permanent . '`')->fetchColumn());
        } finally {
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            $status=proc_get_status($process);
            if ($status['running']) proc_terminate($process);
            $exit=proc_close($process);
            self::assertContains($exit, [0,-1]);
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout=' . $timeout);
        }
    }

    #[TestWith(['exec'])]
    #[TestWith(['prepared'])]
    public function testStatementOnlyLockTimeoutKeepsLiveEpoch(string $via): void
    {
        if ((int) $this->pdo->query('SELECT @@innodb_rollback_on_timeout')->fetchColumn() !== 0) {
            self::markTestSkipped('Statement-only lock timeout requires innodb_rollback_on_timeout=0.');
        }
        self::assertTrue($this->pdo->rollBack());
        $this->permanent = 'epoch_timeout_' . bin2hex(random_bytes(8));
        self::assertSame(0, $this->pdo->exec('CREATE TABLE `' . $this->permanent . '` (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB'));
        self::assertSame(2, $this->pdo->exec('INSERT INTO `' . $this->permanent . '` VALUES (1,0),(2,0)'));
        $config = Config::load(Bootstrap::rootDir());
        $blocker = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port',3306) . ';dbname=' . $config->get('db.name') . ';charset=utf8mb4', $config->get('db.user'), $config->get('db.pass',''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $timeout = (int) $this->pdo->query('SELECT @@innodb_lock_wait_timeout')->fetchColumn();
        try {
            self::assertTrue($blocker->beginTransaction());
            self::assertSame(1, $blocker->exec('UPDATE `' . $this->permanent . '` SET value=value+1 WHERE id=2'));
            self::assertSame(0, $this->pdo->exec('SET SESSION innodb_lock_wait_timeout=1'));
            self::assertTrue($this->pdo->beginTransaction());
            self::assertSame(1, $this->pdo->exec('UPDATE `' . $this->permanent . '` SET value=value+1 WHERE id=1'));
            self::assertSame(0, $this->pdo->exec('SAVEPOINT before_timeout'));
            $token = TransactionSavepointToken::capture($this->pdo, 'before_timeout');
            try {
                $this->runSql($via, 'UPDATE `' . $this->permanent . '` SET value=value+1 WHERE id=2');
                self::fail('Held row did not cause lock timeout.');
            } catch (PDOException $error) {
                self::assertSame(1205, (int) ($error->errorInfo[1] ?? 0));
            }
            self::assertTrue($token->current($this->pdo));
            self::assertTrue($this->pdo->inTransaction());
            self::assertSame(0, $this->pdo->exec('ROLLBACK TO SAVEPOINT before_timeout'));
            self::assertSame(1, (int) $this->pdo->query('SELECT value FROM `' . $this->permanent . '` WHERE id=1')->fetchColumn());
        } finally {
            if ($blocker->inTransaction()) $blocker->rollBack();
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout=' . $timeout);
        }
    }

    private function isolatedTrackedPdo(string $driver): LoggingPdo
    {
        if ($driver === 'sqlite') {
            $pdo = new LoggingPdo('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION], new \Psr\Log\NullLogger());
        } else {
            $config = Config::load(Bootstrap::rootDir());
            $pdo = new LoggingPdo('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port',3306) . ';dbname=' . $config->get('db.name') . ';charset=utf8mb4', $config->get('db.user'), $config->get('db.pass',''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false], new \Psr\Log\NullLogger());
            self::assertStringContainsString('MariaDB', (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
            self::assertSame((string) getenv('MYINVOICE_DB_NAME'), (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
            self::assertSame(0, $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'"));
        }
        self::assertSame($driver, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        return $pdo;
    }

    #[TestWith(['mysql', false])]
    #[TestWith(['mysql', true])]
    #[TestWith(['sqlite', false])]
    #[TestWith(['sqlite', true])]
    public function testPerStatementClassOverridePermanentlyExpiresExistingAndFutureAuthority(string $driver, bool $prepareBeforeCapture): void
    {
        $pdo = $this->isolatedTrackedPdo($driver);
        self::assertTrue($pdo->beginTransaction());
        try {
            $options = [PDO::ATTR_STATEMENT_CLASS=>[UnobservedSavepointStatement::class]];
            $statement = $prepareBeforeCapture ? $pdo->prepare('RELEASE SAVEPOINT override_live', $options) : null;
            self::assertSame(0, $pdo->exec('SAVEPOINT override_live'));
            $token = null;
            $captureRejectedAfterEarlyOverride = false;
            try {
                $token = TransactionSavepointToken::capture($pdo, 'override_live');
            } catch (\LogicException $error) {
                self::assertSame('Transaction statements are not tracked.', $error->getMessage());
                $captureRejectedAfterEarlyOverride = true;
            }
            $statement ??= $pdo->prepare('RELEASE SAVEPOINT override_live', $options);
            self::assertInstanceOf(UnobservedSavepointStatement::class, $statement);
            self::assertTrue($statement->execute());
            $currentBeforeAnyFollowingSql = $token?->current($pdo) ?? false;
            self::assertTrue($pdo->inTransaction());
            try {
                $pdo->exec('RELEASE SAVEPOINT override_live');
                self::fail('Unobserved statement did not release the actual native marker.');
            } catch (PDOException $error) {
                self::assertSame($driver === 'mysql' ? 1305 : 1, (int) ($error->errorInfo[1] ?? 0));
                if ($driver === 'sqlite') self::assertStringContainsString('no such savepoint', $error->getMessage());
            }
            self::assertSame(0, $pdo->exec('SAVEPOINT future_live'));
            $futureRejected = false;
            try {
                TransactionSavepointToken::capture($pdo, 'future_live');
            } catch (\LogicException $error) {
                self::assertSame('Transaction statements are not tracked.', $error->getMessage());
                $futureRejected = true;
            }
            self::assertSame(0, $pdo->exec('ROLLBACK TO SAVEPOINT future_live'));
            self::assertSame(['current'=>false,'future_capture_rejected'=>true,'capture_rejected_after_early_override'=>$prepareBeforeCapture], ['current'=>$currentBeforeAnyFollowingSql,'future_capture_rejected'=>$futureRejected,'capture_rejected_after_early_override'=>$captureRejectedAfterEarlyOverride]);
        } finally {
            if ($pdo->inTransaction()) self::assertTrue($pdo->rollBack());
        }
    }

    #[TestWith(['mysql'])]
    #[TestWith(['sqlite'])]
    public function testStandardPrepareBeforeCaptureKeepsTrackedAuthority(string $driver): void
    {
        $pdo = $this->isolatedTrackedPdo($driver);
        self::assertTrue($pdo->beginTransaction());
        try {
            $statement = $pdo->prepare('RELEASE SAVEPOINT standard_live');
            self::assertInstanceOf(\MyInvoice\Infrastructure\Database\LoggingPdoStatement::class, $statement);
            self::assertSame(0, $pdo->exec('SAVEPOINT standard_live'));
            $token = TransactionSavepointToken::capture($pdo, 'standard_live');
            self::assertTrue($token->current($pdo));
            self::assertTrue($statement->execute());
            self::assertFalse($token->current($pdo));
            self::assertSame(0, $pdo->exec('SAVEPOINT next_live'));
            self::assertTrue(TransactionSavepointToken::capture($pdo, 'next_live')->current($pdo));
        } finally {
            if ($pdo->inTransaction()) self::assertTrue($pdo->rollBack());
        }
    }
}

final class UnobservedSavepointStatement extends \PDOStatement
{
    protected function __construct() {}
}
