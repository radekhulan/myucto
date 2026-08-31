<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Isds;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzIsdsRecipientCatalog;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessChannelCatalog;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Které mzdové agendy JDE z aplikace odeslat datovou schránkou — jediný seznam.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč jeden katalog, a ne tři různá místa
 * ═══════════════════════════════════════════════════════════════════════════
 * Tuhle otázku si klade pět míst: zařazovací služba
 * ({@see PayrollIsdsSubmissionService}), seznam připravených podání
 * ({@see \MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository::listReadySubmissions()}),
 * měsíční přehled ({@see \MyInvoice\Service\Payroll\Submission\PayrollMonthlyChecklistService}),
 * brána oprávnění mzdové odesílací brány
 * ({@see \MyInvoice\Action\Submission\IsdsGatewayAction}) a matice schopností
 * ({@see \MyInvoice\Service\Payroll\Submission\PayrollStatutoryAgendaCatalog}).
 * Dokud byla odpověď zadrátovaná v každém z nich zvlášť, tvrdil katalog
 * schopností u NEMPRI a HZUPN „transport_capability: isds" a workflow krok
 * `send_via_data_box`, zatímco jediná obrazovka, která uměla odesílat, se ptala
 * natvrdo na JMHZ — účetní tak připravila hlášení a neměla ho kde odeslat.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co znamená „doložený kanál"
 * ═══════════════════════════════════════════════════════════════════════════
 * Že je z primárního zdroje známý CELÝ tvar zprávy: příjemce (ID schránky),
 * podoba přílohy a to, že ji ČSSZ tímhle kanálem přijímá. Nestačí, že se dá
 * soubor někam nahrát. Zdroje jsou citované u jednotlivých položek a v
 * {@see JmhzIsdsRecipientCatalog} a {@see SicknessChannelCatalog}.
 *
 * Agenda, která tady NENÍ, se nesmí tvářit, že ji aplikace odešle. Buď se sem
 * doplní i s dokladem, nebo musí příslušný katalog schopností přiznat, že
 * odeslání neumíme, a říct proč. Tuhle shodu hlídá spustitelná brána
 * (`PayrollIsdsAgendaCatalogConsistencyTest`), ne jen tenhle odstavec.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč tu nejsou přehledy zdravotním pojišťovnám
 * ═══════════════════════════════════════════════════════════════════════════
 * PPPZ a HOZ jdou toutéž frontou, ale adresát u nich není jeden doložený —
 * je jich devět, po jedné na pojišťovnu, a drží je
 * {@see \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsurerChannelCatalog}
 * včetně toho, který formát (XML/PDF) která pojišťovna přijímá. Slít obojí do
 * jednoho katalogu by znamenalo buď devět položek s prázdným formátem, nebo
 * jednu položku, která o adresátovi lže.
 */
final class PayrollIsdsAgendaCatalog
{
    /**
     * `agenda_code` z evidence povinností JMHZ je historicky psaný dvěma
     * způsoby — starší obligace nesou `JMHZ`, nové `JMHZ25`. Stejnou
     * normalizaci dělá i {@see \MyInvoice\Service\Submission\SubmissionArtifactValidator},
     * takže by se sem hodnoty jinak nedostaly konzistentně.
     */
    private const ALIASES = ['JMHZ' => JmhzSubmissionBridgeService::AGENDA_CODE];

    /** @var array<string,PayrollIsdsAgenda>|null */
    private static ?array $agendas = null;

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys(self::agendas());
    }

    public function has(string $agendaCode): bool
    {
        return isset(self::agendas()[self::canonical($agendaCode)]);
    }

    /**
     * Fail-closed brána. Nedoložená agenda nikdy nekončí obecným
     * „nepodporováno" — vždy řekne, co konkrétně chybí.
     */
    public function require(string $agendaCode): PayrollIsdsAgenda
    {
        $canonical = self::canonical($agendaCode);
        $agenda = self::agendas()[$canonical] ?? null;
        if ($agenda === null) {
            throw new SubmissionChannelException(
                'payroll_isds_agenda_undocumented',
                'Pro agendu ' . $canonical . ' nemáme doloženou datovou schránku'
                    . ' ani tvar zprávy, takže ji aplikace datovkou neodešle.'
                    . ' Připravené XML stáhněte a odešlete ze své schránky.',
                409,
            );
        }

        return $agenda;
    }

    public static function canonical(string $agendaCode): string
    {
        $normalized = strtoupper(trim($agendaCode));

        return self::ALIASES[$normalized] ?? $normalized;
    }

    /** @return array<string,PayrollIsdsAgenda> */
    private static function agendas(): array
    {
        if (self::$agendas !== null) {
            return self::$agendas;
        }

        $jmhzProduction = JmhzIsdsRecipientCatalog::forEnvironment('production');
        $jmhzTest = JmhzIsdsRecipientCatalog::forEnvironment('test');

        // Obecná specializovaná schránka e-Podání ČSSZ. Pro NEMPRI a HZUPN je
        // to schránka PREFEROVANÁ samotnou ČSSZ (viz SicknessChannelCatalog);
        // vlastní schránku jako JMHZ tyhle dvě agendy nemají.
        $csszGeneral = SicknessChannelCatalog::CSSZ_EPODANI_DATA_BOX;
        $csszTest = JmhzIsdsRecipientCatalog::TEST_BOX_ID;

        $sickness = 'ČSSZ, „Komunikační kanály e-Podání" (podklad z 17. 8. 2026):'
            . ' u NEMPRI25 i HZUPN je ISDS uvedený jako podporovaný kanál do'
            . ' schránky e-Podání ČSSZ (' . $csszGeneral . ') nebo na místně'
            . ' příslušnou OSSZ; Podávací a dotazovací protokol v1.47 k oběma'
            . ' uvádí „holé XML".';

        return self::$agendas = [
            JmhzSubmissionBridgeService::AGENDA_CODE => new PayrollIsdsAgenda(
                code: JmhzSubmissionBridgeService::AGENDA_CODE,
                label: 'Jednotné měsíční hlášení zaměstnavatele',
                recipientCodeProduction: 'cssz_epodani_jmhz',
                recipientCodeTest: 'cssz_epodani_test',
                documentedBoxIdProduction: $jmhzProduction->boxId,
                documentedBoxIdTest: $jmhzTest->boxId,
                sourceNote: 'ČSSZ zřídila pro JMHZ vlastní datovou schránku '
                    . $jmhzProduction->boxId . ' (stránka „Komunikační kanály'
                    . ' e-Podání", podklad z 17. 8. 2026).',
            ),
            'NEMPRI' => new PayrollIsdsAgenda(
                code: 'NEMPRI',
                label: SicknessDocumentKind::Nempri->label(),
                recipientCodeProduction: 'cssz_epodani_obecna',
                recipientCodeTest: 'cssz_epodani_test',
                documentedBoxIdProduction: $csszGeneral,
                documentedBoxIdTest: $csszTest,
                sourceNote: $sickness,
            ),
            'HZUPN' => new PayrollIsdsAgenda(
                code: 'HZUPN',
                label: SicknessDocumentKind::Hzupn->label(),
                recipientCodeProduction: 'cssz_epodani_obecna',
                recipientCodeTest: 'cssz_epodani_test',
                documentedBoxIdProduction: $csszGeneral,
                documentedBoxIdTest: $csszTest,
                sourceNote: $sickness,
            ),
        ];
    }
}
