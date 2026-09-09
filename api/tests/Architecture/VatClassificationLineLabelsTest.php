<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\VatClassificationLineGuard;
use PHPUnit\Framework\TestCase;

/**
 * Výběr řádku přiznání v Číselnících musí mít u KAŽDÉHO řádku popisek.
 *
 * Nález L-1: pole `dphdp3_line` bylo volný text a kódy klasifikací se jmenují jako
 * čísla řádků, takže se dalo omylem opsat číslo kódu. Náprava je výběr s popisky —
 * ten ale chrání jen tehdy, když popisek existuje; holé „42" v nabídce je stejná past
 * jako volný text. Whitelist řádků přitom roste (migrace 1509/1512 přidaly nové cíle),
 * takže bez téhle brány by nový řádek dorazil do UI bez popisku.
 */
final class VatClassificationLineLabelsTest extends TestCase
{
    private const LOCALES = ['cs', 'en'];

    public function testEveryUserSelectableLineHasLabelInBothLocales(): void
    {
        foreach (self::LOCALES as $locale) {
            $messages = $this->vatClassificationMessages($locale);
            self::assertNotSame([], $messages, "Nepodařilo se načíst blok vat_classifications z {$locale}.json.");

            $missing = [];
            foreach (DphPriznaniBuilder::USER_SELECTABLE_LINES as $line) {
                $key = 'line_' . $line;
                if (!isset($messages[$key]) || trim((string) $messages[$key]) === '') {
                    $missing[] = $key;
                }
            }
            self::assertSame([], $missing, sprintf(
                "V %s.json chybí popisky řádků přiznání: %s\nBez nich nabídne UI holé číslo, "
                    . 'což je právě ta záměna kódu a řádku, kterou výběr má odstranit.',
                $locale,
                implode(', ', $missing),
            ));
        }
    }

    /**
     * Kódy hlídané proti záměně musí být v nápovědě pojmenované — jinak uživatel dostane
     * odmítnutí bez vysvětlení, proč zrovna tahle dvojice nejde.
     */
    public function testCollisionHintMentionsGuardedCodes(): void
    {
        $messages = $this->vatClassificationMessages('cs');
        $hint = (string) ($messages['code_hint'] ?? '');
        self::assertNotSame('', $hint, 'Chybí vat_classifications.code_hint.');

        $mentioned = array_filter(
            VatClassificationLineGuard::collidingCodes(),
            static fn (string $code): bool => str_contains($hint, $code),
        );
        self::assertNotSame([], $mentioned, 'Nápověda u pole Kód nezmiňuje žádný z hlídaných kódů.');
    }

    /**
     * @return array<string, mixed>
     */
    private function vatClassificationMessages(string $locale): array
    {
        $path = dirname(__DIR__, 3) . '/web/src/i18n/' . $locale . '.json';
        if (!is_file($path)) {
            self::markTestSkipped('Chybí ' . $path);
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, "Nevalidní JSON: {$path}");

        return is_array($decoded['vat_classifications'] ?? null) ? $decoded['vat_classifications'] : [];
    }
}
