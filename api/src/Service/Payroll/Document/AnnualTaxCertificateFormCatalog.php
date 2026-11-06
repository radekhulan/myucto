<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use DomainException;
use MyInvoice\Service\Payroll\IncomeTax\SeparateTaxBaseRegime;

/**
 * Čísla vzorů tiskopisů jsou skutečně ročníková — MF vydává nový vzor každý rok
 * a odvodit ho nelze. Katalog proto zůstává fail-closed: pro rok, jehož vzor
 * není potvrzený z oficiálního zdroje, se potvrzení nevystaví. To ale není brána
 * na rok modulu (tu drží ruleset), jen evidence známých tiskopisů — nový ročník
 * se přidá sem, ne do podmínky.
 */
final class AnnualTaxCertificateFormCatalog
{
    /**
     * @var non-empty-array<int, array<string, array{
     *   form_number:string,
     *   ministry_form:string,
     *   pattern_number:int,
     *   official_url:string
     * }>>
     */
    private const FORMS = [
        2026 => [
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate->value => [
                'form_number' => '25 5460',
                'ministry_form' => 'MFin 5460',
                'pattern_number' => 33,
                'official_url' =>
                    'https://financnisprava.gov.cz/assets/tiskopisy/5460_33.pdf',
            ],
            PayrollDocumentKind::TaxableIncomeWithholdingCertificate->value => [
                'form_number' => '25 5460/A',
                'ministry_form' => 'MFin 5460/A',
                'pattern_number' => 12,
                'official_url' =>
                    'https://financnisprava.gov.cz/assets/tiskopisy/5460-A_12.pdf',
            ],
        ],
    ];

    /** @return list<int> */
    public static function knownTaxYears(): array
    {
        $years = array_keys(self::FORMS);
        sort($years);

        return $years;
    }

    /**
     * @return array{
     *   tax_year:int,
     *   document_kind:PayrollDocumentKind,
     *   form_number:string,
     *   ministry_form:string,
     *   pattern_number:int,
     *   valid_from:string,
     *   valid_to:string,
     *   official_url:string
     * }
     */
    public static function resolve(int $taxYear, PayrollDocumentKind $kind): array
    {
        // Od zdaňovacího období 2027 není z příjmů ze závislé činnosti srážková
        // daň (§ 6 odst. 4 ZDP ve znění zák. č. 360/2025 Sb. je samostatný
        // základ ZÁLOHY), takže potvrzení o sražené dani nemá co potvrdit.
        // Údaje o záloze ze samostatného základu nese doklad podle § 38j odst. 3.
        if ($kind === PayrollDocumentKind::TaxableIncomeWithholdingCertificate
            && SeparateTaxBaseRegime::forTaxYear($taxYear)->isAdvance()
        ) {
            throw new DomainException(
                "Za rok {$taxYear} se potvrzení o srážkové dani ze závislé činnosti nevystavuje:"
                . ' od roku 2027 se z příjmů podle § 6 odst. 4 ZDP sráží záloha, ne srážková daň.',
            );
        }
        $year = self::FORMS[$taxYear] ?? throw new DomainException(
            "Pro rok {$taxYear} není znám vzor tiskopisu ročního daňového potvrzení;"
            . ' doplň ho do katalogu podle vydání Ministerstva financí.',
        );
        $form = $year[$kind->value] ?? throw new DomainException(
            "Nepodporovaný druh ročního daňového potvrzení: {$kind->value}.",
        );

        return [
            'tax_year' => $taxYear,
            'document_kind' => $kind,
            'form_number' => $form['form_number'],
            'ministry_form' => $form['ministry_form'],
            'pattern_number' => $form['pattern_number'],
            'valid_from' => sprintf('%04d-01-01', $taxYear),
            'valid_to' => sprintf('%04d-12-31', $taxYear),
            'official_url' => $form['official_url'],
        ];
    }
}
