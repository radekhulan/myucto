<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Card;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PDO;

/**
 * Životní cyklus samostatných zápisů k platbě kartou: vypořádání s dokladem
 * (`card_settlement`, 321/378.x) a uzavření platby bez dokladu (`card_writeoff`,
 * 548/378.x nebo 335/378.x). Oba mají source_id = bank_transactions.id.
 *
 * Bankovní zápis platby (378.x/221) zůstává netknutý — párování, zrušení párování
 * i uzavření bez dokladu se odehrávají jen tady. Proto se nikdy nemusí přepisovat
 * pohyb na bance, a zrušení párování v uzavřeném období nic nerozbije.
 *
 * Pravidla změn drží vzor zrušení zaúčtování bankovního pohybu
 * ({@see \MyInvoice\Service\Accounting\Bank\BankPostingService::unpost()}): zrušení
 * = storno ke dni původního zápisu + odpojení zdroje; zavřené nebo zamčené období
 * původního zápisu = 409, nic se nezmění.
 */
final class CardSettlementService
{
    public const SOURCE_SETTLEMENT = 'card_settlement';
    public const SOURCE_WRITEOFF = 'card_writeoff';

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly JournalEntryRepository $journal,
        private readonly AutoPostingPolicyService $policy,
    ) {}

    /**
     * Živý zápis daného typu k pohybu, i s řádky (kód účtu místo id).
     *
     * @return array<string,mixed>|null
     */
    public function liveEntry(int $supplierId, string $sourceType, int $txId): ?array
    {
        $entry = $this->journal->findBySource($supplierId, $sourceType, $txId);
        if ($entry === null || ($entry['reversed_by'] ?? null) !== null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.account_code, jel.side, jel.amount, jel.currency_code, jel.fx_rate, jel.amount_foreign
               FROM journal_entry_lines jel
               JOIN chart_of_accounts c ON c.id = jel.account_id AND c.supplier_id = jel.supplier_id
              WHERE jel.entry_id = ? AND jel.supplier_id = ?
              ORDER BY jel.line_no, jel.id'
        );
        $stmt->execute([(int) $entry['id'], $supplierId]);
        $entry['lines'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $entry;
    }

    /**
     * Srovná zápis daného typu s požadovaným stavem.
     *
     *  - $lines = null  → živý zápis (pokud je) se stornuje,
     *  - shodné řádky   → nic,
     *  - jiné řádky     → přepis zápisu (otevřené období) / nový zápis.
     *
     * Přepis v zavřeném období se neprovádí (vrátí `period_closed`) — rozdíl zůstane
     * vidět v předuzávěrkové kontrole mezičlenu, nikdy se tiše nezmění uzavřený rok.
     *
     * @param list<array<string,mixed>>|null $lines
     * @param array{txDate:string, description?:?string, document_no?:?string, user_id?:?int} $meta
     * @return array{action:string, entry_id?:int, reason?:string}
     */
    public function sync(int $supplierId, int $txId, string $sourceType, ?array $lines, array $meta): array
    {
        $live = $this->liveEntry($supplierId, $sourceType, $txId);
        if ($lines === null) {
            if ($live === null) {
                return ['action' => 'none'];
            }
            $this->reverseLive($supplierId, $txId, $sourceType, $meta + ['reason' => 'sync']);
            return ['action' => 'reversed', 'entry_id' => (int) $live['id']];
        }
        if ($live !== null && self::signature($live['lines']) === self::signature($lines)) {
            return ['action' => 'unchanged', 'entry_id' => (int) $live['id']];
        }
        if ($live !== null && !$this->policy->isOpenDate($supplierId, (string) $live['entry_date'])) {
            return ['action' => 'skipped', 'reason' => 'period_closed', 'entry_id' => (int) $live['id']];
        }
        $date = $live !== null ? (string) $live['entry_date'] : $this->entryDate($supplierId, $meta['txDate']);
        if ($date === null) {
            return ['action' => 'skipped', 'reason' => 'period_closed'];
        }
        $entryId = $this->posting->postDocument($supplierId, $sourceType, $txId, $lines, [
            'entry_date'    => $date,
            'document_date' => $meta['txDate'],
            'document_no'   => $meta['document_no'] ?? ('KARTA-' . $txId),
            'description'   => $meta['description'] ?? null,
            'posted'        => true,
            'user_id'       => $meta['user_id'] ?? null,
            'posted_by'     => $meta['user_id'] ?? null,
        ]);
        return ['action' => 'posted', 'entry_id' => $entryId];
    }

    /**
     * Storno živého zápisu daného typu ke dni původního zápisu + odpojení zdroje.
     * Vrací id storna, null když živý zápis není.
     *
     * @param array{user_id?:?int, reason?:?string} $meta
     */
    public function reverseLive(int $supplierId, int $txId, string $sourceType, array $meta = []): ?int
    {
        $live = $this->journal->findBySource($supplierId, $sourceType, $txId);
        if ($live === null || ($live['reversed_by'] ?? null) !== null) {
            return null;
        }
        $originalDate = (string) $live['entry_date'];
        if (!$this->policy->isOpenDate($supplierId, $originalDate)) {
            throw new PostingException(
                'period_closed',
                'Období zápisu k platbě kartou je uzavřené nebo zamčené — storno nelze provést.',
                409,
            );
        }
        $entryId = (int) $live['id'];
        $reversalId = $this->posting->reverse($supplierId, $entryId, [
            'entry_date'  => $originalDate,
            'description' => $sourceType === self::SOURCE_WRITEOFF
                ? 'Storno uzavření platby kartou #' . $entryId
                : 'Storno vypořádání platby kartou #' . $entryId,
            'user_id'     => $meta['user_id'] ?? null,
            'posted_by'   => $meta['user_id'] ?? null,
        ]);
        $this->journal->detachSource($entryId, $supplierId);
        return $reversalId;
    }

    public function hasLive(int $supplierId, int $txId, string $sourceType): bool
    {
        $live = $this->journal->findBySource($supplierId, $sourceType, $txId);
        return $live !== null && ($live['reversed_by'] ?? null) === null;
    }

    /**
     * Den zápisu: den platby, když je otevřený; jinak první otevřený den po něm
     * (doklad dorazil po uzávěrce — vypořádání patří do otevřeného období, nikdy
     * do uzavřeného roku).
     */
    public function entryDate(int $supplierId, string $txDate): ?string
    {
        $txDate = substr($txDate, 0, 10);
        if ($this->policy->isOpenDate($supplierId, $txDate)) {
            return $txDate;
        }
        $lock = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $lock->execute([$supplierId]);
        $lockedUntil = $lock->fetchColumn();
        $stmt = $this->db->pdo()->prepare(
            "SELECT starts_on, ends_on FROM accounting_periods
              WHERE supplier_id = ? AND status = 'open' AND ends_on >= ?
              ORDER BY starts_on"
        );
        $stmt->execute([$supplierId, $txDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $candidate = max((string) $p['starts_on'], $txDate);
            if (is_string($lockedUntil) && $lockedUntil !== '' && $candidate <= $lockedUntil) {
                $candidate = date('Y-m-d', strtotime($lockedUntil . ' +1 day'));
            }
            if ($candidate <= (string) $p['ends_on'] && $this->policy->isOpenDate($supplierId, $candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Účetní obsah zápisu jako porovnatelná množina (strana|účet|haléře|měna|cizí částka).
     *
     * @param list<array<string,mixed>> $lines
     * @return list<string>
     */
    public static function signature(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $foreign = $l['amount_foreign'] ?? null;
            $out[] = sprintf(
                '%s|%s|%d|%s|%s',
                (string) $l['side'],
                (string) $l['account_code'],
                (int) round(((float) $l['amount']) * 100.0),
                (string) ($l['currency_code'] ?? ''),
                $foreign === null ? '' : (string) (int) round(((float) $foreign) * 100.0),
            );
        }
        sort($out);
        return $out;
    }
}
