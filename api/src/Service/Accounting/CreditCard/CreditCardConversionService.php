<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\CreditCard;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\CreditCardAccountRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PDO;

/**
 * Převod vlastního účtu vedeného jako BĚŽNÝ na úvěrový účet kreditní karty.
 *
 * Typicky ČSOB: kreditní výpis má layout běžného účtu, takže ho dřív přijal import
 * bankovních výpisů a pohyby se zaúčtovaly na banku 221.x - dluh vůči bance tak
 * v rozvaze vystupoval jako (záporné) peníze. Převod:
 *
 *  1. přepne účet v registru na `credit_card` a zaeviduje úvěrový účet s analytikou 231.x,
 *  2. přepíše NA MÍSTĚ každý živý bankovní zápis pohybů toho účtu: noha 221.x (i holé 221)
 *     → 231.x, všechno ostatní (protiúčty, částky, datum, cizoměnová stopa) beze změny.
 *
 * Přesun mezi 221 a 231 je daňově neutrální (obojí rozvaha, třída 2, bez DPH a bez vlivu
 * na výsledek), proto smí jít i do data zamčeného podaným DPH (`tax_neutral_rewrite`,
 * ověří PostingService pod zámkem). Do UZAVŘENÉHO roku nesmí nic: když některý zápis leží
 * v uzavřeném období, převod se odmítne celý - napůl převedený účet by měl historii na
 * dvou účtech.
 */
