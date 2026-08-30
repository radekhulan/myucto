<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Obsah jednoho e-podání NEMPRI25.
 *
 * ## Co tu vědomě NENÍ
 *
 * **`rozhodneObdobi`.** Od 1. 4. 2026 se údaje potřebné pro výpočet dávek
 * sdělují VÝHRADNĚ jednotným měsíčním hlášením — § 97 odst. 4 věta první
 * zák. č. 187/2006 Sb.: „Údaje potřebné pro výpočet dávek je zaměstnavatel
 * povinen sdělit prostřednictvím jednotného měsíčního hlášení podle zákona
 * o jednotném měsíčním hlášení zaměstnavatele" a věta druhá je vymezuje jako
 * „vyměřovací základy pro pojistné na nemocenské a důchodové pojištění podle
 * § 18 odst. 2 a vyloučené dny podle § 18 odst. 7". `CtRozhodneObdobi` je
 * v XSD `minOccurs="0"` právě proto. Vyplnit započitatelný příjem sem
 * i do JMHZ by znamenalo dvě verze téhož čísla, které se mohou rozejít.
 *
 * **`prilohy`.** `CtPrilohy` umí 1 až 9 příloh v base64. § 97 odst. 1 věta
 * třetí je vyžaduje, jen když zaměstnanec předal podklady v listinné podobě
 * — tedy u dokladů, které aplikace nedrží. Přidat sem prázdnou přílohovou
 * část by tvrdilo, že žádné takové podklady nejsou.
 *
 * **`platebniSpojeni`.** Způsob výplaty dávky si volí POJIŠTĚNEC v žádosti,
 * ne zaměstnavatel. Zaměstnavatel podle § 97 odst. 2 věty první sděluje
 * „údaje o způsobu výplaty mzdy, platu nebo odměny", což je jiný údaj než
 * účet, na který má chodit dávka.
 */
final readonly class NempriXmlPayload
{
    public function __construct(
        public SicknessBenefitKind $benefitKind,
        public int $osszCode,
        public bool $correction,
        public ?string $decisionNumber,
        public bool $foreignCase,
        public string $insuredFirstName,
        public string $insuredLastName,
        public string $insuredBirthNumber,
        public ?string $insuredPhone,
        public ?string $insuredEmail,
        public string $employerVariableSymbol,
        public ?string $employerIdentificationNumber,
        public string $employerName,
        public string $employmentFrom,
        public ?string $employmentTo,
        public string $activityCode,
        public bool $workedOnDecisiveDay,
        public ?string $hoursWorked,
        public ?string $dailyWorkingHours,
        public ?int $smallScopeIncomeMinor,
        public bool $receivesPension,
        public ?string $pensionKind,
        public bool $isStudent,
        public ?bool $withinSchoolHolidays,
        public bool $firstEmploymentFreeTime,
        public bool $unpaidLeave,
        public ?string $unpaidLeaveFrom,
        public ?string $unpaidLeaveTo,
        public ?bool $startsMaternity,
        public ?string $childBirthDate,
        public bool $transferredOtherWork,
        public ?string $transferredOn,
        public bool $enforcement,
        public bool $insolvency,
        public ?string $additionalNote,
        public string $productName,
        public string $productVersion,
        public string $payloadVersion,
        public ?string $notificationEmail = null,
        public ?string $contactWorkerName = null,
        public ?string $contactWorkerPhone = null,
        public ?string $contactWorkerEmail = null,
    ) {}
}
