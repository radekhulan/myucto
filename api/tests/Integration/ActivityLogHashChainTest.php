<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\ActivityLogHashChain;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zřetězení auditní stopy hashem — § 33a ZoÚ.
 *
 * Matice účetnictví to vedla mezi vysokými riziky: „`activity_log` je běžná tabulka bez
 * hash-chainu. `JournalIntegrityService` detekuje nekonzistence deníku, ale přepsání
 * samotného logu nikoli."
 *
 * Testy dokazují to podstatné — že zásah je POZNAT. Zvlášť
 * {@see testDeletingRecordBreaksTheChain()}: smazání záznamu je typický způsob, jak
 * auditní stopu „vyčistit", a řetěz musí prasknout i tehdy, když po sobě zásah nenechá
 * žádnou jinou stopu.
 *
 * Co testy NEDOKAZUJÍ a ani dokázat nemohou: odolnost proti útočníkovi, který má právo
 * zápisu a přepočítá celý řetěz znovu. Proti tomu chrání až kotva mimo databázi.
 */
#[Group('integration')]
final class ActivityLogHashChainTest extends TestCase
{
    private Connection $db;
    private ActivityLogger $logger;
    private ActivityLogHashChain $chain;
    private bool $cleanup = false;
    /** Hranice odloženého pečetění před testem; NULL se obnoví jako NULL. */
    private ?int $deferredFrom = null;
    /** Id, od kterého se ověřuje — cizí záznamy v sadě jsou pro tenhle test šum. */
    private int $from = 0;
    private ?\Closure $restorePruneMark = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->logger = $c->get(ActivityLogger::class);
            $this->chain = $c->get(ActivityLogHashChain::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->chain->isAvailable()) {
            $this->markTestSkipped('Migrace 1157 neproběhla.');
        }

        if (!$this->db->hasColumn('activity_log_chain_head', 'deferred_from_id')) {
            $this->markTestSkipped('Migrace 2055 neproběhla.');
        }

        // Pečetí se až po commitu, takže testy zapisují mimo transakci a po sobě uklidí.
        // Do `activity_log` zapisují i jiné testy sady; ověřuje se proto jen úsek od `from`.
        $pdo = $this->db->pdo();
        $this->from = 1 + (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM activity_log')->fetchColumn();
        $deferredFrom = $pdo->query('SELECT deferred_from_id FROM activity_log_chain_head WHERE id = 1')->fetchColumn();
        $this->deferredFrom = $deferredFrom === false || $deferredFrom === null ? null : (int) $deferredFrom;
        $this->cleanup = true;
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($this->restorePruneMark !== null) {
            ($this->restorePruneMark)();
        }
        if ($this->cleanup) {
            $pdo->prepare('DELETE FROM activity_log WHERE id >= ?')->execute([$this->from]);
            $pdo->prepare('UPDATE activity_log_chain_head SET deferred_from_id = ? WHERE id = 1')->execute([$this->deferredFrom]);
        }
        $this->db->close();
    }

    /** Nový záznam dostane hash — bez toho by řetěz nevznikl. */
    public function testNewRecordIsSealed(): void
    {
        $id = $this->write('test.sealed');

        $row = $this->row($id);
        self::assertNotNull($row['hash']);
        self::assertSame(64, strlen((string) $row['hash']), 'SHA-256 v hex má 64 znaků.');
    }

    /** Druhý záznam navazuje na hash prvního. */
    public function testSecondRecordLinksToFirst(): void
    {
        $a = $this->write('test.a');
        $b = $this->write('test.b');

        self::assertSame($this->row($a)['hash'], $this->row($b)['prev_hash']);
    }

    /** Nedotčený řetěz projde ověřením. */
    public function testUntouchedChainVerifies(): void
    {
        $this->write('test.a');
        $this->write('test.b');
        $this->write('test.c');

        self::assertTrue($this->chain->verify($this->from)['ok']);
    }

    /** Změna OBSAHU záznamu se pozná — hash přestane sedět. */
    public function testTamperingWithPayloadIsDetected(): void
    {
        $this->write('test.a');
        $id = $this->write('test.b', ['amount' => 1000]);
        $this->write('test.c');

        $this->db->pdo()->prepare('UPDATE activity_log SET payload = ? WHERE id = ?')
            ->execute(['{"amount":1}', $id]);

        $r = $this->chain->verify($this->from);
        self::assertFalse($r['ok']);
        self::assertContains($id, array_column($r['broken'], 'id'));
        self::assertStringContainsString('byl změněn', $r['broken'][0]['reason']);
    }