final class CreditCardConversionService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CreditCardAccountRepository $accounts,
        private readonly CreditCardAccounts $analytics,
        private readonly PostingService $posting,
        private readonly JournalEntryRepository $journal,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    /**
     * @return array{credit_card_account_id:int, reposted:int}
     */
    public function convert(int $supplierId, int $bankAccountId, ?int $userId, string $issuer = 'other'): array
    {
        $registry = $this->registryRow($supplierId, $bankAccountId);
        if ($registry === null) {
            throw new PostingException('not_found', 'Bankovní účet nenalezen.', 404);
        }
        if ((string) $registry['kind'] === BankAnalyticAssigner::CREDIT_CARD_KIND) {
            $existing = $this->accounts->findByBankAccount($supplierId, $bankAccountId);
            if ($existing !== null) {
                return ['credit_card_account_id' => (int) $existing['id'], 'reposted' => 0];
            }
        }
        if ((string) ($registry['currency'] ?? 'CZK') !== '' && strtoupper((string) ($registry['currency'] ?? 'CZK')) !== 'CZK') {
            throw new PostingException('unsupported_currency', 'Převést lze jen účet vedený v Kč.', 422);
        }

        $oldCodes = ['221'];
        $suffix = $registry['analytic_suffix'] ?? null;
        if (BankAnalyticAssigner::isValidSuffix($suffix)) {
            $oldCodes[] = BankAnalyticAssigner::codeFor((string) $suffix);
            $oldCodes[] = '221' . $suffix; // bezteččkový tvar z doby před migrací 1322
        }
        $entries = $this->liveEntries($supplierId, $registry);
        $this->assertOpen($supplierId, $entries);

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $id = $this->accounts->create($supplierId, [
                'issuer'         => in_array($issuer, CreditCardAccountRepository::ISSUERS, true) ? $issuer : 'other',
                'label'          => (string) (($registry['label'] ?? '') ?: ('Kreditní karta ' . (string) $registry['account_number'])),
                'account_number' => (string) $registry['account_number'],
                'bank_code'      => $registry['bank_code'] !== null ? (string) $registry['bank_code'] : null,
                'currency'       => 'CZK',
                'credit_limit'   => null,
                'is_verified'    => true,
            ], $userId);
            $account = $this->accounts->find($supplierId, $id);
            $code = $account !== null ? $this->analytics->ensureAnalytic($supplierId, $account) : null;
            if ($code === null && $entries !== []) {
                throw new PostingException(
                    'credit_card_account_unavailable',
                    'V účtové osnově chybí účet 231 (krátkodobé úvěry) - převod nejde provést.',
                    422,
                );
            }
            $reposted = 0;
            foreach ($entries as $entry) {
                if ($this->repost($supplierId, $entry, $oldCodes, (string) $code, $userId)) {
                    $reposted++;
                }
            }
            if ($ownTx) {
                $pdo->commit();
            }
            return ['credit_card_account_id' => $id, 'reposted' => $reposted];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array{tx_id:int, entry_id:int} $entry
     * @param list<string> $oldCodes
     */
    private function repost(int $supplierId, array $entry, array $oldCodes, string $code, ?int $userId): bool
    {
        $header = $this->journal->findBySource($supplierId, 'bank', $entry['tx_id']);
        if ($header === null || (int) $header['id'] !== $entry['entry_id']) {
            return false;
        }
        $codes = $this->accountCodes($supplierId);
        $changed = false;
        $lines = [];
        foreach ($this->journal->linesForEntry($entry['entry_id'], $supplierId) as $l) {
            $accountCode = $codes[(int) $l['account_id']] ?? '';
            if (in_array($accountCode, $oldCodes, true)) {
                $accountCode = $code;
                $changed = true;
            }
            $line = [
                'account_code' => $accountCode,
                'side'         => (string) $l['side'],
                'amount'       => (float) $l['amount'],
                'cost_center'  => $l['cost_center'],
                'project_id'   => $l['project_id'],
            ];
            if (!empty($l['is_red_storno'])) {
                $line['is_red_storno'] = true;
            }
            if ($l['currency_code'] !== null) {
                $line['currency_code'] = (string) $l['currency_code'];
                $line['fx_rate'] = $l['fx_rate'];
                $line['amount_foreign'] = $l['amount_foreign'];
            }
            // Jednoduchý zápis: dvojice i jejich text zůstávají, mění se jen účet banky.
            // Složený zápis tyto klíče nemá.
            if (isset($l['pair_no'])) {
                $line['pair_no'] = (int) $l['pair_no'];
            }
            if (isset($l['line_text'])) {
                $line['line_text'] = (string) $l['line_text'];
            }
            $lines[] = $line;
        }
        if (!$changed) {
            return false;
        }
        self::assertNoSameAccountPair($lines, $entry['entry_id']);
        $this->posting->postDocument($supplierId, 'bank', $entry['tx_id'], $lines, [
            'entry_date'          => (string) $header['entry_date'],
            'document_date'       => $header['document_date'] ?? null,
            'description'         => $header['description'] ?? null,
            'posted'              => true,
            'user_id'             => $userId,
            'posted_by'           => $userId,
            'tax_neutral_rewrite' => true,
        ]);
        $this->posting->restampDimensions($supplierId, 'bank', $entry['tx_id']);
        return true;
    }

    /**
     * Dvojice, jejíž obě nohy převod přesune na týž úvěrový účet (převod mezi analytikou
     * a holou 221 téhož účtu), by měla stejný účet na MD i Dal. Invariant dvojic ji
     * odmítne až uprostřed převodu, proto se to řekne dřív a srozumitelně: zápis je
     * potřeba nejdřív opravit v deníku.
     *
     * @param list<array<string,mixed>> $lines
     */
    private static function assertNoSameAccountPair(array $lines, int $entryId): void
    {
        $byPair = [];
        foreach ($lines as $line) {
            if (isset($line['pair_no'])) {
                $byPair[$line['pair_no']][] = (string) $line['account_code'];
            }
        }
        foreach ($byPair as $pairNo => $codes) {
            if (count($codes) === 2 && $codes[0] === $codes[1]) {
                throw new PostingException(
                    'credit_card_same_account_pair',
                    'Zápis #' . $entryId . ' má v dvojici ' . $pairNo . ' na obou stranách účet, který převod přesune na '
                        . $codes[0] . '. Opravte zápis v deníku a převod spusťte znovu.',
                    409,
                    ['entry_id' => $entryId, 'pair_no' => $pairNo],
                );
            }
        }
    }

    /**
     * Živé bankovní zápisy pohybů z výpisů účtu (jen výpisy téže firmy).
     *
     * @param array<string,mixed> $registry
     * @return list<array{tx_id:int, entry_id:int, entry_date:string}>
     */
    private function liveEntries(int $supplierId, array $registry): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id AS tx_id, je.id AS entry_id, je.entry_date
               FROM bank_statements bs
               JOIN bank_transactions bt ON bt.statement_id = bs.id
               JOIN journal_entries je ON je.supplier_id = bs.supplier_id AND je.source_type = 'bank'
                                      AND je.source_id = bt.id AND je.reversed_by IS NULL
              WHERE bs.supplier_id = ?
                AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''), '[^0-9]', '')) = ?
                AND COALESCE(bs.bank_code, '') = ?
              ORDER BY je.entry_date, je.id"
        );
        $stmt->execute([$supplierId, (string) $registry['account_canonical'], (string) $registry['bank_code_norm']]);
        return array_map(static fn (array $r): array => [
            'tx_id'      => (int) $r['tx_id'],
            'entry_id'   => (int) $r['entry_id'],
            'entry_date' => (string) $r['entry_date'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param list<array{tx_id:int, entry_id:int, entry_date:string}> $entries */
    private function assertOpen(int $supplierId, array $entries): void
    {
        $closed = [];
        foreach ($entries as $e) {
            $period = $this->periods->findForDate($supplierId, $e['entry_date']);
            if ($period === null || (string) $period['status'] !== 'open') {
                $closed[substr($e['entry_date'], 0, 4)] = true;
            }
        }
        if ($closed !== []) {
            $years = array_keys($closed);
            sort($years);
            throw new PostingException(
                'period_closed',
                'Účet má zaúčtované pohyby v uzavřeném účetním období (' . implode(', ', $years) . ') - převod nejde provést.',
                409,
                ['years' => $years],
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function registryRow(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM supplier_bank_accounts WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<int,string> id → kód účtu osnovy firmy */
    private function accountCodes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = (string) $r['account_code'];
        }
        return $out;
    }
}
