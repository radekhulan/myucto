<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\PropertyTimeline;
use PDO;

/**
 * Rozpoznání jednotky SVJ z bankovního pohybu (plán § 7.6).
 *
 * Jednotku určují jen identifikátory registru účinné k datu platby: VS, SS, spojovací
 * číslo SIPO, účet uvedený u jednotky a text. Účet plátce známý u osoby, která je
 * k datu platby vlastníkem nebo plátcem jednotky, je slabší signál: učení cílí na
 * plátce, ne na jednotku, takže účet osoby s více jednotkami žádnou z nich neurčí.
 *
 * Výsledek je vždy jen návrh. Více jednotek, rozporné signály, spoluvlastnictví nebo
 * nejistý vlastník končí ve stavu `review_required` se seznamem kandidátů a důvodů;
 * resolver nikdy nevybírá potichu. Bankovní plátce není sám o sobě dlužníkem: pokud
 * účet plátce nepatří zjištěnému dlužníkovi, nese návrh důvod `payer_not_proven`.
 */
final class UnitPaymentResolver
{
    public const VERSION = 1;

    /** Váhy signálů; silné signály (≥ STRONG) samy určují jednotku. */
    public const WEIGHTS = ['vs' => 100, 'sipo_link' => 95, 'account' => 90, 'ss' => 60, 'payer_account' => 50, 'text' => 40];
    public const STRONG = 90;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<array<string,mixed>> $transactions řádky bank_transactions
     * @return array<int,array<string,mixed>> výsledek podle id pohybu
     */
    public function resolveMany(int $supplier, array $transactions): array
    {
        if ($transactions === []) return [];
        $read = new PropertyReadRepository($this->db);
        $units = $read->objects($supplier, 'unit');
        $histories = $read->histories($supplier, array_column($units, 'id'));
        $payers = $this->payerAccounts($supplier, $transactions);
        $out = [];
        foreach ($transactions as $tx) {
            $out[(int) $tx['id']] = $this->resolveOne($tx, $units, $histories, $payers);
        }
        return $out;
    }

    /**
     * Jednotka zvolená účetní ručně: stejný popis jako kandidát resolveru, bez signálů.
     *
     * @return array<string,mixed>
     */
    public function describeUnit(int $supplier, int $unitId, string $at): array
    {
        $read = new PropertyReadRepository($this->db);
        $unit = null;
        foreach ($read->objects($supplier, 'unit') as $object) if ($object['id'] === $unitId) $unit = $object;
        if ($unit === null) PaymentException::reject('unit_not_found', ['unit_id' => $unitId]);
        $snapshot = PropertyTimeline::at($read->histories($supplier, [$unitId])[$unitId] ?? [], $at);
        $state = $snapshot['revision']['state'] ?? null;
        if (!is_array($state) || !($state['active'] ?? false)) PaymentException::reject('unit_inactive', ['unit_id' => $unitId]);
        return ['unit_id' => $unitId, 'unit_no' => (string) ($state['unit_no'] ?? $unit['code']), 'score' => 0, 'signals' => [],
            'debtor_ids' => self::owners($state), 'ownership_review_required' => (bool) $snapshot['ownership_review_required']];
    }

    /** Okamžik platby: bankovní pohyb nese jen den, bere se poledne pražského času. */
    public static function paymentInstant(string $postedAt): string
    {
        $day = substr($postedAt, 0, 10);
        return PropertyInstant::normalize((new \DateTimeImmutable($day . ' 12:00:00', new \DateTimeZone('Europe/Prague')))->format(DATE_ATOM));
    }

