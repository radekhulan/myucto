<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

/**
 * Pracovní úložiště stránkovaného výpočtu J6 v dočasných tabulkách MariaDB.
 *
 * Dočasné tabulky patří jen aktuální session, nezpůsobují implicitní commit
 * (vytváří se i ruší výhradně jako TEMPORARY) a každá instance má vlastní prefix,
 * takže vnořené výpočty v jedné session se nepřekrývají. Čtení jde po stránkách
 * přes materializovaný pořadník, aby paměť PHP nerostla s objemem dat.
 */
final class J6WorkingStore
{
    /** Skupina činnosti a cenová kohorta jsou doložené texty uživatele, nikoli technické identifikátory. */
    public const string LABEL = 'VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL';

    private const array BASE_TABLES = ['activities', 'sources', 'funding_pools', 'funding_members',
        'funding_links', 'funding_history'];

    private string $prefix;
    private ?PDO $pdo;
    private bool $closed = false;
    /** @var array<string, true> */
    private array $tables = [];
    private int $spoolSequence = 0;

    public function __construct(PDO $pdo)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \LogicException('J6 working store requires the MariaDB connection.');
        }
        $this->pdo = $pdo;
        $this->prefix = 'j6w_' . bin2hex(random_bytes(8)) . '_';
        try {
            $this->schema();
        } catch (\Throwable $failure) {
            $this->closeAndDelete();
            throw $failure;
        }
    }

    public function connection(): PDO
    {
        if ($this->closed || $this->pdo === null) throw new \LogicException('J6 working store is closed.');
        return $this->pdo;
    }

    /** Vrátí jméno dočasné tabulky této instance; tabulku zaeviduje k úklidu. */
    public function table(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('J6 working table name is invalid.');
        }
        $this->tables[$name] = true;
        return '`' . $this->prefix . $name . '`';
    }

    /** Nahradí v SQL zástupné názvy `{tabulka}` jmény dočasných tabulek této instance. */
    public function sql(string $template): string
    {
        return (string) preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', fn (array $m): string => $this->table($m[1]), $template);
    }

    public function exec(string $template): void
    {
        $this->connection()->exec($this->sql($template));
    }

    public function prepare(string $template): \PDOStatement
    {
        return $this->connection()->prepare($this->sql($template));
    }

    public function scalar(string $template, array $params = []): mixed
    {
        $stmt = $this->prepare($template);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $value;
    }

    public function dropTable(string $name): void
    {
        $this->connection()->exec('DROP TEMPORARY TABLE IF EXISTS ' . $this->table($name));
    }

    /**
     * Proudové čtení výsledku dotazu po stránkách. Výsledek se nejdřív materializuje
     * do vlastní dočasné tabulky s pořadím podle $orderBy (sloupce výsledku), pak se
     * čte po klíči pořadí. Během čtení lze nad spojením volat další příkazy.
     *
     * @return iterable<array<string, mixed>>
     */
    public function stream(string $select, array $params, string $orderBy, int $pageSize = 500): iterable
    {
        if ($pageSize < 1 || $pageSize > 500) throw new \InvalidArgumentException('J6 working page limit is invalid.');
        $spool = 'spool_' . ++$this->spoolSequence;
        $table = $this->table($spool);
        $create = $this->connection()->prepare('CREATE TEMPORARY TABLE ' . $table
            . ' (PRIMARY KEY(j6_ord)) ENGINE=InnoDB AS SELECT ROW_NUMBER() OVER (ORDER BY ' . $orderBy . ') AS j6_ord, q.* FROM ('
            . $this->sql($select) . ') q');
        try {
            $create->execute($params);
            $page = $this->connection()->prepare('SELECT * FROM ' . $table . ' WHERE j6_ord>? ORDER BY j6_ord LIMIT ' . $pageSize);
            $after = 0;
            do {
                $page->execute([$after]);
                $rows = $page->fetchAll(PDO::FETCH_ASSOC);
                $page->closeCursor();
                foreach ($rows as $row) {
                    $after = (int) $row['j6_ord'];
                    unset($row['j6_ord']);
                    yield $row;
                }
            } while (count($rows) === $pageSize);
        } finally {
            unset($this->tables[$spool]);
            if (!$this->closed && $this->pdo !== null) {
                $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS ' . $table);
            }
        }
    }

    public function appendActivity(string $id, array $payload, string $recordHash): void
    {
        $this->hash($recordHash);
        $stmt = $this->prepare('INSERT INTO {activities}(activity_kind_id,payload_json,record_hash) VALUES(?,?,?)');
        $stmt->execute([$this->identifier($id), NonprofitTaxRules::canonicalJson($payload), $recordHash]);
    }

    public function activity(string $id): array
    {
        $json = $this->scalar('SELECT payload_json FROM {activities} WHERE activity_kind_id=?', [$this->identifier($id)]);
        if (!is_string($json)) throw new \UnexpectedValueException('J6 activity definition is missing.');
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function activities(): iterable
    {
        $rows = $this->stream('SELECT activity_kind_id,payload_json,record_hash FROM {activities}', [], 'activity_kind_id');
        foreach ($rows as $row) {
            yield ['activity_kind_id' => $row['activity_kind_id'],
                'payload' => json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR),
                'record_hash' => $row['record_hash']];
        }
    }

    public function appendSource(array $row, bool $current): void
    {
        $id = $this->identifier($row['id'] ?? null);
        $amount = $this->cents($row['amount_cents'] ?? null);
        $deductible = $this->cents($row['deductible_cents'] ?? 0);
        $activity = $this->identifier($row['activity_kind_id'] ?? null);
        $group = $this->label($row['group'] ?? null);
        $classification = $row['classification'] ?? null;
        if (!is_array($classification) || !is_string($classification['regime'] ?? null)
            || !is_string($classification['method'] ?? null)) {
            throw new \InvalidArgumentException('J6 classified source is invalid.');
        }
        $decision = $row['decision'] ?? null;
        if (!is_array($decision)) throw new \InvalidArgumentException('J6 classified decision is invalid.');
        $stmt = $this->prepare('INSERT INTO {sources}
            (source_id,current_row,row_json,kind,amount_cents,deductible_cents,activity_kind_id,group_id,cohort,regime,method,title,reference_current)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$id, $current ? 1 : 0, NonprofitTaxRules::canonicalJson($row), $row['kind'] ?? null,
            $amount, $deductible, $activity, $group, $this->label((string) ($decision['cohort'] ?? ''), true),
            $classification['regime'], $classification['method'],
            $decision['title'] ?? null, ($row['reference_current'] ?? $current) === true ? 1 : 0]);
    }

    public function appendPool(string $kind, string $anchor, array $payload, string $recordHash): void
    {
        $this->poolKind($kind);
        $this->hash($recordHash);
        $stmt = $this->prepare('INSERT INTO {funding_pools}(pool_kind,anchor,payload_json,record_hash) VALUES(?,?,?,?)');
        $stmt->execute([$kind, $this->identifier($anchor), NonprofitTaxRules::canonicalJson($payload), $recordHash]);
    }

    public function appendMember(string $kind, string $anchor, string $memberId): void
    {
        $this->poolKind($kind);
        $anchor = $this->identifier($anchor);
        if ($this->scalar('SELECT 1 FROM {funding_pools} WHERE pool_kind=? AND anchor=?', [$kind, $anchor]) === false) {
            NonprofitTaxRules::invalid('Člen financování odkazuje na nedeklarovaný fond.');
        }
        $stmt = $this->prepare('INSERT IGNORE INTO {funding_members}(pool_kind,anchor,member_id) VALUES(?,?,?)');
        $stmt->execute([$kind, $anchor, $this->identifier($memberId)]);
        if ($stmt->rowCount() !== 1) NonprofitTaxRules::invalid('Člen fondu financování smí být uveden právě jednou.');
    }

    public function appendLink(string $sourceId, string $costId, array $payload, string $recordHash): void
    {
        $this->hash($recordHash);
        $stmt = $this->prepare('INSERT INTO {funding_links}(source_id,cost_id,payload_json,record_hash) VALUES(?,?,?,?)');
        $stmt->execute([$this->identifier($sourceId), $this->identifier($costId), NonprofitTaxRules::canonicalJson($payload), $recordHash]);
    }

    public function appendHistory(array $row): void
    {
        foreach (['assessment_id', 'period_id', 'net_source_cents', 'prior_drawdown_cents', 'drawdown_cents', 'remaining_cents'] as $field) {
            if (!is_int($row[$field] ?? null)) throw new \InvalidArgumentException('J6 funding history is invalid.');
        }
        if ($row['assessment_id'] <= 0 || $row['period_id'] <= 0) throw new \InvalidArgumentException('J6 funding history is invalid.');
        $stmt = $this->prepare('INSERT INTO {funding_history}
            (assessment_id,period_id,status,current_proof,anchor,member_id,net_source_cents,prior_drawdown_cents,drawdown_cents,remaining_cents,record_hash,row_json)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $hash = (string) ($row['record_hash'] ?? NonprofitTaxRules::hash(['domain' => 'J6FUNDINGHISTORY2', 'row' => $row]));
        $this->hash($hash);
        $stmt->execute([$row['assessment_id'], $row['period_id'], $row['status'] ?? null,
            ($row['current'] ?? false) === true ? 1 : 0, $this->identifier($row['anchor'] ?? null),
            $this->identifier($row['member_id'] ?? null), $row['net_source_cents'], $row['prior_drawdown_cents'],
            $row['drawdown_cents'], $row['remaining_cents'], $hash, NonprofitTaxRules::canonicalJson($row)]);
    }

    public function fundingReferenceEvidencePages(int $limit = 500): iterable
    {
        $this->pageLimit($limit);
        yield from $this->jsonPages($this->stream('SELECT source_id,row_json FROM {sources} WHERE current_row=0', [],
            'source_id', $limit), $limit);
    }

    public function fundingHistoryEvidencePages(int $limit = 500): iterable
    {
        $this->pageLimit($limit);
        yield from $this->jsonPages($this->stream('SELECT assessment_id,anchor,member_id,row_json FROM {funding_history}', [],
            'assessment_id,anchor,member_id', $limit), $limit);
    }

    public function fundingDecisionRecords(): iterable
    {
        $pools = $this->stream('SELECT pool_kind,anchor,payload_json,record_hash FROM {funding_pools}', [], 'pool_kind,anchor');
        foreach ($pools as $row) {
            yield ['record_kind' => 'funding_pool',
                'pool_kind' => $row['pool_kind'], 'anchor' => $row['anchor'],
                'payload' => json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR),
                'decision_record_hash' => $row['record_hash']];
        }
        $members = $this->stream('SELECT pool_kind,anchor,member_id FROM {funding_members}', [], 'pool_kind,anchor,member_id');
        foreach ($members as $row) {
            $row = ['record_kind' => 'funding_member'] + $row;
            $row['decision_record_hash'] = NonprofitTaxRules::hash(['domain' => 'J6DECISION2.funding_member', 'row' => $row]);
            yield $row;
        }
        $links = $this->stream('SELECT source_id,cost_id,payload_json,record_hash FROM {funding_links}', [], 'source_id,cost_id');
        foreach ($links as $row) {
            yield ['record_kind' => 'funding_link',
                'source_id' => $row['source_id'], 'cost_id' => $row['cost_id'],
                'payload' => json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR),
                'decision_record_hash' => $row['record_hash']];
        }
    }

    public function closeAndDelete(): void
    {
        if ($this->closed) return;
        $this->closed = true;
        $pdo = $this->pdo;
        $this->pdo = null;
        if ($pdo === null) return;
        $failure = null;
        foreach (array_keys($this->tables) as $name) {
            try {
                $pdo->exec('DROP TEMPORARY TABLE IF EXISTS `' . $this->prefix . $name . '`');
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        $this->tables = [];
        if ($failure !== null) throw new \RuntimeException('J6 working store cleanup failed.', 0, $failure);
    }

    public function __destruct()
    {
        if (!$this->closed) {
            try { $this->closeAndDelete(); } catch (\Throwable) {}
        }
    }

    private function schema(): void
    {
        $id = 'VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';
        $code = 'VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL';
        $label = self::LABEL;
        $hash = 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';
        $json = 'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL';
        $statements = [
            "CREATE TEMPORARY TABLE {activities} (
                activity_kind_id $id PRIMARY KEY,
                payload_json $json CHECK(JSON_VALID(payload_json)),
                record_hash $hash
            ) ENGINE=InnoDB",
            "CREATE TEMPORARY TABLE {sources} (
                source_id $id PRIMARY KEY,
                current_row TINYINT NOT NULL CHECK(current_row IN (0,1)),
                row_json $json CHECK(JSON_VALID(row_json)),
                kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL CHECK(kind IN ('revenue','expense')),
                amount_cents BIGINT NOT NULL,
                deductible_cents BIGINT NOT NULL,
                activity_kind_id $id,
                group_id $label,
                cohort $label,
                regime $code,
                method $code,
                title $code,
                reference_current TINYINT NOT NULL CHECK(reference_current IN (0,1)),
                KEY ix_j6_sources_activity(activity_kind_id,group_id,source_id),
                KEY ix_j6_sources_group(group_id,source_id)
            ) ENGINE=InnoDB",
            "CREATE TEMPORARY TABLE {funding_pools} (
                pool_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL CHECK(pool_kind IN ('revenue','expense')),
                anchor $id,
                payload_json $json CHECK(JSON_VALID(payload_json)),
                record_hash $hash,
                PRIMARY KEY(pool_kind,anchor)
            ) ENGINE=InnoDB",
            "CREATE TEMPORARY TABLE {funding_members} (
                pool_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL CHECK(pool_kind IN ('revenue','expense')),
                anchor $id,
                member_id $id,
                PRIMARY KEY(pool_kind,anchor,member_id),
                UNIQUE KEY uq_j6_members_kind(pool_kind,member_id),
                KEY ix_j6_members_identity(member_id,pool_kind,anchor)
            ) ENGINE=InnoDB",
            "CREATE TEMPORARY TABLE {funding_links} (
                source_id $id,
                cost_id $id,
                payload_json $json CHECK(JSON_VALID(payload_json)),
                record_hash $hash,
                PRIMARY KEY(source_id,cost_id),
                KEY ix_j6_links_cost(cost_id,source_id)
            ) ENGINE=InnoDB",
            "CREATE TEMPORARY TABLE {funding_history} (
                assessment_id BIGINT NOT NULL CHECK(assessment_id>0),
                period_id BIGINT NOT NULL CHECK(period_id>0),
                status $code,
                current_proof TINYINT NOT NULL CHECK(current_proof IN (0,1)),
                anchor $id,
                member_id $id,
                net_source_cents BIGINT NOT NULL,
                prior_drawdown_cents BIGINT NOT NULL,
                drawdown_cents BIGINT NOT NULL,
                remaining_cents BIGINT NOT NULL,
                record_hash $hash,
                row_json $json CHECK(JSON_VALID(row_json)),
                PRIMARY KEY(assessment_id,anchor,member_id),
                KEY ix_j6_history_member(member_id,anchor,assessment_id),
                KEY ix_j6_history_anchor(anchor,assessment_id,member_id)
            ) ENGINE=InnoDB",
        ];
        foreach (self::BASE_TABLES as $name) $this->table($name);
        foreach ($statements as $statement) $this->exec($statement);
    }

    private function identifier(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) throw new \InvalidArgumentException('J6 working identity is invalid.');
        $value = (string) $value;
        if ($value === '' || strlen($value) > 160 || preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) !== 1) {
            throw new \InvalidArgumentException('J6 working identity is invalid.');
        }
        return $value;
    }

    private function label(mixed $value, bool $allowEmpty = false): string
    {
        if (!is_string($value) || ($value === '' && !$allowEmpty) || mb_strlen($value) > 600 || !mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('J6 working label is invalid.');
        }
        return $value;
    }

    private function poolKind(string $kind): void
    {
        if (!in_array($kind, ['revenue', 'expense'], true)) throw new \InvalidArgumentException('J6 funding pool kind is invalid.');
    }

    private function jsonPages(iterable $rows, int $limit): iterable
    {
        $page = [];
        foreach ($rows as $row) {
            $page[] = json_decode((string) $row['row_json'], true, flags: JSON_THROW_ON_ERROR);
            if (count($page) === $limit) {
                yield $page;
                $page = [];
            }
        }
        if ($page !== []) yield $page;
    }

    private function pageLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('J6 working page limit is invalid.');
    }

    private function hash(string $hash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) throw new \InvalidArgumentException('J6 working hash is invalid.');
    }

    private function cents(mixed $value): int
    {
        if (!is_int($value)) throw new \InvalidArgumentException('J6 working amount must be exact integer cents.');
        return $value;
    }
}
