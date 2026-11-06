<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\InternalDocumentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use PDO;

/**
 * Interní doklady (ID): koncept s řádky kontace → zaúčtování ručním zápisem deníku.
 *
 * Zápis jde přes {@see ManualJournalEntryService}, tedy stejnou cestou jako ruční zápis
 * z deníku: `source_type = 'manual'`, `source_id` NULL, podvojnost, účtová osnova,
 * otevřené období a zámek k datu hlídá {@see PostingService}. Vazbu na zápis nese
 * hlavička dokladu (`journal_entry_id`, `reversal_entry_id`), takže hlavní kniha,
 * výkazy, saldokonto i uzávěrkové kontroly berou zápis jako každý jiný ruční zápis.
 *
 * Storno = storno zápisu přes {@see PostingService::reverse()} (protizápis), stejně
 * jako v deníku. Číslo se vydává při zaúčtování z řady ID (`manual`).
 */
final class InternalDocumentService
{
    public const KINDS = ['other', 'offset', 'reclassification', 'write_off', 'fx_difference', 'correction', 'accrual'];

    private const MAX_LINES = 200;
    private const MAX_AMOUNT = 999999999999.99;

    public function __construct(
        private readonly Connection $db,
        private readonly InternalDocumentRepository $documents,
        private readonly ManualJournalEntryService $manualEntries,
        private readonly PostingService $posting,
        private readonly DimensionService $dimensions,
        private readonly JournalEntryRepository $journal,
        private readonly ChartOfAccountsRepository $accounts,
    ) {}

    public function get(int $supplierId, int $id): array
    {
        return $this->documents->find($supplierId, $id)
            ?? throw new InternalDocumentException('not_found', 'Interní doklad nebyl nalezen.', 404);
    }

    public function list(int $supplierId, array $filters, int $page, int $perPage): array
    {
        return $this->documents->list($supplierId, self::listFilters($filters), max(1, $page), max(1, min(200, $perPage)));
    }

    /** @return \Generator<int, array<string,mixed>> */
    public function exportRows(int $supplierId, array $filters): \Generator
    {
        return $this->documents->exportRows($supplierId, self::listFilters($filters));
    }

    public function create(int $supplierId, array $input, ?int $userId): array
    {
        $data = $this->normalize($supplierId, $input);
        $id = $this->inTransaction(function () use ($supplierId, $data, $userId): int {
            $id = $this->documents->insert($supplierId, $data, $userId);
            $this->documents->replaceLines($supplierId, $id, $data['lines']);
            return $id;
        });
        return $this->get($supplierId, $id);
    }

    public function update(int $supplierId, int $id, array $input, ?int $userId): array
    {
        $this->inTransaction(function () use ($supplierId, $id, $input, $userId): void {
            $existing = $this->documents->find($supplierId, $id, true)
                ?? throw new InternalDocumentException('not_found', 'Interní doklad nebyl nalezen.', 404);
            if ($existing['status'] !== 'draft') {
                throw new InternalDocumentException('not_draft', 'Upravovat lze jen koncept.', 409);
            }
            // Částečná úprava: chybějící klíč = uložená hodnota.
            foreach (['kind', 'title', 'document_date', 'entry_date', 'partner_id', 'partner_name', 'note',
                'lines', 'document_links'] as $key) {
                if (!array_key_exists($key, $input)) $input[$key] = $existing[$key] ?? null;
            }
            // Uzavřenou hodnotu dimenze, kterou koncept už nese, smí úprava ponechat.
            $current = [];
            foreach ($existing['lines'] as $line) {
                foreach ($line['dimensions'] ?? [] as $valueId) $current[] = (int) $valueId;
            }
            $data = $this->normalize($supplierId, $input, array_values(array_unique($current)));
            // Číslo z dřívějšího zaúčtování patří řadě jeho účetního roku; po změně roku
            // dostane doklad při zaúčtování nové číslo z řady nového roku.
            $clearNumber = $existing['document_no'] !== null
                && $this->manualEntries->fiscalYear($supplierId, (string) $existing['entry_date'])
                    !== $this->manualEntries->fiscalYear($supplierId, $data['entry_date']);
            $this->documents->updateDraft($supplierId, $id, $data, $userId, $clearNumber);
            $this->documents->replaceLines($supplierId, $id, $data['lines']);
        });
        return $this->get($supplierId, $id);
    }

