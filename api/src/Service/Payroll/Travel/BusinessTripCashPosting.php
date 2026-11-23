<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Travel;

use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerSettingsRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentDimensionRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;

/**
 * Účetní zápis nezdaněné části cestovní náhrady vypořádané pokladnou.
 *
 * Při vypořádání mzdou zaúčtuje celou náhradu mzdový předpis (MD 512 /
 * D 331) a zálohu odúčtuje odpočet ve výplatě (MD 331 / D 335). Při vypořádání
 * pokladnou jde do mzdy jen nadlimitní část, takže nezdaněnou část musí
 * zaúčtovat samo vyúčtování: MD cestovné / D pohledávka za zaměstnancem (335),
 * na které visí poskytnutá záloha. Pokladní doklad doplatku (MD 335 / D 211)
 * nebo vrácení přeplatku (MD 211 / D 335) pak pohledávku vyrovná — pokladna
 * ho účtuje sama, jako každý pokladní pohyb.
 *
 * Účty jsou firemní předkontace mezd (`travel_expense_debit`,
 * `employee_receivable_debit`), tytéž, které použije vypořádání mzdou.
 *
 * Náklad nese dimenze firmy, na které ukazují mzdové dimenze pracovního vztahu
 * (stejně jako mzdový předpis), zbytek doplní pravidla dimenzí podle účtu.
 *
 * Zápis nesmí shodit promítnutí: zamčené nebo uzavřené období se vrátí jako
 * `skipped` s důvodem a opakované promítnutí zápis dožene. Jiná chyba zaúčtování
 * (např. chybějící povinná dimenze) se vrátí jako `error` s hláškou, aby náhrada
 * nezůstala nezaúčtovaná potichu.
 */
final class BusinessTripCashPosting
{
    public const SOURCE_TYPE = 'payroll_travel';

    /** Důvody, kdy zápis počká na opakované promítnutí (období zamčené, uzavřené, chybí). */
    private const SKIP_CODES = ['period_not_open', 'date_locked', 'no_accounting_period'];

    public function __construct(
        private readonly PostingService $posting,
        private readonly AccountingModeRepository $accountingModes,
        private readonly JournalEntryRepository $journal,
        private readonly PayrollEmployerSettingsRepository $settings,
        private readonly PayrollEmploymentDimensionRepository $employmentDimensions,
        private readonly DimensionRepository $dimensions,
    ) {}

    /**
     * @param array<string,mixed> $trip
     * @return array{status:string,journal_entry_id:?int,reason:?string,message:?string}
     */
    public function post(
        int $supplierId,
        array $trip,
        BusinessTripSettlement $settlement,
        ?int $userId,
    ): array {
        $tripId = PayrollTimeValue::int($trip['id'] ?? null, 'trip_id');
        if ($settlement->mode !== BusinessTripSettlement::MODE_CASH
            || $settlement->exemptMinor === 0
        ) {
            return self::outcome('not_applicable');
        }
        $entryDate = self::entryDate($trip);
        if ($this->accountingModes->forYear($supplierId, (int) substr($entryDate, 0, 4))
            !== 'double_entry'
        ) {
            return self::outcome('not_applicable');
        }
        $existing = $this->journal->findBySource($supplierId, self::SOURCE_TYPE, $tripId);
        if ($existing !== null) {
            return self::outcome('already_posted', (int) $existing['id']);
        }

        $accounts = $this->settings->get($supplierId)['accounts'] ?? [];
        $debit = self::account($accounts, 'travel_expense_debit');
        $credit = self::account($accounts, 'employee_receivable_debit');
        $amount = number_format($settlement->exemptMinor / 100, 2, '.', '');
        try {
            $entryId = $this->posting->postDocument(
                $supplierId,
                self::SOURCE_TYPE,
                $tripId,
                [
                    ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount]
                        + $this->expenseDimensions($supplierId, $trip, $entryDate),
                    ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
                ],
                [
                    'entry_date' => $entryDate,
                    'document_no' => 'CN-' . $tripId,
                    'description' => 'Vyúčtování pracovní cesty — nezdaněná náhrada vypořádaná pokladnou',
                    'posted' => true,
                    'posted_by' => $userId,
                    'user_id' => $userId,
                ],
            );
        } catch (PostingException $exception) {
            if (in_array($exception->errorCode, self::SKIP_CODES, true)) {
                return self::outcome('skipped', null, $exception->errorCode);
            }
            return self::outcome('error', null, $exception->errorCode, $exception->getMessage());
        }

        return self::outcome('posted', $entryId);
    }

    /**
     * Den vyúčtování je den schválení — tehdy vzniká nárok v zaúčtovatelné
     * výši (§ 183 ZP). Neschválená cesta sem nedojde.
     *
     * @param array<string,mixed> $trip
     */
    private static function entryDate(array $trip): string
    {
        $approvedAt = $trip['approved_at'] ?? null;

        return is_string($approvedAt) && strlen($approvedAt) >= 10
            ? substr($approvedAt, 0, 10)
            : date('Y-m-d');
    }

    /**
     * Dimenze nákladového řádku z mzdových dimenzí vztahu platných v den vyúčtování:
     * jediná hodnota typu jako hodnota, více podílů jako rozpad.
     *
     * @param array<string,mixed> $trip
     * @return array<string,mixed>
     */
    private function expenseDimensions(int $supplierId, array $trip, string $entryDate): array
    {
        $employmentId = (int) ($trip['employment_id'] ?? 0);
        if ($employmentId <= 0 || !$this->dimensions->enabled($supplierId)) {
            return [];
        }
        $ref = 'payroll_employment:' . $employmentId;
        $line = [];
        foreach ($this->employmentDimensions->companyDimensionsOn($supplierId, $employmentId, $entryDate) as $typeId => $shares) {
            if (count($shares) === 1) {
                $line['dimensions'][$typeId] = (int) array_key_first($shares);
                $line['dimension_origins'][$typeId] = ['origin' => 'module', 'ref' => $ref];
                continue;
            }
            $line['dimension_splits'][$typeId] = $shares;
            foreach (array_keys($shares) as $valueId) {
                $line['dimension_split_origins'][$typeId][$valueId] = ['origin' => 'module', 'ref' => $ref];
            }
        }

        return $line;
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

    /** @return array{status:string,journal_entry_id:?int,reason:?string,message:?string} */
    private static function outcome(
        string $status,
        ?int $journalEntryId = null,
        ?string $reason = null,
        ?string $message = null,
    ): array {
        return [
            'status' => $status,
            'journal_entry_id' => $journalEntryId,
            'reason' => $reason,
            'message' => $message,
        ];
    }
}
