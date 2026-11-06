<?php

declare(strict_types=1);

namespace MyInvoice\Service;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\LoggingPdo;
use MyInvoice\Infrastructure\Database\TransactionFailure;

/**
 * Zřetězení auditní stopy hashem — § 33a ZoÚ.
 *
 * `activity_log` byla běžná tabulka: `JournalIntegrityService` sice hlídá nekonzistence
 * deníku, ale přepsání SAMOTNÉHO LOGU nezjistí. Každý nový záznam proto nese hash svého
 * obsahu zřetězený s hashem předchozího; změna nebo smazání kteréhokoli záznamu tím
 * rozbije všechny následující.
 *
 * ── Hranice toho, co to dokáže ──────────────────────────────────────────────────────
 * Nechrání to před útočníkem, který má právo zápisu a přepočítá celý řetěz znovu. Proti
 * tomu pomáhá až kotva mimo databázi (pravidelný export otisku, podepsaná záloha). Řetěz
 * dělá zásah PROKAZATELNÝM, ne nemožným — a tvrdit víc by bylo horší než netvrdit nic.
 *
 * ── Proč se historie nedopočítává ───────────────────────────────────────────────────
 * Záznamy z doby před zavedením řetězu zůstávají bez hashe. Dopočítat je zpětně by
 * vytvořilo řetěz, který nedokazuje nic — hash spočtený dnes nad daty, která mohla být
 * kdykoli změněna, jen dodává zdání důvěryhodnosti. {@see verify()} proto historii
 * výslovně odlišuje od chráněné části.
 *
 * ── Proč se pečetí až po commitu ────────────────────────────────────────────────────
 * Záznam vzniká v téže transakci jako zapisovaná mutace a s ní i zaniká, takže stopa
 * nemá díru u provedené změny ani článek u změny vrácené. Hlava řetězu je ale jeden
 * řádek pro celou instanci: dokud se zamykala uvnitř obchodní transakce, řadily se za
 * ni všechny auditované zápisy instance a pod REPEATABLE READ se snapshot isolation
 * končily chybou 1020 (vystavené faktury zůstávaly nezaúčtované). Hash proto doplní až
 * krátká samostatná transakce po commitu ({@see sealPending()}); pořadí řetězu určuje
 * `seal_seq`, ne `id`. Mezi commitem a zapečetěním je záznam bez hashe — normálně
 * milisekundy, po pádu procesu do dalšího pečetění (kterýkoli další zápis, cron).
 *
 * ── Retence ve spravovaném provozu ──────────────────────────────────────────────────
 * Na spravované instanci se stopa drží jen omezeně dlouho ({@see prune()}). Smazaný
 * začátek řetězu tím přestává být prokazatelný: `verify()` od hranice promazání
 * nekontroluje návaznost na předchozí článek, obsah ponechaných záznamů ano.
 */
final class ActivityLogHashChain
{
    /** Sloupce vstupující do hashe. Pořadí je součást definice — změna rozbije řetěz. */
    private const HASHED_COLUMNS = [
        'id', 'supplier_id', 'user_id', 'action', 'entity_type', 'entity_id',
        'payload', 'ip', 'user_agent', 'created_at',
    ];

    /**
     * Akce, které retence nemaže. Nejsou jen historií: aplikace z nich čte stav
     * (ručně upravený popis zápisu, automatické zaúčtování, ruční změna úhrady,
     * storno, přeúčtování, kurzový rozdíl převodu) a odeslané e-maily jsou doklad
     * o doručení dokladu. Smazáním by se změnilo chování, ne jen délka historie.
     */
    public const RETAINED_ACTIONS = [
        'accounting.auto_posted',
        'bank_match.auto_posted',
        'accounting.description_edited',
        'accounting.reposted',
        'accounting.reversed',
        'purchase_invoice.transitioned',
        'bank_transfer.fx_residual',
        'invoice.sent',
        'invoice.send_failed',
        'invoice.reminder_sent',
        'invoice.reminder_failed',
        'invoice.approval_reminder_sent',
        'invoice.approval_reminder_failed',
        'invoice.payment_thanks_sent',
        'invoice.payment_thanks_failed',
        'recurring.reminder_sent',
        'recurring.reminder_failed',
    ];

    /** Klíč `app_meta` s hranicí promazané části řetězu. */
    private const PRUNE_META_KEY = 'activity_log_pruned';