    public function deleteDraft(int $supplierId, int $id): void
    {
        $this->inTransaction(function () use ($supplierId, $id): void {
            $existing = $this->documents->find($supplierId, $id, true)
                ?? throw new InternalDocumentException('not_found', 'Interní doklad nebyl nalezen.', 404);
            if ($existing['status'] !== 'draft') {
                throw new InternalDocumentException('not_draft', 'Smazat lze jen koncept. Zaúčtovaný doklad stornujte.', 409);
            }
            $this->documents->deleteDraft($supplierId, $id);
        });
    }

    /**
     * Zaúčtuje koncept ručním zápisem deníku. Číslo dokladu, zápis i stav hlavičky
     * vznikají v jedné transakci; odvolanou transakci zopakuje celou.
     *
     * @param array<string,mixed> $audit user_id, posted_by, ip, user_agent
     */
    public function post(int $supplierId, int $id, array $audit): array
    {
        $pdo = $this->db->pdo();
        TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $id, $audit): void {
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) $pdo->beginTransaction();
            try {
                $doc = $this->documents->find($supplierId, $id, true)
                    ?? throw new InternalDocumentException('not_found', 'Interní doklad nebyl nalezen.', 404);
                if ($doc['status'] !== 'draft') {
                    throw new InternalDocumentException('not_draft', 'Zaúčtovat lze jen koncept.', 409);
                }
                if ($doc['lines'] === []) {
                    throw new InternalDocumentException('lines_required', 'Doklad nemá žádné řádky kontace.');
                }
                self::assertBalanced($doc['lines']);
                try {
                    $lines = $this->manualEntries->normalizeLines($supplierId, $doc['lines']);
                    $links = $this->manualEntries->normalizeLinks($supplierId, $doc['document_links']);
                } catch (ManualJournalEntryException $e) {
                    throw new InternalDocumentException($e->errorCode, $e->getMessage(), $e->httpStatus);
                }
                $entryDate = (string) $doc['entry_date'];
                // Číslo z dřívějšího zaúčtování (zápis smazaný v deníku) se použije znovu.
                $number = $doc['document_no'] ?? $this->freeNumber($supplierId, $entryDate, $id);
                $userId = isset($audit['user_id']) ? (int) $audit['user_id'] : null;
                $entryId = $this->manualEntries->post($supplierId, $lines, array_merge($audit, [
                    'entry_date' => $entryDate,
                    'document_date' => (string) $doc['document_date'],
                    'document_no' => $number,
                    'description' => (string) $doc['title'],
                    'posted' => true,
                ]), $links);
                $this->documents->setPosted($supplierId, $id, $number, $entryId, $userId);
                if ($ownTx) $pdo->commit();
            } catch (\Throwable $e) {
                if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
        return $this->get($supplierId, $id);
    }

