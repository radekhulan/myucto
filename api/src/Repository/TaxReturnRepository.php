<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Perzistence přiznání k dani z příjmů (income_tax_returns, migrace 1030 + 1031 + 1043,
 * Epic DP) — hlavička (rok, typ FO/PO, druh přiznání, pořadí, draft/final) + ruční vstupy
 * (inputs JSON) + snapshot vypočtených řádků (computed JSON) při finalizaci.
 *
 * Druh přiznání (`variant`): řádné / opravné (před lhůtou) / dodatečné (§141 DŘ, po
 * lhůtě, rozdílově). Pořadí (`variant_seq`, migrace 1043): řádné/opravné vždy 1 (jen
 * jedno smí existovat), dodatečné 1..N (za období lze podat i několik dodatečných).
 * UNIQUE (supplier+year+type+variant+variant_seq). Default variant 'radne', seq 1 drží BC.
 *
 * Optimistická konkurence: každý zápis inkrementuje row_version a CAS UPDATE ověří,
 * že klient posílá aktuální verzi (jinak 0 dotčených řádků → konflikt). Editace
 * vstupů je povolena jen ve stavu draft; finalize/reopen přepínají stav.
 *
 * Tenant izolace: každý dotaz nese supplier_id (viz TenantPredicateTest).
 */
final class TaxReturnRepository
{
    public function __construct(private readonly Connection $db) {}

    private const TYPES = ['fo', 'po'];
    private const VARIANTS = ['radne', 'opravne', 'dodatecne'];

