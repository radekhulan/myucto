<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Agenda Položky dokladů: hledání položek vydaných a přijatých faktur napříč doklady
 * (text, odběratel nebo dodavatel, období, členění DPH, sazba, účet, kód zboží, číslo
 * dokladu) jako výběr pro hromadné akce nad položkami (`invoice_item`,
 * `purchase_invoice_item`).
 *
 * Položky nemají vlastní `supplier_id`, každý dotaz proto vychází z hlavičky dokladu
 * firmy po složeném indexu (supplier_id, datum) a položky dohledá po indexu dokladu.
 * Řadí se jen podle hlavičky (datum, id dokladu), takže seznam jde po indexu bez řazení
 * v paměti a stránka skončí, jakmile má dost řádků. Stránkuje se klíčem (datum a id
 * posledního celého dokladu), ne posunem.
 *
 * Text se porovná jako podřetězec popisu (`LIKE`, kolace bez ohledu na diakritiku
 * a velikost písmen). Na řídký text v dlouhém období by cesta přes datum prošla celou
 * firmu, proto má omezený počet prohlédnutých řádků ({@see ROWS_EXAMINED_CAP}); když ho
 * vyčerpá, dotaz se zopakuje přes fulltextový index popisu (migrace 2317) se slovy
 * hledaného textu jako začátky slov a podřetězec se ověří znovu. Slova kratší než
 * `innodb_ft_min_token_size` a stopslova InnoDB fulltext nezná, ty ověří jen podřetězec.
 * Fulltext hledá od začátku slov: text zadaný od prostředku slova najde jen cesta přes
 * datum.
 */
final class DocumentItemSearch
{
    /** Druh záznamů hromadné akce => druh dokladu. */
    public const TARGETS = ['invoice_item' => 'invoice', 'purchase_invoice_item' => 'purchase_invoice'];
    public const PER_PAGE = 50;
    public const PER_PAGE_MAX = 200;
    public const VAT_CODE_NONE = 'none';

    /** Kolik řádků smí projít cesta přes datum, než hledání textu přejde na fulltext. */
    private const ROWS_EXAMINED_CAP = 20000;
    private const ER_QUERY_EXCEEDED_ROWS_EXAMINED_LIMIT = 1931;

