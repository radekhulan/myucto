<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Action\Accounting\JournalDocumentLinkAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\JournalEntryDocumentLinkRepository;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\Pair\JournalPairInvariant;

/**
 * Ruční zápis deníku - jediná cesta pro `POST /accounting/journal` i zaúčtování
 * interního dokladu, aby obě sdílely tvar řádků, dimenze, vazby na doklady
 * i číslování z řady ID.
 *
 * Zápis má vždy `source_type = 'manual'` a `source_id` NULL: podvojnost, účtovou
 * osnovu, otevřené období a zámek k datu ověřuje {@see PostingService::postDocument()}.
 */
final class ManualJournalEntryService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly DimensionService $dimensions,
        private readonly DocumentSeriesService $series,
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly AccountingPeriodRepository $periods,
        private readonly JournalEntryDocumentLinkRepository $documentLinks,
        private readonly EntryFormService $entryForms,
    ) {}

    /** Účtuje firma k datu zápisu po dvojicích? */
    public function singleAt(int $supplierId, string $entryDate): bool
    {
        return $this->entryForms->formAt($supplierId, $entryDate)->isSingle();
    }

    /**
     * Řádky z těla požadavku do tvaru pro PostingService. Účty a vyváženost
     * kontroluje až PostingService, tady jen tvar a dimenze.
     *
     * @return list<array<string,mixed>>
     */
    public function normalizeLines(int $supplierId, mixed $rawLines): array
    {
        if (!is_array($rawLines) || $rawLines === []) {
            throw new ManualJournalEntryException('validation_failed', 'lines musí obsahovat alespoň jeden řádek.');
        }
        $lines = [];
        foreach ($rawLines as $i => $l) {
            if (!is_array($l)) {
                throw new ManualJournalEntryException('validation_failed', "Řádek #{$i} má neplatný formát.");
            }
            $side = (string) ($l['side'] ?? '');
            if ($side !== 'debit' && $side !== 'credit') {
                throw new ManualJournalEntryException('validation_failed', "Řádek #{$i}: side musí být 'debit' nebo 'credit'.");
            }
            if (array_key_exists('is_red_storno', $l) && !is_bool($l['is_red_storno'])) {
                throw new ManualJournalEntryException('validation_failed', "Řádek #{$i}: is_red_storno musí být boolean.");
            }
            $line = [
                'account_code' => (string) ($l['account_code'] ?? ''),
                'side'         => $side,
                'amount'       => (float) ($l['amount'] ?? 0),
                'is_red_storno' => (bool) ($l['is_red_storno'] ?? false),
            ];
            if (isset($l['cost_center']) && trim((string) $l['cost_center']) !== '') {
                $line['cost_center'] = (string) $l['cost_center'];
            }
            if (!empty($l['dimensions']) && is_array($l['dimensions'])) {
                try {
                    $line['dimensions'] = $this->dimensions->normalize($supplierId, $l['dimensions']);
                } catch (DimensionException $e) {
                    throw new ManualJournalEntryException($e->errorCode, "Řádek #{$i}: " . $e->getMessage(), $e->httpStatus);
                }
            }
            // Rozpad řádku mezi víc hodnot typu: typ => [{value_id, share}], součet 100 %.
            if (!empty($l['dimension_splits']) && is_array($l['dimension_splits'])) {
                try {
                    $splits = $this->dimensions->normalizeSplits($supplierId, $l['dimension_splits']);
                } catch (DimensionException $e) {
                    throw new ManualJournalEntryException($e->errorCode, "Řádek #{$i}: " . $e->getMessage(), $e->httpStatus);
                }
                if ($splits !== []) {
                    $line['dimension_splits'] = $splits;
                    if (isset($line['dimensions'])) {
                        $line['dimensions'] = array_diff_key($line['dimensions'], $splits);
                    }
                }
            }
            // Jednoduchý zápis: řádek může nést číslo a text dvojice (ověří je invariant dvojic).
            if (isset($l['pair_no']) && $l['pair_no'] !== '') {
                $pairNo = JournalPairInvariant::pairNoOf(['pair_no' => $l['pair_no']]);
                if ($pairNo === null || $pairNo < 1) {
                    throw new ManualJournalEntryException('validation_failed', "Řádek #{$i}: pair_no musí být kladné celé číslo.");
                }
                $line['pair_no'] = $pairNo;
            }
            if (isset($l['line_text']) && is_string($l['line_text']) && trim($l['line_text']) !== '') {
                $line['line_text'] = mb_substr(trim($l['line_text']), 0, 255);
            }
            foreach ($line['dimensions'] ?? [] as $typeId => $_) {
                $line['dimension_origins'][$typeId] = ['origin' => 'explicit', 'ref' => null];
            }
            foreach ($line['dimension_splits'] ?? [] as $typeId => $shares) {
                foreach ($shares as $valueId => $_) {
                    $line['dimension_split_origins'][$typeId][$valueId] = ['origin' => 'explicit', 'ref' => null];
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * Ruční zápis po dvojicích: `pairs: [{debit_account_code, credit_account_code, amount,
     * is_red_storno?, line_text?, cost_center?, dimensions?, dimension_splits?}]` → dva
     * řádky na dvojici. Kritérium b) se kontroluje tady (srozumitelná chyba s indexem
     * dvojice) a znovu v invariantu dvojic při zápisu.
     *
     * Středisko a dimenze dvojice se zapíší na výsledkový řádek (5xx/6xx) jako u automatiky;
     * dvojice bez výsledkového účtu je nese na obou řádcích. Zaúčtování v jednoduché formě je
     * pak doplní i na druhý řádek dvojice ({@see \MyInvoice\Service\Accounting\Pair\JournalPairer::shareAnalytics()}).
     * Text dvojice jde na oba řádky.
     * Ve složené formě (`$single` false) vzniknou řádky bez `pair_no`, takže API klient
     * nemůže firmu přepnout do dvojic.
     *
     * @return list<array<string,mixed>> řádky ve tvaru {@see normalizeLines()}
     */
    public function normalizePairs(int $supplierId, mixed $rawPairs, bool $single): array
    {
        if (!is_array($rawPairs) || $rawPairs === []) {
            throw new ManualJournalEntryException('validation_failed', 'pairs musí obsahovat alespoň jednu dvojici.');
        }
        $raw = [];
        $pairNo = 0;
        foreach (array_values($rawPairs) as $i => $pair) {
            $index = $i + 1;
            if (!is_array($pair)) {
                throw new ManualJournalEntryException('validation_failed', "Dvojice #{$index} má neplatný formát.");
            }
            $debit = is_scalar($pair['debit_account_code'] ?? null) ? trim((string) $pair['debit_account_code']) : '';
            $credit = is_scalar($pair['credit_account_code'] ?? null) ? trim((string) $pair['credit_account_code']) : '';
            if ($debit === '') {
                throw new ManualJournalEntryException(JournalPairInvariant::MISSING_DEBIT_ACCOUNT, "Dvojice #{$index}: chybí účet MD.");
            }
            if ($credit === '') {
                throw new ManualJournalEntryException(JournalPairInvariant::MISSING_CREDIT_ACCOUNT, "Dvojice #{$index}: chybí účet Dal.");
            }
            if ($debit === $credit) {
                throw new ManualJournalEntryException(JournalPairInvariant::SAME_ACCOUNT, "Dvojice #{$index}: účet MD a účet Dal musí být různé.");
            }
            $amount = $pair['amount'] ?? null;
            try {
                $cents = is_int($amount) || is_float($amount) || is_string($amount) ? JournalLineAmount::cents($amount) : 0;
            } catch (\InvalidArgumentException) {
                $cents = 0;
            }
            if ($cents <= 0) {
                throw new ManualJournalEntryException(JournalPairInvariant::AMOUNT_NOT_POSITIVE, "Dvojice #{$index}: částka musí být kladná.");
            }
            if (array_key_exists('is_red_storno', $pair) && !is_bool($pair['is_red_storno'])) {
                throw new ManualJournalEntryException('validation_failed', "Dvojice #{$index}: is_red_storno musí být boolean.");
            }
            $pairNo++;
            $analytics = array_intersect_key($pair, array_flip(['cost_center', 'dimensions', 'dimension_splits']));
            $carries = self::pairAnalyticsSides($debit, $credit);
            foreach (['debit' => $debit, 'credit' => $credit] as $side => $code) {
                $line = [
                    'account_code'  => $code,
                    'side'          => $side,
                    'amount'        => $cents / 100,
                    'is_red_storno' => (bool) ($pair['is_red_storno'] ?? false),
                ];
                if ($single) {
                    $line['pair_no'] = $pairNo;
                }
                if (isset($pair['line_text'])) {
                    $line['line_text'] = $pair['line_text'];
                }
                if ($carries[$side]) {
                    $line += $analytics;
                }
                $raw[] = $line;
            }
        }
        return $this->normalizeLines($supplierId, $raw);
    }

    /**
     * Který řádek dvojice nese středisko a dimenze: výsledkový (5xx/6xx), a když ho
     * dvojice nemá, oba. Sdílí ruční zápis, interní doklad i CSV import šablony.
     *
     * @return array{debit:bool, credit:bool}
     */
    public static function pairAnalyticsSides(string $debitCode, string $creditCode): array
    {
        $result = static fn (string $code): bool => in_array(substr($code, 0, 1), ['5', '6'], true);
        $onBoth = !$result($debitCode) && !$result($creditCode);
        return ['debit' => $onBoth || $result($debitCode), 'credit' => $onBoth || $result($creditCode)];
    }

    /**
     * Měkké vazby na doklady (migrace 1514). Validují se PŘED zaúčtováním: neplatná
     * vazba nesmí nechat v deníku zápis, o kterém si uživatel myslí, že doklad nese.
     *
     * @return list<array{doc_type:string, doc_id:int, note:?string}>
     */
    public function normalizeLinks(int $supplierId, mixed $rawLinks): array
    {
        $links = [];
        foreach ((array) ($rawLinks ?? []) as $i => $raw) {
            if (!is_array($raw)) {
                throw new ManualJournalEntryException('validation_failed', "Vazba #{$i} má neplatný formát.");
            }
            $link = JournalDocumentLinkAction::validateLink($raw, $supplierId, $this->documentLinks, $code, $message);
            if ($link === null) {
                throw new ManualJournalEntryException((string) $code, (string) $message, $code === 'not_found' ? 404 : 422);
            }
            $links[] = $link;
        }
        if (count($links) > JournalEntryDocumentLinkRepository::MAX_LINKS_PER_ENTRY) {
            throw new ManualJournalEntryException(
                'too_many_links',
                'Zápis může mít nejvýš ' . JournalEntryDocumentLinkRepository::MAX_LINKS_PER_ENTRY . ' vazeb.',
            );
        }
        return $links;
    }

    /**
     * Zaúčtuje ruční zápis. Bez čísla dokladu ho vydá řada ID, pokud má firma
     * zapnuté číslování ručních zápisů nebo o to volající požádá (`$alwaysNumber`).
     *
     * Výdej čísla drží FOR UPDATE zámek, proto transakce obepíná i postDocument
     * (mezera po rollbacku nevzniká). Řada ID je horký řádek firmy a roku: souběžný
     * zápis ho pod REPEATABLE READ posune a zámek skončí chybou 1020. Vlastní transakce
     * se proto při odvolání zopakuje celá, s původním $meta (bez čísla z odvolaného
     * pokusu). Ve vnější transakci se neopakuje nic, chybu dostane její vlastník.
     *
     * @param list<array<string,mixed>> $lines výstup {@see normalizeLines()}
     * @param array<string,mixed> $meta entry_date, description, document_no, document_date + audit
     * @param list<array{doc_type:string, doc_id:int, note:?string}> $links výstup {@see normalizeLinks()}
     */
    public function post(int $supplierId, array $lines, array $meta, array $links = [], bool $alwaysNumber = false): int
    {
        $pdo = $this->db->pdo();
        return TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $lines, $meta, $links, $alwaysNumber): int {
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            try {
                $entryDate = (string) $meta['entry_date'];
                if (($meta['document_no'] ?? null) === null
                    && ($alwaysNumber || !empty($this->settings->get($supplierId)['manual_doc_series']))) {
                    $meta['document_no'] = $this->nextNumber($supplierId, $entryDate);
                }
                $entryId = $this->posting->postDocument($supplierId, 'manual', null, $lines, $meta);
                foreach ($links as $link) {
                    $this->documentLinks->add(
                        $entryId,
                        $supplierId,
                        $link['doc_type'],
                        $link['doc_id'],
                        $link['note'],
                        isset($meta['user_id']) ? (int) $meta['user_id'] : null,
                    );
                }
                if ($ownTx) {
                    $pdo->commit();
                }
                return $entryId;
            } catch (\Throwable $e) {
                if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
    }

    /** Další číslo řady ID pro účetní rok data zápisu. Vyžaduje transakci volajícího. */
    public function nextNumber(int $supplierId, string $entryDate): string
    {
        return $this->series->next($supplierId, 'manual', $this->fiscalYear($supplierId, $entryDate));
    }

    /** Účetní rok data zápisu (řada ID se čísluje po účetních letech, ne kalendářních). */
    public function fiscalYear(int $supplierId, string $entryDate): int
    {
        $period = $this->periods->findForDate($supplierId, $entryDate);
        return (int) ($period['fiscal_year'] ?? substr($entryDate, 0, 4));
    }

    /** Varování pravidel dimenzí z posledního zaúčtování (zápis proběhl, uživatel je jen uvidí). */
    public function dimensionWarnings(): array
    {
        return $this->posting->dimensionWarnings();
    }
}
