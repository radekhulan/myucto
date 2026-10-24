<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\Shared\ImportedAccountCode;

/** Společný důkaz fyzického počátečního stavu proti původním otevíracím kontacím. */
final class StereoNxOpeningMovementProof
{
    /**
     * Stejná identita a účetní rok jako MovementJournalLinks::indexMovementEntries.
     * Ten záměrně vynechává opening; zde potřebujeme i běžné kontace k vyloučení smíšené skupiny.
     */
    public static function index(array $journal): array
    {
        $result = ['bank' => [], 'cash' => []];
        foreach ($journal['accounting_plan']['entries'] ?? [] as $entry) {
            $identity = self::entryIdentity($entry);
            if ($identity === null) continue;
            $result[$identity['kind']][$identity['key']][] = $entry;
        }
        return $result;
    }

    /** @return array{opening_cents:int,entry_keys:list<string>,account_code:string,date:string}|null */
    public static function prove(array $movement, array $entries, string $prefix, ?string $expectedAccount = null): ?array
    {
        if (!in_array($prefix, ['211', '221'], true)) return null;
        $kind = $prefix === '221' ? 'bank' : 'cash';
        $parts = self::parts((string) ($movement['source_key'] ?? ''), $kind === 'bank' ? 4 : 3);
        $date = (string) ($movement['date'] ?? '');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parts === null || $parsed === false || $parsed->format('Y-m-d') !== $date
            || ($movement['currency'] ?? '') !== 'CZK') return null;
        $expected = StereoNxRecapAmounts::moneyCents($movement['amount'] ?? null);
        if ($expected === null || $expected === 0) return null;
        $sum = 0;
        $keys = [];
        $accountCode = null;
        foreach ($entries as $entry) {
            $identity = self::entryIdentity($entry);
            if ($identity === null || $identity['kind'] !== $kind || $identity['key'] !== $movement['source_key']
                || ($entry['is_opening'] ?? null) !== true || ($entry['is_red_storno'] ?? null) !== false
                || ($entry['date'] ?? null) !== $date || !is_int($entry['posting_year'] ?? null)
                || (string) $entry['posting_year'] !== $parts[0]
                || !is_int($entry['amount_cents'] ?? null) || $entry['amount_cents'] <= 0
                || isset($keys[(string) $entry['source_key']])) return null;
            $debit = substr((string) ($entry['debit'] ?? ''), 0, 3) === $prefix;
            $credit = substr((string) ($entry['credit'] ?? ''), 0, 3) === $prefix;
            // Stejně jako verifiesNonVatCash vyžadujeme jeden směr; protichůdné nohy nejsou čistý stav.
            if ($debit === $credit || ($expected > 0 && !$debit) || ($expected < 0 && !$credit)) return null;
            // Protiúčet počáteční rozvahy už ověřil AccountingWriter podle LFirma.DruhPS.
            $side = $debit ? 'debit' : 'credit';
            $code = ImportedAccountCode::normalize((string) ($entry['target_' . $side] ?? $entry[$side]));
            if (substr($code, 0, 3) !== $prefix || ($accountCode !== null && $accountCode !== $code)
                || ($expectedAccount !== null && $code !== ImportedAccountCode::normalize($expectedAccount))) return null;
            $accountCode = $code;
            $sum += $debit ? $entry['amount_cents'] : -$entry['amount_cents'];
            $keys[(string) $entry['source_key']] = true;
        }
        if ($sum !== $expected || $accountCode === null) return null;
        return ['opening_cents' => $sum, 'entry_keys' => array_keys($keys), 'account_code' => $accountCode, 'date' => $date];
    }

    public static function hasOpening(array $entries): bool
    {
        return array_any($entries, static fn (array $entry): bool => ($entry['is_opening'] ?? false) === true);
    }

    /** @return list<string>|null */
    public static function parts(string $key, int $count): ?array
    {
        $parts = json_decode($key, true);
        if (!is_array($parts) || !array_is_list($parts) || count($parts) !== $count) return null;
        foreach ($parts as $part) if ((!is_string($part) && !is_int($part)) || (string) $part === '') return null;
        return array_map('strval', $parts);
    }

    private static function entryIdentity(array $entry): ?array
    {
        $parts = self::parts((string) ($entry['source_key'] ?? ''), 5);
        if ($parts === null || !in_array($parts[0], ['B', 'P'], true)) return null;
        $year = is_int($entry['posting_year'] ?? null) ? (string) $entry['posting_year'] : substr((string) ($entry['date'] ?? ''), 0, 4);
        $key = [$year, $parts[1], $parts[2]];
        if ($parts[0] === 'B') $key[] = $parts[3];
        return ['kind' => $parts[0] === 'B' ? 'bank' : 'cash', 'key' => json_encode($key, JSON_THROW_ON_ERROR)];
    }
}
