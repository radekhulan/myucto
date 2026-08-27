<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Projde celý katalog kontrol ČSSZ proti vyrobenému XML měsíčního hlášení.
 *
 * Katalog je zdroj pravdy o tom, CO se kontroluje; evaluátor o tom, JAK.
 * Tahle třída obě strany spojí a hlavně pojmenuje třetí stav, který se
 * v podobných vrstvách obvykle ztrácí: kontrolu, která na podání dopadá,
 * je nepropustná, a my ji zatím neumíme vyhodnotit. Taková kontrola se
 * nehlásí jako splněná ani jako selhaná, ale jako mezera v pokrytí, která
 * blokuje odeslání.
 *
 * Dopad kontroly se určuje z atributů, kterých se týká: kontrola, jejíž
 * atributy v podání nejsou, se nevyhodnocuje. Povinnou přítomnost atributů
 * hlídá XSD a resolver před ní, ne tahle vrstva.
 */
final class JmhzScenario1ControlValidator
{
    public function __construct(
        private readonly JmhzControlSourceCatalog $catalog,
        private readonly JmhzScenario1ControlEvaluator $evaluator,
    ) {}

    public static function create(
        PayrollRulesetProvider $rulesets,
        ?string $resourceRoot = null,
    ): self
    {
        $root = $resourceRoot ?? dirname(__DIR__, 5) . '/resources/payroll/jmhz';
        $catalog = JmhzControlSourceCatalog::load($root);
        $specPackages = new JmhzSpecPackageCatalog($root);

        return new self(
            $catalog,
            new JmhzScenario1ControlEvaluator(
                $catalog->parameters(),
                new JmhzDeadlinePolicy($rulesets),
                new JmhzExternalCodebookCatalog($specPackages, $root),
                new JmhzCodebookCatalog($specPackages->load(
                    JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                    JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
                )),
            ),
        );
    }

    public function validate(
        string $xml,
        JmhzControlContext $context,
        ?string $resourceRoot = null,
    ): JmhzControlEvaluationReport {
        $projection = JmhzAttributeProjection::fromXml($xml, $resourceRoot);
        $present = array_flip($projection->presentAttributeIds());
        $findings = [];
        foreach ($this->catalog->definitions() as $definition) {
            foreach ($this->findingsFor($definition, $projection, $present, $context) as $finding) {
                $findings[] = $finding;
            }
        }

        return new JmhzControlEvaluationReport(
            $findings,
            JmhzControlSourceCatalog::CATALOG_KEY,
            JmhzControlSourceCatalog::MANIFEST_SHA256,
            $this->evaluator->documentedDeviations(),
        );
    }

    /**
     * @param array<string, int> $present
     * @return list<JmhzControlFinding>
     */
    private function findingsFor(
        JmhzControlDefinition $definition,
        JmhzAttributeProjection $projection,
        array $present,
        JmhzControlContext $context,
    ): array {
        $controlId = $definition->id->value;
        $applicable = $this->isApplicable($definition, $present);
        if (!$applicable && !$this->evaluator->handles($controlId)) {
            return [$this->finding(
                $definition,
                JmhzControlOutcome::NotApplicable,
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                'Podání neobsahuje žádný z atributů, kterých se kontrola týká.',
            )];
        }
        if (!$this->evaluator->handles($controlId)) {
            return [$this->finding(
                $definition,
                JmhzControlOutcome::Unimplemented,
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                'Kontrola dopadá na podání, ale vykonávací implementaci zatím nemá.',
            )];
        }

        // Kontrola, pro kterou katalog nemá sazbu účinnou k vykazovanému období,
        // se nedá vyhodnotit — ale nesmí strhnout celý report. Podání za období
        // mimo účinnost JMHZ má hlásit kontrola 31, ne výjimka uprostřed běhu.
        try {
            $verdicts = $this->evaluator->evaluate($controlId, $projection, $context);
        } catch (\OutOfBoundsException $exception) {
            return [$this->finding(
                $definition,
                JmhzControlOutcome::Unverifiable,
                JmhzAttributeProjection::PART_SUBMISSION,
                null,
                $exception->getMessage(),
            )];
        }

        $findings = [];
        foreach ($verdicts as $verdict) {
            $findings[] = $this->finding(
                $definition,
                $verdict->outcome,
                $verdict->part,
                $verdict->formOrdinal,
                $verdict->message !== '' ? $verdict->message : $definition->errorMessage,
            );
        }

        return $findings;
    }

    /**
     * Kontrola bez odkazu na atribut je strukturální (počty součástí, duplicita
     * podání) a dopadá vždy. Ostatní dopadají tehdy, když je v podání alespoň
     * jeden z jejich atributů — chybějící blok znamená, že se vykazovaná
     * skutečnost nestala.
     *
     * @param array<string, int> $present
     */
    private function isApplicable(JmhzControlDefinition $definition, array $present): bool
    {
        if ($definition->attributeIds === []) {
            return true;
        }
        foreach ($definition->attributeIds as $attributeId) {
            if (isset($present[$attributeId])) {
                return true;
            }
        }

        return false;
    }

    private function finding(
        JmhzControlDefinition $definition,
        JmhzControlOutcome $outcome,
        string $part,
        ?int $formOrdinal,
        string $message,
    ): JmhzControlFinding {
        return new JmhzControlFinding(
            $definition->id->value,
            $definition->name,
            $outcome,
            $definition->scope,
            $definition->remotePassability,
            $definition->isTechnical(),
            $part,
            $formOrdinal,
            $message,
            $definition->attributeIds,
            $this->errorCode($definition),
        );
    }

    /**
     * Kód chyby se odvozuje od systému, který kontrolu vykonává: DIS přičítá
     * 20 000, cJMHZ 40 000. U kontroly, kterou nevykonává ani jeden, kód chyby
     * neexistuje a nesmí se vymýšlet.
     */
    private function errorCode(JmhzControlDefinition $definition): ?int
    {
        return match ($definition->remoteSystem) {
            JmhzControlSystem::Dis => $definition->id->disErrorCode(),
            JmhzControlSystem::Cjmhz => $definition->id->cjmhzErrorCode(),
            default => null,
        };
    }
}
