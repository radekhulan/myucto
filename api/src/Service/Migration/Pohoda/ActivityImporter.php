<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Dimension\DimensionService;

/**
 * Činnosti POHODY (agenda Činnosti `sCIN`, odkaz `RefCin` na dokladech a v deníku) jako
 * firemní dimenze Činnost (Firma → Dimenze), stejně jako zakázky ({@see ContractImporter}).
 * Sestavy po dimenzích tak ukážou činnost stejně jako v POHODĚ.
 *
 * - Každá činnost z exportu je hodnota dimenze (kód = zkratka, název = text činnosti), i když
 *   ji žádný doklad nepoužívá. Kód činnosti z dokladu, který v číselníku chybí, dostane
 *   hodnotu se stejným kódem i názvem.
 * - Řádky deníku (`pUD.RefCin`, `act:activity`) a doklady dostanou hodnotu podle pravidel
 *   společných se zakázkami ({@see PohodaSplitDimension}).
 *
 * Nezisková organizace (vyhláška 504) má systémovou dimenzi Činnost (kód `activity`, hlavní
 * a hospodářská činnost), na které stojí výkazy a daň z příjmů. Činnost POHODY je volný
 * číselník firmy a z exportu nejde bezpečně poznat, která činnost je hospodářská. Převod
 * proto systémovou dimenzi nemění: činnosti POHODY jsou v samostatné firemní dimenzi
 * „Činnost POHODY" (kód `cinnost`) a hlavní nebo hospodářskou činnost určí účetní.
 *
 * Opakovaný převod doplní hodnoty i dokladům a řádkům z dřívějšího převodu, nic nezdvojí
 * a hodnotu změněnou v MyÚčtu nepřepíše. Zápisy a doklady v uzavřeném období nebo se
 * zmrazenými dimenzemi zůstanou beze změny a protokol je vyjmenuje. Export bez činností
 * nic nemění a dimenze u firmy nezapíná.
 */
final class ActivityImporter
{
    public const STEP = 'activities';

    public const TYPE_CODE = 'cinnost';

    public function __construct(
        private readonly Connection $db,
        private readonly DimensionRepository $dimensions,
        private readonly DimensionService $dimensionService,
        private readonly DimensionPeriodGuard $periodGuard,
        private readonly PohodaSplitDimension $split,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        $activities = $this->activities($ctx);
        $codes = PohodaSplitDimension::codes($ctx, PohodaSplitDimension::ACTIVITY, array_map('strval', array_keys($activities)));
        $this->split->reset(self::STEP);
        if ($codes !== []) {
            $this->periodGuard->transaction(fn () => $this->import($ctx, $activities, $codes));
        }
        $ctx->protocol->finish(self::STEP);
    }

    /**
     * Činnosti z exportu: zkratka => název.
     *
     * @return array<string,string>
     */
    private function activities(PohodaContext $ctx): array
    {
        $out = [];
        foreach ($ctx->export->records('activities', 'activity') as $r) {
            $h = PohodaXml::get($r, 'activityHeader');
            $code = mb_substr(PohodaXml::text($h, 'code'), 0, 50);
            if ($code !== '') {
                $out[$code] = PohodaXml::text($h, 'name');
            }
        }
        return $out;
    }

    /**
     * @param array<string,string> $activities
     * @param list<string> $codes
     */
    private function import(PohodaContext $ctx, array $activities, array $codes): void
    {
        $p = $ctx->protocol;
        $nonprofit = $this->nonprofit($ctx);
        $typeName = $nonprofit ? 'Činnost POHODY' : 'Činnost';
        $this->dimensionService->setEnabled($ctx->supplierId, true);
        $typeId = $this->split->type($ctx->supplierId, self::TYPE_CODE, ['name' => $typeName, 'kind' => 'custom', 'sort_order' => 26]);
        $type = (array) $this->dimensions->findType($ctx->supplierId, $typeId);
        $p->setCount(self::STEP, 'activities', count($activities));

        /** @var array<string,array{value:int,project:?int}> $values */
        $values = [];
        $unlisted = [];
        foreach ($codes as $code) {
            $name = $activities[$code] ?? '';
            if (!isset($activities[$code])) {
                $unlisted[] = $code;
            }
            $values[$code] = $this->split->value($ctx, self::STEP, 'activity', $type, $code, $name !== '' ? $name : $code);
        }
        ['locked' => $locked] = $this->split->stamp($ctx, PohodaSplitDimension::ACTIVITY, self::STEP, 'activity', $typeId, $values);

        $tally = fn (string $name): int => $this->split->tally(self::STEP, $name);
        $listed = array_map('strval', array_keys($activities));
        $p->info(self::STEP, 'activities_summary', sprintf(
            'Činnosti z POHODY: %d (%s). Dimenze %s (Firma → Dimenze): nové hodnoty %d, existující %d. Nově označené řádky deníku: %d, doklady: %d.',
            count($activities), PohodaSplitDimension::list($listed), (string) $type['name'],
            $tally('activity_values_created'), $tally('activity_values_existing'),
            $tally('activity_lines'), $tally('activity_documents'),
        ), ['codes' => $listed]);
        if ($nonprofit) {
            $p->info(self::STEP, 'activity_nonprofit_separate', sprintf(
                'Činnosti POHODY jsou v samostatné dimenzi %s. Systémovou dimenzi Činnost (hlavní a hospodářská činnost) převod podle nich nemění, zařazení řádků do hospodářské činnosti určí účetní.',
                (string) $type['name'],
            ));
        }
        if ($unlisted !== []) {
            $p->info(self::STEP, 'activity_unlisted', sprintf(
                'Doklady nesou činnosti, které export činností neobsahuje; hodnota dimenze má jako název zkratku činnosti: %s.',
                PohodaSplitDimension::list($unlisted),
            ), ['codes' => $unlisted]);
        }
        $this->split->reportLocked($ctx, self::STEP, 'activity_period_not_open', 'Činnost', $locked);
    }

    /** Firma vedená podle vyhlášky 504: osnova neziskové organizace, nebo už má systémovou dimenzi Činnost. */
    private function nonprofit(PohodaContext $ctx): bool
    {
        if ($ctx->export->hasNonprofitChart()) {
            return true;
        }
        $stmt = $this->db->pdo()->prepare("SELECT 1 FROM dimension_types WHERE supplier_id = ? AND system_key = 'activity'");
        $stmt->execute([$ctx->supplierId]);
        return $stmt->fetchColumn() !== false;
    }
}
