<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\CzIscoCodebook;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzEvidenceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;

/**
 * Číselníky, proti kterým se ověřují údaje pro registraci z podkladů docházky
 * už v náhledu importu ({@see AttendanceRegistrationData::plan()}).
 *
 * Kontrola je stejná jako na kartě vztahu ({@see \MyInvoice\Service\Payroll\PayrollEmploymentValidator}):
 * CZ-ISCO proti klasifikaci ČSÚ (platný i vyřazený kód), druh činnosti proti
 * slovníku JMHZ a obec pracoviště proti CISOB k datu nástupu. Bez ní by
 * hodnota mimo číselník prošla náhledem a při založení osoby skončila jen
 * poznámkou, že se podmínky nezapsaly.
 */
final class AttendanceRegistrationCodebooks
{
    public function __construct(
        private readonly CzIscoCodebook $czIsco,
        private readonly PayrollEmploymentJmhzEvidenceCatalog $evidence,
        private readonly JmhzExternalCodebookCatalog $external,
    ) {
    }

    public function czIscoKnown(string $code): bool
    {
        return in_array(
            $this->czIsco->status($code),
            [CzIscoCodebook::STATUS_ACTIVE, CzIscoCodebook::STATUS_RETIRED],
            true,
        );
    }

    public function activityKnown(string $code): bool
    {
        try {
            $this->evidence->requireActivityCode($code);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /** Pokrývají připnuté číselníky obcí datum nástupu? Mimo pokrytí se neověřuje. */
    public function municipalitiesCover(string $validOn): bool
    {
        return $this->external->coversDate($validOn);
    }

    /** `null` = kód obce k datu v CISOB platí (nebo období číselníky nepokrývají). */
    public function municipalityProblem(string $code, string $validOn): ?string
    {
        try {
            $this->evidence->requireWorkplaceMunicipality($code, $validOn);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Obec podle názvu (bez ohledu na diakritiku a velikost písmen):
     * jednoznačná shoda celého názvu, jinak jediná obec, jejíž název obsahuje
     * zadaný text jako celé slovo („Praha“ = „Hlavní město Praha“).
     * Stejnojmenných obcí je v CISOB řada („Lhota“), u nich se kód hádat nesmí.
     *
     * @return array{code:string,label:string}|null
     */
    public function municipalityByName(string $name, string $validOn): ?array
    {
        $needle = CzIscoCodebook::fold($name);
        if (mb_strlen($needle, 'UTF-8') < 2 || !$this->external->coversDate($validOn)) {
            return null;
        }
        $limit = 50;
        $found = $this->external->searchMunicipalities($name, $validOn, $limit);
        $exact = array_values(array_filter(
            $found,
            static fn (array $item): bool => CzIscoCodebook::fold($item['label']) === $needle,
        ));
        if ($exact !== []) {
            return count($exact) === 1 ? $exact[0] : null;
        }
        if (count($found) >= $limit) {
            return null;
        }
        $pattern = '/(^|[\s\-])' . preg_quote($needle, '/') . '($|[\s\-])/u';
        $words = array_values(array_filter(
            $found,
            static fn (array $item): bool => preg_match($pattern, CzIscoCodebook::fold($item['label'])) === 1,
        ));

        return count($words) === 1 ? $words[0] : null;
    }
}