    private function resolveOne(array $tx, array $units, array $histories, array $payers): array
    {
        $at = self::paymentInstant((string) $tx['posted_at']);
        $vs = VariableSymbolNormalizer::forMatching((string) ($tx['variable_symbol'] ?? ''));
        $ss = VariableSymbolNormalizer::forMatching((string) ($tx['specific_symbol'] ?? ''));
        $message = mb_strtolower(trim(($tx['description'] ?? '') . ' ' . ($tx['counterparty_name'] ?? '')));
        $tokens = self::digitTokens($message);
        $account = self::accountKey((string) ($tx['counterparty_account'] ?? ''), (string) ($tx['counterparty_bank'] ?? ''));
        $payerClients = $account === null ? [] : ($payers[$account] ?? []);

        $candidates = [];
        foreach ($units as $unit) {
            $snapshot = PropertyTimeline::at($histories[$unit['id']] ?? [], $at);
            $state = $snapshot['revision']['state'] ?? null;
            if (!is_array($state) || !($state['active'] ?? false)) continue;
            $signals = [];
            foreach ($state['identifiers'] ?? [] as $identifier) {
                $signal = self::identifierSignal($identifier, $vs, $ss, $tokens, $message, $account);
                if ($signal !== null) $signals[$signal] = ['signal' => $signal, 'identifier_kind' => $identifier['kind']];
            }
            $people = self::people($state);
            foreach ($payerClients as $client) {
                if (isset($people[$client])) $signals['payer_account:' . $client] = ['signal' => 'payer_account', 'client_id' => $client];
            }
            if ($signals === []) continue;
            $score = max(array_map(static fn (array $s): int => self::WEIGHTS[$s['signal']], $signals));
            $debtors = self::owners($state);
            $candidates[] = ['unit_id' => (int) $unit['id'], 'unit_no' => (string) ($state['unit_no'] ?? $unit['code']), 'score' => $score,
                'signals' => array_values($signals), 'debtor_ids' => $debtors,
                'ownership_review_required' => (bool) $snapshot['ownership_review_required']];
        }
        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['unit_id'] <=> $b['unit_id']);
        return self::decide((int) $tx['id'], $at, $candidates, $payerClients);
    }

    private static function decide(int $txId, string $at, array $candidates, array $payerClients): array
    {
        $result = ['bank_transaction_id' => $txId, 'resolver_version' => self::VERSION, 'received_at' => $at, 'status' => 'unidentified',
            'score' => 0, 'unit_id' => null, 'debtor_id' => null, 'payer_id' => null, 'reasons' => [], 'candidates' => $candidates];
        if ($candidates === []) {
            $result['reasons'][] = 'no_identifier_match';
            return $result;
        }
        $top = $candidates[0];
        $strong = array_values(array_filter($candidates, static fn (array $c): bool => $c['score'] >= self::STRONG));
        $result['score'] = $top['score'];
        $result['unit_id'] = $top['unit_id'];
        $reasons = [];
        if (count($strong) > 1 || ($strong === [] && count($candidates) > 1)) {
            // Více jednotek: návrh nesmí žádnou předvybrat, rozhodne účetní.
            $result['unit_id'] = null;
            $result['status'] = 'review_required';
            $result['reasons'] = ['multiple_units'];
            return $result;
        }
        if ($strong === []) $reasons[] = 'weak_signal';
        if ($top['ownership_review_required']) $reasons[] = 'ownership_review_required';
        if (count($top['debtor_ids']) === 1) {
            $result['debtor_id'] = $top['debtor_ids'][0];
        } else {
            $reasons[] = 'debtor_choice_required';
        }
        if ($result['debtor_id'] !== null && in_array($result['debtor_id'], $payerClients, true)) {
            $result['payer_id'] = $result['debtor_id'];
        } else {
            $reasons[] = 'payer_not_proven';
            $result['payer_id'] = $result['debtor_id'];
        }
        // Účet plátce sám o sobě jednotku neurčuje; „payer_not_proven“ jen upozorňuje na
        // nutnost potvrdit, že platil dlužník, a jednotku nezpochybňuje.
        $blocking = array_diff($reasons, ['payer_not_proven']);
        $result['status'] = $blocking === [] ? 'identified' : 'review_required';
        $result['reasons'] = array_values($reasons);
        return $result;
    }

    /** Signál jednoho identifikátoru registru vůči pohybu; NULL, když se neshoduje. */
    public static function identifierSignal(array $identifier, string $vs, string $ss, array $tokens, string $message, ?string $account): ?string
    {
        $value = (string) $identifier['value'];
        switch ($identifier['kind']) {
            case 'vs':
                $key = VariableSymbolNormalizer::forMatching($value);
                if ($key === '') return null;
                if ($vs !== '' && $vs === $key) return 'vs';
                return strlen($key) >= 3 && isset($tokens[$key]) ? 'text' : null;
            case 'ss':
                $key = VariableSymbolNormalizer::forMatching($value);
                return $key !== '' && $ss !== '' && $ss === $key ? 'ss' : null;
            case 'sipo_link':
                $key = VariableSymbolNormalizer::forMatching($value);
                if ($key === '') return null;
                return ($vs === $key || $ss === $key || isset($tokens[$key])) ? 'sipo_link' : null;
            case 'account':
                if ($account === null) return null;
                [$number, $bank] = array_pad(explode('/', $value, 2), 2, '');
                return self::accountKey($number, $bank) === $account ? 'account' : null;
            case 'text':
                $needle = mb_strtolower(trim($value));
                return mb_strlen($needle) >= 3 && $message !== '' && str_contains($message, $needle) ? 'text' : null;
        }
        return null;
    }

    /** @return array<string,true> číselné tokeny zprávy bez vodicích nul */
    public static function digitTokens(string $message): array
    {
        preg_match_all('/\d+/', $message, $matches);
        $out = [];
        foreach ($matches[0] as $token) $out[VariableSymbolNormalizer::forMatching($token)] = true;
        return $out;
    }

    /** Klíč českého účtu `předčíslí-číslo/banka` bez vodicích nul; NULL, když účet nejde porovnat. */
    public static function accountKey(string $account, string $bank): ?string
    {
        $national = AccountNumberNormalizer::czechNational(trim($account));
        $bank = trim($bank);
        if ($national === null || !preg_match('/^\d{4}$/D', $bank)) return null;
        return $national . '/' . $bank;
    }

    /** @return list<int> členové všech vlastnických skupin jednotky */
    private static function owners(array $state): array
    {
        $ids = [];
        foreach ($state['ownerships'] ?? [] as $group) foreach ($group['members'] as $member) $ids[(int) $member] = true;
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @return array<int,true> vlastníci a osoby s rolí plátce k datu platby */
    private static function people(array $state): array
    {
        $ids = array_fill_keys(self::owners($state), true);
        foreach ($state['roles'] ?? [] as $role) if ($role['role'] === 'payer') $ids[(int) $role['client_id']] = true;
        return $ids;
    }

    /**
     * Aktivní účty osob známé z evidence klientů, jedním dotazem pro celou dávku.
     *
     * @return array<string,list<int>> klíč účtu => id klientů
     */
    private function payerAccounts(int $supplier, array $transactions): array
    {
        $wanted = [];
        foreach ($transactions as $tx) {
            $key = self::accountKey((string) ($tx['counterparty_account'] ?? ''), (string) ($tx['counterparty_bank'] ?? ''));
            if ($key === null) continue;
            [$national, $bank] = explode('/', $key);
            foreach (self::storedAccountKeys($national) as $stored) $wanted[$stored . '|' . $bank] = $key;
        }
        if ($wanted === []) return [];
        $out = [];
        foreach (array_chunk(array_keys($wanted), 400) as $chunk) {
            $where = [];
            $params = [$supplier];
            foreach ($chunk as $pair) {
                [$stored, $bank] = explode('|', $pair);
                $where[] = '(account_key = ? AND bank_key = ?)';
                array_push($params, $stored, $bank);
            }
            $stmt = $this->db->pdo()->prepare('SELECT client_id, account_key, bank_key FROM client_bank_accounts WHERE supplier_id = ? AND is_active = 1 AND (' . implode(' OR ', $where) . ')');
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $wanted[$row['account_key'] . '|' . $row['bank_key']] ?? null;
                if ($key !== null) $out[$key][(int) $row['client_id']] = (int) $row['client_id'];
            }
        }
        return array_map(static fn (array $ids): array => array_values($ids), $out);
    }

    /**
     * `client_bank_accounts.account_key` jsou číslice zápisu bez vodicích nul, takže týž
     * účet s předčíslím má podle zdroje (ruční zápis, GPC) dvě podoby. Hledají se obě.
     *
     * @return list<string>
     */
    private static function storedAccountKeys(string $national): array
    {
        [$prefix, $base] = str_contains($national, '-') ? explode('-', $national, 2) : ['', $national];
        $keys = [ltrim($prefix . $base, '0'), ltrim($prefix . str_pad($base, 10, '0', STR_PAD_LEFT), '0')];
        return array_values(array_unique(array_filter($keys, static fn (string $k): bool => $k !== '')));
    }
}
