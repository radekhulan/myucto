<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Repository pro journal_entries + journal_entry_lines — účetní deník (Epic F1).
 *
 * Zápis se vkládá atomicky (hlavička + řádky v jedné transakci). Rovnováhu
 * Σ MD = Σ D vynucuje PostingService před voláním insert() — tady se drží jen
 * perzistence. Idempotence přes findBySource() (source_type + source_id).
 */
final class JournalEntryRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Vloží účetní zápis včetně řádků v transakci. Vrací id zápisu.
     *
     * @param array{
     *     supplier_id:int, period_id:int, entry_date:string, document_date?:?string,
     *     document_no?:?string, description?:?string, source_type?:string,
     *     source_id?:?int, posted_at?:?string, posted_by?:?int
     * } $header
     * @param list<array{account_id:int, side:'debit'|'credit', amount:float|string, cost_center?:?string, project_id?:?int, line_no?:int}> $lines
     */
    public function insert(array $header, array $lines): int
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare(
                'INSERT INTO journal_entries
                    (supplier_id, period_id, entry_date, document_date, document_no, description,
                     source_type, source_id, posted_at, posted_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $header['supplier_id'],
                $header['period_id'],
                $header['entry_date'],
                $header['document_date'] ?? null,
                $header['document_no'] ?? null,
                $header['description'] ?? null,
                $header['source_type'] ?? 'manual',
                $header['source_id'] ?? null,
                $header['posted_at'] ?? null,
                $header['posted_by'] ?? null,
            ]);
            $entryId = (int) $pdo->lastInsertId();

            $this->insertLines($pdo, $entryId, (int) $header['supplier_id'], $lines);

            if ($ownTx) {
                $pdo->commit();
            }
            return $entryId;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Přepíše existující zápis in-place (idempotentní re-post v otevřeném období):
     * aktualizuje hlavičku, smaže staré řádky a zapíše nové, zvýší row_version.
     * PŘEDPOKLAD: volající drží transakci (PostingService) — celé to musí být atomické.
     *
     * @param array{
     *     supplier_id:int, period_id:int, entry_date:string, document_date?:?string,
     *     document_no?:?string, description?:?string, source_type?:string,
     *     source_id?:?int, posted_at?:?string, posted_by?:?int
     * } $header
     * @param list<array{account_id:int, side:'debit'|'credit', amount:float|string, cost_center?:?string, project_id?:?int, line_no?:int}> $lines
     */
    public function replace(int $id, array $header, array $lines): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'UPDATE journal_entries
                SET period_id = ?, entry_date = ?, document_date = ?, document_no = ?,
                    description = ?, source_type = ?, source_id = ?, posted_at = ?,
                    posted_by = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND reversed_by IS NULL'
        );
        $stmt->execute([
            $header['period_id'],
            $header['entry_date'],
            $header['document_date'] ?? null,
            $header['document_no'] ?? null,
            $header['description'] ?? null,
            $header['source_type'] ?? 'manual',
            $header['source_id'] ?? null,
            $header['posted_at'] ?? null,
            $header['posted_by'] ?? null,
            $id,
            $header['supplier_id'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new PostingException(
                'entry_reversed',
                'Zápis #' . $id . ' byl mezitím stornován — přepis není možný (§35).',
            );
        }

        $this->deleteLines($id);
        $this->insertLines($pdo, $id, (int) $header['supplier_id'], $lines);
    }

    public function deleteLines(int $entryId): void
    {
        $this->db->pdo()->prepare('DELETE FROM journal_entry_lines WHERE entry_id = ?')->execute([$entryId]);
    }

    /**
     * §35 — aditivní editace narativního `description` na EXISTUJÍCÍM (i zaúčtovaném)
     * zápisu, MIMO PostingService/replace()/postDocument (ty přepisují řádky, vynucují
     * Σ MD = Σ D a zařazují do období). Nemění účet/stranu/částku/období — jen popisek.
     *
     * §35 guardy (POVINNÉ) — atomicky pod SELECT ... FOR UPDATE, zrcadlí
     * {@see PostingService::rewriteExisting}:
     *   - 409 `description_managed_by_source` — §5.4 re-post clobber gate: povoleno JEN pro
     *     source_type IN ('manual','closing','opening') (zápisy, které se nikdy nere-postují);
     *     u zápisů odvozených ze zdrojového dokladu by inline popis PostingService při
     *     příštím re-postu tiše přepsal.
     *   - 409 `entry_reversed` — stornovaný zápis (reversed_by != NULL) je neměnný.
     *   - 409 `period_not_open` — období zápisu zavřené/uzamčené (mirror rewriteExisting:
     *     povolené stavy závisí na source_type — 'closing' zápis smí i v období 'closing'
     *     [závěrkový krok], manual/opening jen v 'open'; jinak odmítnuto).
     * Při úspěchu zapíše description, **bumpne row_version** (optimistická konkurence) a
     * ATOMICKY (pod touž transakcí, před commitem — zrcadlí PostingService::postDocument)
     * zapíše §35 audit `accounting.description_edited`. LOW-7: no-op editace (stejný text)
     * nebumpuje row_version ani nepíše audit (§35 trail zůstává smysluplný).
     *
     * Optimistická konkurence (Issue #15, část B): pokud volající předá `$expectedVersion`
     * (z hlavičky If-Match / body row_version), porovná se pod FOR UPDATE zámkem s aktuální
     * `row_version`; při neshodě 409 `version_conflict` (mezitím editoval jiný uživatel).
     * `$expectedVersion === null` = bez CAS (zpětně kompatibilní — chování jako dřív).
     *
     * @return array{before:?string, after:?string, posted:bool, changed:bool} výsledek pro Action
     * @throws PostingException 404 not_found | 409 version_conflict|description_managed_by_source|entry_reversed|period_not_open
     */
    public function updateDescription(
        int $id,
        int $supplierId,
        ?string $text,
        ?int $userId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?int $expectedVersion = null,
    ): array {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            // Zamkni řádek zápisu + načti guard stav (vč. stavu jeho období) a row_version.
            $stmt = $pdo->prepare(
                'SELECT je.description, je.source_type, je.reversed_by, je.posted_at,
                        je.row_version, p.status AS period_status
                   FROM journal_entries je
              LEFT JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id
                  WHERE je.id = ? AND je.supplier_id = ?
                  FOR UPDATE'
            );
            $stmt->execute([$id, $supplierId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new PostingException('not_found', 'Účetní zápis nenalezen.', 404);
            }

            // Optimistická konkurence: pod FOR UPDATE je přečtená row_version autoritativní,
            // proto stačí porovnat (žádný zvláštní CAS UPDATE není potřeba).
            if ($expectedVersion !== null && (int) $row['row_version'] !== $expectedVersion) {
                throw new PostingException(
                    'version_conflict',
                    'Zápis mezitím změnil jiný uživatel — načtěte aktuální stav.',
                    409,
                );
            }

            // §5.4 re-post clobber gate — jen zápisy, které PostingService nikdy nepřepisuje.
            if (!in_array((string) $row['source_type'], ['manual', 'closing', 'opening'], true)) {
                throw new PostingException(
                    'description_managed_by_source',
                    'Popis se edituje na zdrojovém dokladu.',
                    409,
                );
            }
            // §35 — stornovaný zápis je neměnný.
            if ($row['reversed_by'] !== null) {
                throw new PostingException(
                    'entry_reversed',
                    'Zápis #' . $id . ' je stornovaný — popis nelze měnit (§35).',
                    409,
                );
            }
            // §35 — do zavřeného/uzamčeného období nelze zasahovat (mirror rewriteExisting):
            // povolené stavy závisí na source_type — jen 'closing' zápis smí být v období
            // 'closing' (závěrkový krok, allow_closing_period flag), manual/opening jen 'open'.
            $sourceType = (string) $row['source_type'];
            $allowed = $sourceType === 'closing' ? ['open', 'closing'] : ['open'];
            $periodStatus = $row['period_status'] !== null ? (string) $row['period_status'] : null;
            if ($periodStatus !== null && !in_array($periodStatus, $allowed, true)) {
                throw new PostingException(
                    'period_not_open',
                    'Zápis #' . $id . ' je v období "' . $periodStatus
                        . '" — do uzavřeného období nelze zasahovat (§35 ZoÚ).',
                    409,
                );
            }

            $before = $row['description'] !== null ? (string) $row['description'] : null;
            $posted = $row['posted_at'] !== null;

            // LOW-7 — no-op editace (stejný text): nebumpuj row_version ani nepiš audit.
            if ($before === $text) {
                if ($ownTx) {
                    $pdo->commit();
                }
                return ['before' => $before, 'after' => $text, 'posted' => $posted, 'changed' => false];
            }

            $pdo->prepare(
                'UPDATE journal_entries SET description = ?, row_version = row_version + 1
                  WHERE id = ? AND supplier_id = ?'
            )->execute([$text, $id, $supplierId]);

            // §35 audit ATOMICKY uvnitř téže transakce, PŘED commitem (zrcadlí
            // PostingService::postDocument) — neauditovaná editace zaúčtovaného zápisu
            // nesmí přetrvat, pokud audit INSERT selže.
            $this->activity->log(
                'accounting.description_edited',
                $userId,
                'journal_entry',
                $id,
                ['before' => $before, 'after' => $text, 'posted' => $posted],
                $ip,
                $userAgent,
                $supplierId,
            );

            if ($ownTx) {
                $pdo->commit();
            }

            return [
                'before'  => $before,
                'after'   => $text,
                'posted'  => $posted,
                'changed' => true,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Označí zápis za zaúčtovaný (koncept → zaúčtováno, §12 průkaznost).
     */
    public function markPosted(int $id, int $supplierId, ?int $postedBy): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE journal_entries SET posted_at = NOW(), posted_by = ? WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$postedBy, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Naváže na původní zápis jeho storno (§35 — po zaúčtování jen opravný zápis).
     * Podmínka `reversed_by IS NULL` je ATOMICKÁ pojistka proti dvojímu stornu při
     * souběhu: dva paralelní reverse oba vloží zrcadlo, ale tenhle UPDATE vyhraje
     * jen první (DB serializuje zámek řádku) — druhý dostane rowCount 0 a volající
     * transakci rollbackne (audit F-1). Storno má source_id NULL, takže unique
     * uq_je_supplier_source ho nechrání — chrání ho tenhle podmíněný UPDATE.
     */
    public function setReversedBy(int $id, int $supplierId, int $reversalEntryId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE journal_entries SET reversed_by = ?
              WHERE id = ? AND supplier_id = ? AND reversed_by IS NULL'
        );
        $stmt->execute([$reversalEntryId, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Odpojí zdrojový doklad od zápisu — uvolní unique slot (source_type, source_id)
     * pro nový zápis (mini-epic AUTOMATIZACE: unpost = reverse + detach, R10). Audit
     * pár originál+storno zůstává (storno má source_id=NULL už dnes). Vrací true při
     * zásahu do řádku tenanta.
     */
    public function detachSource(int $entryId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE journal_entries SET source_id = NULL WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$entryId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param list<array{account_id:int, side:'debit'|'credit', amount:float|string, cost_center?:?string, project_id?:?int, line_no?:int}> $lines
     */
    private function insertLines(\PDO $pdo, int $entryId, int $supplierId, array $lines): void
    {
        $lineStmt = $pdo->prepare(
            'INSERT INTO journal_entry_lines
                (entry_id, supplier_id, account_id, side, amount, currency_code, fx_rate,
                 amount_foreign, cost_center, project_id, line_no)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $n = 0;
        foreach ($lines as $line) {
            $lineStmt->execute([
                $entryId,
                $supplierId,
                $line['account_id'],
                $line['side'],
                $line['amount'],
                $line['currency_code'] ?? null,
                $line['fx_rate'] ?? null,
                $line['amount_foreign'] ?? null,
                $line['cost_center'] ?? null,
                isset($line['project_id']) && (int) $line['project_id'] > 0 ? (int) $line['project_id'] : null,
                $line['line_no'] ?? $n,
            ]);
            $n++;
        }
    }

    /**
     * VŠECHNY zápisy zdrojového dokladu, i s řádky — podklad pro sekci „Zaúčtování"
     * na detailu dokladu. Na rozdíl od {@see findBySource()} nevrací jen poslední
     * zápis: doklad se dá přeúčtovat i stornovat a účetní musí vidět celý případ.
     *
     * Protizápis se dohledá přes `reversed_by`, ne přes zdroj: storno má source_id
     * ZÁMĚRNĚ NULL (jinak by ho findBySource() vydával za zaúčtování dokladu), takže
     * by jinak ze sekce vypadlo právě to, co doklad odúčtovalo.
     *
     * @return list<array<string,mixed>>
     */
    public function listBySourceWithLines(int $supplierId, string $sourceType, int $sourceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                    source_type, source_id, posted_at, posted_by, reversed_by, row_version,
                    created_at, updated_at
               FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ?
              ORDER BY id ASC'
        );
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        $reversalIds = array_values(array_filter(array_map(
            static fn (array $r): ?int => $r['reversed_by'] === null ? null : (int) $r['reversed_by'],
            $rows,
        )));
        if ($reversalIds !== []) {
            $place = implode(',', array_fill(0, count($reversalIds), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                        source_type, source_id, posted_at, posted_by, reversed_by, row_version,
                        created_at, updated_at
                   FROM journal_entries
                  WHERE supplier_id = ? AND id IN ({$place})"
            );
            $stmt->execute([$supplierId, ...$reversalIds]);
            $rows = [...$rows, ...$stmt->fetchAll(PDO::FETCH_ASSOC)];
            usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        }

        $entries = [];
        foreach ($rows as $row) {
            $entry = $this->cast($row);
            $entry['lines'] = $this->linesForEntry((int) $entry['id'], $supplierId);
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Existující zápis pro zdrojový doklad (idempotence zaúčtování).
     */
    public function findBySource(int $supplierId, string $sourceType, int $sourceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                    source_type, source_id, posted_at, posted_by, reversed_by, row_version,
                    created_at, updated_at
               FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Jako findBySource, ale ZAMYKAJÍCÍM čtením (FOR UPDATE). Po unique-violation
     * (souběžný zápis pro týž zdroj) nesmí volající dohledat vítěze prostým SELECTem —
     * ten by v REPEATABLE READ vracel konzistentní snapshot z počátku transakce, kde
     * čerstvě commitnutý vítězný řádek ještě NENÍ (stale → null). Zamykající čtení
     * obchází snapshot: přečte poslední commitnutou verzi a počká na dokončení souběžné
     * transakce, takže retry vždy dostane AKTUÁLNÍ vítězný zápis. Volající drží transakci.
     */
    public function findBySourceForUpdate(int $supplierId, string $sourceType, int $sourceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                    source_type, source_id, posted_at, posted_by, reversed_by, row_version,
                    created_at, updated_at
               FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ?
              ORDER BY id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * AKTIVNÍ (nestornovaný) zápis zdrojového dokladu, zamykajícím čtením — nosič
     * idempotence zaúčtování od migrace 1752.
     *
     * `findBySourceForUpdate()` vrací i STORNOVANÝ zápis, což pro idempotenci není
     * to, co se hledá: §35 ZoÚ (a docblock {@see PostingService::rewriteExisting()})
     * říká, že oprava po stornu se dělá NOVÝM zápisem, ne mutací stornovaného. Dokud
     * se ptalo na „poslední zápis dokladu", skončil re-post po stornu chybou
     * `entry_reversed` a doklad se už nedal zaúčtovat vůbec. Unikát
     * `uq_je_supplier_active_source` hlídá přesně tuhle množinu.
     */
    public function findActiveBySourceForUpdate(int $supplierId, string $sourceType, int $sourceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                    source_type, source_id, posted_at, posted_by, reversed_by, row_version,
                    created_at, updated_at
               FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ? AND reversed_by IS NULL
              ORDER BY id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                    source_type, source_id, posted_at, posted_by, reversed_by, row_version,
                    created_at, updated_at
               FROM journal_entries
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $entry = $this->cast($row);
        $entry['lines'] = $this->linesForEntry($id, $supplierId);
        return $entry;
    }

    /**
     * Načte zápis a uzamkne jeho hlavičku pro souběžné reverse()/replace().
     * Volající musí držet transakci; řádky se čtou až po získání zámku, takže
     * protizápis vždy zrcadlí poslední atomicky uloženou verzi zápisu.
     */
    public function findForUpdate(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, period_id, entry_date, document_date, document_no, description,
                    source_type, source_id, posted_at, posted_by, reversed_by, row_version,
                    created_at, updated_at
               FROM journal_entries
              WHERE id = ? AND supplier_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $entry = $this->cast($row);
        $entry['lines'] = $this->linesForEntry($id, $supplierId);
        return $entry;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function linesForEntry(int $entryId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, entry_id, supplier_id, account_id, side, amount, currency_code, fx_rate,
                    amount_foreign, cost_center, project_id, line_no
               FROM journal_entry_lines
              WHERE entry_id = ? AND supplier_id = ?
              ORDER BY line_no ASC, id ASC'
        );
        $stmt->execute([$entryId, $supplierId]);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['entry_id'] = (int) $r['entry_id'];
            $r['supplier_id'] = (int) $r['supplier_id'];
            $r['account_id'] = (int) $r['account_id'];
            $r['amount'] = (float) $r['amount'];
            $r['fx_rate'] = $r['fx_rate'] === null ? null : (float) $r['fx_rate'];
            $r['amount_foreign'] = $r['amount_foreign'] === null ? null : (float) $r['amount_foreign'];
            $r['project_id'] = $r['project_id'] === null ? null : (int) $r['project_id'];
            $r['line_no'] = (int) $r['line_no'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Stránkovaný seznam zápisů pro firmu s filtry (období / rozsah dat / typ zdroje /
     * stav zaúčtování). Vrací hlavičky (bez řádků) + celkový počet pro paginaci.
     *
     * @param array{document_no?:string, period_id?:int, date_from?:string, date_to?:string, source_type?:string, source_id?:int, entry_id?:int, entry_ids?:list<int>, posted?:bool, automation?:string, q?:string, account_from?:string, account_to?:string, amount_from?:float, amount_to?:float} $filters
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function paginate(int $supplierId, array $filters, int $limit, int $offset): array
    {
        [$whereSql, $params] = $this->buildWhere($supplierId, $filters);

        $pdo = $this->db->pdo();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM journal_entries je WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Filtr na účet (Featura D) mění i sémantiku sloupce Částka: bez filtru je to
        // Σ MD celého zápisu (AMOUNT_SUBQUERY — nález „ČÁSTKA u filtru na účet"), S
        // filtrem musí jít o částku PŘIPADAJÍCÍ na vybraný účet/rozsah a jeho stranu,
        // jinak účetní vidí (typicky vyšší) číslo, které s tím, podle čeho filtroval,
        // vůbec nesouvisí — u zápisu s víc nohama na různých účtech to bylo klidně
        // několikanásobně nadhodnocené. Select-param(y) MUSÍ jít PŘED $params z
        // buildWhere(), protože SELECT v SQL textu předchází WHERE.
        $accountFiltered = self::hasAccountRangeFilter($filters);
        if ($accountFiltered) {
            $amountSelect = self::FILTERED_NET_AMOUNT_SUBQUERY . ' AS amount,';
            [$from, $to] = self::accountRangeBounds($filters);
            $selectParams = [$from, $to];
        } else {
            $amountSelect = self::AMOUNT_SUBQUERY . ' AS amount,';
            $selectParams = [];
        }

        // Majetek — čitelný label u source_type 'asset'/'asset_disposal' (source_id = ID
        // karty majetku) i 'depreciation' (source_id = ID řádku depreciation_entries, proto
        // se ID karty dohledává přes mezi-JOIN na dep — viz FEATURA C, audit 2026-07 follow-up).
        $sql = "SELECT je.id, je.supplier_id, je.period_id, je.entry_date, je.document_date,
                       COALESCE(je.document_no, src_i.varsymbol, src_pi.vendor_invoice_number, src_pi.varsymbol) AS document_no,
                       je.description, je.source_type, je.source_id, je.posted_at,
                       je.posted_by, je.reversed_by, je.row_version, je.created_at, je.updated_at,
                       u.name AS posted_by_name,
                       {$amountSelect}
                       bt.statement_id AS source_statement_id,
                       cd.doc_number AS source_doc_number,
                       cd.register_id AS source_register_id,
                       ast.id AS source_asset_id,
                       ast.name AS source_asset_name,
                       stl.doc_type AS source_settlement_doc_type,
                       stl.doc_id AS source_settlement_doc_id
                  FROM journal_entries je
             LEFT JOIN users u ON u.id = je.posted_by
             LEFT JOIN bank_transactions bt ON je.source_type = 'bank' AND bt.id = je.source_id
             LEFT JOIN cash_documents cd ON je.source_type = 'cash' AND cd.id = je.source_id
             -- Zápočet: source_id je ID ZÁPOČTU, ne dokladu. Bez tohohle JOINu nemá deník
             -- kam prokliknout — uživatel vidí číslo faktury, ale odkaz nikam nevede.
             LEFT JOIN invoice_settlements stl ON je.source_type = 'settlement'
                    AND stl.id = je.source_id AND stl.supplier_id = je.supplier_id
             LEFT JOIN invoices src_i ON je.source_type = 'invoice'
                    AND src_i.id = je.source_id AND src_i.supplier_id = je.supplier_id
             LEFT JOIN purchase_invoices src_pi ON je.source_type = 'purchase_invoice'
                    AND src_pi.id = je.source_id AND src_pi.supplier_id = je.supplier_id
             LEFT JOIN depreciation_entries dep ON je.source_type = 'depreciation'
                    AND dep.id = je.source_id AND dep.supplier_id = je.supplier_id
             LEFT JOIN assets ast ON ast.supplier_id = je.supplier_id
                    AND ast.id = CASE
                        WHEN je.source_type IN ('asset', 'asset_disposal') THEN je.source_id
                        WHEN je.source_type = 'depreciation' THEN dep.asset_id
                        ELSE NULL
                    END
                 WHERE {$whereSql}
                 ORDER BY je.entry_date DESC, je.id DESC
                 LIMIT {$limit} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([...$selectParams, ...$params]);
        $items = array_map(fn (array $r): array => $this->castListRow($r, $accountFiltered), $stmt->fetchAll(PDO::FETCH_ASSOC));

        return ['items' => $items, 'total' => $total];
    }

    /** Korelovaný subselect s celkovou částkou zápisu (Σ MD = Σ Dal u vyváženého zápisu). */
    private const AMOUNT_SUBQUERY =
        '(SELECT COALESCE(SUM(jel.amount), 0) FROM journal_entry_lines jel WHERE jel.entry_id = je.id AND jel.side = \'debit\')';

    /**
     * Korelovaný subselect s ČISTOU částkou zápisu na filtrovaném rozsahu účtů
     * (Σ MD − Σ D jen na řádcích, které padnou do account_from/account_to daného
     * zápisu). Znaménko nese stranu (>=0 → MD, <0 → D) — viz castListRow(). Používá
     * se MÍSTO AMOUNT_SUBQUERY, když je aktivní filtr na účet (viz paginate()); bez
     * něj zápis s nohou nákladu i nohou zúčtování zálohy na jiných účtech ukazoval
     * ve sloupci Částka Σ MD celého zápisu, ne částku vybraného účtu.
     *
     * Placeholdery (account_from, account_to) MUSÍ nést stejný rozsah jako EXISTS
     * filtr v buildWhere() — jinak by se řádek v seznamu objevil podle jednoho
     * rozsahu, ale částka by se počítala z jiného (viz accountRangeBounds()).
     */
    private const FILTERED_NET_AMOUNT_SUBQUERY =
        '(SELECT COALESCE(SUM(CASE WHEN jel.side = \'debit\' THEN jel.amount ELSE -jel.amount END), 0)
            FROM journal_entry_lines jel
            JOIN chart_of_accounts c ON c.id = jel.account_id
           WHERE jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
             AND c.account_code BETWEEN ? AND ?)';

    /**
     * Export deníku (audit 2026-07, nález „Export a tisk účetního deníku"): VŠECHNY
     * zápisy odpovídající filtrům (bez stránkování), chronologicky (entry_date ASC),
     * s řádky (MD/Dal, kód a název účtu) — jak to zákonná kniha (§13 ZoÚ) vyžaduje.
     * `$limit` je tvrdý strop (viz JournalExportService) — vrátí max `$limit + 1`
     * hlaviček, aby volající poznal překročení bez druhého COUNT dotazu.
     *
     * Sloupec Částka řeší STEJNÝ nález jako paginate() („ČÁSTKA u filtru na účet"):
     * bez filtru na účet je to Σ MD celého zápisu (AMOUNT_SUBQUERY), s filtrem
     * FILTERED_NET_AMOUNT_SUBQUERY + amount_side — jinak export s filtrem na účet
     * ukazoval v hlavičkovém řádku (ReportXlsxExporter::journal(), journal.twig)
     * cizí číslo (Σ MD celého zápisu), ne částku vybraného účtu.
     *
     * @param array{document_no?:string, period_id?:int, date_from?:string, date_to?:string, source_type?:string, source_id?:int, entry_id?:int, entry_ids?:list<int>, posted?:bool, automation?:string, q?:string, account_from?:string, account_to?:string, amount_from?:float, amount_to?:float} $filters
     * @return list<array<string,mixed>> hlavičky BEZ lines (viz linesForEntries)
     */
    public function forExport(int $supplierId, array $filters, int $limit): array
    {
        [$whereSql, $params] = $this->buildWhere($supplierId, $filters);

        // Select-param(y) MUSÍ jít PŘED $params z buildWhere() — viz stejná poznámka
        // v paginate().
        $accountFiltered = self::hasAccountRangeFilter($filters);
        if ($accountFiltered) {
            $amountSelect = self::FILTERED_NET_AMOUNT_SUBQUERY . ' AS amount';
            [$from, $to] = self::accountRangeBounds($filters);
            $selectParams = [$from, $to];
        } else {
            $amountSelect = self::AMOUNT_SUBQUERY . ' AS amount';
            $selectParams = [];
        }

        $sql = "SELECT je.id, je.entry_date, je.document_date,
                       COALESCE(je.document_no, src_i.varsymbol, src_pi.vendor_invoice_number, src_pi.varsymbol) AS document_no,
                       je.description,
                       je.source_type, je.source_id, je.posted_at, je.reversed_by,
                       u.name AS posted_by_name,
                       {$amountSelect}
                  FROM journal_entries je
             LEFT JOIN users u ON u.id = je.posted_by
             LEFT JOIN invoices src_i ON je.source_type = 'invoice'
                    AND src_i.id = je.source_id AND src_i.supplier_id = je.supplier_id
             LEFT JOIN purchase_invoices src_pi ON je.source_type = 'purchase_invoice'
                    AND src_pi.id = je.source_id AND src_pi.supplier_id = je.supplier_id
                 WHERE {$whereSql}
                 ORDER BY je.entry_date ASC, je.id ASC
                 LIMIT " . ($limit + 1);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([...$selectParams, ...$params]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $r) use ($accountFiltered): array {
            $r['id'] = (int) $r['id'];
            $r['source_id'] = $r['source_id'] === null ? null : (int) $r['source_id'];
            $r['reversed_by'] = $r['reversed_by'] === null ? null : (int) $r['reversed_by'];
            if ($accountFiltered) {
                $net = (float) $r['amount'];
                $r['amount'] = round(abs($net), 2);
                // Nula (viz castListRow) se bere jako MD — neutrální volba, aby strana
                // nikdy nechyběla.
                $r['amount_side'] = $net < 0 ? 'credit' : 'debit';
            } else {
                $r['amount'] = (float) $r['amount'];
                $r['amount_side'] = null;
            }
            return $r;
        }, $rows);
    }

    /**
     * Řádky pro seznam zápisů (export) obohacené o kód/název účtu — jeden dotaz
     * pro celou dávku (žádné N+1), seskupené podle entry_id.
     *
     * @param list<int> $entryIds
     * @return array<int, list<array<string,mixed>>> keyed entry_id
     */
    public function linesForEntries(array $entryIds, int $supplierId): array
    {
        if ($entryIds === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT jel.entry_id, jel.side, jel.amount, jel.cost_center, jel.line_no,
                    c.account_code, c.name AS account_name
               FROM journal_entry_lines jel
               JOIN chart_of_accounts c ON c.id = jel.account_id
              WHERE jel.entry_id IN ({$place}) AND jel.supplier_id = ?
              ORDER BY jel.entry_id ASC, jel.line_no ASC, jel.id ASC"
        );
        $stmt->execute([...$entryIds, $supplierId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $entryId = (int) $r['entry_id'];
            $out[$entryId][] = [
                'side'         => $r['side'],
                'amount'       => (float) $r['amount'],
                'cost_center'  => $r['cost_center'],
                'account_code' => $r['account_code'],
                'account_name' => $r['account_name'],
            ];
        }
        return $out;
    }

    /**
     * Auditní historie zápisu (audit 2026-07, nález „Historie účetního zápisu v UI"):
     * VŠECHNY historické i aktuální verze hlavičky a řádků přes `FOR SYSTEM_TIME ALL`
     * (migrace 1029). Vrací NULL, pokud zápis (ani historicky) neexistuje pro tenanta —
     * supplier_id se u zápisu nikdy neupravuje, takže filtr je tenant-bezpečný i nad
     * historickými řádky.
     *
     * @return array{headers:list<array<string,mixed>>, lines:list<array<string,mixed>>}|null
     */
    public function history(int $id, int $supplierId): ?array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT je.id, je.period_id, je.entry_date, je.document_date, je.document_no,
                    je.description, je.source_type, je.source_id, je.posted_at, je.posted_by,
                    u.name AS posted_by_name, je.reversed_by, je.row_version,
                    ROW_START AS valid_from, ROW_END AS valid_to
               FROM journal_entries FOR SYSTEM_TIME ALL je
          LEFT JOIN users u ON u.id = je.posted_by
              WHERE je.id = ? AND je.supplier_id = ?
              ORDER BY ROW_START ASC'
        );
        $stmt->execute([$id, $supplierId]);
        $headers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($headers === []) {
            return null;
        }

        $lineStmt = $pdo->prepare(
            'SELECT jel.id, jel.account_id, jel.side, jel.amount, jel.cost_center, jel.line_no,
                    c.account_code, c.name AS account_name,
                    ROW_START AS valid_from, ROW_END AS valid_to
               FROM journal_entry_lines FOR SYSTEM_TIME ALL jel
               JOIN chart_of_accounts c ON c.id = jel.account_id
              WHERE jel.entry_id = ? AND jel.supplier_id = ?
              ORDER BY ROW_START ASC, jel.line_no ASC, jel.id ASC'
        );
        $lineStmt->execute([$id, $supplierId]);
        $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'headers' => array_map(static function (array $r): array {
                $r['id'] = (int) $r['id'];
                $r['period_id'] = (int) $r['period_id'];
                $r['source_id'] = $r['source_id'] === null ? null : (int) $r['source_id'];
                $r['posted_by'] = $r['posted_by'] === null ? null : (int) $r['posted_by'];
                $r['reversed_by'] = $r['reversed_by'] === null ? null : (int) $r['reversed_by'];
                $r['row_version'] = (int) $r['row_version'];
                return $r;
            }, $headers),
            'lines' => array_map(static function (array $r): array {
                $r['id'] = (int) $r['id'];
                $r['account_id'] = (int) $r['account_id'];
                $r['amount'] = (float) $r['amount'];
                $r['line_no'] = (int) $r['line_no'];
                return $r;
            }, $lines),
        ];
    }

    /** Je aktivní filtr na rozsah účtu (account_from/account_to)? SSOT pro buildWhere() i paginate(). */
    private static function hasAccountRangeFilter(array $filters): bool
    {
        return !empty($filters['account_from']) || !empty($filters['account_to']);
    }

    /**
     * Normalizované meze rozsahu účtu z filtru — chybějící strana se doplní neutrální
     * hranicí. SSOT pro EXISTS filtr v buildWhere() i pro FILTERED_NET_AMOUNT_SUBQUERY
     * v paginate() (obojí musí vidět STEJNÝ rozsah, jinak by se řádek objevil v seznamu,
     * ale sloupec Částka by počítal jiný rozsah účtů, než podle kterého se filtrovalo).
     *
     * @return array{0:string, 1:string}
     */
    private static function accountRangeBounds(array $filters): array
    {
        return [
            (string) ($filters['account_from'] ?? '0'),
            (string) ($filters['account_to'] ?? 'ZZZZZZZZZZ'),
        ];
    }

    /**
     * @param array{document_no?:string, period_id?:int, date_from?:string, date_to?:string, source_type?:string, source_id?:int, entry_id?:int, entry_ids?:list<int>, posted?:bool, automation?:string, q?:string, account_from?:string, account_to?:string, amount_from?:float, amount_to?:float} $filters
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildWhere(int $supplierId, array $filters): array
    {
        $where = ['je.supplier_id = ?'];
        $params = [$supplierId];
        if (!empty($filters['document_no'])) {
            $needle = '%' . self::escapeLike((string) $filters['document_no']) . '%';
            $where[] = "(je.document_no LIKE ? ESCAPE '='
                OR (je.source_type = 'invoice' AND EXISTS(
                    SELECT 1 FROM invoices i
                     WHERE i.id = je.source_id AND i.supplier_id = je.supplier_id
                       AND i.varsymbol LIKE ? ESCAPE '='
                ))
                OR (je.source_type = 'purchase_invoice' AND EXISTS(
                    SELECT 1 FROM purchase_invoices pi
                     WHERE pi.id = je.source_id AND pi.supplier_id = je.supplier_id
                       AND (pi.vendor_invoice_number LIKE ? ESCAPE '=' OR pi.varsymbol LIKE ? ESCAPE '=')
                )))";
            array_push($params, $needle, $needle, $needle, $needle);
        }
        // Fulltext `q` — jedno vyhledávací pole napříč description + čísly dokladů
        // (Featura D, audit 2026-07 follow-up). ORuje se přes stejné zdroje jako
        // document_no výše, jen navíc description.
        if (!empty($filters['q'])) {
            $needle = '%' . self::escapeLike((string) $filters['q']) . '%';
            $where[] = "(je.description LIKE ? ESCAPE '='
                OR je.document_no LIKE ? ESCAPE '='
                OR (je.source_type = 'invoice' AND EXISTS(
                    SELECT 1 FROM invoices i
                     WHERE i.id = je.source_id AND i.supplier_id = je.supplier_id
                       AND i.varsymbol LIKE ? ESCAPE '='
                ))
                OR (je.source_type = 'purchase_invoice' AND EXISTS(
                    SELECT 1 FROM purchase_invoices pi
                     WHERE pi.id = je.source_id AND pi.supplier_id = je.supplier_id
                       AND (pi.vendor_invoice_number LIKE ? ESCAPE '=' OR pi.varsymbol LIKE ? ESCAPE '=')
                )))";
            array_push($params, $needle, $needle, $needle, $needle, $needle);
        }
        // Rozsah účtů (Featura D) — EXISTS na journal_entry_lines, indexováno přes
        // idx_jel_supplier_account. Chybějící mez se doplní neutrální hranicí.
        if (self::hasAccountRangeFilter($filters)) {
            $where[] = "EXISTS (
                SELECT 1 FROM journal_entry_lines jel
                JOIN chart_of_accounts c ON c.id = jel.account_id
                 WHERE jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
                   AND c.account_code BETWEEN ? AND ?
            )";
            [$from, $to] = self::accountRangeBounds($filters);
            $params[] = $from;
            $params[] = $to;
        }
        // Rozsah částky (Featura D) — Σ MD zápisu (zrcadlí AMOUNT_SUBQUERY výše).
        if (isset($filters['amount_from']) || isset($filters['amount_to'])) {
            $where[] = "EXISTS (
                SELECT 1 FROM journal_entry_lines jel
                 WHERE jel.entry_id = je.id AND jel.side = 'debit'
                 GROUP BY jel.entry_id
                HAVING SUM(jel.amount) BETWEEN ? AND ?
            )";
            $params[] = isset($filters['amount_from']) ? (float) $filters['amount_from'] : 0.0;
            $params[] = isset($filters['amount_to']) ? (float) $filters['amount_to'] : 999999999999.99;
        }
        if (!empty($filters['period_id'])) {
            $where[] = 'je.period_id = ?';
            $params[] = (int) $filters['period_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'je.entry_date >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'je.entry_date <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if (!empty($filters['source_type'])) {
            $where[] = 'je.source_type = ?';
            $params[] = (string) $filters['source_type'];
        }
        if (!empty($filters['source_id'])) {
            $where[] = 'je.source_id = ?';
            $params[] = (int) $filters['source_id'];
        }
        // Přesný odskok na jeden zápis (deep-link ?entry_id=). Dřív se místo filtru
        // jen zúžilo datum na entry_date, takže se vedle hledaného zápisu vypsal celý
        // ten den — u nálezu integrity deníku to mate, protože nesouvisející zápisy
        // vypadají jako součást nálezu.
        if (!empty($filters['entry_id'])) {
            $where[] = 'je.id = ?';
            $params[] = (int) $filters['entry_id'];
        }
        // Výčet konkrétních zápisů (filtr „jen nálezy kontroly integrity" —
        // JournalAction::parseFilters si seznam vytáhne z JournalIntegrityService).
        // PRÁZDNÝ seznam znamená „žádné nálezy", ne „bez filtru" — proto isset(),
        // ne !empty(), a nemožná podmínka místo vynechání filtru.
        if (isset($filters['entry_ids'])) {
            $ids = array_values(array_unique(array_map('intval', (array) $filters['entry_ids'])));
            if ($ids === []) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'je.id IN (' . implode(',', $ids) . ')';
            }
        }
        if (array_key_exists('posted', $filters) && $filters['posted'] !== null) {
            $where[] = $filters['posted'] ? 'je.posted_at IS NOT NULL' : 'je.posted_at IS NULL';
        }
        if (!empty($filters['automation'])) {
            $bankStatus = $filters['automation'] === 'auto' ? 'auto_posted' : 'approved';
            $bankExists = "EXISTS (
                SELECT 1 FROM bank_posting_suggestions aps
                 WHERE aps.supplier_id = je.supplier_id AND aps.journal_entry_id = je.id
                   AND aps.status = '{$bankStatus}'
            )";
            $documentAutoExists = "EXISTS (
                SELECT 1 FROM activity_log aal
                 WHERE aal.supplier_id = je.supplier_id
                   AND aal.action IN ('accounting.auto_posted', 'bank_match.auto_posted')
                   AND CAST(JSON_UNQUOTE(JSON_EXTRACT(aal.payload, '$.journal_entry_id')) AS UNSIGNED) = je.id
            )";
            if ($filters['automation'] === 'auto') {
                $where[] = "({$bankExists} OR {$documentAutoExists})";
            } elseif ($filters['automation'] === 'approved') {
                $where[] = $bankExists;
            } else {
                $where[] = "NOT EXISTS (
                    SELECT 1 FROM bank_posting_suggestions aps
                     WHERE aps.supplier_id = je.supplier_id AND aps.journal_entry_id = je.id
                       AND aps.status IN ('auto_posted', 'approved')
                ) AND NOT {$documentAutoExists}";
            }
        }
        return [implode(' AND ', $where), $params];
    }

    private static function escapeLike(string $value): string
    {
        return strtr($value, ['=' => '==', '%' => '=%', '_' => '=_']);
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['period_id'] = (int) $r['period_id'];
        $r['source_id'] = $r['source_id'] === null ? null : (int) $r['source_id'];
        $r['posted_by'] = $r['posted_by'] === null ? null : (int) $r['posted_by'];
        $r['reversed_by'] = $r['reversed_by'] === null ? null : (int) $r['reversed_by'];
        $r['row_version'] = (int) $r['row_version'];
        return $r;
    }

    /**
     * {@see cast()} + list-only obohacení (Σ MD zápisu nebo — při filtru na účet —
     * čistá částka a strana toho účtu, drill-down na banku/pokladnu/majetek).
     *
     * @param bool $accountFiltered true, pokud SQL vybíralo FILTERED_NET_AMOUNT_SUBQUERY
     *             (podepsané MD−D na filtrovaném rozsahu účtů) místo AMOUNT_SUBQUERY.
     */
    private function castListRow(array $r, bool $accountFiltered = false): array
    {
        $r = $this->cast($r);
        if ($accountFiltered) {
            $net = (float) $r['amount'];
            $r['amount'] = round(abs($net), 2);
            // Nula (např. účet je v zápisu debetní i kreditní ve stejné výši) se bere
            // jako MD — neutrální volba, aby strana nikdy nechyběla.
            $r['amount_side'] = $net < 0 ? 'credit' : 'debit';
        } else {
            $r['amount'] = (float) $r['amount'];
            $r['amount_side'] = null;
        }
        $r['source_statement_id'] = $r['source_statement_id'] === null ? null : (int) $r['source_statement_id'];
        $r['source_register_id'] = $r['source_register_id'] === null ? null : (int) $r['source_register_id'];
        $r['source_asset_id'] = $r['source_asset_id'] === null ? null : (int) $r['source_asset_id'];
        // Zápočet ukazuje na fakturu, kterou vyrovnal (doc_type + doc_id).
        $r['source_settlement_doc_type'] = $r['source_settlement_doc_type'] !== null
            ? (string) $r['source_settlement_doc_type'] : null;
        $r['source_settlement_doc_id'] = $r['source_settlement_doc_id'] !== null
            ? (int) $r['source_settlement_doc_id'] : null;
        return $r;
    }
}
