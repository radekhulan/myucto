<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerSettingsRepository;
use MyInvoice\Repository\Payroll\PayrollPostingBatchRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;

/**
 * Předpis zákonného pojištění odpovědnosti zaměstnavatele do deníku.
 *
 * Pojistné podle vyhlášky č. 125/1993 Sb. si zaměstnavatel počítá sám
 * a pojistitel žádný doklad nevystavuje — předpis nákladu proto nevznikal
 * vůbec a úhrada neměla v deníku proti čemu stát. Předpis teď vzniká spolu
 * s každým řádkem čtvrtletního závazku, včetně rozdílových řádků opravné
 * revize:
 *
 *  - odchozí závazek (pojistné vzniklo nebo se zvýšilo): MD náklad / D závazek,
 *  - příchozí závazek (oprava pojistné snížila): MD závazek / D náklad.
 *
 * Zápis nese `source_type = payroll_accident_insurance` a `source_id` řádku
 * závazku, takže ho drží unikátní klíč `uq_je_supplier_source` — jeden řádek
 * závazku, jeden zápis. Podle téhož zdroje najde protiúčet i zaúčtování úhrady
 * ({@see PayrollPaymentPostingService}), takže úhrada odúčtuje přesně ten účet,
 * na kterém předpis stojí, i když si firma mezitím kontaci změnila.
 *
 * Zamčené nebo uzavřené období závazek nezablokuje: předpis se vrátí jako
 * `skipped` s kódem důvodu a další příprava plateb ho zkusí znovu.
 */
final class PayrollAccidentInsurancePosting
{
    public const SOURCE_TYPE = 'payroll_accident_insurance';

    public function __construct(
        private readonly PostingService $posting,
        private readonly AccountingModeRepository $accountingModes,
        private readonly JournalEntryRepository $journal,
        private readonly PayrollEmployerSettingsRepository $settings,
        private readonly Connection $db,
        private readonly PayrollAccidentInsuranceCostAllocation $costAllocation,
        private readonly PayrollPostingBatchRepository $batches,
    ) {}

    /**
     * Náklad se s klíčem `$costKey` rozpadá na střediska a firemní dimenze
     * pracovních vztahů stejně jako pojistné zaměstnavatele na 524; závazek
     * (379) zůstává jeden řádek bez dimenzí. Opravný (rozdílový) řádek závazku
     * se dělí klíčem opraveného čtvrtletí. Rozpad vzniká jednou, při zaúčtování
     * řádku závazku: už zaúčtovaný předpis se nepřeúčtovává.
     *
     * @param 'outgoing'|'incoming' $direction
     * @return array{status:string,journal_entry_id:?int,reason:?string}
     */
    public function post(
        int $supplierId,
        int $liabilityId,
        string $direction,
        int $amountMinor,
        string $quarterEndMonth,
        ?int $userId,
        ?PayrollAccidentInsuranceCostKey $costKey = null,
    ): array {
        if ($amountMinor <= 0) {
            return self::outcome('not_applicable');
        }
        $entryDate = (new \DateTimeImmutable($quarterEndMonth))->format('Y-m-t');
        if ($this->accountingModes->forYear($supplierId, (int) substr($entryDate, 0, 4))
            !== 'double_entry'
        ) {
            return self::outcome('not_applicable');
        }
        $existing = $this->journal->findBySource($supplierId, self::SOURCE_TYPE, $liabilityId);
        if ($existing !== null) {
            return self::outcome('already_posted', (int) $existing['id']);
        }

        $accounts = $this->settings->get($supplierId)['accounts'] ?? [];
        $expense = self::account($accounts, 'accident_insurance_debit');
        $liability = self::account($accounts, 'accident_insurance_credit');
        $outgoing = $direction === 'outgoing';
        $lines = [];
        foreach ($this->expenseParts($supplierId, $costKey, $amountMinor) as $part) {
            $line = [
                'account_code' => $expense,
                'side' => $outgoing ? 'debit' : 'credit',
                'amount' => self::decimal($part['amount_minor']),
            ];
            if ($part['cost_center'] !== null) {
                $line['cost_center'] = $part['cost_center'];
            }
            if ($part['dimensions'] !== []) {
                $line['dimensions'] = $part['dimensions'];
            }
            $lines[] = $line;
        }
        $lines[] = [
            'account_code' => $liability,
            'side' => $outgoing ? 'credit' : 'debit',
            'amount' => self::decimal($amountMinor),
        ];
        try {
            $entryId = $this->posting->postDocument(
                $supplierId,
                self::SOURCE_TYPE,
                $liabilityId,
                $lines,
                [
                    'entry_date' => $entryDate,
                    'document_no' => 'ZPO-' . $liabilityId,
                    'description' => $outgoing
                        ? 'Zákonné pojištění odpovědnosti zaměstnavatele — předpis za čtvrtletí'
                        : 'Zákonné pojištění odpovědnosti zaměstnavatele — snížení předpisu',
                    'posted' => true,
                    'posted_by' => $userId,
                    'user_id' => $userId,
                ],
            );
        } catch (PostingException $exception) {
            return self::outcome('skipped', null, $exception->errorCode);
        }

        return self::outcome('posted', $entryId);
    }

