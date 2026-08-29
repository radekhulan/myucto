<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\ForeignIncome;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\EpoSupplierBlockBuilder;

/**
 * Sestaví oznámení o příjmech plynoucích do zahraničí (DPSHL1, § 38da ZDP)
 * a hlášení o srážce zajištění daně (DPSZD1, § 38e ZDP).
 *
 * ## Proč se tu nic nedopočítává
 *
 * Obě písemnosti jsou událostní — váží se ke konkrétní výplatě nerezidentovi,
 * ne ke zdaňovacímu období — a aplikace tu událost nikde neeviduje:
 *
 * 1. **Z mezd nepochází ani jedna.** § 38da odst. 5 písm. b) z oznamovací
 *    povinnosti vylučuje příjem podle § 6 odst. 4 a mzdový modul sráží daň
 *    zvláštní sazbou jedině podle § 6 odst. 4. § 38e odst. 1 věta poslední pak
 *    říká rovnou, že ze záloh z příjmů ze závislé činnosti se zajištění daně
 *    nesráží vůbec.
 * 2. **Přijaté doklady srážkovou daň ani zajištění nenesou.** Aplikace nemá
 *    dividendy, licenční poplatky, úroky ani srážku u plateb do zahraničí.
 *
 * Vymýšlet chybějící údaje by znamenalo podat nepravdivé podání, takže věcnou
 * část zadává uživatel. Aplikace přidává to, co umí ověřit: větu plátce, cílový
 * finanční úřad, kritické kontroly schématu, XSD validaci, archivaci a odeslání
 * do EPO.
 */
final class ForeignIncomeNoticeService
{
    public const FORM_INCOME_NOTICE = 'dpshl1';
    public const FORM_TAX_SECURITY = 'dpszd1';

    /** @var list<string> */
    public const FORMS = [self::FORM_INCOME_NOTICE, self::FORM_TAX_SECURITY];

    public function __construct(
        private readonly Connection $db,
        private readonly ForeignIncomeXmlBuilder $xmlBuilder,
    ) {}

    /**
     * Číselníky, které formulář potřebuje nabídnout, aby uživatel nemusel
     * hodnoty hledat v XSD.
     *
     * @return array<string,mixed>
     */
    public function catalog(): array
    {
        $kinds = [];
        foreach (ForeignIncomeKindCatalog::all() as $code => $kind) {
            $kinds[] = [
                'code' => $code,
                'group' => $kind['group'],
                'label' => $kind['label'],
                'paragraph' => $kind['paragraph'],
                'effective_from' => $kind['from'],
                'allows_exempt' => ForeignIncomeKindCatalog::allowsExemptReporting($code),
            ];
        }

        return [
            'income_kinds' => $kinds,
            'taxpayer_types' => ForeignPayee::TYPY_POPLATNIKA,
            'tax_id_types' => ForeignPayee::TYPY_DIC,
            'address_types' => ForeignPayee::TYPY_ADRESY,
            'notice_variants' => ForeignIncomeNotice::TYPY,
            'payment_modes' => ForeignIncomeNotice::ZPUSOBY_UHRADY,
            'security_rates' => TaxSecurityNotice::SAZBY,
        ];
    }

    /**
     * XML jedné písemnosti.
     *
     * @param array<string,mixed> $payload Věcná část podání od uživatele.
     * @param array{verze_sw?:string,verze_pis?:string} $meta
     * @return array{xml:string,summary:array<string,mixed>,warnings:list<string>,period_year:int,variant:string}
     */
    public function build(
        int $supplierId,
        string $formCode,
        array $payload,
        array $meta = [],
    ): array {
        if (!in_array($formCode, self::FORMS, true)) {
            throw new \InvalidArgumentException(
                'Neznámá písemnost: ' . $formCode,
            );
        }
        $supplier = EpoSupplierBlockBuilder::loadSupplier($this->db->pdo(), $supplierId);

        if ($formCode === self::FORM_INCOME_NOTICE) {
            $notice = $this->incomeNoticeFrom($payload);
            $built = $this->xmlBuilder->buildIncomeNotice($supplier, $notice, $meta);
        } else {
            $notice = $this->securityNoticeFrom($payload);
            $built = $this->xmlBuilder->buildSecurityNotice($supplier, $notice, $meta);
        }

        return [
            'xml' => $built['xml'],
            'summary' => $notice->toSummary(),
            'warnings' => $built['warnings'],
            'period_year' => $notice->periodYear(),
            'variant' => $notice->variant,
        ];
    }