    /**
     * SMAZÁNÍ záznamu se pozná. Je to typický způsob, jak auditní stopu „vyčistit" —
     * a po smazaném řádku nezůstane žádná jiná stopa než přerušený řetěz.
     */
    public function testDeletingRecordBreaksTheChain(): void
    {
        $this->write('test.a');
        $id = $this->write('test.b');
        $this->write('test.c');

        $this->db->pdo()->prepare('DELETE FROM activity_log WHERE id = ?')->execute([$id]);

        $r = $this->chain->verify($this->from);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('řetěz přerušen', $r['broken'][0]['reason']);
    }

    /** Změna metadat (kdo/odkud) se pozná stejně jako změna obsahu. */
    public function testChangingActorMetadataIsDetected(): void
    {
        $this->write('test.a');
        $id = $this->write('test.b');

        // `user_id` má FK na `users`, takže se podvrhává `ip` — do hashe vstupuje stejně
        // a jde o typický údaj, který by chtěl někdo zahladit.
        $this->db->pdo()->prepare('UPDATE activity_log SET ip = INET6_ATON(?) WHERE id = ?')
            ->execute(['10.0.0.1', $id]);

        self::assertFalse($this->chain->verify($this->from)['ok']);
    }

    /**
     * Historie před zavedením řetězu se počítá zvlášť a NEVYDÁVÁ se za chráněnou.
     * Zpětný dopočet by nedokázal nic — hash spočtený dnes nad daty, která mohla být
     * kdykoli změněna, dodá jen zdání důvěryhodnosti.
     */
    public function testUnprotectedHistoryIsReportedSeparately(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO activity_log (supplier_id, user_id, action, hash) VALUES (NULL, NULL, 'test.legacy', NULL)"
        )->execute();
        $legacy = (int) $pdo->lastInsertId();
        // Záznam pod hranicí pečetění po commitu = historie před zavedením řetězu.
        $pdo->prepare('UPDATE activity_log_chain_head SET deferred_from_id = ? WHERE id = 1')->execute([$legacy + 1]);
        $this->write('test.new');

