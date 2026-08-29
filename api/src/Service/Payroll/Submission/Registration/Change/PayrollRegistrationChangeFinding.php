<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration\Change;

/**
 * Jeden rozešlý údaj: co se změnilo, z čeho na co a jakou akcí se to hlásí.
 *
 * Návrh musí říct KONKRÉTNÍ položku („adresa pobytu, obec"), ne jen že „něco
 * se změnilo". Účetní jinak nemá jak ověřit, jestli je návrh pravda, a buď ho
 * odešle naslepo, nebo ho naslepo zavře — obojí je horší než nic.
 */
final readonly class PayrollRegistrationChangeFinding
{
    public function __construct(
        public string $path,
        public string $group,
        public int $actionCode,
        public bool $sensitive,
        public ?string $from,
        public ?string $to,
    ) {}

    /**
     * Podoba pro API. U citlivých údajů se hodnoty NEVRACEJÍ: rodné číslo,
     * EČP, VČP, daňový identifikátor ani číslo dokladu nemá listovací endpoint
     * proč ukazovat, a „změnilo se rodné číslo" je pro rozhodnutí dost.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'group' => $this->group,
            'action_code' => $this->actionCode,
            'sensitive' => $this->sensitive,
            'from' => $this->sensitive ? null : $this->from,
            'to' => $this->sensitive ? null : $this->to,
        ];
    }
}
