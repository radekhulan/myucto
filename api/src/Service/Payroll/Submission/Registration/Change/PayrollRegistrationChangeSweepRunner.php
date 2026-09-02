<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration\Change;

/**
 * Noční průchod detekcí registračních změn přes VŠECHNY firmy se mzdami.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč to existuje
 * ═══════════════════════════════════════════════════════════════════════════
 * Detekce ({@see PayrollRegistrationChangeDetectionService}) běžela jen tehdy,
 * když někdo otevřel kartu konkrétního zaměstnance, později také na tlačítko
 * ve frontě odchozích podání. Jenže právě detekce zakládá návrh povinnosti
 * a rozjíždí osmidenní lhůtu (§ 19 odst. 5 zákona č. 323/2025 Sb.). U stovky
 * zaměstnanců s téměř denními změnami kartu denně nikdo neotevře a nikdo
 * nemá důvod mačkat tlačítko — lhůta tak tiše uteče. Tah, který se spustí sám
 * každou noc, je jediné, co tomu zabrání.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co runner NEDĚLÁ
 * ═══════════════════════════════════════════════════════════════════════════
 * Neodesílá. Vůbec. Vznikne návrh povinnosti s termínem, který účetní uvidí
 * ve frontě podání a v přehledu termínů; podání z něj vyrobí až člověk.
 * Strojové odeslání do registru pojištěnců za zády účetní tenhle modul
 * záměrně nemá nikde.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Idempotence — proč se návrhy nezdvojí
 * ═══════════════════════════════════════════════════════════════════════════
 * Runner nemá vlastní frontu ani vlastní pojistku; spoléhá na tytéž dvě, jaké
 * chrání kartu zaměstnance i tlačítko ve frontě:
 *
 * 1. **Vodoznak zdroje** — `payroll_registration_change_scans.source_watermark`
 *    drží otisk hlásitelných údajů z posledního porovnání. Výběr kandidátů
 *    ({@see \MyInvoice\Repository\Payroll\PayrollRegistrationChangeProposalRepository::staleEmployments()})
 *    bere jen vztahy, kde se otisk pohnul. Druhý běh nad nezměněnými daty tedy
 *    neporovná ani jeden vztah a report je prázdný.
 * 2. **Unikátní klíč nad otiskem stavu** — i kdyby se týž vztah porovnal
 *    dvakrát (souběh cronu s otevřenou kartou), návrh je určený otiskem
 *    AKTUÁLNÍHO stavu a druhý insert se srazí na unikátním klíči; vrátí se
 *    ten původní s `created = false`.
 *
 * Souběh s kartou ani s tlačítkem tedy nehrozí: všechny tři cesty volají tutéž
 * službu, každý vztah se počítá v transakci nad zamčenými řádky, a kdo přijde
 * druhý, dostane už existující návrh. Runner navíc nikdy nesahá na vztah, jehož
 * porovnání selhalo — vodoznak se u něj neuloží, takže se příště zkusí znovu.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Dávkování a izolace firem
 * ═══════════════════════════════════════════════════════════════════════════
 * Detekce dešifruje obsah, takže se nikdy nenačítá celá firma naráz: jede se
 * po porcích {@see self::DEFAULT_BATCH} vztahů, dokud porce chodí plné. Strop
 * {@see self::DEFAULT_MAX_BATCHES} je pojistka proti nekonečnu — a proti tomu,
 * aby jedna obří firma sežrala celé noční okno. Co se nestihlo, je v reportu
 * vidět jako `truncated` a dojede to další noc (vodoznak drží pozici).
 *
 * Selhání jedné firmy (rozbitý klíč, nečitelný snapshot, chybějící osnova)
 * nesmí připravit o lhůtu firmy ostatní — proto je try/catch kolem každé zvlášť
 * a report nese jmenovitý seznam.
 */