    /**
     * Storno zaúčtovaného dokladu = storno jeho zápisu protizápisem. Bez data se
     * ruší k datu původního zápisu (do zamčeného/uzavřeného období PostingService
     * nepustí, účetní pak zadá datum v otevřeném období).
     *
     * @param array<string,mixed> $audit
     */
    public function reverse(int $supplierId, int $id, string $reason, ?string $entryDate, array $audit): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new InternalDocumentException('reason_required', 'Uveďte důvod storna (3 až 500 znaků).');
        }
        if ($entryDate !== null && !IsoDate::isValid($entryDate)) {
            throw new InternalDocumentException('invalid_date', 'Neplatné datum storna.');
        }
        // Pořadí zámků jako JournalAction::reverse (období firmy, pak hlavička a zápis),
        // jinak se storno dokladu a souběžné účtování zablokují navzájem. Odvolanou
        // transakci zopakuje celou.
        TransactionFailure::retrying($this->db->pdo(), fn () => $this->inTransaction(function () use ($supplierId, $id, $reason, $entryDate, $audit): void {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            $doc = $this->documents->find($supplierId, $id, true)
                ?? throw new InternalDocumentException('not_found', 'Interní doklad nebyl nalezen.', 404);
            if ($doc['status'] !== 'posted' || $doc['journal_entry_id'] === null) {
                throw new InternalDocumentException('not_posted', 'Stornovat lze jen zaúčtovaný doklad.', 409);
            }
            $reversalId = $this->posting->reverse($supplierId, (int) $doc['journal_entry_id'], array_filter([
                'entry_date' => $entryDate,
                'description' => 'Storno ' . $doc['document_no'] . ': ' . $reason,
            ], static fn ($v): bool => $v !== null) + $audit);
            $this->documents->setReversed($supplierId, $id, $reversalId, $reason,
                isset($audit['user_id']) ? (int) $audit['user_id'] : null);
        }));
        return $this->get($supplierId, $id);
    }

    /**
     * Číslo z řady ID, které ještě nenese jiný interní doklad. Kolize vzniká, když
     * někdo čítač řady ručně snížil, nebo když stejné číslo doklad převzal ze zápisu;
     * kolidující číslo se přeskočí, jinak by koncept nešel zaúčtovat nikdy (rollback
     * vrací čítač zpět na totéž číslo).
     */
    private function freeNumber(int $supplierId, string $entryDate, ?int $exceptId): string
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $number = $this->manualEntries->nextNumber($supplierId, $entryDate);
            if (!$this->documents->documentNoTaken($supplierId, $number, $exceptId)) return $number;
        }
        throw new InternalDocumentException('series_exhausted',
            'Číselná řada ID nevydala volné číslo. Zkontrolujte další číslo řady v Nástrojích.', 409);
    }

    /**
     * Hlavička nad existujícím ručním zápisem. Zápis se nemění, jen dostane číslo
     * z řady ID, pokud žádné nemá (to smí jen zápis v otevřeném období, stejně jako
     * úprava popisu zápisu).
     *
     * @return array{document: array<string,mixed>, numbered: bool}
     */
    public function createFromEntry(int $supplierId, int $entryId, array $input, array $audit): array
    {
        $kind = (string) ($input['kind'] ?? 'other');
        if (!in_array($kind, self::KINDS, true)) {
            throw new InternalDocumentException('invalid_kind', 'Neplatný druh interního dokladu.');
        }
        $userId = isset($audit['user_id']) ? (int) $audit['user_id'] : null;
        $numbered = false;
        // Obyčejný closure (ne arrow fn): arrow fn by $numbered zachytil hodnotou a příznak by se ztratil.
        $id = TransactionFailure::retrying($this->db->pdo(), function () use ($supplierId, $entryId, $input, $kind, $userId, $audit, &$numbered): int {
            $numbered = false;
            return $this->inTransaction(function () use ($supplierId, $entryId, $input, $kind, $userId, $audit, &$numbered): int {
            $pdo = $this->db->pdo();
            // Pořadí zámků jako mazání a storno v deníku: období firmy, pak zápis.
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            $stmt = $pdo->prepare(
                'SELECT je.id, je.entry_date, je.document_date, je.document_no, je.description, je.source_type,
                        je.source_id, je.posted_at, je.reversed_by,
                        EXISTS(SELECT 1 FROM journal_entries r
                                WHERE r.supplier_id = je.supplier_id AND r.reversed_by = je.id) AS is_reversal
                   FROM journal_entries je
                  WHERE je.id = ? AND je.supplier_id = ?
                  FOR UPDATE'
            );
            $stmt->execute([$entryId, $supplierId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($entry === false) {
                throw new InternalDocumentException('entry_not_found', 'Účetní zápis nenalezen.', 404);
            }
            if ($entry['source_type'] !== 'manual' || $entry['source_id'] !== null) {
                throw new InternalDocumentException('entry_not_manual', 'Interní doklad lze vytvořit jen z ručního zápisu.', 409);
            }
            if ($entry['posted_at'] === null || $entry['reversed_by'] !== null || (int) $entry['is_reversal'] === 1) {
                throw new InternalDocumentException('entry_not_active',
                    'Interní doklad lze vytvořit jen ze zaúčtovaného zápisu, který není stornovaný ani stornem.', 409);
            }
            // Ruční zápis převzatý dokladem (skupina zaúčtování faktury) je část jeho předpisu
            // a řídí ho doklad, ne interní doklad.
            if ((new DocumentPostingGroup($this->db))->owner($supplierId, $entryId, true) !== null) {
                throw new InternalDocumentException('entry_owned_by_document',
                    'Zápis patří k zaúčtování dokladu, interní doklad nad ním vytvořit nelze.', 409);
            }
            // Stejné pojistky navazujících agend jako mazání zápisu v deníku.
            (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, [$entryId]);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertEntriesMutable($supplierId, [$entryId]);
            (new \MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, [$entryId]);
            if ($this->documents->findByEntry($supplierId, $entryId, true) !== null) {
                throw new InternalDocumentException('entry_has_document', 'Zápis už má interní doklad.', 409);
            }
            $number = $entry['document_no'] !== null && trim((string) $entry['document_no']) !== ''
                ? (string) $entry['document_no'] : null;
            if ($number !== null && $this->documents->documentNoTaken($supplierId, $number)) {
                throw new InternalDocumentException('document_no_taken',
                    'Číslo zápisu ' . $number . ' už nese jiný interní doklad.', 409);
            }
            if ($number === null) {
                // Číslo doplní repozitář deníku s pojistkami a §35 auditem (jako úprava popisu).
                $number = $this->freeNumber($supplierId, (string) $entry['entry_date'], null);
                $this->journal->assignDocumentNo($entryId, $supplierId, $number, $userId,
                    isset($audit['ip']) ? (string) $audit['ip'] : null,
                    isset($audit['user_agent']) ? (string) $audit['user_agent'] : null);
                $numbered = true;
            }
            $accMap = $this->accounts->idToAccountMap($supplierId);
            $lines = [];
            $total = 0;
            foreach ($this->journal->linesForEntry($entryId, $supplierId) as $line) {
                $amount = round((float) $line['amount'], 2);
                if ($amount <= 0) continue;
                $red = (bool) $line['is_red_storno'];
                if ($line['side'] === 'debit') $total += (int) round($amount * 100) * ($red ? -1 : 1);
                // Jen náhled kontace v Kč: dimenze, měna a vazby zůstávají na zápisu, proto
                // se zápis takového dokladu v deníku smazat nedá (prepareEntryDeletion).
                $lines[] = [
                    'account_code' => (string) ($accMap[(int) $line['account_id']]['code'] ?? ''),
                    'side' => (string) $line['side'],
                    'amount' => $amount,
                    'is_red_storno' => $red,
                    'cost_center' => $line['cost_center'] !== null ? (string) $line['cost_center'] : null,
                    'dimensions' => [],
                ];
            }
            $title = trim((string) ($input['title'] ?? '')) ?: trim((string) ($entry['description'] ?? ''));
            $data = [
                'kind' => $kind,
                'title' => mb_substr($title !== '' ? $title : $number, 0, 255),
                'document_date' => (string) ($entry['document_date'] ?? $entry['entry_date']),
                'entry_date' => (string) $entry['entry_date'],
                'partner_id' => null,
                'partner_name' => null,
                'amount' => $total / 100,
                'note' => self::optional($input['note'] ?? null, 10000),
            ];
            [$data['partner_id'], $data['partner_name']] = $this->partner($supplierId, $input['partner_id'] ?? null, $input['partner_name'] ?? null);
            $id = $this->documents->insertPosted($supplierId, $data, $number, $entryId, (string) $entry['posted_at'], $userId);
            $this->documents->replaceLines($supplierId, $id, $lines);
            return $id;
            });
        });
        return ['document' => $this->get($supplierId, $id), 'numbered' => $numbered];
    }

    /**
     * Zápis zaúčtování interního dokladu mažou v deníku jen ve stejné transakci jako
     * návrat dokladu do konceptu - jinak by hlavička ukazovala na neexistující zápis.
     * Volá {@see \MyInvoice\Action\Accounting\JournalAction::delete()}.
     */
    public function prepareEntryDeletion(int $supplierId, int $entryId, ?int $userId): ?array
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('InternalDocumentService::prepareEntryDeletion vyžaduje transakci.');
        }
        $doc = $this->documents->findByEntry($supplierId, $entryId, true);
        if ($doc === null) return null;
        if ($doc['status'] !== 'posted' || (int) $doc['journal_entry_id'] !== $entryId) {
            throw new InternalDocumentException('internal_document_use_detail',
                'Zápis patří ke stornovanému internímu dokladu, smazat ho nelze.', 409);
        }
        // Hlavička nad převzatým zápisem drží jen náhled kontace (bez dimenzí, měny a vazeb);
        // po smazání zápisu by z ní nešlo zaúčtovat totéž. Oprava jde stornem.
        if (($doc['origin'] ?? 'draft') === 'journal') {
            throw new InternalDocumentException('internal_document_use_detail',
                'Zápis nese interní doklad vytvořený nad ručním zápisem. Smazat ho nelze, stornujte doklad v jeho detailu.', 409);
        }
        $this->documents->setUnposted($supplierId, (int) $doc['id'], $userId);
        return $doc;
    }

    /**
     * Storno zápisu z deníku: zápis interního dokladu se stornuje v detailu dokladu.
     * Kontrola běží v transakci storna po zamčení zápisu a čte hlavičku zamykacím čtením,
     * takže ji nepředběhne souběžné vytvoření dokladu nad týmž zápisem.
     */
    public function assertJournalReversible(int $supplierId, int $entryId): void
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('InternalDocumentService::assertJournalReversible vyžaduje transakci.');
        }
        $pdo->prepare('SELECT id FROM journal_entries WHERE id = ? AND supplier_id = ? FOR UPDATE')
            ->execute([$entryId, $supplierId]);
        if ($this->documents->findByEntry($supplierId, $entryId, true) !== null) {
            throw new PostingException('internal_document_use_detail',
                'Účetní zápis interního dokladu stornujte v jeho detailu, aby se aktualizoval i stav dokladu.', 409);
        }
    }

    /** Doklad, ke kterému zápis deníku patří (zaúčtování i storno). */
    public function forEntry(int $supplierId, int $entryId): ?array
    {
        return $this->documents->findByEntry($supplierId, $entryId);
    }

    /** @param list<int> $entryIds */
    public function forJournalEntries(int $supplierId, array $entryIds): array
    {
        return $this->documents->forJournalEntries($supplierId, $entryIds);
    }

    /** @param list<int> $currentDimensions hodnoty dimenzí, které koncept už nese */
    private function normalize(int $supplierId, array $input, array $currentDimensions = []): array
    {
        $kind = (string) ($input['kind'] ?? 'other');
        if (!in_array($kind, self::KINDS, true)) {
            throw new InternalDocumentException('invalid_kind', 'Neplatný druh interního dokladu.');
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new InternalDocumentException('invalid_title', 'Zadejte popis dokladu do 255 znaků.');
        }
        $documentDate = trim((string) ($input['document_date'] ?? ''));
        if (!IsoDate::isValid($documentDate)) {
            throw new InternalDocumentException('invalid_date', 'Neplatné datum dokladu.');
        }
        $entryDate = trim((string) ($input['entry_date'] ?? ''));
        if ($entryDate === '') {
            $entryDate = $documentDate;
        } elseif (!IsoDate::isValid($entryDate)) {
            throw new InternalDocumentException('invalid_date', 'Neplatné datum účetního případu.');
        }
        [$partnerId, $partnerName] = $this->partner($supplierId, $input['partner_id'] ?? null, $input['partner_name'] ?? null);
        $lines = $this->normalizeLines($supplierId, $input['lines'] ?? [], $currentDimensions);
        $total = 0;
        foreach ($lines as $line) {
            if ($line['side'] === 'debit') $total += (int) round($line['amount'] * 100) * ($line['is_red_storno'] ? -1 : 1);
        }
        try {
            $links = $this->manualEntries->normalizeLinks($supplierId, $input['document_links'] ?? []);
        } catch (ManualJournalEntryException $e) {
            throw new InternalDocumentException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return [
            'kind' => $kind,
            'title' => $title,
            'document_date' => $documentDate,
            'entry_date' => $entryDate,
            'partner_id' => $partnerId,
            'partner_name' => $partnerName,
            'amount' => $total / 100,
            'note' => self::optional($input['note'] ?? null, 10000),
            'lines' => $lines,
            'document_links' => $links,
        ];
    }

    /**
     * Řádky konceptu. Koncept smí být nevyrovnaný (rozpracovaný), ale každý řádek
     * potřebuje účet, stranu a kladnou částku na haléře. Účet proti osnově a MD = D
     * ověří zaúčtování.
     *
     * @return list<array<string,mixed>>
     */
    private function normalizeLines(int $supplierId, mixed $raw, array $currentDimensions = []): array
    {
        if ($raw === null || $raw === []) return [];
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_LINES) {
            throw new InternalDocumentException('invalid_lines', 'Zadejte nejvýše ' . self::MAX_LINES . ' řádků kontace.');
        }
        $lines = [];
        foreach ($raw as $i => $row) {
            $n = $i + 1;
            if (!is_array($row)) {
                throw new InternalDocumentException('invalid_lines', "Řádek {$n} má neplatný formát.");
            }
            $code = trim((string) ($row['account_code'] ?? ''));
            $side = (string) ($row['side'] ?? '');
            $amount = filter_var($row['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($code === '' || strlen($code) > 20) {
                throw new InternalDocumentException('invalid_lines', "Řádek {$n}: vyberte účet.");
            }
            if ($side !== 'debit' && $side !== 'credit') {
                throw new InternalDocumentException('invalid_lines', "Řádek {$n}: strana musí být MD nebo D.");
            }
            if ($amount === false || !is_finite((float) $amount) || $amount <= 0 || $amount > self::MAX_AMOUNT
                || round((float) $amount, 2) !== (float) $amount) {
                throw new InternalDocumentException('invalid_lines', "Řádek {$n}: částka musí být kladná a zadaná na haléře.");
            }
            $costCenter = trim((string) ($row['cost_center'] ?? ''));
            if (mb_strlen($costCenter) > 50) {
                throw new InternalDocumentException('invalid_lines', "Řádek {$n}: středisko je příliš dlouhé.");
            }
            $dims = [];
            if (!empty($row['dimensions']) && is_array($row['dimensions'])) {
                try {
                    $dims = $this->dimensions->normalize($supplierId, $row['dimensions'], $currentDimensions);
                } catch (DimensionException $e) {
                    throw new InternalDocumentException($e->errorCode, "Řádek {$n}: " . $e->getMessage(), $e->httpStatus);
                }
            }
            $lines[] = [
                'account_code' => $code,
                'side' => $side,
                'amount' => round((float) $amount, 2),
                'is_red_storno' => self::flag($row['is_red_storno'] ?? false, $n),
                'cost_center' => $costCenter !== '' ? $costCenter : null,
                'dimensions' => $dims,
            ];
        }
        return $lines;
    }

    /** Příznak z JSON i formuláře: řetězec "false" nebo "0" nesmí znamenat červené storno. */
    private static function flag(mixed $value, int $n): bool
    {
        if (is_bool($value)) return $value;
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new InternalDocumentException('invalid_lines', "Řádek {$n}: červené storno musí být ano, nebo ne.");
        }
        return $parsed;
    }

    /** @param list<array<string,mixed>> $lines */
    private static function assertBalanced(array $lines): void
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            $cents = (int) round((float) $line['amount'] * 100) * (!empty($line['is_red_storno']) ? -1 : 1);
            if ($line['side'] === 'debit') $debit += $cents;
            else $credit += $cents;
        }
        if ($debit !== $credit) {
            throw new InternalDocumentException('unbalanced',
                'Strany MD a D se neshodují (MD ' . number_format($debit / 100, 2, ',', ' ')
                . ', D ' . number_format($credit / 100, 2, ',', ' ') . ').');
        }
    }

    /** @return array{0:?int, 1:?string} */
    private function partner(int $supplierId, mixed $partnerId, mixed $partnerName): array
    {
        $id = $partnerId !== null && $partnerId !== '' ? (int) $partnerId : null;
        $name = self::optional($partnerName, 190);
        if ($id !== null) {
            if ($id <= 0) throw new InternalDocumentException('invalid_partner', 'Partner nebyl nalezen.', 404);
            $stmt = $this->db->pdo()->prepare('SELECT company_name FROM clients WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$id, $supplierId]);
            $company = $stmt->fetchColumn();
            if ($company === false) throw new InternalDocumentException('invalid_partner', 'Partner nebyl nalezen.', 404);
            $name ??= self::optional(mb_substr((string) $company, 0, 190), 190);
        }
        return [$id, $name];
    }

    private static function listFilters(array $filters): array
    {
        $out = [];
        if (isset($filters['status']) && in_array($filters['status'], ['draft', 'posted', 'reversed', 'all'], true)) {
            $out['status'] = $filters['status'];
        }
        if (isset($filters['kind']) && in_array($filters['kind'], self::KINDS, true)) {
            $out['kind'] = $filters['kind'];
        }
        foreach (['from', 'to'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                if (!IsoDate::isValid((string) $filters[$key])) {
                    throw new InternalDocumentException('invalid_date', 'Neplatné datum ve filtru.');
                }
                $out[$key] = (string) $filters[$key];
            }
        }
        if (isset($out['from'], $out['to']) && $out['from'] > $out['to']) {
            throw new InternalDocumentException('invalid_date', 'Neplatný rozsah období.');
        }
        if (isset($filters['partner_id']) && (int) $filters['partner_id'] > 0) {
            $out['partner_id'] = (int) $filters['partner_id'];
        }
        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $out['q'] = mb_substr(trim((string) $filters['q']), 0, 100);
        }
        foreach (['sort_by', 'sort_dir'] as $key) {
            if (isset($filters[$key])) $out[$key] = (string) $filters[$key];
        }
        return $out;
    }

    private static function optional(mixed $value, int $limit): ?string
    {
        $text = trim((string) ($value ?? ''));
        if (mb_strlen($text) > $limit) throw new InternalDocumentException('text_too_long', 'Text je příliš dlouhý.');
        return $text === '' ? null : $text;
    }

    /**
     * @template T
     * @param callable():T $call
     * @return T
     */
    private function inTransaction(callable $call): mixed
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $result = $call();
            if ($ownTx) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
