<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;

/**
 * Okolnosti, které kontroly potřebují a v samotném podání nejsou:
 * den vyhodnocení (kontrola 90 porovnává období s aktuálním kalendářem)
 * a variabilní symbol z GovTalk obálky VREP (kontrola 355 hlídá jejich shodu).
 *
 * Obálka se dodává až s transportní vrstvou. Dokud není, kontrola 355 se
 * nevyhodnocuje — nesmí projít jako splněná jen proto, že nemá s čím porovnat.
 */
final readonly class JmhzControlContext
{
    public function __construct(
        public string $evaluatedOn,
        public ?string $govTalkVariableSymbol = null,
        /**
         * Prošlo XML připnutým XSD? Kontroly 61 a 62 nic jiného nedělají, ale
         * vykonat je smí jen ten, kdo validaci opravdu provedl. Volající, který
         * si tuhle vrstvu zavolá nad cizím XML, je nesmí vydat za splněné.
         */
        public bool $schemaValidated = false,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $evaluatedOn) !== 1) {
            throw new \InvalidArgumentException('Den vyhodnocení kontrol musí být ve tvaru RRRR-MM-DD.');
        }
    }

    /**
     * Den vyhodnocení se bere v českém čase, ne v UTC. Hlášené období je
     * kalendářní měsíc podle českého kalendáře, takže mezi půlnocí a druhou
     * hodinou letního času prvního dne měsíce by UTC ukazovalo ještě na měsíc
     * předchozí — a kontrola 90 by odmítla hlášení za právě skončený měsíc
     * s tím, že ještě neskončil.
     */
    public static function today(
        ?string $govTalkVariableSymbol = null,
        bool $schemaValidated = false,
        ?\DateTimeInterface $at = null,
    ): self {
        return new self(PayrollSubmissionCalendar::today($at), $govTalkVariableSymbol, $schemaValidated);
    }
}
