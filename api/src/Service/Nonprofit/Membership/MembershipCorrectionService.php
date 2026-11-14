<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;

/**
 * Opravy členské evidence, dokud na záznamu nestojí účetní zápis (#321, krok 7).
 * Předpis = případ předpisu včetně stornovaného; revize nese předpis, když je v důkazu operace
 * (revize k účetnímu datu nebo pokrytý interval). Každá oprava jde do auditní stopy ve stejné transakci.
 * Pořadí zámků: (u zrušení revize období jako zápis revize a předpis →) člen → historie → navázaná data.
 * Oprava kontaktu a smazání období nezamykají; s předpisem se serializují zámkem člena.
 */
final class MembershipCorrectionService
{
    public const AUDIT_MEMBER_UPDATE = 'nonprofit_membership_member_update';
    public const AUDIT_MEMBER_DELETE = 'nonprofit_membership_member_delete';
    public const AUDIT_REVISION_VOID = 'nonprofit_membership_revision_void';
    private const OPEN_BATCH_PHASES = "'seeding','preparing','ready','posting'";

    public function __construct(
        private readonly Connection $db,
        private readonly MembershipRepository $members,
        private readonly ActivityLogger $activity,
    ) {}

    /** Které opravy server u člena právě dovolí; detail podle toho ukazuje akce. */
    public function availability(int $supplier, array $member, array $history): array
    {
        $charged = $this->hasCharges($supplier, $member['id'], false);
        $effective = MembershipRevisionTimeline::effective($history);
        $last = $effective === [] ? null : $effective[array_key_last($effective)];
        $voidBlock = $last === null ? null : $this->revisionBlock($supplier, $member['id'], $last['id'], false);
        $deleteBlock = $history !== [] ? 'nonprofit_membership_member_has_revisions'
            : ($charged ? 'nonprofit_membership_member_has_charges'
            : ($this->openBatchIds($supplier, $member['id'], false) !== [] ? 'nonprofit_membership_member_in_batch' : null));
        return [
            'can_edit_identity' => !$charged,
            'edit_identity_block' => $charged ? 'nonprofit_membership_member_has_charges' : null,
            'can_delete' => $deleteBlock === null,
            'delete_block' => $deleteBlock,
            'voidable_revision_id' => $last !== null && $voidBlock === null ? $last['id'] : null,
            'void_block' => $last === null ? null : $voidBlock,
            'head_revision_no' => $history === [] ? 0 : $history[array_key_last($history)]['revision_no'],
        ];
    }