    /** @param array<string,mixed> $payload */
    public function incomeNoticeFrom(array $payload): ForeignIncomeNotice
    {
        $remittances = [];
        foreach ($this->rows($payload, 'remittances') as $row) {
            $remittances[] = new ForeignIncomeRemittance(
                $this->requireString($row, 'paid_on'),
                $this->requireInt($row, 'amount_czk'),
                $this->optionalString($row, 'account'),
            );
        }

        return new ForeignIncomeNotice(
            strtoupper($this->requireString($payload, 'variant')),
            $this->optionalString($payload, 'discovered_on'),
            $this->payeeFrom($payload),
            $this->requireInt($payload, 'income_kind'),
            $this->requireInt($payload, 'rate_tenths_of_percent'),
            strtoupper($this->requireString($payload, 'payment_mode')),
            $this->optionalString($payload, 'payment_date'),
            $this->optionalInt($payload, 'payment_year'),
            $this->requireInt($payload, 'paid_amount_minor'),
            $this->requireInt($payload, 'tax_base_minor'),
            $this->requireInt($payload, 'withheld_tax_czk'),
            $this->optionalString($payload, 'withholding_due_on'),
            $this->optionalString($payload, 'remittance_due_on'),
            $this->optionalInt($payload, 'gross_income_minor'),
            $this->optionalInt($payload, 'mandatory_insurance_czk'),
            $this->optionalInt($payload, 'foreign_gross_minor'),
            $this->optionalUpper($payload, 'foreign_gross_currency'),
            $this->optionalUpper($payload, 'payment_currency'),
            $this->optionalInt($payload, 'exchange_rate_thousandths'),
            $this->optionalString($payload, 'note'),
            $remittances,
        );
    }

    /** @param array<string,mixed> $payload */
    public function securityNoticeFrom(array $payload): TaxSecurityNotice
    {
        return new TaxSecurityNotice(
            strtoupper($this->requireString($payload, 'variant')),
            $this->payeeFrom($payload),
            $this->requireString($payload, 'income_description'),
            strtoupper($this->requireString($payload, 'rate')),
            $this->requireInt($payload, 'income_minor'),
            $this->requireInt($payload, 'secured_tax_czk'),
            $this->requireString($payload, 'receivable_on'),
            $this->requireString($payload, 'decisive_on'),
            $this->optionalString($payload, 'remitted_on'),
            $this->optionalString($payload, 'permanent_establishment_address'),
            $this->optionalString($payload, 'note'),
        );
    }

    /** @param array<string,mixed> $payload */
    private function payeeFrom(array $payload): ForeignPayee
    {
        $payee = $payload['payee'] ?? null;
        if (!is_array($payee)) {
            throw new \InvalidArgumentException('Podání neobsahuje údaje o poplatníkovi.');
        }

        return new ForeignPayee(
            $this->requireString($payee, 'taxpayer_type'),
            $this->optionalString($payee, 'first_name'),
            $this->optionalString($payee, 'last_name'),
            $this->optionalString($payee, 'company_name'),
            $this->optionalString($payee, 'birth_date'),
            $this->optionalString($payee, 'tax_id'),
            $this->optionalUpper($payee, 'tax_id_type'),
            $this->optionalUpper($payee, 'tax_id_country'),
            strtoupper($this->requireString($payee, 'residence_country')),
            $this->requireString($payee, 'city'),
            $this->optionalString($payee, 'postal_code'),
            $this->optionalString($payee, 'street'),
            $this->optionalString($payee, 'address_type') ?? ForeignPayee::ADRESA_BYDLISTE,
            $this->optionalString($payee, 'birth_place'),
            $this->optionalUpper($payee, 'birth_country'),
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array<string,mixed>>
     */
    private function rows(array $payload, string $key): array
    {
        $rows = $payload[$key] ?? [];
        if (!is_array($rows)) {
            throw new \InvalidArgumentException('Položka „' . $key . '" musí být seznam.');
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException(
                    'Položka „' . $key . '" musí být seznam objektů.',
                );
            }
            $result[] = $row;
        }

        return $result;
    }

    /** @param array<string,mixed> $data */
    private function requireString(array $data, string $key): string
    {
        $value = $this->optionalString($data, $key);
        if ($value === null) {
            throw new \InvalidArgumentException('Chybí povinná položka „' . $key . '".');
        }

        return $value;
    }

    /** @param array<string,mixed> $data */
    private function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null || is_array($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $data */
    private function optionalUpper(array $data, string $key): ?string
    {
        $value = $this->optionalString($data, $key);

        return $value === null ? null : strtoupper($value);
    }

    /** @param array<string,mixed> $data */
    private function requireInt(array $data, string $key): int
    {
        $value = $this->optionalInt($data, $key);
        if ($value === null) {
            throw new \InvalidArgumentException('Chybí povinná položka „' . $key . '".');
        }

        return $value;
    }

    /** @param array<string,mixed> $data */
    private function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (!is_numeric($value) || (string) (int) $value !== (string) $value) {
            throw new \InvalidArgumentException(
                'Položka „' . $key . '" musí být celé číslo.',
            );
        }

        return (int) $value;
    }
}