    /** Kolik záznamů zapečetí jedna krátká transakce. */
    private const SEAL_BATCH = 500;

    /** Kolik záznamů smaže jedna transakce retence. */
    private const PRUNE_BATCH = 2000;

    /**
     * `created_at` je TIMESTAMP, takže ho MariaDB renderuje podle zóny SESSION —
     * táž řádka vrátí jiný řetězec, jakmile se zóna zapečeťujícího a ověřujícího
     * spojení liší. To se dělo: `Connection` připínala session na PEVNÝ offset
     * (`date('P')`), takže po přechodu na zimní čas se všechny letní záznamy
     * renderovaly o hodinu jinak a `verify()` je hlásil jako pozměněné — auditní
     * stopa tvrdila, že s ní někdo manipuloval. Do hashe proto vstupuje hodnota
     * převedená do UTC, která je na zóně spojení nezávislá.
     *
     * `COALESCE` je pojistka pro session se zónou `SYSTEM`, nad kterou `CONVERT_TZ`
     * vrací NULL. Bez ní by se do hashe dostala prázdná hodnota — tedy stejný otisk
     * pro záznamy s různým časem.
     */
    private const CREATED_AT_UTC_SQL =
        "DATE_FORMAT(COALESCE(CONVERT_TZ(created_at, @@session.time_zone, '+00:00'), created_at), "
        . "'%Y-%m-%d %H:%i:%s') AS created_at";

    private ?int $watermark = null;
    private bool $watermarkResolved = false;
    private bool $sealing = false;
    private bool $shutdownSweep = false;

    public function __construct(private readonly Connection $db) {}

    /**
     * Seznam sloupců do SELECTu — `created_at` kanonizovaný do UTC, ostatní tak, jak jsou.
     */
    private static function selectList(): string
    {
        $columns = [];
        foreach (self::HASHED_COLUMNS as $col) {
            $columns[] = $col === 'created_at' ? self::CREATED_AT_UTC_SQL : $col;
        }

        return implode(', ', $columns);
    }

    public function isAvailable(): bool
    {
        return $this->db->hasColumn('activity_log', 'hash');
    }

    /** Pečetí se po commitu (migrace 2055)? */
    private function isDeferred(): bool
    {
        return $this->db->hasColumn('activity_log', 'seal_seq')
            && $this->db->hasColumn('activity_log_chain_head', 'deferred_from_id');
    }

