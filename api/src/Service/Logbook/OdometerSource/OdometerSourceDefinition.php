<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook\OdometerSource;

/**
 * Popis zdroje pro UI. Texty (návod, popisky polí) bere frontend z i18n
 * `logbook.odosrc_providers.<code>.*`, tady jsou jen jazykově neutrální údaje.
 */
final class OdometerSourceDefinition
{
    /**
     * @param list<array{key:string, placeholder?:string, pattern?:string}> $fields pole formuláře připojení
     * @param int|null $dailyCallLimit denní limit volání služby (informace pro uživatele)
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly array $fields,
        public readonly ?int $dailyCallLimit = null,
    ) {}

    /** @return array{code:string, label:string, fields:list<array<string,string>>, daily_call_limit:?int} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'label' => $this->label, 'fields' => $this->fields,
            'daily_call_limit' => $this->dailyCallLimit];
    }
}
