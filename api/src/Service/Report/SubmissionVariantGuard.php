<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Accounting\PostingException;

/**
 * Společná pravidla pro VOLBU TYPU PODÁNÍ u přiznání k DPH a kontrolního hlášení.
 *
 * Obě tvrzení mají tutéž trojici otázek — je zvolený typ v souladu se lhůtou, existuje
 * podání, které opravuje, a sedí perioda na registraci plátce? — a obě si na ně dosud
 * odpovídaly každé po svém: KH hlídalo lhůtu i periodu, přiznání ani jedno; přiznání
 * hlídalo základnu dodatečného, KH jen základnu následného. Přesně ten vzor, na který
 * AGENTS.md ukazuje prstem: pravidlo implementované na jedné větvi a nepropagované na
 * druhou. Proto tahle třída — a proto je VEŘEJNÁ a volatelná, ne privátní helper uvnitř
 * jednoho builderu.
 *
 * Co je varování a co tvrdá brzda:
 *   - **Varování** všude, kde je volba obhajitelná: lhůtu lze individuálně prodloužit,
 *     perioda plátce se v roce mění a opravné tvrzení podané omylem po lhůtě je chyba
 *     účetní, ne aplikace. Tvrdá brzda těsně před termínem je horší než falešný poplach.
 *   - **Chyba (422)** jen tam, kde by podání nemělo protějšek, se kterým ho správce daně
 *     spáruje: opravné/následné/dodatečné bez evidovaného předchozího podání.
 */
final class SubmissionVariantGuard
{
    /** Formy, které NAHRAZUJÍ nebo MĚNÍ dříve podané tvrzení (potřebují základnu). */
    private const REPLACES_PRIOR = ['O' => true, 'N' => true, 'E' => true, 'D' => true];

    /** Formy, které smí být podané jen PŘED lhůtou pro řádné tvrzení (§ 138 DŘ, § 101f/1). */
    private const BEFORE_DEADLINE_ONLY = ['O' => true];

    /** Formy, které patří až PO lhůtě (§ 141 DŘ, § 101f/2). */
    private const AFTER_DEADLINE_ONLY = ['N' => true, 'E' => true, 'D' => true];

    public function __construct(private readonly TaxSubmissionRepository $submissions) {}

    /**
     * Sedí zvolený typ podání na lhůtu pro řádné tvrzení?
     *
     * @param string $forma        EPO forma (`B`/`O`/`N`/`E`/`D`)
     * @param string $deadline     lhůta pro ŘÁDNÉ tvrzení (Y-m-d)
     * @param string $correctiveLabel jak se v daném tvrzení jmenuje oprava PŘED lhůtou
     * @param string $followUpLabel   jak se jmenuje oprava PO lhůtě
     * @param string $legalRef     odkaz na ustanovení do textu varování
     * @return list<string>
     */
    public static function deadlineWarnings(
        string $forma,
        string $deadline,
        string $correctiveLabel,
        string $followUpLabel,
        string $legalRef,
        ?string $today = null,
    ): array {
        if ($deadline === '') {
            return [];
        }
        $today ??= date('Y-m-d');
        $warnings = [];
        if (isset(self::BEFORE_DEADLINE_ONLY[$forma]) && $today > $deadline) {
            $warnings[] = sprintf(
                'Lhůta pro řádné podání (%s) už uplynula — %s (%s) lze podat jen před ní. '
                    . 'Po lhůtě se podává %s; zkontrolujte typ podání.',
                $deadline,
                $correctiveLabel,
                $legalRef,
                $followUpLabel,
            );
        }
        if (isset(self::AFTER_DEADLINE_ONLY[$forma]) && $today <= $deadline) {
            $warnings[] = sprintf(
                'Lhůta pro řádné podání (%s) ještě běží — do jejího uplynutí se chyba opravuje '
                    . '%s (%s), ne %s. Zkontrolujte typ podání.',
                $deadline,
                $correctiveLabel,
                $legalRef,
                $followUpLabel,
            );
        }

        return $warnings;
    }