        $r = $this->chain->verify($this->from);
        self::assertGreaterThan(0, $r['unprotected']);
        self::assertSame(0, $r['pending']);
        self::assertNull($this->row($legacy)['hash'], 'Historie se zpětně nepečetí.');
        self::assertTrue($r['ok'], 'Chybějící hash u historie není porušení řetězu.');
    }

    /**
     * Záznam zapsaný v transakci dostane hash až po commitu a obchodní transakce mezitím
     * hlavu řetězu nedrží. Dokud ji držela, řadily se za ni všechny auditované zápisy
     * instance a pod snapshot isolation končily chybou 1020.
     */
    public function testRecordWrittenInTransactionIsSealedAfterCommitWithoutHoldingTheHead(): void
    {
        $pdo = $this->db->pdo();
        $other = Connection::withoutSharedTestConnection(static fn (): Connection => Bootstrap::buildContainer()->get(Connection::class));
        try {
            $pdo->beginTransaction();
            $id = $this->write('test.in_tx');
            self::assertNull($this->row($id)['hash'], 'Uvnitř transakce se ještě nepečetí.');

            $otherPdo = $other->pdo();
            $otherPdo->beginTransaction();
            $head = $otherPdo->query('SELECT last_hash FROM activity_log_chain_head WHERE id = 1 FOR UPDATE NOWAIT');
            self::assertNotFalse($head, 'Hlava řetězu není zamčená probíhající obchodní transakcí.');
            $otherPdo->rollBack();

            $pdo->commit();
            self::assertNotNull($this->row($id)['hash'], 'Commit záznam zapečetí.');
            self::assertTrue($this->chain->verify($this->from)['ok']);
        } finally {
            $other->close();
        }
    }

    /** Vrácený záznam v řetězu nezanechá díru ani článek. */
    public function testRolledBackRecordDoesNotBreakTheChain(): void
    {
        $pdo = $this->db->pdo();
        $this->write('test.before');
        $pdo->beginTransaction();
        $this->write('test.rolled_back');
        $pdo->rollBack();
        $this->write('test.after');

        $r = $this->chain->verify($this->from);
        self::assertTrue($r['ok']);
        self::assertSame(0, $r['pending']);
        self::assertSame(2, $r['checked']);
    }

    /**
     * Transakce se commitují v jiném pořadí, než dostaly id. Řetěz se proto řadí podle
     * pořadí zapečetění, ne podle id, a ověření musí projít.
     */
    public function testOutOfOrderCommitsKeepTheChainValid(): void
    {
        $pdo = $this->db->pdo();
        $otherContainer = Connection::withoutSharedTestConnection(static function () {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class);
            return $container;
        });
        $other = $otherContainer->get(Connection::class);
        try {
            $pdo->beginTransaction();
            $earlier = $this->write('test.long_tx');

            $otherContainer->get(ActivityLogger::class)->log('test.short_tx');
            $later = (int) $other->pdo()->query("SELECT MAX(id) FROM activity_log WHERE action = 'test.short_tx'")->fetchColumn();
            self::assertGreaterThan($earlier, $later);
            // Hlavní spojení drží snapshot své transakce, cizí commit čte druhé spojení.
            $laterHash = $other->pdo()->prepare('SELECT hash FROM activity_log WHERE id = ?');
            $laterHash->execute([$later]);
            self::assertNotNull($laterHash->fetchColumn() ?: null, 'Kratší transakce se zapečetí hned po svém commitu.');

            $pdo->commit();
            self::assertSame($this->row($later)['hash'], $this->row($earlier)['prev_hash'], 'Později commitnutý záznam navazuje na dříve zapečetěný.');
            $r = $this->chain->verify($this->from);
            self::assertTrue($r['ok'], (string) json_encode($r['broken'], JSON_UNESCAPED_UNICODE));
            self::assertSame(2, $r['checked']);
        } finally {
            $other->close();
        }
    }

    /**
     * Záznam vložený mimo logger (cron zapisuje přímým INSERTem) nad hranicí čeká na
     * zapečetění: ověření ho hlásí zvlášť, ne jako porušení, a další pečetění ho doplní.
     */
    public function testDirectInsertIsPendingUntilNextSeal(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('test.cron', NULL)")->execute();
        $id = (int) $pdo->lastInsertId();

        $before = $this->chain->verify($this->from);
        self::assertTrue($before['ok']);
        self::assertSame(1, $before['pending']);
        self::assertSame(0, $before['unprotected']);

        self::assertSame(1, $this->chain->sealPending());
        self::assertNotNull($this->row($id)['hash']);
        $after = $this->chain->verify($this->from);
        self::assertTrue($after['ok']);
        self::assertSame(0, $after['pending']);
    }

    /**
     * Hlava řetězu (migrace 1171) musí po zapečetění ukazovat na POSLEDNÍ článek.
     *
     * Hlava existuje proto, aby se zamykal jeden známý řádek místo skenování rozsahu —
     * ten bral gap locky a souběžné transakce se zaklesly (naměřeno: SQLSTATE 40001,
     * chyba 1213). Když se ale hlava rozejde s řetězem, další článek naváže na špatný
     * `prev_hash` a ověření nahlásí porušení, které nenastalo.
     */
    public function testChainHeadFollowsTheLastSealedRecord(): void
    {
        if (!$this->db->hasColumn('activity_log_chain_head', 'last_hash')) {
            self::markTestSkipped('Migrace 1171 neproběhla.');
        }

        $this->write('test.a');
        $last = $this->write('test.b');

        $head = $this->db->pdo()->query('SELECT last_id, last_hash FROM activity_log_chain_head WHERE id = 1')
            ->fetch(\PDO::FETCH_ASSOC);

        self::assertSame($last, (int) $head['last_id'], 'Hlava ukazuje na poslední článek.');
        self::assertSame($this->row($last)['hash'], $head['last_hash'], 'A nese jeho hash.');
        self::assertTrue($this->chain->verify($this->from)['ok']);
    }

    /**
     * Schválený výmaz osobních údajů (#321, krok 10) přepíše payload auditu oprav člena.
     * Uložený hash zůstane, řetěz dál navazuje a ověření přijme redigovaný obsah jen s otiskem redakce.
     */
    public function testRedactionKeepsChainVerifiable(): void
    {
        $this->write('test.a');
        $id = $this->write('nonprofit_membership_member_update', ['before' => ['member_number' => 'SYN-1', 'client_id' => 5]]);
        $this->write('test.c');
        $hash = $this->row($id)['hash'];
        self::assertNotNull($hash);

        $this->redactInTransaction($id, ['before' => ['member_number' => '[vymazáno]', 'client_id' => null]]);

        self::assertSame($hash, $this->row($id)['hash'], 'Hash redigovaného záznamu se nemění.');
        $attestation = $this->db->pdo()->prepare('SELECT hash FROM activity_log WHERE action = ? AND entity_id = ? AND id >= ?');
        $attestation->execute([ActivityLogHashChain::REDACTION_ACTION, $id, $this->from]);
        self::assertNotNull($attestation->fetchColumn(), 'Atestace redakce se zapečetí hned po commitu, je článkem řetězu.');
        self::assertTrue($this->chain->verify($this->from)['ok'], 'Redigovaný záznam s otiskem redakce řetěz neporuší.');
        self::assertStringNotContainsString('SYN-1', (string) $this->payload($id));

        // Další změna redigovaného obsahu se pozná stejně jako u neredigovaného záznamu.
        $this->db->pdo()->prepare('UPDATE activity_log SET payload = ? WHERE id = ?')->execute(['{"before":{"member_number":"SYN-1"}}', $id]);
        $r = $this->chain->verify($this->from);
        self::assertFalse($r['ok']);
        self::assertContains($id, array_column($r['broken'], 'id'));
    }

    public function testRedactionIsLimitedToAllowlistedActionsAndProvenRecords(): void
    {
        $other = $this->write('invoice.sent', ['to' => 'synthetic@example.test']);
        try {
            $this->redactInTransaction($other, []);
            self::fail('Redakce mimo allowlist prošla.');
        } catch (\LogicException) {
        }
        $tampered = $this->write('nonprofit_membership_revision_void', ['reason' => 'Synthetic reason']);
        $this->db->pdo()->prepare('UPDATE activity_log SET payload = ? WHERE id = ?')->execute(['{"reason":"Changed"}', $tampered]);
        try {
            $this->redactInTransaction($tampered, ['reason' => '[vymazáno]']);
            self::fail('Redakce zakryla dřívější zásah do záznamu.');
        } catch (\RuntimeException) {
        }
        self::assertSame('{"reason":"Changed"}', $this->payload($tampered), 'Odmítnutá redakce nic nezměnila.');
    }

    private function redactInTransaction(int $id, array $payload): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->chain->redact($id, $payload, 'test', 1);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function payload(int $id): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT payload FROM activity_log WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    /**
     * Redakce bez zřetězené atestace, nebo u akce mimo allowlist, verify() neprojde, i když otisk
     * redakce sedí. Jinak by stačil jeden UPDATE a jeden INSERT a zásah do minulosti by zmizel.
     */
    public function testForgedRedactionWithoutAttestationOrOutsideAllowlistIsDetected(): void
    {
        // Řádek s id nižším než redigovaný (zapečetí ho další zápis); jeho id později převezme atestace.
        // Jeho smazání přeruší řetěz u následujícího článku; test proto kontroluje jen redigovaný záznam.
        $this->db->pdo()->prepare("INSERT INTO activity_log (action, payload) VALUES ('test.placeholder', NULL)")->execute();
        $placeholder = (int) $this->db->pdo()->lastInsertId();
        $forged = $this->write('nonprofit_membership_member_update', ['member_number' => 'SYN-2']);
        $other = $this->write('invoice.sent', ['to' => 'synthetic@example.test']);
        $this->write('test.c');
        $pdo = $this->db->pdo();
        foreach ([$forged, $other] as $id) {
            $pdo->prepare('UPDATE activity_log SET payload = ? WHERE id = ?')->execute(['{"changed":true}', $id]);
            $digest = $this->redactionDigestOf($id);
            $pdo->prepare("INSERT INTO activity_log_redactions (activity_log_id, supplier_id, redacted_digest, source, source_id) VALUES (?, NULL, ?, 'test', 1)")
                ->execute([$id, $digest]);
        }
        // Atestace jen pro záznam mimo allowlist (a zapečetěná jako běžný článek).
        $this->logger->log(ActivityLogHashChain::REDACTION_ACTION, null, 'activity_log', $other, ['digest' => $this->redactionDigestOf($other)]);
        $broken = array_column($this->chain->verify($this->from)['broken'], 'id');
        self::assertContains($forged, $broken, 'Redakce bez atestace.');
        self::assertContains($other, $broken, 'Redakce akce mimo allowlist.');

        // Nezapečetěná atestace v mezeře pod hranicí odloženého pečetění (po promazání) se neuznává.
        $pdo->prepare("INSERT INTO activity_log (action, entity_type, entity_id, payload) VALUES (?, 'activity_log', ?, ?)")
            ->execute([ActivityLogHashChain::REDACTION_ACTION, $forged, json_encode(['digest' => $this->redactionDigestOf($forged)])]);
        $unsealed = (int) $pdo->lastInsertId();
        // V okně odloženého pečetění se nezapečetěná atestace uzná (dopečetí ji další zápis)...
        self::assertNotContains($forged, array_column($this->chain->verify($this->from)['broken'], 'id'));
        // ...ale jen za redigovaným záznamem: atestace s nižším id (zapsaná „předem“) neplatí.
        $pdo->prepare('DELETE FROM activity_log WHERE id = ?')->execute([$placeholder]);
        $pdo->prepare('UPDATE activity_log SET id = ? WHERE id = ?')->execute([$placeholder, $unsealed]);
        self::assertContains($forged, array_column($this->chain->verify($this->from)['broken'], 'id'), 'Atestace před záznamem.');
        $pdo->prepare('UPDATE activity_log SET id = ? WHERE id = ?')->execute([$unsealed, $placeholder]);
        // ...pod hranicí okna (mezera po promazání) ne; tearDown hranici vrací.
        $pdo->prepare('UPDATE activity_log_chain_head SET deferred_from_id = ? WHERE id = 1')->execute([$unsealed + 1]);
        self::assertContains($forged, array_column($this->chain->verify($this->from)['broken'], 'id'), 'Atestace mimo řetěz.');
    }

    /** Redakce dosud nezapečetěného záznamu: zapečetí se nad novým obsahem a řetěz projde. */
    public function testRedactionOfUnsealedRecordIsSealedOverTheNewContent(): void
    {
        $this->write('test.a');
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('nonprofit_membership_revision_void', ?)")->execute(['{"reason":"Synthetic reason"}']);
        $id = (int) $pdo->lastInsertId();
        self::assertNull($this->row($id)['hash'] ?? null);
        $this->redactInTransaction($id, ['reason' => '[vymazáno]']);
        $this->chain->sealPending();
        self::assertNotNull($this->row($id)['hash']);
        self::assertTrue($this->chain->verify($this->from)['ok']);
        self::assertSame('{"reason":"[vymazáno]"}', $this->payload($id));
    }

    /**
     * Souběh s odloženým pečetěním: pečetění během nedokončené redakce nesmí zapečetit původní obsah
     * (verify() by pak hlásil podvržený záznam) ani na redakci čekat; řádek přeskočí a zapečetí ho až
     * další běh nad redigovaným obsahem.
     */
    public function testSealingSkipsRecordUnderRedactionAndSealsItsRedactedContent(): void
    {
        $this->write('test.a');
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('client.updated', ?)")->execute(['{"name":"Synthetic Person"}']);
        $id = (int) $pdo->lastInsertId();
        $pdo->beginTransaction();
        $this->chain->redact($id, ['name' => '[vymazáno]'], 'test', 1);

        $base = tempnam(sys_get_temp_dir(), 'seal');
        $script = $base . '.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . ' \MyInvoice\Bootstrap::buildContainer()->get(\MyInvoice\Service\ActivityLogHashChain::class)->sealPending();');
        $env = getenv();
        $env['MYINVOICE_DB_NAME'] = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $pipes = [];
        $child = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        $exit = null;
        $output = '';
        try {
            self::assertIsResource($child);
            // Výstup potomka se čte průběžně: plná roura by ho zablokovala a příčina pádu by se ztratila.
            foreach ([1, 2] as $stream) stream_set_blocking($pipes[$stream], false);
            // Čekání jen na zámek, který drží tato transakce redakce (sdílený server může mít jiná čekání).
            $waits = $pdo->prepare('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w
                JOIN information_schema.INNODB_TRX blocker ON blocker.trx_id = w.blocking_trx_id
                WHERE blocker.trx_mysql_thread_id = CONNECTION_ID()');
            $waited = false;
            for ($i = 0; $i < 600; $i++) {
                foreach ([1, 2] as $stream) $output .= (string) stream_get_contents($pipes[$stream]);
                $status = proc_get_status($child);
                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                $waits->execute();
                if ((int) $waits->fetchColumn() > 0) {
                    $waited = true;
                    break;
                }
                usleep(50_000);
            }
            self::assertTrue($waited || $exit !== null, 'Pečetění ve druhém spojení nedoběhlo ani nečekalo: ' . $output);
            $pdo->commit();
            foreach ([1, 2] as $stream) {
                stream_set_blocking($pipes[$stream], true);
                $output .= (string) stream_get_contents($pipes[$stream]);
            }
            if ($exit === null) $exit = proc_close($child);
            else proc_close($child);
            $child = null;
            self::assertFalse($waited, 'Pečetění čekalo na nedokončenou redakci místo přeskočení řádku.');
            self::assertSame(0, $exit, $output);
        } finally {
            if (is_resource($child)) {
                proc_terminate($child);
                proc_close($child);
            }
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            @unlink($script);
            @unlink($base);
        }
        // Commit redakce záznam zapečetí sám (háček po commitu); jinak ho dopečetí další běh.
        $this->chain->sealPending();
        self::assertNotNull($this->row($id)['hash']);
        self::assertSame('{"name":"[vymazáno]"}', $this->payload($id));
        self::assertTrue($this->chain->verify($this->from)['ok']);
    }

    private function redactionDigestOf(int $id): string
    {
        $method = new \ReflectionMethod(ActivityLogHashChain::class, 'redactionDigest');
        $select = new \ReflectionMethod(ActivityLogHashChain::class, 'selectList');
        $row = $this->db->pdo()->query('SELECT ' . $select->invoke(null) . ', prev_hash, hash FROM activity_log WHERE id = ' . $id)->fetch(\PDO::FETCH_ASSOC);
        $data = array_diff_key($row, ['prev_hash' => 1, 'hash' => 1]);
        return $method->invoke(null, (string) $row['hash'], $data, $row['prev_hash']);
    }

    /**
     * Zapíše záznam a vrátí jeho id.
     *
     * `lastInsertId()` se použít NEDÁ: zapečetění uvnitř `log()` provede UPDATE, po kterém
     * už hodnota neodpovídá vloženému řádku. Id se proto dohledá podle akce.
     */
    private function write(string $action, ?array $payload = null): int
    {
        $this->logger->log($action, null, null, null, $payload);

        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM activity_log WHERE action = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$action]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Tolerance historického renderu má hranici.
     *
     * Články zapečetěné starým kódem se ověřují i podle lokálního renderu
     * `created_at`. Kdyby ta výjimka platila napořád, prošel by ověřením i posun
     * `created_at` přesně o offset zóny — obě podoby popisují týž okamžik. Watermark
     * `activity_log_chain_head.utc_created_at_from_id` (migrace 1528) proto od svého
     * id výš uznává jediný, kanonický hash.
     */
    public function testShiftingCreatedAtOfNewRecordIsDetected(): void
    {
        if (!$this->db->hasColumn('activity_log_chain_head', 'utc_created_at_from_id')) {
            $this->markTestSkipped('Migrace 1528 neproběhla.');
        }

        $id = $this->write('test.watermark');

        // Posun ZPĚT přesně o offset aplikační zóny. Směr je podstatný: hash byl
        // spočten nad UTC hodnotou U, a historický kandidát se počítá jako
        // local(načtená hodnota). Po posunu na U−offset dá local(U−offset) zase U,
        // takže bez watermarku by falzifikát prošel jako neporušený. Posun dopředu
        // se pozná i bez něj — proto by test s opačným znaménkem nedokazoval nic.
        $offsetSeconds = (new \DateTimeZone(date_default_timezone_get()))
            ->getOffset(new \DateTimeImmutable());
        self::assertGreaterThan(0, $offsetSeconds, 'Test dává smysl jen v zóně s nenulovým offsetem.');

        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE activity_log SET created_at = created_at - INTERVAL ? SECOND WHERE id = ?')
            ->execute([$offsetSeconds, $id]);

        $result = $this->chain->verify($this->from);
        self::assertFalse($result['ok'], 'Posun created_at u nového článku musí být poznat.');
        self::assertContains($id, array_column($result['broken'], 'id'));
    }

    /**
     * Ověření nesmí záviset na zóně spojení.
     *
     * `created_at` je TIMESTAMP, takže ho MariaDB renderuje podle zóny session. Dokud
     * do hashe vstupovala ta renderovaná hodnota, stačil přechod na zimní čas (nebo
     * cron běžící v UTC) a `verify()` označil VŠECHNY letní záznamy za pozměněné —
     * auditní stopa tvrdila, že s ní někdo manipuloval. Test to simuluje tvrdě:
     * po zapečetění přepne zónu session a ověřuje znovu.
     */
    public function testVerificationSurvivesSessionTimeZoneChange(): void
    {
        $this->write('test.tz.a');
        $this->write('test.tz.b');

        $pdo = $this->db->pdo();
        $original = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();

        // PDO se mezi testy SDÍLÍ a resetSharedTestSessions() zónu nevrací. Bez
        // `finally` by selhaná asserce nechala celý proces běžet na -08:00 a shodila
        // by datumové testy úplně jinde.
        try {
            foreach (["+00:00", "+05:45", "-08:00"] as $zone) {
                $pdo->exec("SET time_zone = '{$zone}'");
                self::assertTrue(
                    $this->chain->verify($this->from)['ok'],
                    "Řetěz musí projít i v session zóně {$zone}.",
                );
            }
        } finally {
            $pdo->exec("SET time_zone = '{$original}'");
        }
    }

    /**
     * Retence spravovaného provozu smaže starý začátek stopy. Ponechaný zbytek musí
     * projít ověřením, i když mezi smazanými zůstal záznam, ze kterého aplikace čte stav.
     */
    public function testPruneKeepsRetainedActionsAndTheRestVerifies(): void
    {
        $this->keepPruneMark();
        $old = [];
        foreach (['test.old.a', 'accounting.description_edited', 'test.old.b'] as $action) {
            $old[$action] = $this->insertOld($action);
        }
        $this->chain->sealPending();
        $fresh = $this->write('test.fresh.a');
        $this->write('test.fresh.b');

        self::assertSame(2, $this->chain->prune(14, $this->from));

        self::assertSame([], $this->row($old['test.old.a']));
        self::assertSame([], $this->row($old['test.old.b']));
        self::assertNotSame([], $this->row($old['accounting.description_edited']), 'Stavová akce zůstává.');
        self::assertNotSame([], $this->row($fresh));
        self::assertNotNull($this->chain->prunedBefore());
        $r = $this->chain->verify($this->from);
        self::assertTrue($r['ok'], (string) json_encode($r['broken'], JSON_UNESCAPED_UNICODE));
    }

    /** Za hranicí promazání se smazání záznamu pozná dál. */
    public function testDeletingRecordAfterPruneStillBreaksTheChain(): void
    {
        $this->keepPruneMark();
        $this->insertOld('test.old');
        $this->chain->sealPending();
        $this->write('test.a');
        $id = $this->write('test.b');
        $this->write('test.c');
        self::assertSame(1, $this->chain->prune(14, $this->from));

        $this->db->pdo()->prepare('DELETE FROM activity_log WHERE id = ?')->execute([$id]);

        $r = $this->chain->verify($this->from);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('řetěz přerušen', $r['broken'][0]['reason']);
    }

    /** Záznam se starým časem vložený přímo, čeká na zapečetění. */
    private function insertOld(string $action): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO activity_log (action, created_at) VALUES (?, NOW() - INTERVAL 30 DAY)")
            ->execute([$action]);

        return (int) $pdo->lastInsertId();
    }

    /** Hranice promazání je globální, po testu se vrátí. */
    private function keepPruneMark(): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT v FROM app_meta WHERE k = 'activity_log_pruned'");
        $stmt->execute();
        $previous = $stmt->fetchColumn();
        $this->restorePruneMark = static function () use ($pdo, $previous): void {
            if ($previous === false) {
                $pdo->exec("DELETE FROM app_meta WHERE k = 'activity_log_pruned'");
            } else {
                $pdo->prepare("UPDATE app_meta SET v = ? WHERE k = 'activity_log_pruned'")->execute([$previous]);
            }
        };
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT prev_hash, hash FROM activity_log WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
}
