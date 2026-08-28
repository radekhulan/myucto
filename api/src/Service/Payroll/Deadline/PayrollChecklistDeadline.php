<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Deadline;

/**
 * Zákonná lhůta jedné položky nástupního / výstupního checklistu.
 *
 * `dueOn === null` NENÍ „bez termínu, tak si to udělejte kdy chcete": znamená
 * „lhůta existovat může, ale z události samotné se odvodit nedá" (typicky
 * potvrzení o zdanitelných příjmech — deset dnů běží od ŽÁDOSTI zaměstnance,
 * kterou aplikace neeviduje). Vymyslet v takovém případě datum by bylo horší
 * než ho nechat prázdné: varování, které lže, se přestane číst.
 */
final readonly class PayrollChecklistDeadline
{
    public function __construct(
        public string $itemKey,
        public ?string $dueOn,
        public ?string $rulesetId,
        public ?string $source,
        public string $sourceStatus,
        public ?string $note = null,
    ) {
        if (!in_array(
            $sourceStatus,
            ['statute_verified', 'external_unverified', 'not_derived'],
            true,
        )) {
            throw new \InvalidArgumentException(
                'Stav pramene lhůty checklistu není podporovaný.',
            );
        }
        if ($dueOn === null && $sourceStatus !== 'not_derived') {
            throw new \InvalidArgumentException(
                'Položka bez odvozeného termínu musí mít stav pramene not_derived.',
            );
        }
    }
}
