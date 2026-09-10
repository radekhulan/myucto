<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Card;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\PaymentCardRepository;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\Card\CardNumberMask;
use PDO;

/**
 * Analytiky mezičlenu plateb kartou (378.101, 378.102 …) — jediné místo, které rozhoduje,
 * jakou analytiku karta dostane a jak se jmenuje.
 *
 * Suffix se přiděluje POSTUPNĚ (101, 102 …), ne podle koncovky karty: koncovka se
 * opakuje (nová karta téže banky, jiná firma), analytika ne. Kód skládá {@see codeFor()}
 * ze syntetiky v nastavení a suffixu uloženého u karty, stejně jako to dělá
 * {@see \MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner} u bankovních účtů.
 *
 * Volný suffix = nedrží ho jiná karta firmy a v osnově buď neexistuje, nebo existuje jako
 * aktivní analytika BEZ řádků v deníku — analytiku s cizí historií si karta nepřivlastní.
 * Suffix {@see FALLBACK_SUFFIX} je vyhrazený pro záchrannou analytiku „neevidované karty",
 * kam jdou jen platby, u kterých kartu nejde jednoznačně určit.
 */
final class CardClearingAccounts
{
    public const FALLBACK_SUFFIX = '199';

    /** @var array<string, list<string>> */
    private array $chartCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentCardRepository $cards,
        private readonly ChartOfAccountsRepository $chart,
    ) {}

    public static function codeFor(string $synthetic, string $suffix): string
    {
        return $synthetic . '.' . $suffix;
    }

    /** Název analytiky karty v osnově: „Karta ****1234 (název karty)". */
    public static function accountName(array $card): string
    {
        $name = 'Karta ****' . (string) ($card['last4'] ?? '');
        $label = trim((string) ($card['label'] ?? ''));
        return $label !== '' ? $name . ' (' . $label . ')' : $name;
    }

    /**
     * Kandidáti v pořadí přidělování: 101 … 198, pak 200 … 998. Suffix 199 je záchranná
     * analytika, 999 zůstává volné pro ruční potřeby účetní.
     *
     * @return list<string>
     */
    public static function candidateSuffixes(): array
    {
        $out = [];
        for ($n = 101; $n <= 998; $n++) {
            if ((string) $n !== self::FALLBACK_SUFFIX) {
                $out[] = (string) $n;
            }
        }
        return $out;
    }

    /**
     * Analytika karty pod syntetikou mezičlenu. Chybějící suffix přidělí, chybějící účet
     * dohraje do osnovy (obojí idempotentně). Null jen když syntetika v osnově není.
     *
     * @param array<string,mixed> $card řádek z PaymentCardRepository
     */
    public function ensureCardAnalytic(int $supplierId, array $card, string $synthetic): ?string
    {
        if ($this->chart->findByCode($supplierId, $synthetic) === null) {
            return null;
        }
        $suffix = $card['analytic_suffix'] ?? null;
        if (!is_string($suffix) || $suffix === '') {
            $suffix = $this->nextFreeSuffix($supplierId, $synthetic);
            if ($suffix === null) {
                return null;
            }
            if (!$this->cards->assignSuffixIfEmpty($supplierId, (int) $card['id'], $suffix)) {
                $fresh = $this->cards->find($supplierId, (int) $card['id']);
                $suffix = $fresh['analytic_suffix'] ?? null;
                if (!is_string($suffix) || $suffix === '') {
                    return null;
                }
            }
        }
        return $this->ensureChartAccount($supplierId, $synthetic, $suffix, self::accountName($card));
    }

    /** Záchranná analytika pro platby, jejichž kartu nejde určit. */
    public function ensureFallback(int $supplierId, string $synthetic): ?string
    {
        if ($this->chart->findByCode($supplierId, $synthetic) === null) {
            return null;
        }
        return $this->ensureChartAccount($supplierId, $synthetic, self::FALLBACK_SUFFIX, 'Neevidované karty');
    }

    /**
     * Mezičlen pro pohyb kartou. Vrací kód analytiky a id karty (null u záchranné analytiky).
     *
     * Karta k datu pohybu → její analytika. Koncovka, kterou firma vůbec neeviduje → nová
     * NEOVĚŘENÁ karta (když to nastavení dovolí). Koncovka evidovaná, ale k datu pohybu
     * neplatná, nebo vypnuté zakládání karet → záchranná analytika: založit další kartu se
     * stejnou koncovkou by se překrylo s tou evidovanou.
     *
     * S $create = false (náhled, návrh, kontrola) NIC nezapisuje: vrátí analytiku, která
     * by vznikla (`resolved` = false, `pending` = new_card | new_analytic). Kartu
     * i analytiku založí až skutečné zaúčtování pohybu.
     *
     * @param array<string,mixed> $tx řádek pohybu s card_last4, posted_at a účtem výpisu
     * @param array<string,mixed> $settings výstup CardClearingSettingsRepository::find()
     * @return array{code:string, card_id:?int, resolved:bool, pending?:?string}|null
     */
    public function resolveForTransaction(int $supplierId, array $tx, array $settings, bool $create = true): ?array
    {
        $last4 = (string) ($tx['card_last4'] ?? '');
        if (!CardNumberMask::isValidLast4($last4)) {
            return null;
        }
        $synthetic = (string) $settings['clearing_synthetic'];
        $date = substr((string) ($tx['posted_at'] ?? ''), 0, 10);
        if (!$create && !$this->chartHas($supplierId, $synthetic)) {
            return null; // zaúčtování by stejně nešlo přes mezičlen (ensure* vrací null)
        }

        $card = $this->cards->findByLast4OnDate($supplierId, $last4, $date);
        if ($card === null && !empty($settings['auto_create_cards'])
            && $this->cards->findAllByLast4($supplierId, $last4) === []) {
            if (!$create) {
                $suffix = $this->nextFreeSuffix($supplierId, $synthetic);
                return $suffix === null ? null
                    : ['code' => self::codeFor($synthetic, $suffix), 'card_id' => null, 'resolved' => false, 'pending' => 'new_card'];
            }
            $cardId = $this->cards->createUnverified(
                $supplierId,
                $last4,
                $this->statementCurrencyId($supplierId, $tx),
                'Karta ****' . $last4,
            );
            $card = $this->cards->find($supplierId, $cardId);
        }

        if ($card !== null) {
            if (!$create) {
                $suffix = $card['analytic_suffix'] ?? null;
                if (!is_string($suffix) || $suffix === '') {
                    $suffix = $this->nextFreeSuffix($supplierId, $synthetic);
                    if ($suffix === null) {
                        return null;
                    }
                }
                $code = self::codeFor($synthetic, $suffix);
                $exists = $this->chartHas($supplierId, $code);
                return ['code' => $code, 'card_id' => (int) $card['id'], 'resolved' => $exists, 'pending' => $exists ? null : 'new_analytic'];
            }
            $code = $this->ensureCardAnalytic($supplierId, $card, $synthetic);
            return $code === null ? null : ['code' => $code, 'card_id' => (int) $card['id'], 'resolved' => true];
        }

        $fallback = self::codeFor($synthetic, self::FALLBACK_SUFFIX);
        if (!$create) {
            $exists = $this->chartHas($supplierId, $fallback);
            return ['code' => $fallback, 'card_id' => null, 'resolved' => $exists, 'pending' => $exists ? null : 'new_analytic'];
        }
        $code = $this->ensureFallback($supplierId, $synthetic);
        return $code === null ? null : ['code' => $code, 'card_id' => null, 'resolved' => true];
    }

    /**
     * Všechny kódy, které jsou analytikou mezičlenu karet firmy — pod KAŽDOU povolenou
     * syntetikou (po změně syntetiky zůstávají staré analytiky pořád analytikami karet).
     *
     * @return list<string>
     */
    public function allClearingCodes(int $supplierId): array
    {
        $suffixes = array_keys($this->cards->usedSuffixes($supplierId));
        $suffixes[] = self::FALLBACK_SUFFIX;
        $out = [];
        foreach (\MyInvoice\Repository\CardClearingSettingsRepository::SYNTHETICS as $synthetic) {
            foreach ($suffixes as $suffix) {
                $out[] = self::codeFor($synthetic, (string) $suffix);
            }
        }
        return $out;
    }

    /**
     * Existující analytiky mezičlenu v osnově (nabídka ručního výběru v detailu karty).
     *
     * @return list<array{id:int, account_code:string, name:string, card_id:?int}>
     */
    public function analyticOptions(int $supplierId, string $synthetic): array
    {
        $used = $this->cards->usedSuffixes($supplierId);
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, account_code, name FROM chart_of_accounts
              WHERE supplier_id = ? AND is_active = 1 AND account_code LIKE CONCAT(?, '.%')
              ORDER BY account_code"
        );
        $stmt->execute([$supplierId, $synthetic]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $suffix = substr((string) $r['account_code'], strlen($synthetic) + 1);
            if ($suffix === self::FALLBACK_SUFFIX || preg_match('/^[0-9]{1,6}$/', $suffix) !== 1) {
                continue;
            }
            $out[] = [
                'id'           => (int) $r['id'],
                'account_code' => (string) $r['account_code'],
                'name'         => (string) $r['name'],
                'card_id'      => $used[$suffix] ?? null,
            ];
        }
        return $out;
    }

    /** Zůstatek účtu (MD − D) z nestornovaných i stornovaných zápisů (storno se vyruší). */
    public function balance(int $supplierId, string $code, ?string $asOf = null): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND a.account_code = ? AND e.posted_at IS NOT NULL
                AND (? IS NULL OR e.entry_date <= ?)"
        );
        $stmt->execute([$supplierId, $code, $asOf, $asOf]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    public function chartHas(int $supplierId, string $code): bool
    {
        return $this->chart->findByCode($supplierId, $code) !== null;
    }

    /** První volný suffix pro syntetiku, nebo null. */
    public function nextFreeSuffix(int $supplierId, string $synthetic): ?string
    {
        $taken = $this->cards->usedSuffixes($supplierId);
        $state = $this->chartState($supplierId, $synthetic);
        foreach (self::candidateSuffixes() as $suffix) {
            if (isset($taken[$suffix])) {
                continue;
            }
            $s = $state[$suffix] ?? null;
            if ($s === null || ($s['is_active'] && !$s['has_lines'])) {
                return $suffix;
            }
        }
        return null;
    }

    private function ensureChartAccount(int $supplierId, string $synthetic, string $suffix, string $name): string
    {
        $code = self::codeFor($synthetic, $suffix);
        if ($this->chart->findByCode($supplierId, $code) !== null) {
            return $code;
        }
        $parent = $this->chart->findByCode($supplierId, $synthetic);
        $this->chart->insert($supplierId, [
            'account_code' => $code,
            'name'         => mb_substr($name, 0, 190),
            'account_type' => $parent['account_type'] ?? 'asset',
            'normal_side'  => $parent['normal_side'] ?? 'debit',
            'is_synthetic' => false,
            'parent_id'    => $parent !== null ? (int) $parent['id'] : null,
            'is_active'    => true,
        ]);
        return $code;
    }

    /** @return array<string, array{is_active:bool, has_lines:bool}> */
    private function chartState(int $supplierId, string $synthetic): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.account_code, c.is_active,
                    EXISTS (SELECT 1 FROM journal_entry_lines jel
                             WHERE jel.supplier_id = c.supplier_id AND jel.account_id = c.id) AS has_lines
               FROM chart_of_accounts c
              WHERE c.supplier_id = ? AND c.account_code LIKE CONCAT(?, '.%')"
        );
        $stmt->execute([$supplierId, $synthetic]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $suffix = substr((string) $row['account_code'], strlen($synthetic) + 1);
            $out[$suffix] = ['is_active' => (bool) $row['is_active'], 'has_lines' => (bool) $row['has_lines']];
        }
        return $out;
    }

    /** Bankovní (měnový) účet firmy, ze kterého pohyb přišel — kvůli měně nové karty. */
    private function statementCurrencyId(int $supplierId, array $tx): ?int
    {
        $account = trim((string) ($tx['recipient_account'] ?? ''));
        if ($account === '') {
            return null;
        }
        $currency = strtoupper((string) (($tx['currency'] ?? null) ?: ($tx['statement_currency'] ?? 'CZK')));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, account_number, iban FROM currencies
              WHERE supplier_id = ? AND (account_number IS NOT NULL OR iban IS NOT NULL) ORDER BY id'
        );
        $stmt->execute([$supplierId]);
        $fallback = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
            if (!AccountNumberNormalizer::matchesAny($account, $c['account_number'] ?? null, $c['iban'] ?? null)) {
                continue;
            }
            if (strtoupper((string) $c['code']) === $currency) {
                return (int) $c['id'];
            }
            $fallback ??= (int) $c['id'];
        }
        return $fallback;
    }
}