    /**
     * Existuje podání, které tenhle typ opravuje? Chybí-li, je to chyba, ne varování:
     * opravné ani následné tvrzení nemá bez základny co nahradit a správce daně ho nemá
     * s čím spárovat. PRVNÍ podání za období je vždy řádné, i po termínu.
     *
     * @param list<string> $baselineForms formy, které se berou jako základna
     */
    public function requirePriorSubmission(
        string $forma,
        int $supplierId,
        string $formCode,
        int $year,
        ?int $month,
        ?int $quarter,
        array $baselineForms,
        string $errorCode,
        string $message,
    ): void {
        if (!isset(self::REPLACES_PRIOR[$forma])) {
            return;
        }
        $prior = $this->submissions->findLatestForPeriod($supplierId, $formCode, $year, $month, $quarter, $baselineForms);
        if ($prior === null) {
            throw new PostingException($errorCode, $message, 422);
        }
    }

    /**
     * Generuje se ŘÁDNÉ tvrzení za období, které už je podané? Druhé řádné za totéž období
     * nahrazuje to první jen do lhůty a jen jako OPRAVNÉ — bez upozornění se stane, že
     * účetní stáhne nové řádné XML, odešle ho a diví se, proč správce daně eviduje dvě.
     *
     * @return list<string>
     */
    public function alreadyFiledWarnings(
        string $forma,
        int $supplierId,
        string $formCode,
        int $year,
        ?int $month,
        ?int $quarter,
        string $correctiveLabel,
        string $followUpLabel,
    ): array {
        if ($forma !== 'B') {
            return [];
        }
        $filedAt = $this->filedAt($supplierId, $formCode, $year, $month, $quarter, ['B', 'O']);
        if ($filedAt === null) {
            return [];
        }

        return [sprintf(
            'Za toto období už je evidované podané tvrzení (%s). Další ŘÁDNÉ podání ho '
                . 'nenahrazuje — do lhůty se oprava podává jako %s, po lhůtě jako %s.',
            $filedAt,
            $correctiveLabel,
            $followUpLabel,
        )];
    }

    /**
     * Rozhodný čas podání za období, je-li evidované (`submitted` i `accepted`, dodejka
     * není podmínkou). Z téhož údaje vzniká varování o už podaném tvrzení i stav karty
     * s termínem podání, aby si UI a varování nemohly odporovat.
     *
     * @param list<string> $forms formy, které se počítají jako podání v řádné lhůtě
     */
    public function filedAt(
        int $supplierId,
        string $formCode,
        int $year,
        ?int $month,
        ?int $quarter,
        array $forms,
    ): ?string {
        $prior = $this->submissions->findLatestForPeriod($supplierId, $formCode, $year, $month, $quarter, $forms);
        if ($prior === null) {
            return null;
        }
        $at = (string) ($prior['submitted_at'] ?? $prior['generated_at'] ?? '');

        return $at === '' ? null : $at;
    }

    /**
     * Sedí zdaňovací období tvrzení na registraci plátce?
     *
     * Varování, ne brzda: perioda se v roce mění (§ 99a) a za měsíce před změnou je měsíční
     * tvrzení správně. `supplier.vat_period` přitom drží jen AKTUÁLNÍ nastavení, takže tvrdá
     * kontrola by znemožnila podat správné tvrzení za starší období.
     *
     * @param string $periodType  perioda tvrzení (`monthly`/`quarterly`)
     * @param string $vatPeriod   registrovaná perioda plátce (`monthly`/`quarterly`/prázdné)
     * @return list<string>
     */
    public static function periodMismatchWarnings(string $periodType, string $vatPeriod): array
    {
        if ($vatPeriod === '' || $periodType === '' || $periodType === $vatPeriod) {
            return [];
        }

        return [sprintf(
            'Firma má u DPH registrované %s zdaňovací období, ale tvrzení se sestavuje jako %s. '
                . 'Zkontrolujte období — pokud se perioda během roku měnila (§ 99a), je to v pořádku; '
                . 'jinak by tvrzení bylo za období, které pro tuhle firmu neexistuje.',
            $vatPeriod === 'quarterly' ? 'čtvrtletní' : 'měsíční',
            $periodType === 'quarterly' ? 'čtvrtletní' : 'měsíční',
        )];
    }
}
