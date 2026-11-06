<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryAttachmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Document\JournalAttachmentStorage;
use PDO;

/**
 * Odklizení zápisů dokladu z deníku bez protizápisu, když to deník dovolí.
 *
 * Doklad, který se maže (admin force-delete) nebo kterému se ruší storno, nemá po
 * sobě nechat v deníku ani aktivní zápis, ani storno dvojici, která se jen vzájemně
 * ruší. Pokud by šly zápisy smazat ručně v deníku, udělá to tahle služba sama: stejnými
 * branami ({@see JournalEntryDeletionRules}) a se stejnou stopou v auditu jako
 * {@see \MyInvoice\Action\Accounting\JournalAction::delete()} a
 * {@see \MyInvoice\Action\Accounting\JournalAction::deleteReversalPair()}.
 *
 * Buď projde celá skupina dokladů, nebo se nesmaže nic — jediný zápis v uzavřeném či
 * uzamčeném období vrátí `blocked` a volající pokračuje svou dosavadní cestou.
 * Uzamčené datum projde, když účetní zásah potvrdil (`$lockedAcknowledged`, viz
 * {@see JournalEntryDeletionRules::blockPeriod()}); zavřené období nikdy.
 * Běží výhradně v transakci volajícího.
 */
final class DocumentJournalPurge
{
    public function __construct(
        private readonly Connection $db,
        private readonly JournalEntryRepository $journal,
        private readonly JournalEntryAttachmentRepository $attachments,
        private readonly JournalAttachmentStorage $attachmentStorage,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @param list<int> $sourceIds
     * @param array{user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{
     *   deleted: list<int>,
     *   blocked: array{source_id:int, entry_id:int, code:string, message:string, can_acknowledge?:bool}|null,
     *   attachments: list<array<string,mixed>>
     * }
     */
    public function purge(
        int $supplierId,
        string $sourceType,
        array $sourceIds,
        array $meta,
        string $reason,
        bool $lockedAcknowledged = false,
    ): array {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('DocumentJournalPurge::purge musí běžet v transakci volajícího.');
        }
        (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->lockClaims($supplierId);

        (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        $lock = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE');
        $lock->execute([$supplierId]);
        $lockedUntil = $lock->fetchColumn();
        $lockedUntil = ($lockedUntil === false || $lockedUntil === null) ? null : (string) $lockedUntil;

        $bySource = $pdo->prepare(
            self::ENTRY_SELECT . ' WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id = ? FOR UPDATE'
        );
        $byId = $pdo->prepare(self::ENTRY_SELECT . ' WHERE je.supplier_id = ? AND je.id = ? FOR UPDATE');

        /** @var list<array{source_id:int, entries:list<array<string,mixed>>}> $groups */
        $groups = [];
        $postingGroups = (new DocumentPostingGroup($this->db))->activeMany($supplierId, $sourceType, $sourceIds, true);
        foreach (array_values(array_unique(array_map('intval', $sourceIds))) as $sourceId) {
            $postingGroup = $postingGroups[$sourceId] ?? [];
            if (DocumentPostingGroup::hasOwnedEntries($postingGroup)) {
                // Přímý výmaz jednoho člena by nechal část předpisu osiřet. Volající
                // použije společnou storno cestu DocumentJournalSync pro celou skupinu.
                return self::blocked($sourceId, (int) $postingGroup[0]['id'], [
                    'code' => 'posting_group_requires_reversal',
                    'message' => 'Doklad má skupinu účetních zápisů; ruší se společnými protizápisy.',
                ]);
            }
            $bySource->execute([$supplierId, $sourceType, $sourceId]);
            $entry = $bySource->fetch(PDO::FETCH_ASSOC);
            if ($entry === false) {
                continue;
            }

            if ($entry['reversed_by'] === null) {
                $block = in_array($sourceType, JournalEntryDeletionRules::SINGLE_SOURCE_TYPES, true)
                    ? JournalEntryDeletionRules::blockSingle($entry, $lockedUntil, $lockedAcknowledged)
                    : ['code' => 'entry_delete_not_supported', 'message' => 'Tento typ zápisu se ruší přes své zdrojové workflow.'];
                if ($block !== null) {
                    return self::blocked($sourceId, (int) $entry['id'], $block);
                }
                $groups[] = ['source_id' => $sourceId, 'entries' => [$entry]];
                continue;
            }

            $byId->execute([$supplierId, (int) $entry['reversed_by']]);
            $reversal = $byId->fetch(PDO::FETCH_ASSOC);
            if ($reversal === false) {
                return self::blocked($sourceId, (int) $entry['id'], [
                    'code' => 'not_found', 'message' => 'Protizápis nenalezen.',
                ]);
            }
            if ($reversal['reversed_by'] !== null || (bool) $entry['is_reversal']) {
                return self::blocked($sourceId, (int) $entry['id'], [
                    'code' => 'reversal_chain', 'message' => 'Na storno dvojici navazuje další storno.',
                ]);
            }
            if (!in_array($sourceType, JournalEntryDeletionRules::PAIR_SOURCE_TYPES, true)) {
                return self::blocked($sourceId, (int) $entry['id'], [
                    'code' => 'entry_delete_not_supported', 'message' => 'Tento typ zápisu se ruší přes své zdrojové workflow.',
                ]);
            }
            foreach ([$entry, $reversal] as $row) {
                if ($block = JournalEntryDeletionRules::blockPeriod($row, $lockedUntil, $lockedAcknowledged)) {
                    return self::blocked($sourceId, (int) $row['id'], $block);
                }
            }
            // Původní zápis první: reversed_by je FK se SET NULL, smazání protizápisu napřed
            // by původní zápis „oživilo" a vedle náhradního zápisu téhož dokladu by narazilo
            // na unikát aktivního zdroje. Na původní zápis nic neodkazuje.
            $groups[] = ['source_id' => $sourceId, 'entries' => [$entry, $reversal]];
        }

        $entryIds = [];
        foreach ($groups as $group) foreach ($group['entries'] as $entry) $entryIds[] = (int) $entry['id'];
        (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, $entryIds);
        (new \MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, $entryIds);
        (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable($supplierId, $entryIds);
        (new \MyInvoice\Repository\AccountingSetupRepository($this->db))->detachReclassificationItemsFromEntries($supplierId, $entryIds);
        $deleted = [];
        $attachmentRows = [];
        $delete = $pdo->prepare('DELETE FROM journal_entries WHERE id = ? AND supplier_id = ?');
        foreach ($groups as $group) {
            $lines = [];
            foreach ($group['entries'] as $row) {
                $entryId = (int) $row['id'];
                $attachmentRows = array_merge($attachmentRows, $this->attachments->list($entryId, $supplierId));
                $lines[$entryId] = $this->journal->linesForEntry($entryId, $supplierId);
                $delete->execute([$entryId, $supplierId]);
                if ($delete->rowCount() !== 1) {
                    throw new \RuntimeException('Účetní zápis se nepodařilo smazat.');
                }
                $deleted[] = $entryId;
            }

            [$statusFrom, $statusTo] = $this->unbookSource($supplierId, $sourceType, $group['source_id']);

            $original = $group['entries'][0];
            $isPair = count($group['entries']) === 2;
            $lockedOverride = false;
            foreach ($group['entries'] as $row) {
                $lockedOverride = $lockedOverride || JournalEntryDeletionRules::isDateLocked($row, $lockedUntil);
            }
            $this->logger->log(
                $isPair ? 'accounting.reversal_pair_deleted' : 'accounting.entry_deleted',
                $meta['user_id'] ?? null,
                'journal_entry',
                (int) $original['id'],
                array_filter([
                    'reason'               => $reason,
                    'locked_override'      => $lockedOverride ? $lockedUntil : null,
                    'reversal_entry_id'    => $isPair ? (int) $group['entries'][1]['id'] : null,
                    'period_id'            => (int) $original['period_id'],
                    'entry_date'           => (string) $original['entry_date'],
                    'document_no'          => $original['document_no'],
                    'source_type'          => $sourceType,
                    'source_id'            => $group['source_id'],
                    'document_status_from' => $statusFrom,
                    'document_status_to'   => $statusTo,
                    'lines'                => array_map(
                        static fn (array $entryLines): array => array_map(static fn (array $line): array => [
                            'account_id'     => (int) $line['account_id'],
                            'side'           => (string) $line['side'],
                            'amount'         => (float) $line['amount'],
                            'is_red_storno'  => (bool) $line['is_red_storno'],
                            'currency_code'  => $line['currency_code'],
                            'amount_foreign' => $line['amount_foreign'],
                            'cost_center'    => $line['cost_center'],
                        ], $entryLines),
                        $lines,
                    ),
                ], static fn (mixed $value): bool => $value !== null),
                $meta['ip'] ?? null,
                $meta['user_agent'] ?? null,
                $supplierId,
            );
        }

        return ['deleted' => $deleted, 'blocked' => null, 'attachments' => $attachmentRows];
    }

    /**
     * Soubory příloh smazaných zápisů, na které už nic neukazuje. Volat až po commitu.
     *
     * @param list<array<string,mixed>> $attachmentRows
     */
    public function cleanupAttachments(int $supplierId, array $attachmentRows): void
    {
        foreach ($attachmentRows as $attachment) {
            $this->attachmentStorage->deleteIfOrphan(
                $supplierId,
                (string) $attachment['sha256'],
                (string) $attachment['filename'],
                $this->attachments,
            );
        }
    }

    private const ENTRY_SELECT =
        'SELECT je.id, je.period_id, je.entry_date, je.document_no, je.source_type, je.source_id,
                je.posted_at, je.reversed_by, p.status AS period_status,
                EXISTS(SELECT 1 FROM journal_entries r
                        WHERE r.supplier_id = je.supplier_id AND r.reversed_by = je.id) AS is_reversal
           FROM journal_entries je
           JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id';

    /** @return array{0:?string, 1:?string} stav dokladu před a po odemčení */
    private function unbookSource(int $supplierId, string $sourceType, int $sourceId): array
    {
        $pdo = $this->db->pdo();
        if ($sourceType === 'invoice') {
            $pdo->prepare('UPDATE invoices SET booked_at = NULL, booked_by = NULL WHERE id = ? AND supplier_id = ?')
                ->execute([$sourceId, $supplierId]);
            return [null, null];
        }

        $doc = $pdo->prepare('SELECT status FROM purchase_invoices WHERE id = ? AND supplier_id = ? FOR UPDATE');
        $doc->execute([$sourceId, $supplierId]);
        $status = $doc->fetchColumn();
        if ($status === false) {
            return [null, null];
        }
        $pdo->prepare(
            "UPDATE purchase_invoices
                SET booked_at = NULL, booked_by = NULL,
                    status = CASE WHEN status = 'booked' THEN 'received' ELSE status END
              WHERE id = ? AND supplier_id = ?"
        )->execute([$sourceId, $supplierId]);

        return [(string) $status, (string) $status === 'booked' ? 'received' : (string) $status];
    }

    /**
     * @param array{code:string, message:string, can_acknowledge?:bool} $block
     * @return array{deleted: list<int>, blocked: array{source_id:int, entry_id:int, code:string, message:string, can_acknowledge?:bool}, attachments: list<array<string,mixed>>}
     */
    private static function blocked(int $sourceId, int $entryId, array $block): array
    {
        return [
            'deleted'     => [],
            'blocked'     => ['source_id' => $sourceId, 'entry_id' => $entryId] + $block,
            'attachments' => [],
        ];
    }
}