    public function updateIdentity(int $supplier, int $member, array $input, int $actor, ?string $ip = null, ?string $userAgent = null): array
    {
        $allowed = ['client_id', 'member_number', 'expected_client_id', 'expected_member_number'];
        if (array_diff(array_keys($input), $allowed) !== [] || array_diff($allowed, array_keys($input)) !== [] || $actor <= 0
            || !is_int($input['client_id']) || $input['client_id'] <= 0 || !is_int($input['expected_client_id'])
            || !is_string($input['member_number']) || !is_string($input['expected_member_number'])) {
            throw new MembershipException('nonprofit_membership_invalid', 'Oprava člena vyžaduje kontakt, členské číslo a původní hodnoty.');
        }
        $number = trim($input['member_number']);
        if ($number === '' || mb_strlen($number) > 80) throw new MembershipException('nonprofit_membership_invalid', 'Členské číslo musí mít 1–80 znaků.');
        $client = $input['client_id'];
        return TransactionFailure::retrying($this->db->pdo(), fn (): array => (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $member, $input, $number, $client, $actor, $ip, $userAgent): array {
            $current = $this->lockedMember($supplier, $member);
            // Opakování po nejisté odpovědi: cílový stav už platí, vrátí se beze změny a bez dalšího auditu.
            if ($current['client_id'] === $client && $current['member_number'] === $number) return $current;
            if ($current['client_id'] !== $input['expected_client_id'] || $current['member_number'] !== $input['expected_member_number']) {
                throw new MembershipException('nonprofit_membership_member_changed', 'Člen se mezitím změnil. Načtěte ho znovu.', 409);
            }
            if ($this->hasCharges($supplier, $member, true)) {
                throw new MembershipException('nonprofit_membership_member_has_charges', 'Člen už má předpis, kontakt ani členské číslo nejde opravit.', 409);
            }
            $q = $this->db->pdo()->prepare('SELECT id FROM clients WHERE supplier_id=? AND id=? LOCK IN SHARE MODE');
            $q->execute([$supplier, $client]);
            if ($q->fetchColumn() === false) throw new MembershipException('nonprofit_membership_client_not_found', 'Klient nebyl nalezen v této organizaci.', 404);
            // Kolizi posuzuje databáze se svou kolací (oprava jen velikosti písmen u téhož člena projde).
            // Dva dotazy po unikátních indexech zamknou jen dotčený záznam, ne všechny členy organizace.
            foreach (['uq_nm_client' => 'client_id', 'uq_nm_number' => 'member_number'] as $index => $column) {
                $q = $this->db->pdo()->prepare("SELECT id FROM nonprofit_members FORCE INDEX({$index}) WHERE supplier_id=? AND {$column}=? AND id<>? FOR UPDATE");
                $q->execute([$supplier, $column === 'client_id' ? $client : $number, $member]);
                if ($q->fetchColumn() !== false) throw new MembershipException('nonprofit_membership_identity_conflict', 'Klient nebo členské číslo už patří jinému členovi.', 409);
            }
            $q = $this->db->pdo()->prepare('UPDATE nonprofit_members SET client_id=?,member_number=? WHERE supplier_id=? AND id=?');
            $q->execute([$client, $number, $supplier, $member]);
            $this->activity->log(self::AUDIT_MEMBER_UPDATE, $actor, 'nonprofit_member', $member, [
                'before' => ['client_id' => $current['client_id'], 'member_number' => $current['member_number']],
                'after' => ['client_id' => $client, 'member_number' => $number],
            ], $ip, $userAgent, $supplier);
            return $this->members->member($supplier, $member, true) ?? throw new \LogicException('Updated member disappeared.');
        }));
    }

    public function delete(int $supplier, int $member, int $actor, ?string $ip = null, ?string $userAgent = null): array
    {
        if ($actor <= 0) throw new MembershipException('nonprofit_membership_invalid', 'Smazání člena vyžaduje autora.');
        return TransactionFailure::retrying($this->db->pdo(), fn (): array => (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $member, $actor, $ip, $userAgent): array {
            // Plnění dávky zamyká dávku a pak (cizím klíčem položky) člena; smazání proto zamyká plněné dávky
            // dřív než člena. Zámek rozsahu indexu zároveň pozdrží založení nové dávky do konce smazání.
            $seeding = $this->seedingBatchIds($supplier, $member, true);
            $current = $this->lockedMember($supplier, $member);
            $q = $this->db->pdo()->prepare('SELECT 1 FROM nonprofit_member_revisions WHERE supplier_id=? AND member_id=? LIMIT 1 LOCK IN SHARE MODE');
            $q->execute([$supplier, $member]);
            if ($q->fetchColumn() !== false) throw new MembershipException('nonprofit_membership_member_has_revisions', 'Člen má zapsanou historii členství, smazat ho nejde. Opravit jde kontakt a číslo.', 409);
            // Předpis vyžaduje aktivní revizi, takže tuto větev zastaví už kontrola revizí; zůstává jako pojistka pro převzatá data.
            if ($this->hasCharges($supplier, $member, true)) throw new MembershipException('nonprofit_membership_member_has_charges', 'Člen už má předpis, smazat ho nejde.', 409);
            $open = $this->openBatchIds($supplier, $member, true, $seeding);
            if ($open !== []) throw new MembershipException('nonprofit_membership_member_in_batch', 'Člen je v rozpracovaném hromadném předpisu. Dokončete nebo zrušte dávku.', 409, ['batch_ids' => $open]);
            // Dokončená nebo zrušená dávka zapisuje i člena bez revize jako odmítnutého; řádek bez operace
            // nenese účetní zápis. Hlavička dávky se sníží stejně, aby její počty dál odpovídaly položkám.
            $q = $this->db->pdo()->prepare("SELECT bm.batch_id FROM nonprofit_membership_batch_members bm
                JOIN nonprofit_membership_batches b ON b.supplier_id=bm.supplier_id AND b.id=bm.batch_id
                WHERE bm.supplier_id=? AND bm.member_id=? AND bm.status='rejected' AND bm.operation_id IS NULL AND b.phase IN ('finished','cancelled')
                ORDER BY bm.batch_id FOR UPDATE");
            $q->execute([$supplier, $member]);
            $batches = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
            if ($batches !== []) {
                $marks = implode(',', array_fill(0, count($batches), '?'));
                $this->db->pdo()->prepare("DELETE FROM nonprofit_membership_batch_members WHERE supplier_id=? AND member_id=? AND status='rejected' AND operation_id IS NULL AND batch_id IN ({$marks})")
                    ->execute([$supplier, $member, ...$batches]);
                $this->db->pdo()->prepare("UPDATE nonprofit_membership_batches SET member_count=member_count-1,rejected_count=rejected_count-1 WHERE supplier_id=? AND id IN ({$marks})")
                    ->execute([$supplier, ...$batches]);
            }
            try {
                $this->db->pdo()->prepare('DELETE FROM nonprofit_members WHERE supplier_id=? AND id=?')->execute([$supplier, $member]);
            } catch (\PDOException $e) {
                // Pojistka pro vazbu, kterou kontroly výše neznají (např. tabulka z dalšího kroku).
                if (TransactionFailure::aborted($e) || (int) ($e->errorInfo[1] ?? 0) !== 1451) throw $e;
                throw new MembershipException('nonprofit_membership_member_in_use', 'Člena používají další záznamy, smazat ho nejde.', 409);
            }
            $this->activity->log(self::AUDIT_MEMBER_DELETE, $actor, 'nonprofit_member', $member, [
                'client_id' => $current['client_id'], 'member_number' => $current['member_number'], 'rejected_batch_ids' => $batches,
            ], $ip, $userAgent, $supplier);
            return ['deleted' => true, 'member_id' => $member];
        }));
    }

    public function voidRevision(int $supplier, int $member, int $revision, array $input, int $actor, ?string $ip = null, ?string $userAgent = null): array
    {
        $allowed = ['expected_revision', 'reason', 'idempotency_key'];
        if (array_diff(array_keys($input), $allowed) !== [] || $actor <= 0 || $revision <= 0
            || !is_int($input['expected_revision'] ?? null) || $input['expected_revision'] < 1) {
            throw new MembershipException('nonprofit_membership_invalid', 'Zrušení revize vyžaduje očekávanou revizi, důvod a autora.');
        }
        $reason = MembershipChargeRequest::text($input['reason'] ?? null, 'reason', 500);
        $key = MembershipChargeRequest::key($input['idempotency_key'] ?? null);
        $request = ['action' => 'void', 'member_id' => $member, 'revision_id' => $revision, 'reason' => $reason,
            'expected_revision' => $input['expected_revision'], 'actor' => $actor];
        $requestHash = NonprofitTaxRules::hash($request);
        return TransactionFailure::retrying($this->db->pdo(), fn (): array => (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $member, $revision, $input, $actor, $key, $request, $requestHash, $ip, $userAgent): array {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);
            $existing = $this->members->revisionByKey($supplier, $key, true);
            if ($existing !== null) {
                if (!hash_equals($existing['request_hash'], $requestHash)) throw new MembershipException('nonprofit_membership_idempotency_conflict', 'Klíč požadavku už patří jiné revizi členství.', 409);
                return $existing;
            }
            $this->lockedMember($supplier, $member);
            $history = $this->members->history($supplier, $member, true);
            if (!in_array($revision, array_column($history, 'id'), true)) {
                throw new MembershipException('nonprofit_membership_revision_not_found', 'Revize nepatří tomuto členovi.', 404);
            }
            $head = $history[array_key_last($history)];
            if ($head['revision_no'] !== $input['expected_revision']) throw new MembershipException('nonprofit_membership_revision_changed', 'Historie členství se mezitím změnila.', 409);
            $effective = MembershipRevisionTimeline::effective($history);
            $target = $effective === [] ? null : $effective[array_key_last($effective)];
            if ($target === null || $target['id'] !== $revision) {
                throw new MembershipException('nonprofit_membership_revision_not_last', 'Zrušit jde jen poslední platnou revizi členství.', 409);
            }
            $block = $this->revisionBlock($supplier, $member, $revision, true);
            if ($block !== null) throw new MembershipException($block, 'Na revizi stojí předpis příspěvku, zrušit ji nejde.', 409);
            $voiding = $this->members->appendRevision([
                'supplier_id' => $supplier, 'member_id' => $member, 'revision_no' => $head['revision_no'] + 1,
                'previous_id' => $head['id'], 'voids_revision_id' => $target['id'], 'effective_from' => $target['effective_from'],
                'status' => $target['status'], 'legal_basis' => $target['legal_basis'], 'request' => $request,
                'idempotency_key' => $key, 'request_hash' => $requestHash, 'parent_hash' => $head['record_hash'], 'created_by' => $actor,
            ]);
            $this->activity->log(self::AUDIT_REVISION_VOID, $actor, 'nonprofit_member', $member, [
                'revision_id' => $target['id'], 'revision_no' => $target['revision_no'], 'voiding_revision_id' => $voiding['id'], 'reason' => $request['reason'],
            ], $ip, $userAgent, $supplier);
            return $voiding;
        }));
    }

    private function lockedMember(int $supplier, int $member): array
    {
        return $this->members->member($supplier, $member, true)
            ?? throw new MembershipException('nonprofit_membership_member_not_found', 'Člen nebyl nalezen v této organizaci.', 404);
    }

    /** Předpis včetně stornovaného; pohledávka bez případu nevzniká, kontroluje se jako pojistka. */
    private function hasCharges(int $supplier, int $member, bool $lock): bool
    {
        $suffix = $lock ? ' LOCK IN SHARE MODE' : '';
        foreach (['nonprofit_membership_charge_cases', 'nonprofit_membership_charge_claims'] as $table) {
            $q = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE supplier_id=? AND member_id=? LIMIT 1{$suffix}");
            $q->execute([$supplier, $member]);
            if ($q->fetchColumn() !== false) return true;
        }
        return false;
    }

    /** Revize nese předpis, je-li revizí k účetnímu datu nebo pokrytým intervalem v důkazu kterékoli operace člena. */
    private function revisionBlock(int $supplier, int $member, int $revision, bool $lock): ?string
    {
        $q = $this->db->pdo()->prepare('SELECT o.member_revision_id,o.proof_json FROM nonprofit_membership_charge_cases c
            JOIN nonprofit_membership_charge_operations o FORCE INDEX(uq_nmop_case) ON o.supplier_id=c.supplier_id AND o.case_id=c.id
            WHERE c.supplier_id=? AND c.member_id=? ORDER BY o.id' . ($lock ? ' LOCK IN SHARE MODE' : ''));
        $q->execute([$supplier, $member]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['member_revision_id'] === $revision) return 'nonprofit_membership_revision_has_charges';
            try {
                $proof = json_decode((string) $row['proof_json'], true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return 'nonprofit_membership_revision_has_charges';
            }
            foreach ((array) ($proof['membership_intervals'] ?? []) as $interval) {
                if ((int) ($interval['id'] ?? 0) === $revision) return 'nonprofit_membership_revision_has_charges';
            }
        }
        return null;
    }

    /**
     * Rozpracované dávky člena: už zapsaná položka, nebo plněná dávka, do které člen ještě může spadnout
     * (plnění čte členy bez zámku a jeho položka by po smazání skončila chybou cizího klíče).
     */
    private function openBatchIds(int $supplier, int $member, bool $lock, ?array $seeding = null): array
    {
        $q = $this->db->pdo()->prepare('SELECT bm.batch_id FROM nonprofit_membership_batch_members bm
            JOIN nonprofit_membership_batches b ON b.supplier_id=bm.supplier_id AND b.id=bm.batch_id
            WHERE bm.supplier_id=? AND bm.member_id=? AND b.phase IN (' . self::OPEN_BATCH_PHASES . ')' . ($lock ? ' LOCK IN SHARE MODE' : ''));
        $q->execute([$supplier, $member]);
        $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_values(array_unique([...$ids, ...($seeding ?? $this->seedingBatchIds($supplier, $member, false))]));
        sort($ids);
        return $ids;
    }

    /**
     * Plněné dávky, do kterých člen ještě může spadnout (plnění čte členy bez zámku).
     * Index (supplier_id, phase) omezí zámek jen na plněné dávky, ostatní dávky organizace neblokuje.
     */
    private function seedingBatchIds(int $supplier, int $member, bool $lock): array
    {
        $q = $this->db->pdo()->prepare("SELECT id FROM nonprofit_membership_batches FORCE INDEX(ix_nmb_phase) WHERE supplier_id=? AND phase='seeding'
            AND seed_cursor<? AND seed_until>=? AND (selection_mode='active' OR JSON_CONTAINS(selection_json, ?))" . ($lock ? ' LOCK IN SHARE MODE' : ''));
        $q->execute([$supplier, $member, $member, (string) $member]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }
}
