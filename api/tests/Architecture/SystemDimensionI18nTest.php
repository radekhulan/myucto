<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class SystemDimensionI18nTest extends TestCase
{
    /**
     * Pevné hodnoty katalogu rámce 504 se ve frontendu překládají klíčem složeným
     * z významu (`dimensions.system_values.${systemKey}.${semanticKey}`, viz
     * `web/src/utils/dimensionValueLabel.ts`). Bez překladu by se tiše ukázal český
     * název z databáze i v angličtině. Český překlad musí odpovídat názvu, který
     * katalog zakládá, jinak by se výběry rozešly se serverovými štítky a exporty.
     */
    public function testEveryFixedCatalogValueHasCzechAndEnglishLabel(): void
    {
        $root = dirname(__DIR__, 3);
        $messages = [];
        foreach (['cs', 'en'] as $locale) {
            $messages[$locale] = json_decode(
                (string) file_get_contents($root . "/web/src/i18n/{$locale}.json"),
                true,
                flags: JSON_THROW_ON_ERROR,
            )['dimensions']['system_values'] ?? [];
        }
        $checked = 0;
        foreach (SystemDimensionCatalog::DEFINITIONS as $systemKey => $definition) {
            foreach ($definition['values'] as $semanticKey => $name) {
                self::assertSame($name, $messages['cs'][$systemKey][$semanticKey] ?? null, "cs.json: dimensions.system_values.{$systemKey}.{$semanticKey}");
                self::assertNotSame('', trim((string) ($messages['en'][$systemKey][$semanticKey] ?? '')), "en.json: dimensions.system_values.{$systemKey}.{$semanticKey}");
                $checked++;
            }
        }
        self::assertGreaterThan(0, $checked);
    }
}
