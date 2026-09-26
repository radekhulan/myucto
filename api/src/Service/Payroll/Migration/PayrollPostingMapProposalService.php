<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerSettingsRepository;
use PDO;

/**
 * Vstupní bod pro převod: z převzatého zaúčtování postaví a uloží návrh.
 *
 * Existuje kvůli volajícím. Bez něj by si každý převod musel sám sehnat osnovu
 * firmy a její dosavadní předkontace, a právě tam se dá udělat chyba, po které
 * návrh vypadá hotově a přitom srovnává proti prázdnu: osnova načtená i s
 * neaktivními účty označí za „v osnově" i účet, který se uložit nedá.
 *
 * ⚠ Nic to neúčtuje. Uloží se JEN návrh nastavení; do `payroll_employer_settings`
 * sahá teprve potvrzení účetní ({@see PayrollPostingMapApplyService}).
 */
final class PayrollPostingMapProposalService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPostingMapProposalStore $proposals,
        private readonly PayrollEmployerSettingsRepository $settings,
    ) {}

    /**
     * @param ?int $year rok exportu, ze kterého návrh vznikl
     * @param ?string $reference název nebo otisk exportu
     * @param bool $preserveConfirmed potvrzený návrh tohoto zdroje se při opakování nepřepíše
     * @return array<string,mixed>|null uložený návrh, nebo `null`, když tabulka
     *         návrhů v instalaci ještě není (starší DB před migrací 1852)
     */
    public function refresh(
        int $supplierId,
        PayrollLegacyPostingSource $source,
        ?int $year = null,
        ?string $reference = null,
        bool $preserveConfirmed = false,
    ): ?array {
        $rows = $source->postingRows();
        if ($rows === [] || !$this->proposals->available()) {
            return null;
        }

        $current = $this->settings->get($supplierId);
        /** @var array<string,string> $accounts */
        $accounts = is_array($current['accounts'] ?? null) ? $current['accounts'] : [];

        $proposal = PayrollPostingMapProposalBuilder::build(
            $source->sourceKey(),
            $rows,
            $this->activeChartCodes($supplierId),
            $accounts,
        );

        return $this->proposals->store($supplierId, $source->sourceKey(), $proposal, $year, $reference, $preserveConfirmed);
    }

    /**
     * Aktivní účty osnovy firmy.
     *
     * Neaktivní se ZÁMĚRNĚ nepočítají: `PayrollEmployerSettingsValidator` je
     * odmítne stejně jako neexistující, takže by je návrh nabídl jako hotovou
     * volbu, kterou nejde uložit.
     *
     * @return list<string>
     */
    private function activeChartCodes(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_code FROM chart_of_accounts WHERE supplier_id = ? AND is_active = 1',
        );
        $stmt->execute([$supplierId]);

        return array_values(array_map(
            static fn (mixed $code): string => (string) $code,
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        ));
    }
}