    private const COLS = 'id, supplier_id, year, taxpayer_type, variant, variant_seq, status, inputs, computed,
                          final_snapshot_id, finalized_at, finalized_by,
                          last_submission_id, row_version, created_by, created_at, updated_at';

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $year, string $type, string $variant = 'radne', int $variantSeq = 1, bool $currentRead = false): ?array
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Current tax return reads require a caller transaction.');
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . '
               FROM income_tax_returns
              WHERE supplier_id = ? AND year = ? AND taxpayer_type = ? AND variant = ? AND variant_seq = ?' . ($currentRead ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $year, $this->normType($type), $this->normVariant($variant), $this->normSeq($variant, $variantSeq)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Vloží nový draft (row_version = 1). Selže na duplicitě (UNIQUE
     * supplier+year+type+variant+variant_seq) — volající má nejdřív zavolat find().
     *
     * @param array<string,mixed> $inputs
     * @return array<string,mixed> uložený řádek
     */
    public function create(int $supplierId, int $year, string $type, array $inputs, ?int $createdBy, string $variant = 'radne', int $variantSeq = 1): array
    {
        $seq = $this->normSeq($variant, $variantSeq);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO income_tax_returns (supplier_id, year, taxpayer_type, variant, variant_seq, status, inputs, row_version, created_by)
             VALUES (?, ?, ?, ?, ?, \'draft\', ?, 1, ?)'
        );
        $stmt->execute([
            $supplierId,
            $year,
            $this->normType($type),
            $this->normVariant($variant),
            $seq,
            $this->encode($inputs),
            $createdBy,
        ]);
        return $this->find($supplierId, $year, $type, $variant, $seq) ?? [];
    }

    /**
     * CAS update ručních vstupů (jen ve stavu draft). Vrací aktualizovaný řádek,
     * nebo null když se nic neaktualizovalo (verze nesedí / řádek není draft / neexistuje).
     * Rozlišení příčiny nechává na volajícím (přečte find()).
     *
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>|null
     */
    public function updateInputs(int $supplierId, int $year, string $type, array $inputs, int $expectedRowVersion, string $variant = 'radne', int $variantSeq = 1): ?array
    {
        $seq = $this->normSeq($variant, $variantSeq);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE income_tax_returns
                SET inputs = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND year = ? AND taxpayer_type = ? AND variant = ? AND variant_seq = ?
                AND status = \'draft\' AND row_version = ?'
        );
        $stmt->execute([
            $this->encode($inputs),
            $supplierId,
            $year,
            $this->normType($type),
            $this->normVariant($variant),
            $seq,
            $expectedRowVersion,
        ]);
        if ($stmt->rowCount() === 0) {
            return null;
        }
        return $this->find($supplierId, $year, $type, $variant, $seq);
    }

    /**
     * Zmrazí přiznání: status='final' + uloží snapshot vypočtených řádků. CAS na verzi.
     *
     * @param array<string,mixed> $computed
     * @return array<string,mixed>|null null = konflikt/neexistuje/už final
     */
    public function finalize(int $supplierId, int $year, string $type, array $computed, int $expectedRowVersion, string $variant = 'radne', int $variantSeq = 1, ?int $snapshotId = null, ?int $finalizedBy = null): ?array
    {
        $seq = $this->normSeq($variant, $variantSeq);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE income_tax_returns
                SET status = \'final\', computed = ?, final_snapshot_id = ?, finalized_at = NOW(), finalized_by = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND year = ? AND taxpayer_type = ? AND variant = ? AND variant_seq = ?
                AND status = \'draft\' AND row_version = ?'
        );
        $stmt->execute([
            $this->encode($computed),
            $snapshotId,
            $finalizedBy,
            $supplierId,
            $year,
            $this->normType($type),
            $this->normVariant($variant),
            $seq,
            $expectedRowVersion,
        ]);
        if ($stmt->rowCount() === 0) {
            return null;
        }
        return $this->find($supplierId, $year, $type, $variant, $seq);
    }

    /**
     * Vrátí přiznání zpět do draftu (zahodí computed snapshot). CAS na verzi.
     *
     * @return array<string,mixed>|null null = konflikt/neexistuje/už draft
     */
    public function reopen(int $supplierId, int $year, string $type, int $expectedRowVersion, string $variant = 'radne', int $variantSeq = 1): ?array
    {
        $seq = $this->normSeq($variant, $variantSeq);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE income_tax_returns
                SET status = \'draft\', computed = NULL, final_snapshot_id = NULL, finalized_at = NULL, finalized_by = NULL,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND year = ? AND taxpayer_type = ? AND variant = ? AND variant_seq = ?
                AND status = \'final\' AND row_version = ?'
        );
        $stmt->execute([$supplierId, $year, $this->normType($type), $this->normVariant($variant), $seq, $expectedRowVersion]);
        if ($stmt->rowCount() === 0) {
            return null;
        }
        return $this->find($supplierId, $year, $type, $variant, $seq);
    }

    /**
     * Zapíše id posledního archivovaného podání (tax_submissions) k přiznání.
     * NEinkrementuje row_version (audit metadata, ne uživatelský vstup).
     */
    public function setLastSubmission(int $supplierId, int $year, string $type, int $submissionId, string $variant = 'radne', int $variantSeq = 1): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE income_tax_returns SET last_submission_id = ?
              WHERE supplier_id = ? AND year = ? AND taxpayer_type = ? AND variant = ? AND variant_seq = ?'
        );
        $stmt->execute([$submissionId, $supplierId, $year, $this->normType($type), $this->normVariant($variant), $this->normSeq($variant, $variantSeq)]);
    }

    /** @param array<string,mixed> $snapshot @param list<array<string,mixed>> $manifest @param list<string> $businessErrors */
    public function createSnapshot(
        int $returnId,
        int $supplierId,
        array $snapshot,
        array $manifest,
        string $xml,
        string $businessStatus,
        array $businessErrors,
        int $finalizedBy,
    ): int {
        $rev = $this->db->pdo()->prepare('SELECT COALESCE(MAX(revision_no),0)+1 FROM income_tax_return_snapshots WHERE tax_return_id = ?');
        $rev->execute([$returnId]);
        $revision = (int) $rev->fetchColumn();
        $manifestJson = $this->encode($manifest);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO income_tax_return_snapshots
                (tax_return_id, supplier_id, revision_no, snapshot_json, source_manifest, source_sha256,
                 xml_content, xml_sha256, business_status, business_errors, finalized_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $returnId, $supplierId, $revision, $this->encode($snapshot), $manifestJson,
            hash('sha256', $manifestJson), $xml, hash('sha256', $xml),
            $businessStatus === 'passed' ? 'passed' : 'failed', $this->encode($businessErrors), $finalizedBy,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function snapshot(int $supplierId, int $snapshotId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, tax_return_id, revision_no, snapshot_json, source_manifest, source_sha256,
                    xml_content, xml_sha256, business_status, business_errors, finalized_by, finalized_at
               FROM income_tax_return_snapshots WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $snapshotId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        foreach (['snapshot_json', 'source_manifest', 'business_errors'] as $key) {
            $row[$key] = $this->decode((string) $row[$key]);
        }
        $row['id'] = (int) $row['id'];
        $row['tax_return_id'] = (int) $row['tax_return_id'];
        $row['revision_no'] = (int) $row['revision_no'];
        $row['finalized_by'] = (int) $row['finalized_by'];
        return $row;
    }

    /**
     * E9 — aplikuje bankou spárované zálohy (daň/sociální/zdravotní) do ručních vstupů
     * přiznání JEN tam, kde je cílové pole dosud prázdné/nulové (žádný ruční vstup).
     *
     * Adversariální review 2026-07 (nález F3): PŮVODNÍ implementace (JSON_SET) byla ve
     * skutečnosti PŘEPIS, ne merge — kdyby účetní zadala ruční hodnotu (znala úhradu,
     * kterou modul netrackuje) a párování by pak našlo NIŽŠÍ částku, tiše by ji přepsalo
     * a podhodnotilo zaplacené zálohy → nadhodnocený doplatek. Nová sémantika: pole se
     * zapíše jen když existující hodnota v `inputs` je 0/chybí; jinak se ruční hodnota
     * NIKDY nepřepíše (vrátí se v `skipped` — volající/FE ukáže návrh k ručnímu ověření,
     * ne tiché přepsání). Metoda proto NENÍ pojmenovaná „merge" (to naznačovalo sčítání
     * nebo bezpečný merge, ani jedno neplatí).
     *
     * Používá {@see updateInputs()} (CAS na row_version) — konkurenční zápis vrátí
     * conflict=true a nic neaplikuje (bezpečnější než tiché „vyhraje poslední zápis").
     *
     * @param array{tax?:float,social?:float,health?:float} $matchedFromBank
     * @return array{applied:list<string>,skipped:list<string>,conflict:bool}
     */
    public function applyAutoMatchedAdvancesIfEmpty(int $supplierId, int $year, string $type, array $matchedFromBank, string $variant = 'radne', int $variantSeq = 1): array
    {
        $row = $this->find($supplierId, $year, $type, $variant, $variantSeq);
        if ($row === null || $row['status'] !== 'draft') {
            return ['applied' => [], 'skipped' => array_keys(array_filter($matchedFromBank, fn ($v) => (float) $v > 0)), 'conflict' => false];
        }

        $map = ['tax' => 'tax_paid_advances', 'social' => 'social_paid_advances', 'health' => 'health_paid_advances'];
        $inputs = (array) $row['inputs'];
        $newInputs = $inputs;
        $applied = [];
        $skipped = [];

        foreach ($map as $key => $field) {
            $value = (float) ($matchedFromBank[$key] ?? 0);
            if ($value <= 0.0) {
                continue;
            }
            $existing = (float) ($inputs[$field] ?? 0);
            if ($existing > 0.0) {
                // Ruční hodnota už existuje — NIKDY ji tiše nepřepisovat.
                $skipped[] = $key;
                continue;
            }
            $newInputs[$field] = round($value, 2);
            $applied[] = $key;
        }

        if ($applied === []) {
            return ['applied' => [], 'skipped' => $skipped, 'conflict' => false];
        }

        $updated = $this->updateInputs($supplierId, $year, $type, $newInputs, (int) $row['row_version'], $variant, $variantSeq);
        if ($updated === null) {
            // Konkurenční zápis mezitím vstupy změnil (CAS nesedí) — nic tiše nepřepisuj,
            // volající to zopakuje na aktuální verzi.
            return ['applied' => [], 'skipped' => array_merge($skipped, $applied), 'conflict' => true];
        }
        return ['applied' => $applied, 'skipped' => $skipped, 'conflict' => false];
    }

    /**
     * Nejvyšší dosavadní pořadí varianty za období (0 = žádné). Slouží k odvození
     * pořadí NOVÉHO dodatečného přiznání (max + 1) a k validaci, že se nezakládá díra.
     */
    public function maxVariantSeq(int $supplierId, int $year, string $type, string $variant): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(variant_seq), 0)
               FROM income_tax_returns
              WHERE supplier_id = ? AND year = ? AND taxpayer_type = ? AND variant = ?'
        );
        $stmt->execute([$supplierId, $year, $this->normType($type), $this->normVariant($variant)]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Poslední známá daňová povinnost pro dodatečné přiznání dle §141 odst. 1 DŘ —
     * NAPOSLEDY pravomocně stanovená daň, tedy z posledního FINALIZOVANÉHO přiznání
     * téhož období v řetězu řádné → opravné → dodatečné č.1 → dodatečné č.2 → …
     * Řadíme podle „pozdějšího v řetězu": dodatečné (nejvyšší seq) > opravné > řádné.
     * Vrací null, když žádné finalizované nemáme — uživatel pak zadá poslední známou
     * daň ručně.
     *
     * @param 'fo'|'po'|string $type
     */
    public function findLastKnownTax(int $supplierId, int $year, string $type): ?float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT variant, computed
               FROM income_tax_returns
              WHERE supplier_id = ? AND year = ? AND taxpayer_type = ?
                AND status = 'final' AND computed IS NOT NULL
              ORDER BY FIELD(variant,'radne','opravne','dodatecne') DESC, variant_seq DESC, updated_at DESC, id DESC
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $year, $this->normType($type)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $computed = $this->decode((string) ($row['computed'] ?? ''));
        return $this->extractTax($computed, $this->normType($type));
    }

    /** @return array<string,mixed>|null Poslední finalizovaná varianta přiznání za období. */
    public function findLastFinalized(int $supplierId, int $year, string $type, ?int $excludeId = null, bool $currentRead = false): ?array
    {
        if ($currentRead && !$this->db->pdo()->inTransaction()) throw new \LogicException('Current tax return reads require a caller transaction.');
        $exclude = $excludeId !== null ? ' AND id <> ?' : '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . '
               FROM income_tax_returns
              WHERE supplier_id = ? AND year = ? AND taxpayer_type = ?
                AND status = \'final\' AND computed IS NOT NULL' . $exclude . '
              ORDER BY FIELD(variant,\'radne\',\'opravne\',\'dodatecne\') DESC,
                       variant_seq DESC, updated_at DESC, id DESC
              LIMIT 1' . ($currentRead ? ' FOR UPDATE' : '')
        );
        $params = [$supplierId, $year, $this->normType($type)];
        if ($excludeId !== null) {
            $params[] = $excludeId;
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /**
     * Seznam existujících variant za období (pro FE — které druhy/pořadí už jsou
     * založené, jejich stav a časová osa podání). Řadí přirozeným pořadím řetězu.
     *
     * @return list<array{variant:string,variant_seq:int,status:string,row_version:int,updated_at:?string,submitted_at:?string,submission_status:?string}>
     */
    public function listVariants(int $supplierId, int $year, string $type): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT itr.variant, itr.variant_seq, itr.status, itr.row_version, itr.updated_at,
                    ts.generated_at AS submitted_at, ts.validation_status AS submission_status
               FROM income_tax_returns itr
          LEFT JOIN tax_submissions ts ON ts.id = itr.last_submission_id
              WHERE itr.supplier_id = ? AND itr.year = ? AND itr.taxpayer_type = ?
              ORDER BY FIELD(itr.variant,'radne','opravne','dodatecne'), itr.variant_seq"
        );
        $stmt->execute([$supplierId, $year, $this->normType($type)]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'variant' => (string) $r['variant'],
                'variant_seq' => (int) $r['variant_seq'],
                'status' => (string) $r['status'],
                'row_version' => (int) $r['row_version'],
                'updated_at' => $r['updated_at'] === null ? null : (string) $r['updated_at'],
                'submitted_at' => $r['submitted_at'] === null ? null : (string) $r['submitted_at'],
                'submission_status' => $r['submission_status'] === null ? null : (string) $r['submission_status'],
            ];
        }
        return $out;
    }

    /**
     * Extrahuje celkovou daňovou povinnost ze snapshotu computed. Snapshot má tvar
     * {computed:{...},podklady:...} — sáhneme do computed.summary (PO: total_tax,
     * ř.340; FO: tax / fields.kc_dan_celk).
     *
     * @param array<string,mixed> $snapshot
     */
    private function extractTax(array $snapshot, string $type): ?float
    {
        $result = (array) ($snapshot['computed'] ?? []);
        $summary = (array) ($result['summary'] ?? []);
        if ($type === 'po') {
            if (isset($summary['total_tax'])) {
                return round((float) $summary['total_tax'], 2);
            }
            return null;
        }
        // FO: preferuj celkovou daň (po zvýhodnění); fallback fields.kc_dan_celk.
        if (isset($result['tax'])) {
            return round((float) $result['tax'], 2);
        }
        $fields = (array) ($result['fields'] ?? []);
        if (isset($fields['kc_dan_celk'])) {
            return round((float) $fields['kc_dan_celk'], 2);
        }
        return null;
    }

    private function normType(string $type): string
    {
        $t = strtolower(trim($type));
        return in_array($t, self::TYPES, true) ? $t : 'fo';
    }

    private function normVariant(string $variant): string
    {
        $v = strtolower(trim($variant));
        return in_array($v, self::VARIANTS, true) ? $v : 'radne';
    }

    /** Řádné/opravné mají vždy pořadí 1; dodatečné 1..N (min. 1). */
    private function normSeq(string $variant, int $variantSeq): int
    {
        if ($this->normVariant($variant) !== 'dodatecne') {
            return 1;
        }
        return $variantSeq > 0 ? $variantSeq : 1;
    }

    /** @param array<string,mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['year'] = (int) $r['year'];
        $r['variant'] = (string) ($r['variant'] ?? 'radne');
        $r['variant_seq'] = (int) ($r['variant_seq'] ?? 1);
        $r['status'] = (string) $r['status'];
        $r['inputs'] = $this->decode($r['inputs'] ?? null);
        $r['computed'] = $r['computed'] === null ? null : $this->decode((string) $r['computed']);
        $r['final_snapshot_id'] = $r['final_snapshot_id'] === null ? null : (int) $r['final_snapshot_id'];
        $r['finalized_by'] = $r['finalized_by'] === null ? null : (int) $r['finalized_by'];
        $r['last_submission_id'] = $r['last_submission_id'] === null ? null : (int) $r['last_submission_id'];
        $r['row_version'] = (int) $r['row_version'];
        $r['created_by'] = $r['created_by'] === null ? null : (int) $r['created_by'];
        return $r;
    }

    /** @return array<string,mixed> */
    private function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