final readonly class PayrollRegistrationChangeSweepRunner
{
    /** Kolik vztahů se porovná v jedné porci. */
    public const DEFAULT_BATCH = PayrollRegistrationChangeDetectionService::SWEEP_LIMIT;

    /**
     * Kolik porcí smí jedna firma spotřebovat v jednom běhu.
     * 25 × 200 = 5 000 vztahů — víc, než má kterýkoli reálný zákazník, a pořád
     * konečné číslo, takže se běh nemůže zaseknout.
     */
    public const DEFAULT_MAX_BATCHES = 25;

    public function __construct(
        private PayrollRegistrationSweepTargets $targets,
        private PayrollRegistrationChangeSweeper $sweeper,
    ) {}

    /**
     * @param 'production'|'test' $environment
     * @param list<int>|null $onlySuppliers omezení na konkrétní firmy (ladění)
     * @return array{
     *   environment:string,suppliers:int,scanned:int,changed:int,created:int,
     *   unreadable:int,errors:int,truncated:list<int>,
     *   failures:list<array{supplier_id:int,message:string}>
     * }
     */
    public function run(
        string $environment,
        ?array $onlySuppliers = null,
        int $batch = self::DEFAULT_BATCH,
        int $maxBatches = self::DEFAULT_MAX_BATCHES,
    ): array {
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí detekce musí být production nebo test.',
            );
        }
        $batch = max(1, min(1000, $batch));
        $maxBatches = max(1, $maxBatches);

        $supplierIds = $this->targets->payrollEnabledSupplierIds();
        if ($onlySuppliers !== null) {
            $wanted = array_flip($onlySuppliers);
            $supplierIds = array_values(array_filter(
                $supplierIds,
                static fn (int $id): bool => isset($wanted[$id]),
            ));
        }

        $report = [
            'environment' => $environment,
            'suppliers' => count($supplierIds),
            'scanned' => 0,
            'changed' => 0,
            'created' => 0,
            'unreadable' => 0,
            'errors' => 0,
            'truncated' => [],
            'failures' => [],
        ];

        foreach ($supplierIds as $supplierId) {
            try {
                $this->runSupplier($supplierId, $environment, $batch, $maxBatches, $report);
            } catch (\Throwable $exception) {
                // Jedna rozbitá firma nesmí připravit o lhůtu ostatní. Chyba
                // patří do reportu, ať je vidět v Systém → Plánované úlohy;
                // tiché přeskočení by bylo horší než hlášená chyba.
                ++$report['errors'];
                $report['failures'][] = [
                    'supplier_id' => $supplierId,
                    'message' => mb_substr($exception->getMessage(), 0, 300),
                ];
            }
        }

        return $report;
    }

    /**
     * @param array{
     *   environment:string,suppliers:int,scanned:int,changed:int,created:int,
     *   unreadable:int,errors:int,truncated:list<int>,
     *   failures:list<array{supplier_id:int,message:string}>
     * } $report
     * @param-out array{
     *   environment:string,suppliers:int,scanned:int,changed:int,created:int,
     *   unreadable:int,errors:int,truncated:list<int>,
     *   failures:list<array{supplier_id:int,message:string}>
     * } $report
     */
    private function runSupplier(
        int $supplierId,
        string $environment,
        int $batch,
        int $maxBatches,
        array &$report,
    ): void {
        for ($round = 1; $round <= $maxBatches; ++$round) {
            $result = $this->sweeper->sweep($supplierId, $environment, $batch);
            $scanned = (int) $result['scanned'];
            $unreadable = (int) $result['skipped'];
            $report['scanned'] += $scanned;
            $report['changed'] += (int) $result['changed'];
            $report['created'] += (int) $result['created'];
            $report['unreadable'] += $unreadable;

            // Porce nebyla plná = fronta kandidátů došla.
            if ($scanned < $batch) {
                return;
            }
            // Plná porce, ve které se nepodařilo porovnat ANI JEDEN vztah, se
            // příště vrátí ve stejném složení (vodoznak se u selhání neukládá).
            // Další kolo by jen opakovalo tytéž chyby donekonečna.
            if ($scanned === $unreadable) {
                return;
            }
        }

        // Strop porcí: zbytek dojede další běh, vodoznak drží pozici.
        $report['truncated'][] = $supplierId;
    }
}
