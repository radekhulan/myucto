<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Jeden úsek zákonné evidence převzaté z předchozího mzdového systému: stav, od kdy
 * (do kdy) platí a čím je doložený.
 *
 * Stejný tvar nese daňová rezidence (`czech-resident` / `non-resident`), prohlášení
 * poplatníka (`signed` / `not-signed`), sleva pracujícího důchodce, zdravotní pojištění
 * (stav = kód pojišťovny) i příslušnost k sociálnímu pojištění (`czech` / `foreign`).
 * Odkaz na zdroj a poznámku skládá čtečka zdroje: jen ona ví, z čeho údaj pochází.
 * `country` nese stát daňové rezidence nerezidenta, pokud ho zdroj vede.
 */
final readonly class PayrollTakeoverEvidencePeriod
{
    public function __construct(
        public string $status,
        public string $from,
        public ?string $to = null,
        public ?string $reference = null,
        public string $note = '',
        public ?string $country = null,
    ) {}
}