    /** Výchozí stopslova InnoDB (INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD). */
    private const FT_STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in',
        'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who',
        'will', 'with', 'und', 'www',
    ];

    private ?int $minToken = null;

    public function __construct(private readonly Connection $db) {}

    public static function isItemTarget(string $target): bool
    {
        return isset(self::TARGETS[$target]);
    }

    public static function documentType(string $target): string
    {
        return self::TARGETS[$target] ?? throw new BulkActionException('validation_failed', 'Neznámý druh položek.', 422);
    }

    /**
     * Filtry z query stringu (`filter[...]` nebo přímo klíče).
     *
     * @param array<string,mixed> $query
     * @return array{q:string, partner_id:?int, date_field:string, date_from:?string, date_to:?string,
     *               vat_code:?string, vat_rate_id:?int, account:?string, product_code:?string, doc_number:?string}
     * @throws BulkActionException
     */
    public static function filters(array $query): array
    {
        $f = is_array($query['filter'] ?? null) ? $query['filter'] + $query : $query;
        $text = static function (string $key, int $max) use ($f): ?string {
            $v = $f[$key] ?? null;
            if ($v === null || !is_scalar($v)) return null;
            $v = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
            return $v === '' ? null : mb_substr($v, 0, $max);
        };
        $id = static function (string $key) use ($f): ?int {
            $v = $f[$key] ?? null;
            if ($v === null || $v === '') return null;
            if (!is_scalar($v) || preg_match('/^[1-9][0-9]{0,18}$/D', (string) $v) !== 1) {
                throw new BulkActionException('validation_failed', 'Neplatný filtr ' . $key . '.', 422);
            }
            return (int) $v;
        };
        $dates = [];
        foreach (['date_from', 'date_to'] as $key) {
            $dates[$key] = $text($key, 10);
            if ($dates[$key] !== null && !IsoDate::isValid($dates[$key])) {
                throw new BulkActionException('validation_failed', 'Datum musí být ve tvaru RRRR-MM-DD.', 422);
            }
        }
        if ($dates['date_from'] !== null && $dates['date_to'] !== null && $dates['date_from'] > $dates['date_to']) {
            throw new BulkActionException('validation_failed', 'Začátek období je po jeho konci.', 422);
        }
        $dateField = (string) ($text('date_field', 10) ?? 'tax');
        if (!in_array($dateField, ['tax', 'issue'], true)) {
            throw new BulkActionException('validation_failed', 'Datum filtru je DUZP (tax) nebo datum vystavení (issue).', 422);
        }
        $code = $text('vat_code', 10);
        if ($code !== null && $code !== self::VAT_CODE_NONE && preg_match('/^[0-9A-Za-z]{1,10}$/D', $code) !== 1) {
            throw new BulkActionException('validation_failed', 'Neplatný kód členění DPH.', 422);
        }
        $account = $text('account', 10);
        if ($account !== null && preg_match('/^[0-9][0-9A-Za-z.]{0,9}$/D', $account) !== 1) {
            throw new BulkActionException('validation_failed', 'Účet nebo začátek účtu není platný.', 422);
        }
        return [
            'q'            => (string) ($text('q', 100) ?? ''),
            'partner_id'   => $id('partner_id'),
            'date_field'   => $dateField,
            'date_from'    => $dates['date_from'],
            'date_to'      => $dates['date_to'],
            'vat_code'     => $code,
            'vat_rate_id'  => $id('vat_rate_id'),
            'account'      => $account,
            'product_code' => $text('product_code', 64),
            'doc_number'   => $text('doc_number', 50),
        ];
    }

    /**
     * Stránka položek podle filtru (nejnovější doklady první). `next_cursor` je klíč
     * posledního celého dokladu na stránce; NULL = další stránka není.
     *
     * @param array<string,mixed> $filters {@see filters()}
     * @return array{items:list<array<string,mixed>>, next_cursor:?string, search:string}
     */
    public function page(int $supplierId, string $target, array $filters, ?string $cursor, int $perPage = self::PER_PAGE): array
    {
        $type = self::documentType($target);
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));
        $after = self::decodeCursor($cursor);
        $q = $this->query($supplierId, $type, $filters, $after);
        [$rows, $search] = $this->fetch($q, self::columns($type), 'ORDER BY ' . $q['order'] . ' LIMIT ' . ($perPage + 1));
        $next = null;
        if (count($rows) > $perPage) {
            $rows = array_slice($rows, 0, $perPage + 1);
            $lastDoc = (int) $rows[$perPage]['document_id'];
            $kept = array_values(array_filter(array_slice($rows, 0, $perPage), static fn (array $r): bool => (int) $r['document_id'] !== $lastDoc));
            if ($kept === []) {
                // Jediný doklad má víc odpovídajících položek, než se vejde na stránku: vrátí se celý.
                $whole = $this->query($supplierId, $type, $filters, null, $lastDoc);
                [$kept] = $this->fetch($whole, self::columns($type), 'ORDER BY ' . $whole['order']);
            }
            $last = $kept[count($kept) - 1];
            $rows = $kept;
            $next = self::encodeCursor((string) $last['document_date'], (int) $last['document_id']);
        }
        return ['items' => array_map([self::class, 'castRow'], $rows), 'next_cursor' => $next, 'search' => $search];
    }

    /**
     * Počet odpovídajících položek, nejvýš do `$cap` (výběr „všech N" víc nepotřebuje).
     *
     * @param array<string,mixed> $filters
     * @return array{count:int, capped:bool}
     */
    public function count(int $supplierId, string $target, array $filters, int $cap): array
    {
        $ids = $this->matchingIds($supplierId, $target, $filters, $cap);
        return ['count' => count($ids), 'capped' => count($ids) >= $cap];
    }

    /**
     * Id odpovídajících položek, nejvýš `$limit`.
     *
     * @param array<string,mixed> $filters
     * @return list<int>
     */
    public function matchingIds(int $supplierId, string $target, array $filters, int $limit): array
    {
        $q = $this->query($supplierId, self::documentType($target), $filters, null);
        [$rows] = $this->fetch($q, 'ii.id AS item_id', 'ORDER BY ' . $q['order'] . ' LIMIT ' . max(1, $limit));
        return array_map(static fn (array $r): int => (int) $r['item_id'], $rows);
    }

    /**
     * Ověří ručně vybraná id položek: kladná celá čísla, nejvýš {@see BulkSelection::LIMIT},
     * všechna z dokladů aktuální firmy. Cizí id celý výběr odmítne.
     *
     * @return list<int>
     * @throws BulkActionException
     */
    public function explicitIds(int $supplierId, string $target, mixed $raw): array
    {
        $type = self::documentType($target);
        if (!is_array($raw) || !array_is_list($raw)
            || array_filter($raw, static fn (mixed $id): bool => !is_int($id) || $id <= 0) !== []) {
            throw new BulkActionException('validation_failed', 'Neplatný seznam vybraných položek.', 422);
        }
        $ids = array_values(array_unique($raw));
        if ($ids === []) {
            throw new BulkActionException('no_items', 'Vyberte alespoň jednu položku.', 422);
        }
        if (count($ids) > BulkSelection::LIMIT) {
            throw BulkSelection::tooMany(count($ids));
        }
        [$items, $docs, $fk] = self::tables($type);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT ii.id FROM {$items} ii JOIN {$docs} d ON d.id = ii.{$fk} WHERE d.supplier_id = ? AND ii.id IN ({$marks})"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (count($found) !== count($ids)) {
            throw new BulkActionException('foreign_ids',
                'Některé vybrané položky v aktuální firmě nejsou (' . (count($ids) - count($found)) . '). Obnovte seznam a vyberte znovu.',
                422, ['unknown' => count($ids) - count($found)]);
        }
        sort($found);
        return $found;
    }

    /**
     * Záznamy výběru pro běh nanečisto: doklady vybraných položek (položka hromadné akce
     * je doklad, změní se na něm jen vybrané položky) s id položek v `item_ids`.
     *
     * @param list<int> $itemIds
     * @return list<array<string,mixed>>
     */
    public function rows(int $supplierId, string $target, array $itemIds): array
    {
        $type = self::documentType($target);
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($itemIds === []) {
            return [];
        }
        [$items, $docs, $fk] = self::tables($type);
        $marks = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT ii.{$fk} AS doc_id, ii.id FROM {$items} ii JOIN {$docs} d ON d.id = ii.{$fk}
              WHERE d.supplier_id = ? AND ii.id IN ({$marks})"
        );
        $stmt->execute([$supplierId, ...$itemIds]);
        $byDoc = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byDoc[(int) $r['doc_id']][] = (int) $r['id'];
        }
        $docRows = (new BulkSelection($this->db))->rows($supplierId, $type, array_keys($byDoc));
        foreach ($docRows as &$row) {
            $ids = $byDoc[(int) $row['source_id']] ?? [];
            sort($ids);
            $row['item_ids'] = $ids;
        }
        unset($row);
        return $docRows;
    }

    // ── SQL ──────────────────────────────────────────────────────────────────

    /** @return array{0:string, 1:string, 2:string} tabulka položek, tabulka dokladů, sloupec vazby */
    private static function tables(string $type): array
    {
        return $type === 'invoice'
            ? ['invoice_items', 'invoices', 'invoice_id']
            : ['purchase_invoice_items', 'purchase_invoices', 'purchase_invoice_id'];
    }

    private static function columns(string $type): string
    {
        return $type === 'invoice'
            ? "ii.id AS item_id, d.id AS document_id, d.varsymbol AS document_no, d.status, d.invoice_type AS document_kind,
               {DATE} AS document_date, d.issue_date, d.client_id AS partner_id, c.company_name AS partner_name,
               ii.description, ii.quantity, ii.unit, ii.total_without_vat, ii.total_vat, ii.total_with_vat,
               ii.vat_rate_id, ii.vat_rate_snapshot AS vat_rate, ii.vat_classification_code,
               d.vat_classification_code AS document_vat_classification_code,
               ii.revenue_account_code AS account_code, ii.seller_item_code AS product_code,
               COALESCE(ii.oss_applicable, 0) AS oss_applicable, cur.code AS currency"
            : "ii.id AS item_id, d.id AS document_id,
               COALESCE(NULLIF(d.vendor_invoice_number, ''), d.varsymbol) AS document_no, d.status, d.document_kind,
               {DATE} AS document_date, d.issue_date, d.vendor_id AS partner_id, c.company_name AS partner_name,
               ii.description, ii.quantity, ii.unit, ii.total_without_vat, ii.total_vat, ii.total_with_vat,
               ii.vat_rate_id, ii.vat_rate_snapshot AS vat_rate, ii.vat_classification_code,
               d.vat_classification_code AS document_vat_classification_code,
               ii.expense_account_code AS account_code, ii.seller_item_code AS product_code,
               0 AS oss_applicable, cur.code AS currency";
    }

    /**
     * Části dotazu: FROM, WHERE (začíná firmou a obdobím dokladu), parametry, řazení
     * a fulltextová podmínka pro druhou cestu hledání textu.
     *
     * @param array<string,mixed> $f
     * @param array{0:string, 1:int}|null $after klíč posledního dokladu předchozí stránky
     * @return array{from:string, where:list<string>, params:list<mixed>, order:string, date:string,
     *               text:?string, ft:?string, items:string, docs:string, fk:string}
     */
    private function query(int $supplierId, string $type, array $f, ?array $after, ?int $onlyDocument = null): array
    {
        [$items, $docs, $fk] = self::tables($type);
        $date = match (true) {
            $type === 'invoice' && $f['date_field'] === 'tax' => 'd.effective_tax_date',
            $type === 'invoice' => 'd.issue_date',
            $f['date_field'] === 'tax' => 'd.tax_date',
            default => 'd.issue_date',
        };
        $partner = $type === 'invoice' ? 'd.client_id' : 'd.vendor_id';
        $where = ['d.supplier_id = ?'];
        $params = [$supplierId];
        if ($f['date_from'] !== null) {
            $where[] = "{$date} >= ?";
            $params[] = $f['date_from'];
        }
        if ($f['date_to'] !== null) {
            $where[] = "{$date} < ?";
            $params[] = (new \DateTimeImmutable($f['date_to']))->modify('+1 day')->format('Y-m-d');
        }
        if ($type === 'purchase_invoice' && $f['date_field'] === 'tax') {
            $where[] = 'd.tax_date IS NOT NULL';
        }
        if ($after !== null) {
            $where[] = "({$date} < ? OR ({$date} = ? AND d.id < ?))";
            array_push($params, $after[0], $after[0], $after[1]);
        }
        if ($onlyDocument !== null) {
            $where[] = 'd.id = ?';
            $params[] = $onlyDocument;
        }
        $where[] = "d.status <> 'cancelled'";
        if ($f['partner_id'] !== null) {
            $where[] = "{$partner} = ?";
            $params[] = $f['partner_id'];
        }
        if ($f['doc_number'] !== null) {
            $prefix = self::likePrefix($f['doc_number']);
            if ($type === 'invoice') {
                $where[] = "d.varsymbol LIKE ? ESCAPE '!'";
                $params[] = $prefix;
            } else {
                $where[] = "(d.vendor_invoice_number LIKE ? ESCAPE '!' OR d.varsymbol LIKE ? ESCAPE '!')";
                array_push($params, $prefix, $prefix);
            }
        }
        if ($type === 'invoice') {
            $where[] = "ii.item_kind = 'standard'";
        }
        if ($f['vat_code'] !== null) {
            if ($f['vat_code'] === self::VAT_CODE_NONE) {
                $where[] = 'ii.vat_classification_code IS NULL';
            } else {
                $where[] = 'ii.vat_classification_code = ?';
                $params[] = $f['vat_code'];
            }
        }
        if ($f['vat_rate_id'] !== null) {
            $where[] = 'ii.vat_rate_id = ?';
            $params[] = $f['vat_rate_id'];
        }
        if ($f['account'] !== null) {
            $where[] = ($type === 'invoice' ? 'ii.revenue_account_code' : 'ii.expense_account_code') . " LIKE ? ESCAPE '!'";
            $params[] = self::likePrefix($f['account']);
        }
        if ($f['product_code'] !== null) {
            $where[] = "ii.seller_item_code LIKE ? ESCAPE '!'";
            $params[] = self::likePrefix($f['product_code']);
        }
        $text = null;
        $ft = null;
        if ($f['q'] !== '') {
            $text = '%' . self::likeEscape($f['q']) . '%';
            $ft = $this->fulltextExpression($f['q']);
        }
        $from = "{$docs} d
                 JOIN {$items} ii ON ii.{$fk} = d.id
            LEFT JOIN clients c ON c.id = {$partner} AND c.supplier_id = d.supplier_id
            LEFT JOIN currencies cur ON cur.id = d.currency_id AND cur.supplier_id = d.supplier_id";
        return [
            'from' => $from, 'where' => $where, 'params' => $params, 'order' => "{$date} DESC, d.id DESC",
            'date' => $date, 'text' => $text, 'ft' => $ft, 'items' => $items, 'docs' => $docs, 'fk' => $fk,
        ];
    }

    /**
     * Provede dotaz: s textem nejdřív cestou přes datum s omezením prohlédnutých řádků,
     * při jeho vyčerpání přes fulltext. Vrací řádky a použitou cestu (`date`, `fulltext`,
     * `date_full`).
     *
     * @param array<string,mixed> $q
     * @return array{0:list<array<string,mixed>>, 1:string}
     */
    private function fetch(array $q, string $columns, string $tail): array
    {
        $columns = str_replace('{DATE}', $q['date'], $columns);
        $where = $q['where'];
        $params = $q['params'];
        if ($q['text'] !== null) {
            $where[] = "ii.description LIKE ? ESCAPE '!'";
            $params[] = $q['text'];
        }
        $sql = "SELECT {$columns} FROM {$q['from']} WHERE " . implode(' AND ', $where) . ' ' . $tail;
        if ($q['text'] === null) {
            return [$this->select($sql, $params), 'date'];
        }
        if ($q['ft'] === null) {
            return [$this->select($sql, $params), 'date_full'];
        }
        $pdo = $this->db->pdo();
        $cap = (stripos($tail, 'LIMIT') === false ? ' LIMIT' : '') . ' ROWS EXAMINED ' . self::ROWS_EXAMINED_CAP;
        $rows = $this->select($sql . $cap, $params);
        $exceeded = false;
        foreach ($pdo->query('SHOW WARNINGS')->fetchAll(PDO::FETCH_ASSOC) as $warning) {
            $exceeded = $exceeded || (int) $warning['Code'] === self::ER_QUERY_EXCEEDED_ROWS_EXAMINED_LIMIT;
        }
        if (!$exceeded) {
            return [$rows, 'date'];
        }
        // Fulltext vybere kandidáty napříč firmami, firmu a období ověří spojení s hlavičkou;
        // podřetězec zůstává, fulltext jen zužuje.
        $partner = $q['docs'] === 'invoices' ? 'd.client_id' : 'd.vendor_id';
        $ftSql = "SELECT {$columns} FROM {$q['items']} ii
                    JOIN {$q['docs']} d ON d.id = ii.{$q['fk']}
               LEFT JOIN clients c ON c.id = {$partner} AND c.supplier_id = d.supplier_id
               LEFT JOIN currencies cur ON cur.id = d.currency_id AND cur.supplier_id = d.supplier_id
                   WHERE MATCH(ii.description) AGAINST(? IN BOOLEAN MODE) AND " . implode(' AND ', $where) . ' ' . $tail;
        return [$this->select($ftSql, [$q['ft'], ...$params]), 'fulltext'];
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function select(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Výraz pro fulltext v booleovském režimu: každé slovo hledaného textu, které index
     * zná (aspoň `innodb_ft_min_token_size` znaků, ne stopslovo), jako povinný začátek
     * slova. NULL = žádné takové slovo, hledá se jen podřetězcem.
     */
    private function fulltextExpression(string $q): ?string
    {
        $min = $this->minTokenSize();
        $terms = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) < $min || in_array($word, self::FT_STOPWORDS, true)) {
                continue;
            }
            $terms[$word] = '+' . $word . '*';
        }
        return $terms === [] ? null : implode(' ', $terms);
    }

    private function minTokenSize(): int
    {
        if ($this->minToken === null) {
            $value = $this->db->pdo()->query('SELECT @@innodb_ft_min_token_size')->fetchColumn();
            $this->minToken = max(1, (int) ($value ?: 3));
        }
        return $this->minToken;
    }

    private static function likeEscape(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    private static function likePrefix(string $value): string
    {
        return self::likeEscape($value) . '%';
    }

    /** @return array{0:string, 1:int}|null */
    private static function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || preg_match('/^(\d{4}-\d{2}-\d{2})\|([1-9][0-9]{0,18})$/D', $raw, $m) !== 1 || !IsoDate::isValid($m[1])) {
            throw new BulkActionException('validation_failed', 'Neplatný kurzor stránky.', 422);
        }
        return [$m[1], (int) $m[2]];
    }

    private static function encodeCursor(string $date, int $docId): string
    {
        return rtrim(strtr(base64_encode(substr($date, 0, 10) . '|' . $docId), '+/', '-_'), '=');
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function castRow(array $r): array
    {
        return [
            'item_id'        => (int) $r['item_id'],
            'document_id'    => (int) $r['document_id'],
            'document_no'    => $r['document_no'] === null ? null : (string) $r['document_no'],
            'document_kind'  => (string) $r['document_kind'],
            'status'         => (string) $r['status'],
            'document_date'  => $r['document_date'] === null ? null : (string) $r['document_date'],
            'issue_date'     => (string) $r['issue_date'],
            'partner_id'     => (int) $r['partner_id'],
            'partner_name'   => $r['partner_name'] === null ? null : (string) $r['partner_name'],
            'description'    => (string) $r['description'],
            'quantity'       => (float) $r['quantity'],
            'unit'           => (string) $r['unit'],
            'total_without_vat' => (float) $r['total_without_vat'],
            'total_vat'      => (float) $r['total_vat'],
            'total_with_vat' => (float) $r['total_with_vat'],
            'currency'       => $r['currency'] === null ? null : (string) $r['currency'],
            'vat_rate_id'    => (int) $r['vat_rate_id'],
            'vat_rate'       => (float) $r['vat_rate'],
            'vat_classification_code' => $r['vat_classification_code'] === null ? null : (string) $r['vat_classification_code'],
            'document_vat_classification_code' => $r['document_vat_classification_code'] === null ? null : (string) $r['document_vat_classification_code'],
            'account_code'   => $r['account_code'] === null ? null : (string) $r['account_code'],
            'product_code'   => $r['product_code'] === null ? null : (string) $r['product_code'],
            'oss'            => (bool) $r['oss_applicable'],
        ];
    }
}
