<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Component\PayrollWholeCrowns;

/**
 * Kontrola běhu: vstupy s haléři, které by zablokovaly měsíční hlášení.
 *
 * Příprava JMHZ odmítne výdělek vztahu s haléři
 * (`jmhz_scenario1_whole_czk_required`), jenže na to se přišlo až po
 * schválení mzdy, kdy už vstupy opravit nejde. Kontrola proto hlásí totéž
 * už před schválením, se seznamem vstupů a odkazem na měsíční vstupy vztahu,
 * kde je hromadné „Zaokrouhlit vstupy na celé koruny nahoru".
 *
 * Výdělek se v hlášení sčítá po složkách do atributů, takže stačí, aby
 * součet každé složky vztahu vyšel na celé koruny: dva vstupy po 0,50 Kč
 * téže složky nevadí. Do kanonického snímku se nepíše, otisk vstupu zůstává.
 *
 * Osvobozený benefit v koši § 6 odst. 9 ZDP (stravenkový paušál 129,50 Kč za
 * směnu) haléře nést smí, viz {@see PayrollWholeCrowns::required()}.
 */
final class PayrollRunWholeCrownInputs
{
    /**
     * @param list<array<string,mixed>> $employments řádky {@see PayrollRunSnapshotBuilder::employmentRows()}
     * @param array<int,list<array<string,mixed>>> $inputRows schválené vstupy období po vztazích
     * @return list<PayrollRunValidation>
     */
    public static function validations(array $employments, array $inputRows, string $periodStart): array
    {
        if (!PayrollWholeCrowns::jmhzPeriod($periodStart)) {
            return [];
        }
        $validations = [];
        $period = substr($periodStart, 0, 7);
        foreach ($employments as $row) {
            $employmentId = (int) $row['employment_id'];
            $reported = [];
            foreach ($inputRows[$employmentId] ?? [] as $input) {
                $amount = $input['amount_minor'] ?? null;
                $component = is_string($input['component_snapshot_json'] ?? null)
                    ? json_decode($input['component_snapshot_json'], true)
                    : null;
                if (!is_numeric($amount) || !is_array($component)
                    || !PayrollWholeCrowns::required(
                        $component,
                        is_numeric($input['benefit_taxable_minor'] ?? null)
                            ? (int) $input['benefit_taxable_minor']
                            : null,
                    )
                ) {
                    continue;
                }
                $reported[] = [
                    'group' => (string) ($component['component_id'] ?? $component['code'] ?? ''),
                    'amount_minor' => (int) $amount,
                    'label' => (string) ($component['name'] ?? $component['code'] ?? ''),
                ];
            }
            $listed = [];
            foreach (PayrollWholeCrowns::blockingInputs($reported) as $key) {
                $listed[] = sprintf(
                    '%s %s Kč',
                    $reported[$key]['label'],
                    number_format($reported[$key]['amount_minor'] / 100, 2, ',', ' '),
                );
            }
            if ($listed === []) {
                continue;
            }
            $validations[] = new PayrollRunValidation(
                'blocker',
                'inputs_not_whole_crowns',
                'employment',
                $employmentId,
                sprintf(
                    '%s: mzdové vstupy s haléři (%s). Měsíční hlášení vykazuje výdělek v celých '
                    . 'korunách a mzda se podle § 142 odst. 2 zákoníku práce zaokrouhluje nahoru. '
                    . 'V měsíčních vstupech použijte „Zaokrouhlit vstupy na celé koruny nahoru“; '
                    . 'vstupy uzamčené během uvolníte zrušením běhu (vstupy se nesmažou) a pak '
                    . 'začněte z opravených vstupů.',
                    (string) ($row['full_name'] ?? ''),
                    implode('; ', $listed),
                ),
                '/payroll/components?tab=inputs&period=' . $period . '&employment=' . $employmentId,
            );
        }

        return $validations;
    }
}
