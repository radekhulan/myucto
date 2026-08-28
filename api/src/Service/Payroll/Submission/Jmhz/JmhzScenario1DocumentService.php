<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzScenario1DocumentService
{
    public function __construct(
        private JmhzPreparationSnapshotService $preparations,
        private JmhzPvpojPreviewService $pvpoj,
        private JmhzScenario1DocumentResolver $resolver,
        private JmhzScenario2DocumentResolver $scenario2Resolver,
        private JmhzSpecialScenarioDocumentResolver $specialScenarios,
    ) {}

    public function resolveScenario2(
        int $supplierId,
        string $environment,
        int $preparationId,
    ): JmhzScenario2Resolution {
        return $this->scenario2Resolver->resolve(
            $this->preparations->loadVerified(
                $supplierId,
                $environment,
                $preparationId,
            ),
        );
    }

    public function resolveSpecialScenarios(
        int $supplierId,
        string $environment,
        int $preparationId,
    ): ?JmhzSpecialScenarioResolution {
        return $this->specialScenarios->resolve(
            $this->preparations->loadVerified(
                $supplierId,
                $environment,
                $preparationId,
            ),
        );
    }

    /**
     * @param int|null $officeId registrace u OSSZ, za kterou se hlášení
     *        sestavuje. Přehled o výši pojistného se podává za účtárnu, takže
     *        se jí musí ptát i tahle vrstva — bez toho spadne běh přes víc
     *        účtáren na `jmhz_scenario1_pvpoj_source_mismatch`. `null` zůstává
     *        jednoúčtárenským během.
     */
    public function resolve(
        int $supplierId,
        string $environment,
        int $preparationId,
        ?int $officeId = null,
    ): JmhzScenario1Resolution {
        $preparation = $this->preparations->loadVerified(
            $supplierId,
            $environment,
            $preparationId,
        );
        if (!in_array(
            $preparation->builderVersion,
            JmhzScenario1DocumentResolver::SUPPORTED_BUILDER_VERSIONS,
            true,
        )) {
            return $this->resolver->resolve($preparation, null, null, $officeId);
        }
        try {
            $pvpoj = $this->pvpoj->preview(
                $supplierId,
                $preparation->sourceRevisionId,
                $officeId,
            );
            return $this->resolver->resolve($preparation, $pvpoj, null, $officeId);
        } catch (JmhzPvpojPreviewException $exception) {
            return $this->resolver->resolve(
                $preparation,
                null,
                $exception->validationCode === 'jmhz_pvpoj_source_not_found'
                    ? 'jmhz_scenario1_pvpoj_unavailable'
                    : 'jmhz_scenario1_pvpoj_source_mismatch',
                $officeId,
            );
        }
    }
}