    /**
     * Zapečetí čerstvě vložený záznam: mimo transakci hned, v transakci až po jejím
     * commitu. Hlavu řetězu obchodní transakce nezamyká, takže souběžné zápisy na ni
     * nečekají a pod snapshot isolation nekončí chybou 1020.
     */
    public function seal(int $id): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        if (!$this->isDeferred()) {
            $this->sealInTransaction($id);
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo instanceof LoggingPdo) {
            $pdo->afterCommit(self::class, function (): void {
                $this->sealPending();
            });
            return;
        }
        if (!$pdo->inTransaction()) {
            $this->sealPending();
            return;
        }
        // Spojení bez háčku po commitu: dopečetí se na konci procesu, jinak dalším zápisem.
        if (!$this->shutdownSweep) {
            $this->shutdownSweep = true;
            register_shutdown_function(function (): void {
                $this->sealPending();
            });
        }
    }

    /**
     * Zapečetí všechny commitnuté záznamy bez hashe od hranice `deferred_from_id`,
     * v pořadí id, každou dávku ve vlastní krátké transakci pod zámkem hlavy.
     *
     * READ COMMITTED: zámek hlavy čte poslední commitnutou verzi (pod REPEATABLE READ
     * by tu vznikla táž 1020) a nezacommitované záznamy cizích transakcí nejsou vidět,
     * zapečetí je až jejich vlastní commit. Uvnitř cizí transakce nedělá nic.
     *
     * @return int počet zapečetěných záznamů
     */
    public function sealPending(): int
    {
        if ($this->sealing || !$this->isAvailable() || !$this->isDeferred()) {
            return 0;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return 0;
        }

        $this->sealing = true;
        try {
            $sealed = 0;
            do {
                $batch = TransactionFailure::retrying($pdo, fn (): int => $this->sealBatch($pdo));
                $sealed += $batch;
            } while ($batch === self::SEAL_BATCH);

            return $sealed;
        } finally {
            $this->sealing = false;
        }
    }

    private function sealBatch(\PDO $pdo): int
    {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $head = $pdo->query(
                'SELECT last_hash, last_seq, deferred_from_id FROM activity_log_chain_head WHERE id = 1 FOR UPDATE'
            )->fetch(\PDO::FETCH_ASSOC);
            if ($head === false || $head['deferred_from_id'] === null) {
                $pdo->commit();
                return 0;
            }

            $rows = $pdo->prepare(
                'SELECT ' . self::selectList() . ' FROM activity_log
                  WHERE hash IS NULL AND id >= ? ORDER BY id LIMIT ' . self::SEAL_BATCH
            );
            $rows->execute([(int) $head['deferred_from_id']]);

            $prevHash = $head['last_hash'] === null ? null : (string) $head['last_hash'];
            $seq = (int) $head['last_seq'];
            $lastId = null;
            $count = 0;
            $update = $pdo->prepare('UPDATE activity_log SET prev_hash = ?, hash = ?, seal_seq = ? WHERE id = ? AND hash IS NULL');
            foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $hash = self::hashOf($row, $prevHash);
                $update->execute([$prevHash, $hash, ++$seq, (int) $row['id']]);
                $prevHash = $hash;
                $lastId = (int) $row['id'];
                $count++;
            }
            if ($count > 0) {
                $pdo->prepare('UPDATE activity_log_chain_head SET last_id = ?, last_hash = ?, last_seq = ? WHERE id = 1')
                    ->execute([$lastId, $prevHash, $seq]);
            }
            $pdo->commit();

            return $count;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Pečetění uvnitř zapisující transakce — instalace bez migrace 2055.
     *
     * Předchozí článek se čte pod zámkem: bez něj by dvě souběžné transakce přečetly týž
     * `prev_hash` a řetěz by se rozvětvil. Zamyká se jeden známý řádek hlavy řetězu
     * (`activity_log_chain_head`, migrace 1171), ne poslední záznam přes
     * `ORDER BY id DESC LIMIT 1`: ten dotaz bral gap locky a končil deadlockem 1213.
     */
    private function sealInTransaction(int $id): void
    {
        $pdo = $this->db->pdo();
        $inTx = $pdo->inTransaction();
        $useHead = $this->db->hasColumn('activity_log_chain_head', 'last_hash');

        if ($useHead) {
            $prevStmt = $pdo->prepare(
                'SELECT last_hash FROM activity_log_chain_head WHERE id = 1'
                . ($inTx ? ' FOR UPDATE' : '')
            );
            $prevStmt->execute();
        } else {
            // Fallback pro instalaci bez migrace 1171 — chová se jako dřív, včetně
            // rizika deadlocku. Tiše NEPŘESKAKUJE: bez `prev_hash` by se řetěz přerušil.
            $prevStmt = $pdo->prepare(
                'SELECT hash FROM activity_log
                  WHERE id < ? AND hash IS NOT NULL
               ORDER BY id DESC LIMIT 1' . ($inTx ? ' FOR UPDATE' : '')
            );
            $prevStmt->execute([$id]);
        }
        $prev = $prevStmt->fetchColumn();
        $prevHash = $prev === false || $prev === null ? null : (string) $prev;

        $rowStmt = $pdo->prepare(
            'SELECT ' . self::selectList() . ' FROM activity_log WHERE id = ?'
        );
        $rowStmt->execute([$id]);
        $row = $rowStmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }

        $hash = self::hashOf($row, $prevHash);
        $pdo->prepare('UPDATE activity_log SET prev_hash = ?, hash = ? WHERE id = ?')
            ->execute([$prevHash, $hash, $id]);

        if ($useHead) {
            // Posun hlavy je součástí TÉŽE transakce jako zapečetění — kdyby se rozešly,
            // ukazovala by hlava na článek, který po rollbacku neexistuje.
            $pdo->prepare('UPDATE activity_log_chain_head SET last_id = ?, last_hash = ? WHERE id = 1')
                ->execute([$id, $hash]);
        }
    }

    /**
     * Ověří zapečetěný řetěz v pořadí zapečetění.
     *
     * `$fromId` omezí ověření na úsek od daného záznamu. Používá se v testech, kde do
     * `activity_log` zapisují i jiné testy v transakcích, které se rollbacknou — článek,
     * na který mezitím navázal jiný, pak zmizí a globální ověření by hlásilo porušení
     * tam, kde v reálném provozu žádné není. Produkční ověření běží bez omezení.
     *
     * `pending` = commitnuté záznamy, které na zapečetění teprve čekají (nad hranicí
     * `deferred_from_id`); nejsou porušením ani nechráněnou historií.
     *
     * @return array{
     *   checked:int, unprotected:int, pending:int, first_protected_id:?int,
     *   broken:list<array{id:int, reason:string}>, ok:bool
     * }
     */
    public function verify(?int $fromId = null): array
    {
        if (!$this->isAvailable()) {
            return ['checked' => 0, 'unprotected' => 0, 'pending' => 0, 'first_protected_id' => null, 'broken' => [], 'ok' => true];
        }
        $pdo = $this->db->pdo();
        $deferred = $this->isDeferred();

        $where = $fromId === null ? '' : ' AND id >= ' . (int) $fromId;
        $deferredFrom = $deferred
            ? $pdo->query('SELECT deferred_from_id FROM activity_log_chain_head WHERE id = 1')->fetchColumn()
            : false;
        $deferredFrom = $deferredFrom === false || $deferredFrom === null ? null : (int) $deferredFrom;
        $unprotectedWhere = $deferredFrom === null ? '' : ' AND id < ' . $deferredFrom;
        $unprotected = (int) $pdo->query('SELECT COUNT(*) FROM activity_log WHERE hash IS NULL' . $where . $unprotectedWhere)->fetchColumn();
        $pending = $deferredFrom === null
            ? 0
            : (int) $pdo->query('SELECT COUNT(*) FROM activity_log WHERE hash IS NULL AND id >= ' . $deferredFrom . $where)->fetchColumn();

        $chainWhere = $where;
        if ($deferred && $fromId !== null) {
            $fromSeq = $pdo->query('SELECT seal_seq FROM activity_log WHERE id = ' . (int) $fromId)->fetchColumn();
            if ($fromSeq !== false && $fromSeq !== null) {
                $chainWhere = ' AND seal_seq >= ' . (int) $fromSeq;
            }
        }
        $stmt = $pdo->query(
            'SELECT ' . self::selectList() . ', prev_hash, hash' . ($deferred ? ', seal_seq' : '') . '
               FROM activity_log WHERE hash IS NOT NULL' . $chainWhere
            . ($deferred ? ' ORDER BY seal_seq, id' : ' ORDER BY id')
        );

        $prunedBelow = $deferred ? ($this->pruneMark()['below_seq'] ?? null) : null;
        $pastPruned = false;
        $checked = 0;
        $firstId = null;
        $broken = [];
        $expectedPrev = null;

        foreach ($stmt as $row) {
            $id = (int) $row['id'];
            $firstId ??= $id;
            $checked++;

            // V promazané části a u prvního článku za ní předchůdce chybí záměrně.
            $linkKnown = true;
            if ($prunedBelow !== null && $row['seal_seq'] !== null) {
                if ((int) $row['seal_seq'] < $prunedBelow) {
                    $linkKnown = false;
                } elseif (!$pastPruned) {
                    $pastPruned = true;
                    $linkKnown = false;
                }
            }

            // Prvnímu článku se předchozí hash neověřuje — žádný nemá.
            if ($checked > 1 && $linkKnown && (string) ($row['prev_hash'] ?? '') !== (string) $expectedPrev) {
                $broken[] = ['id' => $id, 'reason' => 'navazuje na jiný záznam, než na který má (řetěz přerušen)'];
            }

            $data = array_intersect_key($row, array_flip(self::HASHED_COLUMNS));
            $prevOfRow = $row['prev_hash'] !== null ? (string) $row['prev_hash'] : null;
            if (!in_array((string) $row['hash'], $this->hashCandidates($data, $prevOfRow), true)) {
                $broken[] = ['id' => $id, 'reason' => 'obsah záznamu neodpovídá jeho hashi (byl změněn)'];
            }

            $expectedPrev = (string) $row['hash'];
        }

        return [
            'checked' => $checked,
            'unprotected' => $unprotected,
            'pending' => $pending,
            'first_protected_id' => $firstId,
            'broken' => $broken,
            'ok' => $broken === [],
        ];
    }

    /**
     * Smaže záznamy starší než `$days` dní kromě {@see self::RETAINED_ACTIONS}.
     *
     * Jen pro spravovaný provoz (`cron-cleanup.php`). Nezapečetěné záznamy nad hranicí
     * `deferred_from_id` nechává, na článek v řetězu teprve čekají. Hranici promazání
     * zapisuje do `app_meta` v téže transakci jako mazání, takže `verify()` nikdy
     * nevidí díru bez hranice.
     *
     * `$fromId` omezí úklid na záznamy od daného id (testy, stejně jako u `verify()`).
     *
     * @return int počet smazaných záznamů
     */
    public function prune(int $days, int $fromId = 0): int
    {
        if (!$this->isAvailable() || !$this->isDeferred() || !$this->db->hasTable('app_meta')) {
            return 0;
        }
        $pdo = $this->db->pdo();
        $before = (string) $pdo->query('SELECT NOW() - INTERVAL ' . max(1, $days) . ' DAY')->fetchColumn();
        $lastOld = $this->lastIdCreatedBefore($pdo, $before, $fromId);
        if ($lastOld === null) {
            return 0;
        }
        $deferredFrom = $pdo->query('SELECT deferred_from_id FROM activity_log_chain_head WHERE id = 1')->fetchColumn();
        $unsealedBelow = $deferredFrom === false || $deferredFrom === null ? PHP_INT_MAX : (int) $deferredFrom;

        $keep = implode(',', array_fill(0, count(self::RETAINED_ACTIONS), '?'));
        $select = $pdo->prepare(
            "SELECT id, seal_seq FROM activity_log
              WHERE id > ? AND id <= ? AND created_at < ? AND action NOT IN ($keep)
                AND (hash IS NOT NULL OR id < ?)
              ORDER BY id LIMIT " . self::PRUNE_BATCH
        );

        $deleted = 0;
        $cursor = $fromId - 1;
        do {
            $select->execute([$cursor, $lastOld, $before, ...self::RETAINED_ACTIONS, $unsealedBelow]);
            $rows = $select->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === []) {
                break;
            }
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $maxSeq = max(array_map(static fn (array $r): int => (int) ($r['seal_seq'] ?? 0), $rows));
            $deleted += TransactionFailure::retrying($pdo, fn (): int => $this->pruneBatch($pdo, $ids, $maxSeq, $before));
            $cursor = $ids[count($ids) - 1];
        } while (count($rows) === self::PRUNE_BATCH);

        return $deleted;
    }

    /** Čas, před kterým už stopa není úplná (`null` = nic se nepromazalo). */
    public function prunedBefore(): ?string
    {
        $before = $this->pruneMark()['before'] ?? null;

        return is_string($before) && $before !== '' ? $before : null;
    }

    /**
     * @param list<int> $ids
     */
    private function pruneBatch(\PDO $pdo, array $ids, int $maxSeq, string $before): int
    {
        $pdo->beginTransaction();
        try {
            $mark = $this->pruneMark();
            $pdo->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
                ->execute([self::PRUNE_META_KEY, json_encode([
                    'below_seq' => max((int) ($mark['below_seq'] ?? 0), $maxSeq + 1),
                    'before' => max((string) ($mark['before'] ?? ''), $before),
                ])]);
            $delete = $pdo->prepare('DELETE FROM activity_log WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $delete->execute($ids);
            $pdo->commit();

            return $delete->rowCount();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Nejvyšší id záznamu staršího než `$before`, půlením po primárním klíči. Tabulka
     * nemá index na samotném `created_at` a id roste s časem zápisu; drobné odchylky
     * pořadí jistí podmínka na `created_at` přímo v mazání.
     */
    private function lastIdCreatedBefore(\PDO $pdo, string $before, int $fromId): ?int
    {
        $bounds = $pdo->prepare('SELECT MIN(id), MAX(id) FROM activity_log WHERE id >= ?');
        $bounds->execute([$fromId]);
        [$lo, $hi] = $bounds->fetch(\PDO::FETCH_NUM);
        if ($lo === null) {
            return null;
        }
        $probe = $pdo->prepare('SELECT id, created_at < ? FROM activity_log WHERE id >= ? ORDER BY id LIMIT 1');
        $probe->execute([$before, (int) $lo]);
        $first = $probe->fetch(\PDO::FETCH_NUM);
        if ($first === false || !(int) $first[1]) {
            return null;
        }

        $lo = (int) $lo;
        $hi = (int) $hi;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            $probe->execute([$before, $mid]);
            $row = $probe->fetch(\PDO::FETCH_NUM);
            if ($row !== false && (int) $row[1]) {
                $lo = (int) $row[0];
            } else {
                $hi = $mid - 1;
            }
        }

        return $lo;
    }

    /** @return array{below_seq?:int, before?:string} */
    private function pruneMark(): array
    {
        if (!$this->db->hasTable('app_meta')) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare('SELECT v FROM app_meta WHERE k = ?');
        $stmt->execute([self::PRUNE_META_KEY]);
        $value = $stmt->fetchColumn();
        $mark = is_string($value) ? json_decode($value, true) : null;

        return is_array($mark) ? $mark : [];
    }

    /**
     * Hashe, které se u JIŽ ZAPEČETĚNÉHO článku uznávají.
     *
     * Články zapečetěné před kanonizací {@see self::CREATED_AT_UTC_SQL} mají v hashi
     * `created_at` vyrenderovaný v zóně session, ne v UTC. Zpětně je přepočítat nelze
     * (přepsat hash auditní stopy je přesně to, co má řetěz znemožnit), takže se
     * u nich uznává i tahle historická podoba.
     *
     * ⚠️ Výjimka MUSÍ mít hranici. Obě podoby popisují týž okamžik, takže tam, kde se
     * uznávají obě, projde ověřením i posun `created_at` přesně o offset zóny — a to
     * je díra v tom, co má řetěz dokazovat. Hranicí je watermark
     * `activity_log_chain_head.utc_created_at_from_id` (migrace 1528): pod ním leží
     * články zapečetěné starým kódem, od něj výš platí jediný, kanonický hash.
     *
     * Bez watermarku (instalace bez migrace 1528) se historická podoba uznává všem —
     * jinak by ověření po nasazení hlásilo celou existující stopu jako pozměněnou.
     *
     * @param array<string,mixed> $row  `created_at` už v UTC
     * @return list<string>
     */
    private function hashCandidates(array $row, ?string $prevHash): array
    {
        $candidates = [self::hashOf($row, $prevHash)];

        $watermark = $this->utcHashWatermark();
        if ($watermark !== null && (int) ($row['id'] ?? 0) >= $watermark) {
            return $candidates;
        }

        $utc = $row['created_at'] ?? null;
        if (is_string($utc) && $utc !== '') {
            try {
                $local = (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s');
                if ($local !== $utc) {
                    $candidates[] = self::hashOf(['created_at' => $local] + $row, $prevHash);
                }
            } catch (\Throwable) {
                // Nečitelné razítko — zůstane jen kanonický kandidát.
            }
        }

        return $candidates;
    }

    /**
     * Od kterého `id` platí výhradně kanonický UTC hash.
     *
     * `null` = watermark není k dispozici (instalace bez migrace 1528), pak se
     * historická podoba uznává všem článkům. Čte se jednou za instanci; během
     * ověření se hodnota měnit nemůže.
     */
    private function utcHashWatermark(): ?int
    {
        if ($this->watermarkResolved) {
            return $this->watermark;
        }
        $this->watermarkResolved = true;

        if (!$this->db->hasColumn('activity_log_chain_head', 'utc_created_at_from_id')) {
            return $this->watermark = null;
        }

        $value = $this->db->pdo()
            ->query('SELECT utc_created_at_from_id FROM activity_log_chain_head WHERE id = 1')
            ->fetchColumn();

        return $this->watermark = ($value === false || $value === null) ? null : (int) $value;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function hashOf(array $row, ?string $prevHash): string
    {
        $parts = [];
        foreach (self::HASHED_COLUMNS as $col) {
            // Oddělovač `\x1F` (unit separator) se v datech nevyskytuje, takže hodnoty
            // nejdou „přelít" jedna do druhé (payload končící tam, kde začíná ip).
            $parts[] = $row[$col] === null ? "\x00" : (string) $row[$col];
        }
        $parts[] = $prevHash ?? '';

        return hash('sha256', implode("\x1F", $parts));
    }
}