    /**
     * Účet závazku, na kterém předpis daného řádku stojí, nebo `null`, když
     * předpis nevznikl (daňová evidence, závazek z doby před zavedením
     * předpisu, zamčené období).
     *
     * @param 'outgoing'|'incoming' $direction
     */
    public function liabilityAccount(int $supplierId, int $liabilityId, string $direction): ?string
    {
        $entry = $this->journal->findBySource($supplierId, self::SOURCE_TYPE, $liabilityId);
        if ($entry === null || ($entry['reversed_by'] ?? null) !== null) {
            return null;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT account.account_code
               FROM journal_entry_lines line
               JOIN chart_of_accounts account
                 ON account.supplier_id = line.supplier_id
                AND account.id = line.account_id
              WHERE line.supplier_id = ? AND line.entry_id = ? AND line.side = ?
              ORDER BY line.id
              LIMIT 1'
        );
        $statement->execute([
            $supplierId,
            (int) $entry['id'],
            $direction === 'outgoing' ? 'credit' : 'debit',
        ]);
        $code = $statement->fetchColumn();

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Nákladové části předpisu. Bez klíče, nebo když se náklad na střediska
     * a dimenze nerozpadá, je to jedna část za celou částku, tedy přesně
     * dosavadní dvojice 548 / 379.
     *
     * Hodnota firemní dimenze, kterou firma už použít nemůže (smazaná, jiný typ,
     * hodnota skupiny, kterou firma opustila), se z části vypustí: rozpad je
     * analytika navíc a kvůli ní nesmí předpis pojistného zůstat nezaúčtovaný.
     *
     * @return list<array{cost_center:?string,dimensions:array<int,int>,amount_minor:int}>
     */
    private function expenseParts(int $supplierId, ?PayrollAccidentInsuranceCostKey $costKey, int $amountMinor): array
    {
        $plain = [['cost_center' => null, 'dimensions' => [], 'amount_minor' => $amountMinor]];
        if ($costKey === null) {
            return $plain;
        }
        $parts = $this->costAllocation->allocate($supplierId, $costKey, $amountMinor);
        $pairs = [];
        foreach ($parts as $part) {
            foreach ($part['dimensions'] as $typeId => $valueId) {
                $pairs["{$typeId}:{$valueId}"] = ['type_id' => $typeId, 'value_id' => $valueId];
            }
        }
        $unusable = [];
        foreach ($this->batches->unusableDimensionValues($supplierId, array_values($pairs)) as $pair) {
            $unusable["{$pair['type_id']}:{$pair['value_id']}"] = true;
        }
        if ($unusable !== []) {
            foreach ($parts as $index => $part) {
                foreach ($part['dimensions'] as $typeId => $valueId) {
                    if (isset($unusable["{$typeId}:{$valueId}"])) {
                        unset($parts[$index]['dimensions'][$typeId]);
                    }
                }
            }
        }
        $grouped = PayrollAccidentInsuranceCostAllocation::byAnalytics(array_values($parts));

        return $grouped === [] ? $plain : $grouped;
    }

    private static function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    /** @param array<string,mixed> $accounts */
    private static function account(array $accounts, string $key): string
    {
        $code = $accounts[$key] ?? PayrollAccountingDefaults::defaultCode($key);
        if (!is_string($code) || trim($code) === '') {
            throw new \DomainException("Chybí účetní předkontace {$key}.");
        }

        return $code;
    }

    /** @return array{status:string,journal_entry_id:?int,reason:?string} */
    private static function outcome(
        string $status,
        ?int $journalEntryId = null,
        ?string $reason = null,
    ): array {
        return [
            'status' => $status,
            'journal_entry_id' => $journalEntryId,
            'reason' => $reason,
        ];
    }
}
