<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\EpoOkecCodebook;

/**
 * Podklad pro nastavení sazby zákonného pojištění odpovědnosti: sazebník
 * přílohy č. 2 vyhlášky č. 125/1993 Sb. plus NEZÁVAZNÝ návrh podle činnosti,
 * kterou má firma zapsanou v CZ-NACE.
 *
 * Návrh se hledá podle NÁZVU činnosti, nikdy podle čísla kódu. Příloha č. 2
 * totiž člení činnosti podle OKEČ (zrušené k 31. 12. 2007) a stejné číslo tam
 * znamená něco jiného než v CZ-NACE — OKEČ 62 je „Letecká doprava", CZ-NACE 62
 * jsou činnosti v oblasti informačních technologií. Párování čísel by tedy
 * softwarové firmě nabídlo sazbu letecké dopravy.
 *
 * Ani po převodu na název není odpověď jednoznačná: závazný převodník
 * OKEČ ↔ CZ-NACE neexistuje v žádném právním předpise, a v převodníku, který
 * jako metodickou pomůcku publikuje Kooperativa, vychází několika kódům
 * CZ-NACE dvě různé sazby (např. 20.59.0 → 10,5 ‰ i 5,6 ‰). Sazbu proto
 * určuje účetní podle skutečné převažující činnosti; tahle třída jí k tomu
 * dává podklad, ne výsledek.
 */
final class AccidentInsuranceRateAdvisor
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccidentInsuranceRateSchedule $schedule,
    ) {}

    /**
     * @return array{
     *   schedule:array{groups:list<array<string,mixed>>,legal:array<string,mixed>,codebook:array<string,mixed>},
     *   nace:array{code:string,display:string,name:?string,status:string}|null,
     *   suggestions:list<array{group_key:string,rate_per_mille:string,okec_code:string,label:string,score:int}>,
     *   suggestions_binding:false
     * }
     */
    public function advise(int $supplierId): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma sazebníku úrazového pojištění není platná.');
        }
        $nace = EpoOkecCodebook::describe($this->supplierNaceCode($supplierId));
        $suggestions = $nace === null || $nace['name'] === null
            ? []
            : $this->schedule->suggestByActivityName($nace['name']);

        return [
            'schedule' => [
                'groups' => $this->schedule->groups(),
                'legal' => $this->schedule->legal(),
                'codebook' => $this->schedule->provenance(),
            ],
            'nace' => $nace === null ? null : [
                'code' => $nace['code'],
                'display' => $nace['display'],
                'name' => $nace['name'],
                'status' => $nace['status'],
            ],
            'suggestions' => $suggestions,
            // Nikdy se nemění. Je to smluvní pojistka: kdo bere `suggestions`,
            // vidí v téže odpovědi, že za ně aplikace neručí.
            'suggestions_binding' => false,
        ];
    }

    private function supplierNaceCode(int $supplierId): ?string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT cz_nace_code FROM supplier WHERE id = ? LIMIT 1'
        );
        $statement->execute([$supplierId]);
        $value = $statement->fetchColumn();

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
