<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzScenario1Blocker
{
    /**
     * @param list<string> $attributeIds
     * @param string|null $message konkrétní věta od zdroje nálezu (např. serializéru
     *        formuláře), když obecné vysvětlení kódu nestačí
     */
    public function __construct(
        public string $code,
        public string $entityType,
        public ?int $entityId = null,
        public array $attributeIds = [],
        public ?string $message = null,
    ) {}

    /**
     * Nález s tím, co účetní potřebuje k nápravě: důvod, krok a kam jít.
     *
     * Dřív nesl jen kód a UI u kódů, které nemělo v překladech, ukazovalo
     * „neznámá blokace" s odkazem na podporu - přestože server věděl přesně,
     * co chybí i kde se to doplňuje.
     *
     * @return array{
     *   code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>,
     *   reason:string,action:string,message:?string,
     *   remediation:array{kind:string,field:?string},deferrable:bool
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'attribute_ids' => $this->attributeIds,
            'reason' => JmhzBlockerExplainer::reason($this->code, $this->message),
            'action' => JmhzBlockerExplainer::action($this->code, $this->entityType, $this->attributeIds),
            'message' => $this->message,
            'remediation' => JmhzBlockerCatalog::remediationFor($this->code, $this->entityType, $this->attributeIds),
            'deferrable' => $this->deferrable(),
        ];
    }

    /**
     * Nález, kvůli kterému lze vztah odložit z řádného hlášení.
     *
     * Jen nález na konkrétním vztahu nebo osobě. Firemní nález (účtárna a její
     * variabilní symbol, revize, pojistná část, souhrn, mzdová složka) se
     * odložením jednoho vztahu nevyřeší - hlášení by bez něj nešlo sestavit
     * ani za ostatní.
     */
    public function deferrable(): bool
    {
        return $this->entityId !== null
            && in_array($this->entityType, ['employment', 'person', 'employee'], true);
    }
}
