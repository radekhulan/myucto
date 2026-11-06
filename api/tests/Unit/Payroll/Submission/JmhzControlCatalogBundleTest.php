<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use PHPUnit\Framework\TestCase;

final class JmhzControlCatalogBundleTest extends TestCase
{
    public function testOfficialControlCatalogIsPinnedAndSelfConsistent(): void
    {
        $catalog = JmhzControlSourceCatalog::load();
        $manifest = $catalog->manifest();
        $counts = $manifest['payload']['counts'];

        self::assertSame(JmhzControlSourceCatalog::MANIFEST_SHA256, $manifest['manifest_sha256']);
        self::assertSame('1.4.2.11', $manifest['payload']['version']);
        self::assertSame(203, $counts['controls']);
        self::assertSame(851, $counts['attribute_refs']);
        self::assertSame(227, $counts['unique_attributes']);
        self::assertSame(1, $counts['symbolic_attribute_refs']);
        self::assertSame(0, $counts['source_anomalies']);
        self::assertSame(22, $counts['parameters']);
        self::assertSame(30, $counts['parameter_control_refs']);
        self::assertSame(10, $counts['missing_parameter_control_refs']);
        self::assertSame(50, $counts['parameter_values']);
        self::assertSame(180, $counts['blocking_remote_controls']);
        self::assertSame(19, $counts['passable_remote_controls']);
        self::assertSame(4, $counts['unavailable_remote_controls']);
    }

    public function testExcelFormulasAreArchivedButNeverUsedAsDefinitionText(): void
    {
        $controls = JmhzControlSourceCatalog::load()->manifest()['payload']['controls'];
        $byId = array_column($controls, null, 'control_id');

        self::assertStringStartsWith('=', $byId[3]['detail_formula']);
        self::assertStringStartsWith('Sleva na pojistném', $byId[3]['detail_text']);
        self::assertStringNotContainsString('=', substr($byId[3]['detail_text'], 0, 1));
        self::assertNull($byId[1]['detail_formula']);
    }

    public function testParameterSourceAnomaliesRemainExplicit(): void
    {
        $parameters = JmhzControlSourceCatalog::load()->manifest()['payload']['parameters'];
        $byRow = array_column($parameters, null, 'source_row');

        self::assertSame([118, 270], array_column($byRow[7]['control_refs'], 'control_id'));
        self::assertSame([168, 270], array_column($byRow[8]['control_refs'], 'control_id'));
        self::assertSame('118270', $byRow[7]['control_refs_raw']);
        self::assertSame('118,270', $byRow[7]['control_refs_formatted']);
        self::assertSame(
            'known_excel_number_format_split_118_270',
            $byRow[7]['control_refs_anomaly'],
        );
        self::assertSame('missing', $byRow[11]['control_refs'][0]['resolution']);
        self::assertSame('15', (string) $byRow[20]['values'][0]['canonical_value']);
        self::assertSame('D4', $byRow[4]['values'][1]['source_cell']);
        self::assertSame('s', $byRow[4]['values'][1]['raw_type']);
        self::assertSame("0.298\u{00A0}", $byRow[4]['values'][1]['raw_value']);
        self::assertSame('0.298', $byRow[4]['values'][1]['normalized_value']);
        self::assertSame('n', $byRow[4]['values'][2]['raw_type']);
    }

    public function testExternalSymbolicDependencyIsPreservedSeparately(): void
    {
        $definition = JmhzControlSourceCatalog::load()->definition(291);

        self::assertSame(['OZUSPOJ'], $definition->symbolicAttributeRefs);
        self::assertCount(6, $definition->attributeIds);
    }

    public function testOfficialChangesToControls164And290ArePinnedVerbatim(): void
    {
        $definitions = JmhzControlSourceCatalog::load()->definitions();

        self::assertSame(['10032', '10006', '10010', '10011'], $definitions[164]->attributeIds);
        self::assertSame('unavailable', $definitions[164]->portalSystem->value);
        self::assertStringContainsString('rozhodná období od 04/2026 dál', $definitions[164]->detail);
        self::assertSame(['10032', '10006', '10010', '10011'], $definitions[290]->attributeIds);
        self::assertStringContainsString('jehož pojistná část byla akceptována', $definitions[290]->detail);
        // 1.4.2.10: kontrola platí pro jakékoli podání po lhůtě, ne jen opravné.
        self::assertStringContainsString('v zákonné i po zákonné lhůtě', $definitions[290]->detail);
        self::assertStringNotContainsString('opravného podání', $definitions[290]->detail);
        self::assertSame('passable', $definitions[290]->remotePassability->value);
    }

    public function testControl22DuplicateSubmissionGuidIsPinned(): void
    {
        $definition = JmhzControlSourceCatalog::load()->definition(22);

        self::assertSame(['10001', '10007'], $definition->attributeIds);
        self::assertSame('dis', $definition->remoteSystem->value);
        self::assertSame('blocking', $definition->remotePassability->value);
        self::assertSame(20022, $definition->id->disErrorCode());
        self::assertStringContainsString('Shodné podání typu řádné', $definition->detail);
    }

    /**
     * Katalog 1.4.2.11 opravil deklarované atributy kontroly 333 na 10495
     * a 10016, takže se shodují s pravidlem a anomálie z 1.4.2.9 a 1.4.2.10
     * zmizela. Pravidlo je doslova totéž jako u kontroly 332.
     */
    public function testControl333AttributesNowMatchItsRule(): void
    {
        $catalog = JmhzControlSourceCatalog::load();
        $definitions = $catalog->definitions();
        $row = array_column($catalog->manifest()['payload']['controls'], null, 'control_id')[333];

        self::assertArrayNotHasKey('source_anomaly', $row);
        self::assertNull($definitions[333]->sourceAnomaly);
        self::assertSame(['10495', '10016'], $definitions[333]->attributeIds);
        self::assertSame($definitions[332]->detail, $definitions[333]->detail);
        self::assertSame('cjmhz', $definitions[333]->remoteSystem->value);
        self::assertSame('blocking', $definitions[333]->remotePassability->value);
    }

    public function testNewEldpAndForeignWorkerControlsArePinned(): void
    {
        $definitions = JmhzControlSourceCatalog::load()->definitions();

        self::assertSame(['10356', '10241', '10242'], $definitions[368]->attributeIds);
        self::assertSame('dis', $definitions[368]->remoteSystem->value);
        self::assertSame('blocking', $definitions[368]->remotePassability->value);
        self::assertSame(20368, $definitions[368]->id->disErrorCode());
        self::assertStringContainsString('(10356) <= (10242)', $definitions[368]->detail);

        self::assertSame(['10240', '10241', '10242'], $definitions[369]->attributeIds);
        self::assertSame('blocking', $definitions[369]->remotePassability->value);
        self::assertSame(20369, $definitions[369]->id->disErrorCode());

        self::assertSame('cjmhz', $definitions[371]->remoteSystem->value);
        self::assertSame('passable', $definitions[371]->remotePassability->value);
        self::assertContains('10414', $definitions[371]->attributeIds);
        self::assertContains('10007', $definitions[371]->attributeIds);
    }
}
