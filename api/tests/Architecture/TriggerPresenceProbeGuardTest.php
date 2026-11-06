<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\TestCase;

/**
 * Zda databáze má triggery, se zjišťuje přes {@see \MyInvoice\Infrastructure\Database\TriggerMetadata::any()}.
 *
 * `information_schema.TRIGGERS` otevírá definice všech tabulek schématu. Na kopii
 * se stovkami tabulek trvá jeden dotaz kolem 0,6 s i bez jediného triggeru a pojistky
 * anonymizace se ptají před každou fází, takže jen ony zabíraly přes 20 s jednoho běhu.
 */
final class TriggerPresenceProbeGuardTest extends TestCase
{
    public function testApplicationCodeDoesNotProbeTriggersThroughInformationSchema(): void
    {
        $offenders = [];
        foreach (SourceCorpus::sources(dirname(__DIR__, 2) . '/src') as $rel => $code) {
            if (preg_match_all('/[\'"][^\'"\n]*\binformation_schema\s*\.\s*TRIGGERS\b/i', $code, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[0] as [$_, $offset]) {
                    $offenders[] = $rel . ':' . (substr_count($code, "\n", 0, $offset) + 1);
                }
            }
        }

        self::assertSame([], $offenders, "Dotaz na information_schema.TRIGGERS nahraď TriggerMetadata::any() nebo TriggerMetadata::read():\n  " . implode("\n  ", $offenders));
    }
}
