<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipErasureRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\ActivityLogHashChain;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Výmaz osobních údajů bývalých členů (#321, krok 10; vzor PayrollErasureProposalRepository).
 *
 * Nic se nemaže na pozadí: návrh jmenuje členy, kterým uplynula lhůta, člověk ho schválí
 * a teprve pak se provede. Schválí-li ho tentýž člověk, který ho sestavil, jde provést až po
 * odkladné lhůtě a do té doby jde schválení vzít zpět. Provedení posuzuje každého člena znovu
 * pod jeho zámkem; co se mezitím změnilo, přeskočí s důvodem.
 *
 * Účetní záznamy zůstávají. U člena jde o pseudonymizaci: členské číslo, volné texty revizí
 * (legal_basis, důvod zrušení), kopie čísla a textů v důkazech operací a příkazech, VS případů,
 * kontakt (jen nesdílený, MembershipContactErasure) a auditní stopa oprav z kroku 7. Hashované
 * záznamy dostanou pečeť (MembershipErasureSeal), uložené hashe se nemění.
 *
 * Každý člen se vymaže ve vlastní transakci; provedení zpracuje nejvýše EXECUTE_STEP členů
 * a opakované spuštění pokračuje u dalších, takže přerušený výmaz jde dokončit.
 * Pořadí zámků: návrh → položka → člen → historie → případy a operace → příkazy → kontakt → audit.
 */
final class MembershipErasureService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXECUTED = 'executed';
    public const SOLO_COOLING_OFF_HOURS = 72;
    /** Potvrzovací fráze; nevratný výmaz se nemá dát odklepnout stejně jako uložení formuláře. */
    public const EXECUTE_CONFIRMATION = 'TRVALE SMAZAT';
    public const MAX_ITEMS = 500;
    public const EXECUTE_STEP = 50;
    public const REDACTED = '[vymazáno]';
    public const AUDIT_CREATED = 'nonprofit_membership_erasure_created';
    public const AUDIT_APPROVED = 'nonprofit_membership_erasure_approved';
    public const AUDIT_REVOKED = 'nonprofit_membership_erasure_revoked';
    public const AUDIT_REJECTED = 'nonprofit_membership_erasure_rejected';
    public const AUDIT_EXECUTED = 'nonprofit_membership_erasure_executed';
    public const REDACTION_SOURCE = 'nonprofit_membership_erasure';
    private const MEMBER_AUDIT_ACTIONS = [MembershipCorrectionService::AUDIT_MEMBER_UPDATE, MembershipCorrectionService::AUDIT_MEMBER_DELETE, MembershipCorrectionService::AUDIT_REVISION_VOID];
    private const CLIENT_AUDIT_ACTIONS = ['client.created', 'client.updated'];

    /** @var \Closure():string|null */
    private ?\Closure $today;

    public function __construct(
        private readonly Connection $db,
        private readonly MembershipRepository $members,
        private readonly MembershipChargeRepository $charges,
        private readonly MembershipErasureRepository $erasures,
        private readonly MembershipErasureAssessment $assessment,
        private readonly MembershipContactErasure $contacts,
        private readonly ActivityLogger $activity,
        private readonly ActivityLogHashChain $chain,
        private readonly LoggerInterface $logger,
        ?\Closure $today = null,
    ) {
        $this->today = $today;
    }

    public static function placeholderNumber(int $member): string
    {
        return 'vymazano-' . $member;
    }

    /**
     * Vymazaný člen = členské číslo nahrazené pseudonymem. Trigger dovolí tuto změnu jen s provedenou
     * položkou návrhu a zpět ji nepustí; běžné zadání čísla pseudonym odmítá ({@see assertMemberNumberAllowed()}).
     */
    public static function isErasedMember(array $member): bool
    {
        return isset($member['id'], $member['member_number']) && $member['member_number'] === self::placeholderNumber((int) $member['id']);
    }

    public static function assertNotErased(array $member): void
    {
        if (self::isErasedMember($member)) {
            throw new MembershipException('nonprofit_membership_member_erased', 'Osobní údaje člena byly vymazány; členství ani předpisy už měnit nejde.', 409);
        }
    }

    /** Pseudonym vymazaného člena je vyhrazený (porovnání v databázi nerozlišuje velikost písmen). */
    public static function assertMemberNumberAllowed(string $number): void
    {
        if (preg_match('/^vymazano-[0-9]+$/iD', $number) === 1) {
            throw new MembershipException('nonprofit_membership_member_number_reserved', 'Členské číslo ve tvaru „vymazano-…“ je vyhrazené pro vymazané členy.');
        }
    }

    /** Posouzení ke dnešku: stránka ukončených členů a smazaní členové se stopou v auditu. */
    public function assessment(int $supplier, int $after, int $limit): array
    {
        $today = $this->today();
        $page = $this->assessment->members($supplier, $today, $after, $limit);
        return ['as_of' => $today, 'items' => $page['items'], 'next_after' => $page['next_after'],
            'deleted_members' => $after === 0 ? $this->assessment->deletedMembers($supplier, $today) : []];
    }

    /** Sestaví návrh ke dnešku; null, když není koho navrhnout. Otevřený může být jen jeden návrh. */
    public function create(int $supplier, int $actor, ?string $note, ?string $ip = null, ?string $userAgent = null): ?array
    {
        if ($actor <= 0) throw new MembershipException('nonprofit_membership_invalid', 'Návrh výmazu vyžaduje autora.');
        $note = $note === null ? null : trim($note);
        if ($note !== null && ($note === '' || mb_strlen($note) > 500 || !mb_check_encoding($note, 'UTF-8'))) {
            throw new MembershipException('nonprofit_membership_invalid', 'Poznámka k návrhu může mít nejvýše 500 znaků.');
        }
        $today = $this->today();
        return $this->tx(function () use ($supplier, $actor, $note, $ip, $userAgent, $today): ?array {
            // Zámek otevřených návrhů firmy (i mezery v indexu) seřadí souběžné sestavení návrhu.
            $q = $this->db->pdo()->prepare("SELECT id FROM nonprofit_membership_erasure_proposals FORCE INDEX(ix_nmep_status)
                WHERE supplier_id=? AND status IN ('pending','approved') FOR UPDATE");
            $q->execute([$supplier]);
            $open = $q->fetchColumn();
            if ($open !== false) {
                throw new MembershipException('nonprofit_membership_erasure_open', 'Jiný návrh výmazu ještě není vyřízený.', 409, ['proposal_id' => (int) $open]);
            }
            $items = [];
            $after = 0;
            do {
                $page = $this->assessment->members($supplier, $today, $after);
                foreach ($page['items'] as $item) if ($item['erasable'] && count($items) < self::MAX_ITEMS) $items[] = $item;
                $after = $page['next_after'];
            } while ($after !== null && count($items) < self::MAX_ITEMS);
            foreach ($this->assessment->deletedMembers($supplier, $today) as $item) if ($item['erasable'] && count($items) < self::MAX_ITEMS) $items[] = $item;
            if ($items === []) return null;
            $proposal = $this->erasures->insertProposal($supplier, $today, $note, $actor);
            $this->erasures->insertItems($supplier, $proposal, $items);
            $this->activity->log(self::AUDIT_CREATED, $actor, 'nonprofit_membership_erasure', $proposal, [
                'as_of' => $today, 'items' => count($items),
            ], $ip, $userAgent, $supplier);
            return $this->erasures->proposal($supplier, $proposal);
        });
    }

    public function approve(int $supplier, int $proposal, int $actor, ?string $ip = null, ?string $userAgent = null): array
    {
        return $this->tx(function () use ($supplier, $proposal, $actor, $ip, $userAgent): array {
            $row = $this->locked($supplier, $proposal, self::STATUS_PENDING);
            // Bez autora návrhu nejde tvrdit, že schvaluje někdo jiný; počítá se jako sólo.
            $solo = $row['created_by'] === null || $row['created_by'] === $actor;
            $hours = $solo ? self::SOLO_COOLING_OFF_HOURS : 0;
            $this->db->pdo()->prepare(sprintf("UPDATE nonprofit_membership_erasure_proposals SET status='approved',approved_by=?,approved_at=UTC_TIMESTAMP(),
                executable_from=UTC_TIMESTAMP() + INTERVAL %d HOUR,revoked_by=NULL,revoked_at=NULL,row_version=row_version+1
                WHERE supplier_id=? AND id=? AND status='pending'", $hours))->execute([$actor, $supplier, $proposal]);
            $this->activity->log(self::AUDIT_APPROVED, $actor, 'nonprofit_membership_erasure', $proposal, [
                'self_approved' => $solo, 'cooling_off_hours' => $hours,
            ], $ip, $userAgent, $supplier);
            return $this->erasures->proposal($supplier, $proposal, true) ?? throw new \LogicException('Approved proposal disappeared.');
        });
    }

    /** Vzetí schválení zpět; jen dokud se žádný člen nevymazal. */
    public function revoke(int $supplier, int $proposal, int $actor, ?string $ip = null, ?string $userAgent = null): array
    {
        return $this->tx(function () use ($supplier, $proposal, $actor, $ip, $userAgent): array {
            $this->locked($supplier, $proposal, self::STATUS_APPROVED);
            $this->assertUntouched($supplier, $proposal);
            $this->db->pdo()->prepare("UPDATE nonprofit_membership_erasure_proposals SET status='pending',revoked_by=?,revoked_at=UTC_TIMESTAMP(),
                approved_by=NULL,approved_at=NULL,executable_from=NULL,row_version=row_version+1 WHERE supplier_id=? AND id=? AND status='approved'")
                ->execute([$actor, $supplier, $proposal]);
            $this->activity->log(self::AUDIT_REVOKED, $actor, 'nonprofit_membership_erasure', $proposal, [], $ip, $userAgent, $supplier);
            return $this->erasures->proposal($supplier, $proposal, true) ?? throw new \LogicException('Revoked proposal disappeared.');
        });
    }

    /**
     * Zamítnutí návrhu ke schválení, nebo ukončení schváleného návrhu, který nejde dokončit. Provedené
     * položky zůstávají dokladem; nezpracované se uvolní pro další návrh.
     */
    public function reject(int $supplier, int $proposal, int $actor, ?string $ip = null, ?string $userAgent = null): array
    {
        return $this->tx(function () use ($supplier, $proposal, $actor, $ip, $userAgent): array {
            $row = $this->locked($supplier, $proposal, self::STATUS_PENDING, self::STATUS_APPROVED);
            $this->db->pdo()->prepare("UPDATE nonprofit_membership_erasure_proposals SET status='rejected',rejected_by=?,rejected_at=UTC_TIMESTAMP(),row_version=row_version+1
                WHERE supplier_id=? AND id=? AND status IN ('pending','approved')")->execute([$actor, $supplier, $proposal]);
            $this->activity->log(self::AUDIT_REJECTED, $actor, 'nonprofit_membership_erasure', $proposal, ['previous_status' => $row['status']], $ip, $userAgent, $supplier);
            return $this->erasures->proposal($supplier, $proposal, true) ?? throw new \LogicException('Rejected proposal disappeared.');
        });
    }

    /**
     * Provede dalších nejvýše EXECUTE_STEP členů schváleného návrhu. Po posledním členovi návrh uzavře.
     *
     * @return array{proposal:array<string,mixed>, processed:int, remaining:int, summary:array<string,int>}
     */
    public function execute(int $supplier, int $proposal, int $actor, string $confirmation, ?string $ip = null, ?string $userAgent = null): array
    {
        if (trim($confirmation) !== self::EXECUTE_CONFIRMATION) {
            throw new MembershipException('nonprofit_membership_erasure_confirmation', 'Výmaz vyžaduje opsání potvrzovací fráze.', 422);
        }
        if ($actor <= 0) throw new MembershipException('nonprofit_membership_invalid', 'Výmaz vyžaduje autora.');
        $summary = ['done' => 0, 'skipped_changed' => 0, 'skipped_hold' => 0];
        $processed = 0;
        while ($processed < self::EXECUTE_STEP) {
            $outcome = $this->tx(function () use ($supplier, $proposal): ?string {
                $this->executable($supplier, $proposal);
                $item = $this->erasures->nextPending($supplier, $proposal);
                if ($item === null) return null;
                return $this->eraseItem($supplier, $item);
            });
            if ($outcome === null) break;
            $summary[$outcome]++;
            $processed++;
        }
        $finished = $this->tx(function () use ($supplier, $proposal, $actor, $ip, $userAgent): array {
            $row = $this->executable($supplier, $proposal);
            $remaining = $this->remaining($supplier, $proposal);
            if ($remaining === 0) {
                $this->db->pdo()->prepare("UPDATE nonprofit_membership_erasure_proposals SET status='executed',executed_by=?,executed_at=UTC_TIMESTAMP(),row_version=row_version+1
                    WHERE supplier_id=? AND id=? AND status='approved'")->execute([$actor, $supplier, $proposal]);
                $this->activity->log(self::AUDIT_EXECUTED, $actor, 'nonprofit_membership_erasure', $proposal, [
                    'approved_by' => $row['approved_by'], 'summary' => $this->outcomes($supplier, $proposal),
                ], $ip, $userAgent, $supplier);
            }
            return ['remaining' => $remaining];
        });
        return ['proposal' => $this->erasures->proposal($supplier, $proposal) ?? throw new \LogicException('Executed proposal disappeared.'),
            'processed' => $processed, 'remaining' => $finished['remaining'], 'summary' => $summary];
    }

    /**
     * Vymaže jednoho člena (nebo auditní stopu smazaného člena). Změněné podmínky položku přeskočí;
     * rozpracované změny se pak vrátí na úložný bod, položka se jen označí.
     */
    private function eraseItem(int $supplier, array $item): string
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT membership_erasure_item');
        try {
            $item['subject'] === MembershipErasureAssessment::SUBJECT_DELETED
                ? $this->eraseDeleted($supplier, $item)
                : $this->eraseMember($supplier, $item);
            $pdo->exec('RELEASE SAVEPOINT membership_erasure_item');
            return 'done';
        } catch (MembershipErasureSkip $skip) {
            $pdo->exec('ROLLBACK TO SAVEPOINT membership_erasure_item');
            $this->erasures->finishItem($supplier, $item['id'], $skip->outcome, $skip->reason, []);
            return $skip->outcome;
        } catch (\Throwable $e) {
            // Odvolanou transakci (deadlock, čekání na zámek) řeší vlastník transakce opakováním.
            if (TransactionFailure::aborted($e) || TransactionFailure::lockConflict($e) || !$pdo->inTransaction()) throw $e;
            // Trvalá chyba jednoho člena (nečekaná data) nesmí návrh zablokovat: položka se přeskočí
            // s technickým důvodem, změny člena se vrátí a ostatní členové pokračují.
            $pdo->exec('ROLLBACK TO SAVEPOINT membership_erasure_item');
            // Diagnostika do logu aplikace; doklad o výmazu nese jen třídu chyby (zpráva může obsahovat údaj člena).
            $this->logger->error('Membership erasure item failed', ['supplier_id' => $supplier, 'erasure_item_id' => $item['id'], 'exception' => $e]);
            $this->erasures->finishItem($supplier, $item['id'], 'skipped_changed', 'technical_error', ['error' => $e::class]);
            return 'skipped_changed';
        }
    }

    private function eraseDeleted(int $supplier, array $item): array
    {
        $fresh = $this->assessment->deletedMembers($supplier, $this->today(), [$item['member_id']], $item['proposal_id']);
        $this->assertFresh($fresh[0] ?? null);
        $audit = $this->redactAudit($supplier, $item);
        $counts = ['audit_records' => $audit];
        $this->erasures->finishItem($supplier, $item['id'], 'done', null, $counts);
        return $counts;
    }

    private function eraseMember(int $supplier, array $item): array
    {
        $member = $item['member_id'];
        $row = $this->members->member($supplier, $member, true) ?? throw new MembershipErasureSkip('skipped_changed', 'member_missing');
        $fresh = $this->assessment->members($supplier, $this->today(), 0, 1, [$member], $item['proposal_id'])['items'];
        $this->assertFresh($fresh[0] ?? null, 'not_ended');
        try {
            // Celý řetěz se před přepisem ověří původním pravidlem; pečeť nesmí vzniknout nad neprůkazným obsahem.
            $history = $this->members->history($supplier, $member, true);
            $operations = $this->operations($supplier, $member);
            $commands = $this->commands($supplier, array_column($operations, 'command_id'));
        } catch (MembershipException $e) {
            if ($e->httpStatus !== 409) throw $e;
            throw new MembershipErasureSkip('skipped_changed', 'history_unproven', $e);
        }
        // Stav členství z historie čtené pod zámkem člena (posouzení čte snímek): nová revize zapsaná
        // souběžně s čekáním na zámek člena se tím nepřehlédne.
        $valid = MembershipRevisionTimeline::effective($history);
        $last = $valid === [] ? null : $valid[array_key_last($valid)];
        if ($last === null || $last['status'] !== 'ended' || $last['effective_from'] !== $fresh[0]['ended_on']) {
            throw new MembershipErasureSkip('skipped_changed', 'not_ended');
        }
        foreach ([...$history, ...$operations] as $sealedAlready) {
            if (MembershipErasureSeal::sealed($sealedAlready)) throw new MembershipErasureSkip('skipped_changed', 'already_erased');
        }
        $itemId = $item['id'];
        $pdo = $this->db->pdo();
        $rows = [];
        foreach ($history as $revision) {
            $revision['legal_basis'] = self::redactLegalBasis($revision['legal_basis']);
            $revision['request'] = self::redactRevisionRequest($revision['request']);
            $rows[] = ['id' => $revision['id'], 'legal_basis_json' => NonprofitTaxRules::canonicalJson($revision['legal_basis']),
                'request_json' => NonprofitTaxRules::canonicalJson($revision['request']), 'erasure_item_id' => $itemId,
                'erased_digest' => MembershipErasureSeal::digest($itemId, $revision['record_hash'], MembershipRepository::revisionRecord($revision))];
        }
        $this->bulkUpdate('nonprofit_member_revisions', $supplier, $rows);
        $seals = self::seals(MembershipErasureAnchor::REVISION, $rows);
        $rows = [];
        foreach ($operations as $operation) {
            $operation['proof'] = self::redactProof($operation['proof'], $member);
            $rows[] = ['id' => $operation['id'], 'proof_json' => NonprofitTaxRules::canonicalJson($operation['proof']), 'erasure_item_id' => $itemId,
                'erased_digest' => MembershipErasureSeal::digest($itemId, $operation['record_hash'], MembershipChargeRepository::operationRecord($operation))];
        }
        $this->bulkUpdate('nonprofit_membership_charge_operations', $supplier, $rows);
        $seals += self::seals(MembershipErasureAnchor::OPERATION, $rows);
        $rows = [];
        foreach ($commands as $command) {
            $request = self::redactCommandRequest($command['request']);
            $rows[] = ['id' => $command['id'], 'request_json' => NonprofitTaxRules::canonicalJson($request), 'erasure_item_id' => $itemId,
                'erased_digest' => MembershipErasureSeal::digest($itemId, $command['request_hash'], ['request' => $request])];
        }
        $this->bulkUpdate('nonprofit_membership_charge_commands', $supplier, $rows);
        $seals += self::seals(MembershipErasureAnchor::COMMAND, $rows);
        ksort($seals);
        // Kotva pečetí v zřetězeném auditu: pozdější změna zapečetěného řádku i s dopočítanou pečetí neprojde.
        $this->activity->log(MembershipErasureAnchor::ACTION, null, MembershipErasureAnchor::ENTITY, $itemId,
            ['member_id' => $member, 'seals' => $seals], null, null, $supplier);
        // VS odvozený z členského čísla; pohledávky jsou uhrazené, párování ho už nepotřebuje.
        $cases = $pdo->prepare('UPDATE nonprofit_membership_charge_cases SET variable_symbol=NULL,row_version=row_version+1
            WHERE supplier_id=? AND member_id=? AND variable_symbol IS NOT NULL');
        $cases->execute([$supplier, $member]);
        $contact = $this->contacts->erase($supplier, $row['client_id'], $member);
        $counts = [
            'revisions' => count($history), 'operations' => count($operations), 'commands' => count($commands),
            'case_variable_symbols' => $cases->rowCount(), 'audit_records' => $this->redactAudit($supplier, $item),
            'contact' => $contact['contact'], 'contact_other_uses' => $contact['other_uses'],
            'contact_audit_records' => $contact['contact'] === MembershipContactErasure::ANONYMIZED ? $this->redactAudit($supplier, $item, 'client', $row['client_id']) : 0,
        ];
        $this->erasures->finishItem($supplier, $itemId, 'done', null, $counts);
        $pdo->prepare('UPDATE nonprofit_members SET member_number=? WHERE supplier_id=? AND id=?')->execute([self::placeholderNumber($member), $supplier, $member]);
        // Pojistka: zapečetěný obsah musí projít stejným ověřením jako při každém dalším čtení.
        $this->members->history($supplier, $member, true);
        $this->commands($supplier, array_column($this->operations($supplier, $member), 'command_id'), true);
        return $counts;
    }

    /** @return array<string,string> klíč kotvy → otisk pečeti */
    private static function seals(string $type, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) $out[MembershipErasureAnchor::key($type, (int) $row['id'])] = (string) $row['erased_digest'];
        return $out;
    }

    /** Operace všech případů člena pod zámkem, každá ověřená (s pečetí i bez ní). */
    private function operations(int $supplier, int $member): array
    {
        return $this->charges->operationsForMember($supplier, $member, true);
    }

    /**
     * Jeden UPDATE na tabulku pro všechny zapečetěné řádky člena (CASE podle id). Trigger neměnnosti
     * posuzuje každý řádek zvlášť, takže hromadný zápis nic neobchází.
     *
     * @param list<array<string,mixed>> $rows řádky s `id` a stejnými sloupci
     */
    private function bulkUpdate(string $table, int $supplier, array $rows): void
    {
        if ($rows === []) return;
        $fields = array_values(array_diff(array_keys($rows[0]), ['id']));
        $sets = [];
        $params = [];
        foreach ($fields as $field) {
            $case = [];
            foreach ($rows as $row) {
                $case[] = 'WHEN ? THEN ?';
                array_push($params, $row['id'], $row[$field]);
            }
            $sets[] = "{$field}=CASE id " . implode(' ', $case) . " ELSE {$field} END";
        }
        $ids = array_column($rows, 'id');
        $q = $this->db->pdo()->prepare("UPDATE {$table} SET " . implode(',', $sets) . ' WHERE supplier_id=? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute([...$params, $supplier, ...$ids]);
        if ($q->rowCount() !== count($rows)) throw new \LogicException('Erasure update lost a locked row.');
    }

    /** Příkazy operací člena pod zámkem: před výmazem nezapečetěné a průkazné, po něm zapečetěné, průkazné a ukotvené. */
    private function commands(int $supplier, array $ids, bool $sealed = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->db->pdo()->prepare("SELECT * FROM nonprofit_membership_charge_commands WHERE supplier_id=? AND id IN ({$marks}) ORDER BY id FOR UPDATE");
        $q->execute([$supplier, ...$ids]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['request'] = json_decode((string) $row['request_json'], true, flags: JSON_THROW_ON_ERROR);
            unset($row['request_json']);
            // Před výmazem: už zapečetěný příkaz znamená dřívější výmaz (stejně jako u revizí a operací), ne poškození.
            if (!$sealed && MembershipErasureSeal::sealed($row)) throw new MembershipErasureSkip('skipped_changed', 'already_erased');
            if (MembershipErasureSeal::sealed($row) !== $sealed || !MembershipErasureSeal::commandProven($row)) {
                throw new MembershipException('nonprofit_membership_command_corrupt', 'Požadavek členského příspěvku není průkazný.', 409);
            }
            $out[] = $row;
        }
        if ($sealed) (new MembershipErasureAnchor($this->db))->assertRows($supplier, array_map(static fn (array $row): array => [MembershipErasureAnchor::COMMAND, $row], $out),
            'nonprofit_membership_command_corrupt', 'Požadavek členského příspěvku není průkazný.');
        return $out;
    }

    /**
     * Auditní stopa oprav člena (krok 7): číslo, kontakt a důvod se nahradí, řetěz zůstane průkazný.
     * U anonymizovaného kontaktu i jeho vlastní audit (název, IČO a e-mailové kontakty z adresáře).
     */
    private function redactAudit(int $supplier, array $item, string $entityType = 'nonprofit_member', ?int $entityId = null): int
    {
        $actions = $entityType === 'client' ? self::CLIENT_AUDIT_ACTIONS : self::MEMBER_AUDIT_ACTIONS;
        $marks = implode(',', array_fill(0, count($actions), '?'));
        $q = $this->db->pdo()->prepare("SELECT a.id,a.payload FROM activity_log a FORCE INDEX(idx_al_entity)
            WHERE a.entity_type=? AND a.entity_id=? AND a.supplier_id=? AND a.action IN ({$marks})
              AND NOT EXISTS(SELECT 1 FROM activity_log_redactions r WHERE r.activity_log_id=a.id)
            ORDER BY a.id");
        $q->execute([$entityType, $entityId ?? $item['member_id'], $supplier, ...$actions]);
        $count = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = $row['payload'] === null ? [] : json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);
            $redacted = $entityType === 'client' ? ['redacted' => self::REDACTED] : self::redactAuditPayload(is_array($payload) ? $payload : []);
            try {
                $this->chain->redact((int) $row['id'], $redacted, self::REDACTION_SOURCE, $item['id']);
            } catch (\RuntimeException $e) {
                // PDOException je také RuntimeException: zámek ani odvolanou transakci nepřebalovat, řeší je opakování.
                if (TransactionFailure::aborted($e) || TransactionFailure::lockConflict($e)) throw $e;
                throw new MembershipErasureSkip('skipped_changed', 'audit_unproven', $e);
            }
            $count++;
        }
        return $count;
    }

    public static function redactLegalBasis(array $basis): array
    {
        foreach (['reference', 'evidence'] as $field) if (array_key_exists($field, $basis)) $basis[$field] = '';
        return $basis;
    }

    public static function redactRevisionRequest(array $request): array
    {
        if (is_array($request['legal_basis'] ?? null)) $request['legal_basis'] = self::redactLegalBasis($request['legal_basis']);
        if (array_key_exists('reason', $request)) $request['reason'] = self::REDACTED;
        return $request;
    }

    /** Důkaz operace: číslo člena, právní podklad revizí a důvod storna; finanční obsah zůstává. */
    public static function redactProof(array $proof, int $member): array
    {
        if (is_array($proof['member'] ?? null) && array_key_exists('member_number', $proof['member'])) $proof['member']['member_number'] = self::placeholderNumber($member);
        if (is_array($proof['member_revision']['legal_basis'] ?? null)) $proof['member_revision']['legal_basis'] = self::redactLegalBasis($proof['member_revision']['legal_basis']);
        foreach ((array) ($proof['membership_intervals'] ?? []) as $index => $interval) {
            if (is_array($interval['legal_basis'] ?? null)) $proof['membership_intervals'][$index]['legal_basis'] = self::redactLegalBasis($interval['legal_basis']);
        }
        // Podklad titulu předpisu je volný text zadaný k předpisu člena (stejně ho čistí klon).
        if (is_array($proof['legal_title'] ?? null)) $proof['legal_title'] = self::redactLegalBasis($proof['legal_title']);
        if (array_key_exists('reversal_reason', $proof)) $proof['reversal_reason'] = self::REDACTED;
        return $proof;
    }

    /**
     * Uložený požadavek předpisu. Šablona hromadné dávky (nonprofit_membership_batches.template_json) se
     * nemění: patří dávce za všechny členy a podklad titulu v ní je rozhodnutí spolku, ne údaj člena.
     */
    public static function redactCommandRequest(array $command): array
    {
        if (is_array($command['request'] ?? null)) {
            if (is_array($command['request']['legal_title'] ?? null)) $command['request']['legal_title'] = self::redactLegalBasis($command['request']['legal_title']);
            if (array_key_exists('reason', $command['request'])) $command['request']['reason'] = self::REDACTED;
            if (array_key_exists('variable_symbol', $command['request'])) $command['request']['variable_symbol'] = null;
        }
        return $command;
    }

    /** @param array<mixed> $payload */
    public static function redactAuditPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) $payload[$key] = self::redactAuditPayload($value);
            elseif ($key === 'client_id') $payload[$key] = null;
            elseif ($key === 'member_number' || $key === 'reason') $payload[$key] = self::REDACTED;
        }
        return $payload;
    }

    private function assertFresh(?array $fresh, string $missing = 'not_ended'): void
    {
        if ($fresh === null) throw new MembershipErasureSkip('skipped_changed', $missing);
        if ($fresh['blocked_by'] === 'retention_hold') throw new MembershipErasureSkip('skipped_hold', 'retention_hold');
        if ($fresh['blocked_by'] !== null) throw new MembershipErasureSkip('skipped_changed', (string) $fresh['blocked_by']);
    }

    private function locked(int $supplier, int $proposal, string ...$statuses): array
    {
        $row = $this->erasures->proposal($supplier, $proposal, true)
            ?? throw new MembershipException('nonprofit_membership_erasure_not_found', 'Návrh výmazu nebyl nalezen.', 404);
        if (!in_array($row['status'], $statuses, true)) throw new MembershipException('nonprofit_membership_erasure_state', 'Návrh výmazu je v jiném stavu. Načtěte ho znovu.', 409);
        return $row;
    }

    /**
     * Schválený návrh po odkladné lhůtě, pod zámkem; čas posuzuje databáze, která lhůtu zapsala.
     * Bez nezamykajícího čtení tabulky: snímek transakce (REPEATABLE READ) smí vzniknout až po zámku člena,
     * jinak by posouzení nevidělo změnu zapsanou během čekání na zámek.
     */
    private function executable(int $supplier, int $proposal): array
    {
        $row = $this->locked($supplier, $proposal, self::STATUS_APPROVED);
        $now = (string) $this->db->pdo()->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
        if ($row['executable_from'] !== null && (string) $row['executable_from'] > $now) {
            throw new MembershipException('nonprofit_membership_erasure_cooling_off', 'Návrh výmazu je v odkladné lhůtě; do té doby jde schválení vzít zpět.', 409, ['executable_from' => $row['executable_from']]);
        }
        return $row;
    }

    private function assertUntouched(int $supplier, int $proposal): void
    {
        $q = $this->db->pdo()->prepare("SELECT 1 FROM nonprofit_membership_erasure_items WHERE supplier_id=? AND proposal_id=? AND outcome<>'pending' LIMIT 1");
        $q->execute([$supplier, $proposal]);
        if ($q->fetchColumn() !== false) throw new MembershipException('nonprofit_membership_erasure_started', 'Výmaz už probíhá, schválení nejde vzít zpět.', 409);
    }

    private function remaining(int $supplier, int $proposal): int
    {
        $q = $this->db->pdo()->prepare("SELECT COUNT(*) FROM nonprofit_membership_erasure_items WHERE supplier_id=? AND proposal_id=? AND outcome='pending'");
        $q->execute([$supplier, $proposal]);
        return (int) $q->fetchColumn();
    }

    /** @return array<string,int> */
    private function outcomes(int $supplier, int $proposal): array
    {
        $q = $this->db->pdo()->prepare('SELECT outcome,COUNT(*) FROM nonprofit_membership_erasure_items WHERE supplier_id=? AND proposal_id=? GROUP BY outcome ORDER BY outcome');
        $q->execute([$supplier, $proposal]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    private function today(): string
    {
        if ($this->today !== null) return ($this->today)();
        return (new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
    }

    private function tx(callable $work): mixed
    {
        return TransactionFailure::retrying($this->db->pdo(), fn (): mixed => (new DimensionPeriodGuard($this->db))->transaction($work));
    }
}
