<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\Codebooks\AbstractCodebookImportService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Import seznamu členů (krok 8 #321): nejdřív náhled po řádcích, potom potvrzení stejného souboru.
 *
 * Čtení souboru, limity a mapování hlaviček sdílí s importem číselníků; zápis je ale po řádcích
 * existující cestou `MembershipMemberService::create` + `appendRevision`, každý řádek ve vlastní
 * transakci. Existující člen se páruje jen podle členského čísla, nový člen podle ID kontaktu
 * nebo IČO, jinak se kontakt založí. Podle jména se nepáruje nikdy.
 *
 * Opakování nic nezdvojí: revize nese klíč odvozený z člena a obsahu řádku a řádek se stavem
 * shodným s poslední platnou revizí je „beze změny“; nový člen je po zápisu existujícím číslem.
 * Potvrzení přijme jen soubor, jehož náhled se od zobrazení nezměnil (preview_hash), a s chybným
 * řádkem nezapíše nic. Mezi kontrolou a zápisem řádku hlídá souběh `expected_revision`.
 */
final class MembershipImportService extends AbstractCodebookImportService
{
    public const AUDIT_IMPORT = 'nonprofit_membership_import';
    private const STATUS_ALIASES = [
        'active' => 'active', 'aktivni' => 'active',
        'suspended' => 'suspended', 'pozastavene' => 'suspended', 'pozastaveno' => 'suspended', 'pozastaveni' => 'suspended',
        'ended' => 'ended', 'ukoncene' => 'ended', 'ukonceno' => 'ended', 'ukonceni' => 'ended',
    ];

    private int $actor = 0;
    private ?string $expectedHash = null;
    private ?string $ip = null;
    private string $agent = '';

    /** @param ?\Closure(array):void $beforeWrite volá se před zápisem každého řádku; testy jím simulují souběžnou změnu člena */
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $activity,
        private readonly ?MembershipMemberService $memberService = null,
        private readonly ?\Closure $beforeWrite = null,
    ) {}

    /**
     * Náhled (dryRun) nebo potvrzení. Vrací report s řádky, počty a preview_hash.
     * Potvrzení s jiným hashem než náhled vyhodí 409 se čerstvým náhledem v detailech.
     */
    public function run(int $supplier, int $actor, string $content, string $filename, bool $dryRun, ?string $expectedHash = null, ?string $ip = null, string $agent = ''): array
    {
        if ($actor <= 0) throw new MembershipException('nonprofit_membership_actor_required', 'Změna členské evidence vyžaduje autora.', 403);
        if (!$dryRun && ($expectedHash === null || preg_match('/^[0-9a-f]{64}$/D', $expectedHash) !== 1)) {
            throw new MembershipException('nonprofit_membership_invalid', 'Potvrzení importu vyžaduje otisk náhledu.');
        }
        [$this->actor, $this->expectedHash, $this->ip, $this->agent] = [$actor, $expectedHash, $ip, $agent];
        $report = $this->import($supplier, $actor, $content, $filename, $dryRun);
        if (!isset($report['preview_hash'])) {
            // Chyba celého souboru (prázdný, chybí sloupec) ze sdíleného čtení.
            $message = (string) ($report['rows'][0]['message'] ?? 'Soubor nelze zpracovat.');
            throw new MembershipException('nonprofit_membership_import_file_invalid', $message, 422);
        }
        return $report;
    }

    public static function columns(): array
    {
        return MembershipListColumns::all();
    }

    protected function requiredHeaderKeys(): array
    {
        return ['member_number', 'new_status', 'new_from', 'reference'];
    }

    protected function process(int $supplierId, array $map, array $rows, bool $dryRun): array
    {
        $plan = $this->plan($supplierId, $map, $rows);
        $hash = NonprofitTaxRules::hash(['rows' => array_map(static fn (array $row): array => $row['plan'] ?? ['line' => $row['line'], 'status' => $row['status'], 'reason' => $row['reason'] ?? null], $plan)]);
        $report = $this->report($plan, $hash, $dryRun);
        if ($dryRun) return $report;
        if (!hash_equals($hash, (string) $this->expectedHash)) {
            throw new MembershipException('nonprofit_membership_import_changed', 'Data se od náhledu změnila. Zkontrolujte nový náhled a potvrďte ho znovu.', 409, ['preview' => $report]);
        }
        if ($report['counts']['error'] > 0) {
            throw new MembershipException('nonprofit_membership_import_has_errors', 'Import obsahuje chybné řádky, nic nebylo zapsáno.', 422, ['preview' => $report]);
        }
        $written = $failed = 0;
        foreach ($plan as $index => $row) {
            if (!in_array($row['status'], ['new_revision', 'new_member'], true)) continue;
            try {
                if ($this->beforeWrite !== null) ($this->beforeWrite)($row['plan']);
                $result = $row['status'] === 'new_revision' ? $this->writeRevision($supplierId, $row['plan']) : $this->writeMember($supplierId, $row['plan']);
                $plan[$index] += ['outcome' => 'written'] + $result;
                $written++;
            } catch (MembershipException $e) {
                // Souběžná změna člena mezi náhledem a zápisem: řádek se odmítne, další pokračují
                // a opakovaný import dokončí zbytek (revize i člen jsou idempotentní).
                $plan[$index] += ['outcome' => 'failed', 'failure' => $e->errorCode, 'failure_message' => $e->getMessage()];
                $failed++;
            }
        }
        $report = $this->report($plan, $hash, false) + ['written' => $written, 'failed_writes' => $failed];
        if ($written > 0 || $failed > 0) {
            $this->activity->log(self::AUDIT_IMPORT, $this->actor, 'nonprofit_member', null, [
                'new_members' => count(array_filter($plan, static fn (array $row): bool => $row['status'] === 'new_member' && ($row['outcome'] ?? '') === 'written')),
                'new_revisions' => count(array_filter($plan, static fn (array $row): bool => $row['status'] === 'new_revision' && ($row['outcome'] ?? '') === 'written')),
                'failed' => $failed,
            ], $this->ip, $this->agent, $supplierId);
        }
        return $report;
    }

    /**
     * Klasifikace všech řádků jedním průchodem s dávkovým čtením (bez dotazu na řádek).
     * @param array<string,int> $map @param array<int,list<string>> $rows
     * @return list<array<string,mixed>>
     */
    private function plan(int $supplier, array $map, array $rows): array
    {
        $parsed = [];
        foreach ($rows as $line => $cols) {
            $value = fn (string $field): string => self::unescape($this->col($cols, $map, $field));
            $parsed[] = ['line' => $line, 'member_number' => $value('member_number'), 'client_id' => $value('client_id'), 'ic' => $value('ic'),
                'name' => $value('name'), 'new_status' => $value('new_status'), 'new_from' => $value('new_from'),
                'reference' => $value('reference'), 'evidence' => $value('evidence')];
        }
        $members = $this->membersByNumber($supplier, array_column($parsed, 'member_number', 'line'));
        $memberIds = array_values(array_unique(array_map(static fn (array $m): int => $m['id'], $members)));
        $histories = [];
        foreach (array_chunk($memberIds, 500) as $chunk) $histories += (new MembershipRepository($this->db))->historiesMany($supplier, $chunk);
        $clientIds = array_merge(array_map(static fn (array $m): int => $m['client_id'], $members),
            array_map('intval', array_filter(array_column($parsed, 'client_id'), static fn (string $id): bool => preg_match('/^[1-9][0-9]{0,17}$/D', $id) === 1)));
        $clients = $this->clientsById($supplier, $clientIds);
        $byIc = $this->clientsByIc($supplier, array_filter(array_map(static fn (array $row): ?string => self::ic($row['ic']), $parsed)));

        // Klíč revize, kterou řádek zapsal dřívějším importem: takový řádek je beze změny i tehdy,
        // když po něm přibyla další revize (jinak by starý soubor hlásil chybu data).
        $candidates = [];
        foreach ($parsed as $row) {
            $revision = isset($members[$row['line']]) ? self::revisionFields($row) : null;
            if (is_array($revision)) $candidates[] = self::revisionKey($members[$row['line']]['id'], $revision);
        }
        $known = $this->existingKeys($supplier, $candidates);

        $out = [];
        $seenNumbers = [];
        foreach ($parsed as $row) {
            $out[] = $this->classify($row, $members[$row['line']] ?? null, $histories, $clients, $byIc, $known);
        }
        // Totéž číslo (bez ohledu na velikost písmen) nebo tentýž kontakt nového člena dvakrát v souboru.
        foreach ($out as $i => $row) {
            if ($row['member_number'] === '') continue;
            $seenNumbers[mb_strtolower($row['member_number'])][] = $i;
        }
        $newClients = [];
        foreach ($out as $i => $row) {
            if ($row['status'] === 'new_member' && $row['plan']['client_id'] !== null) $newClients[$row['plan']['client_id']][] = $i;
        }
        foreach ([[$seenNumbers, 'duplicate_number'], [$newClients, 'duplicate_client']] as [$groups, $reason]) {
            foreach ($groups as $indexes) {
                if (count($indexes) < 2) continue;
                foreach ($indexes as $i) $out[$i] = $this->error($out[$i], $reason, ['lines' => implode(', ', array_map(static fn (int $j): int => $out[$j]['line'], $indexes))]);
            }
        }
        return $out;
    }

    /**
     * Stav, datum a podklad řádku, nebo kód chyby. Prázdné doložení převezme podklad.
     * @return array{status:string,effective_from:string,reference:string,evidence:string}|string
     */
    private static function revisionFields(array $row): array|string
    {
        $status = self::STATUS_ALIASES[self::normalize($row['new_status'])] ?? null;
        if ($status === null) return $row['new_status'] === '' ? 'status_missing' : 'status_invalid';
        $from = self::date($row['new_from']);
        if ($from === null) return $row['new_from'] === '' ? 'date_missing' : 'date_invalid';
        $reference = trim($row['reference']);
        $evidence = trim($row['evidence']) === '' ? $reference : trim($row['evidence']);
        if ($reference === '') return 'reference_missing';
        if (mb_strlen($reference) > 1000 || mb_strlen($evidence) > 2000) return 'reference_too_long';
        return ['status' => $status, 'effective_from' => $from, 'reference' => $reference, 'evidence' => $evidence];
    }

    private function classify(array $row, ?array $member, array $histories, array $clients, array $byIc, array $known): array
    {
        $base = ['line' => $row['line'], 'member_number' => $row['member_number'], 'name' => mb_substr($row['name'], 0, 190)];
        if ($row['member_number'] === '') return $this->error($base, 'number_missing');
        if (mb_strlen($row['member_number']) > 80) return $this->error($base, 'number_too_long');
        $change = $row['new_status'] !== '' || $row['new_from'] !== '' || $row['reference'] !== '' || $row['evidence'] !== '';
        $clientId = null;
        if ($row['client_id'] !== '') {
            if (preg_match('/^[1-9][0-9]{0,17}$/D', $row['client_id']) !== 1) return $this->error($base, 'client_id_invalid');
            $clientId = (int) $row['client_id'];
        }
        $ic = null;
        if ($row['ic'] !== '') {
            $ic = self::ic($row['ic']);
            if ($ic === null) return $this->error($base, 'ic_invalid');
        }
        if ($member !== null) {
            $base['member_id'] = $member['id'];
            $client = $clients[$member['client_id']] ?? null;
            if (($clientId !== null && $clientId !== $member['client_id']) || ($ic !== null && ($client === null || $client['ic'] !== $ic))) {
                return $this->error($base, 'contact_mismatch');
            }
            if (!$change) return $this->unchanged($base, 'no_change', []);
        } elseif (!$change) {
            return $this->error($base, 'new_member_needs_change');
        }
        $revision = self::revisionFields($row);
        if (is_string($revision)) return $this->error($base, $revision);
        ['status' => $status, 'effective_from' => $from] = $revision;

        if ($member !== null) {
            $history = $histories[$member['id']] ?? [];
            $valid = MembershipRevisionTimeline::effective($history);
            $last = $valid === [] ? null : $valid[array_key_last($valid)];
            $base += ['new_status' => $status, 'effective_from' => $from];
            if (isset($known[self::revisionKey($member['id'], $revision)])) return $this->unchanged($base, 'already_imported', []);
            if ($last === null && $status !== 'active') return $this->error($base, 'initial_status');
            if ($last !== null && $last['status'] === $status) return $this->unchanged($base, 'same_status', ['status' => $status, 'date' => $last['effective_from']]);
            if ($last !== null && $from <= $last['effective_from']) return $this->error($base, 'effective_conflict', ['date' => $last['effective_from']]);
            $expected = $history === [] ? 0 : $history[array_key_last($history)]['revision_no'];
            return $base + ['status' => 'new_revision', 'plan' => ['line' => $row['line'], 'kind' => 'revision', 'member_id' => $member['id'],
                'expected_revision' => $expected, 'idempotency_key' => self::revisionKey($member['id'], $revision)] + $revision];
        }

        $base += ['new_status' => $status, 'effective_from' => $from];
        if ($status !== 'active') return $this->error($base, 'initial_status');
        $create = null;
        if ($clientId !== null) {
            $client = $clients[$clientId] ?? null;
            if ($client === null) return $this->error($base, 'client_not_found');
            if ($ic !== null && $client['ic'] !== $ic) return $this->error($base, 'contact_mismatch');
        } elseif ($ic !== null && count($byIc[$ic] ?? []) > 1) {
            return $this->error($base, 'ic_ambiguous');
        } elseif ($ic !== null && count($byIc[$ic] ?? []) === 1) {
            $client = $byIc[$ic][0];
            $clientId = $client['id'];
        } else {
            $name = trim($row['name']);
            if ($name === '') return $this->error($base, 'name_missing');
            if (mb_strlen($name) > 190) return $this->error($base, 'name_too_long');
            $create = ['company_name' => $name, 'ic' => $ic];
            $client = null;
        }
        if ($client !== null && $client['member_number'] !== null) return $this->error($base, 'client_is_member', ['number' => $client['member_number']]);
        $base['client_id'] = $clientId;
        return $base + ['status' => 'new_member', 'contact' => $create === null ? 'existing' : 'create',
            'plan' => ['line' => $row['line'], 'kind' => 'member', 'member_number' => $row['member_number'], 'client_id' => $clientId, 'create_contact' => $create] + $revision];
    }

    private function writeRevision(int $supplier, array $plan): array
    {
        $revision = $this->members()->appendRevision($supplier, $plan['member_id'], $this->revisionInput($plan, $plan['expected_revision'], $plan['idempotency_key']), $this->actor);
        return ['revision_id' => $revision['id']];
    }

    /** Kontakt, člen a první revize v jedné transakci: částečně založený člen nezůstane. */
    private function writeMember(int $supplier, array $plan): array
    {
        $pdo = $this->db->pdo();
        $created = null;
        // Obyčejné closure, ne arrow funkce: ta by $created zachytila hodnotou a založený kontakt by se neauditoval.
        $result = TransactionFailure::retrying($pdo, function () use ($supplier, $plan, &$created): array { return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $plan, &$created): array {
            $created = null;
            $client = $plan['client_id'];
            if ($plan['create_contact'] !== null) {
                $client = (new ClientRepository($this->db))->create(['company_name' => $plan['create_contact']['company_name'],
                    'ic' => $plan['create_contact']['ic'], 'is_customer' => 1, 'is_vendor' => 0, 'is_vat_payer' => 0], $supplier);
                $created = $client;
            }
            // Číslo nebo kontakt jiného člena (i souběžně založeného) odmítne create() jako identity_conflict;
            // stejného člena vrátí a revize se pak řídí jeho historií a klíčem řádku.
            $member = $this->members()->create($supplier, $client, $plan['member_number'], $this->actor);
            $history = (new MembershipRepository($this->db))->history($supplier, $member['id'], true);
            $expected = $history === [] ? 0 : $history[array_key_last($history)]['revision_no'];
            $revision = $this->members()->appendRevision($supplier, $member['id'], $this->revisionInput($plan, $expected, self::revisionKey($member['id'], $plan)), $this->actor);
            return ['member_id' => $member['id'], 'client_id' => $client, 'revision_id' => $revision['id']];
        }); });
        if ($created !== null) {
            $this->activity->log('client.created', $this->actor, 'client', $created, ['company_name' => $plan['create_contact']['company_name'],
                'ic' => $plan['create_contact']['ic'], 'source' => self::AUDIT_IMPORT], $this->ip, $this->agent, $supplier);
        }
        return $result;
    }

    private function revisionInput(array $plan, int $expected, string $key): array
    {
        return ['status' => $plan['status'], 'effective_from' => $plan['effective_from'], 'expected_revision' => $expected, 'idempotency_key' => $key,
            'legal_basis' => ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership', 'reference' => $plan['reference'], 'evidence' => $plan['evidence']]];
    }

    /** Klíč revize z člena a obsahu řádku: stejný řádek dává stejný klíč pro kohokoli a kdykoli. */
    public static function revisionKey(int $member, array $revision): string
    {
        return 'import:' . substr(NonprofitTaxRules::hash(['member_id' => $member, 'status' => $revision['status'], 'effective_from' => $revision['effective_from'],
            'reference' => $revision['reference'], 'evidence' => $revision['evidence']]), 0, 64);
    }

    private function report(array $plan, string $hash, bool $dryRun): array
    {
        $counts = ['new_revision' => 0, 'new_member' => 0, 'unchanged' => 0, 'error' => 0];
        foreach ($plan as $row) $counts[$row['status']]++;
        $rows = array_map(static function (array $row): array {
            unset($row['plan']);
            return $row;
        }, $plan);
        return ['ok' => $counts['error'] === 0, 'dry_run' => $dryRun, 'preview_hash' => $hash, 'counts' => $counts, 'rows' => $rows];
    }

    private function error(array $row, string $reason, array $params = []): array
    {
        unset($row['plan'], $row['contact']);
        return ['status' => 'error', 'reason' => $reason, 'params' => $params] + $row;
    }

    private function unchanged(array $row, string $reason, array $params): array
    {
        unset($row['plan'], $row['contact']);
        return ['status' => 'unchanged', 'reason' => $reason, 'params' => $params] + $row;
    }

    /**
     * Členové podle čísla ve stejné kolaci jako unikátní index (bez rozdílu velikosti písmen),
     * jedním dotazem na 500 řádků přes uq_nm_number. Vstup je UNION ALL, ne tabulkový konstruktor
     * VALUES: ten MariaDB u nativně připravených dotazů plní prázdnými řetězci místo parametrů.
     * @param array<int,string> $numbers řádek => číslo
     * @return array<int,array{id:int,client_id:int,member_number:string}> řádek => člen
     */
    private function membersByNumber(int $supplier, array $numbers): array
    {
        $numbers = array_filter($numbers, static fn (string $n): bool => $n !== '' && mb_strlen($n) <= 80);
        $out = [];
        foreach (array_chunk($numbers, 500, true) as $chunk) {
            $values = 'SELECT ? line,? num' . str_repeat(' UNION ALL SELECT ?,?', count($chunk) - 1);
            $params = [];
            foreach ($chunk as $line => $number) array_push($params, $line, $number);
            $params[] = $supplier;
            $q = $this->db->pdo()->prepare("WITH input AS ({$values})
                SELECT input.line,m.id,m.client_id,m.member_number FROM input
                  JOIN nonprofit_members m FORCE INDEX(uq_nm_number) ON m.supplier_id=? AND m.member_number=input.num");
            $q->execute($params);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(int) $row['line']] = ['id' => (int) $row['id'], 'client_id' => (int) $row['client_id'], 'member_number' => $row['member_number']];
            }
        }
        return $out;
    }

    /** @return array<int,array{id:int,ic:?string,member_number:?string}> */
    private function clientsById(int $supplier, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->db->pdo()->prepare("SELECT c.id,c.ic,m.member_number FROM clients c
                LEFT JOIN nonprofit_members m ON m.supplier_id=c.supplier_id AND m.client_id=c.id
                WHERE c.supplier_id=? AND c.id IN ({$marks})");
            $q->execute([$supplier, ...$chunk]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int) $row['id']] = self::clientRow($row);
        }
        return $out;
    }

    /** @return array<string,list<array{id:int,ic:?string,member_number:?string}>> */
    private function clientsByIc(int $supplier, array $ics): array
    {
        $ics = array_values(array_unique($ics));
        $out = [];
        foreach (array_chunk($ics, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->db->pdo()->prepare("SELECT c.id,c.ic,m.member_number FROM clients c FORCE INDEX(idx_clients_ic)
                LEFT JOIN nonprofit_members m ON m.supplier_id=c.supplier_id AND m.client_id=c.id
                WHERE c.ic IN ({$marks}) AND c.supplier_id=? ORDER BY c.id");
            $q->execute([...$chunk, $supplier]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(string) $row['ic']][] = self::clientRow($row);
        }
        return $out;
    }

    /** @param list<string> $keys @return array<string,true> */
    private function existingKeys(int $supplier, array $keys): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->db->pdo()->prepare("SELECT idempotency_key FROM nonprofit_member_revisions FORCE INDEX(uq_nmr_key) WHERE supplier_id=? AND idempotency_key IN ({$marks})");
            $q->execute([$supplier, ...$chunk]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $key) $out[(string) $key] = true;
        }
        return $out;
    }

    private static function clientRow(array $row): array
    {
        return ['id' => (int) $row['id'], 'ic' => $row['ic'] === null || $row['ic'] === '' ? null : (string) $row['ic'], 'member_number' => $row['member_number']];
    }

    /** IČO jen z číslic; Excel z buňky čísla ztratí úvodní nuly, proto doplnění na 8 míst. */
    private static function ic(string $value): ?string
    {
        $digits = preg_replace('/\s+/u', '', $value) ?? '';
        if (preg_match('/^[0-9]{1,20}$/D', $digits) !== 1) return null;
        return strlen($digits) < 8 ? str_pad($digits, 8, '0', STR_PAD_LEFT) : $digits;
    }

    /** Datum RRRR-MM-DD, D. M. RRRR nebo sériové číslo Excelu; neexistující den odmítne. */
    private static function date(string $value): ?string
    {
        $value = trim($value);
        if (IsoDate::isValid($value)) return $value;
        if (preg_match('/^([0-9]{1,2})\.\s*([0-9]{1,2})\.\s*([0-9]{4})$/D', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]) : null;
        }
        if (preg_match('/^[0-9]{4,6}$/D', $value) === 1) {
            $date = ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            return IsoDate::isValid($date) ? $date : null;
        }
        return null;
    }

    /** CSV export chrání buňky začínající =, +, -, @ apostrofem (CsvWriter::safe); import ho zase odebere. */
    private static function unescape(string $value): string
    {
        return strlen($value) > 1 && $value[0] === "'" && str_contains("=+-@\t\r", $value[1]) ? substr($value, 1) : $value;
    }

    private function members(): MembershipMemberService
    {
        return $this->memberService ?? new MembershipMemberService($this->db, new MembershipRepository($this->db));
    }
}
