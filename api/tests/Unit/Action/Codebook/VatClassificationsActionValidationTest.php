<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Codebook;

use MyInvoice\Action\Codebook\VatClassificationsAction;
use PHPUnit\Framework\TestCase;

final class VatClassificationsActionValidationTest extends TestCase
{
    public function testKhSpecialAttributesAcceptOnlyDocumentedValues(): void
    {
        $reflection = new \ReflectionClass(VatClassificationsAction::class);
        $action = $reflection->newInstanceWithoutConstructor();
        $validate = $reflection->getMethod('validate');
        $base = ['code' => 'T90', 'label' => 'Test', 'direction' => 'sale'];

        self::assertNull($validate->invoke($action, $base + ['kh_regime_code' => '2', 'kh_bad_debt' => 'P'], false));
        self::assertStringContainsString('kh_regime_code', (string) $validate->invoke($action, $base + ['kh_regime_code' => '9'], false));
        self::assertStringContainsString('kh_bad_debt', (string) $validate->invoke($action, $base + ['kh_bad_debt' => 'X'], false));
    }

    /**
     * `dphdp3_line` byl volný text bez validace. Řádek, který generátor neumí, se do XML
     * nedostane — základ i daň tiše zmizí, přestože náhled v UI je zobrazí.
     *
     * Zvlášť hlídáme '34' a krácené 40k/41k/42k: ty v $lineMap JSOU, takže by prošly
     * naivní kontrolou „je v mapě", ale uživatel je nastavovat nesmí. U '34' by to bylo
     * horší než tiché zahození — jeho `base` slot nese daň, takže by se do opr_dluz
     * dostal ZÁKLAD a v podaném XML by byla tiše chybná hodnota.
     */
    public function testDphLineAcceptsOnlySupportedReturnLines(): void
    {
        $reflection = new \ReflectionClass(VatClassificationsAction::class);
        $action = $reflection->newInstanceWithoutConstructor();
        $validate = $reflection->getMethod('validate');
        $base = ['code' => 'T91', 'label' => 'Test', 'direction' => 'sale'];

        self::assertNull($validate->invoke($action, $base + ['dphdp3_line' => '1'], false));
        self::assertNull($validate->invoke($action, $base + ['dphdp3_line' => '51b'], false));
        self::assertNull($validate->invoke($action, $base + ['dphdp3_line_secondary' => '43'], false));
        // Prázdné / nevyplněné je v pořádku — klasifikace nemusí do přiznání mířit.
        self::assertNull($validate->invoke($action, $base + ['dphdp3_line' => null], false));
        self::assertNull($validate->invoke($action, $base + ['dphdp3_line' => ''], false));

        self::assertStringContainsString('dphdp3_line', (string) $validate->invoke($action, $base + ['dphdp3_line' => '99'], false));
        self::assertStringContainsString('dphdp3_line', (string) $validate->invoke($action, $base + ['dphdp3_line' => '34'], false));
        self::assertStringContainsString('dphdp3_line', (string) $validate->invoke($action, $base + ['dphdp3_line' => '40k'], false));
        self::assertStringContainsString('dphdp3_line', (string) $validate->invoke($action, $base + ['dphdp3_line' => '33'], false));
        self::assertStringContainsString(
            'dphdp3_line_secondary',
            (string) $validate->invoke($action, $base + ['dphdp3_line_secondary' => '34'], false),
        );
    }

    /**
     * Nález L-1: kód klasifikace se jmenuje stejně jako řádek přiznání, ale znamená
     * něco jiného. Kód `42` = přijaté plnění BEZ nároku na odpočet, řádek 42 = odpočet
     * při dovozu zboží vyměřeném celním úřadem — admin, který kódu `42` nastaví řádek
     * `42`, tiše vyrobí neexistující odpočet. Seed tu chybu udělal a musely ji opravovat
     * migrace 0063 a 0106; per-tenant kód si ji ale může vyrobit znovu.
     */
    public function testCodeNamedLikeADifferentReturnLineIsRejected(): void
    {
        $reflection = new \ReflectionClass(VatClassificationsAction::class);
        $action = $reflection->newInstanceWithoutConstructor();
        $validate = $reflection->getMethod('validate');
        $label = ['label' => 'Test', 'direction' => 'purchase'];

        $err = (string) $validate->invoke($action, ['code' => '42'] + $label + ['dphdp3_line' => '42'], false);
        self::assertStringContainsString('dphdp3_line', $err);
        self::assertStringContainsString('celní úřad', $err, 'Hláška má vysvětlit, co řádek 42 doopravdy je.');

        // Táž past na prodejní straně: kód 3 = osvobozené plnění (patří na ř. 50),
        // řádek 3 = pořízení zboží z JČS.
        self::assertNotNull($validate->invoke(
            $action,
            ['code' => '3', 'label' => 'Test', 'direction' => 'sale', 'dphdp3_line' => '3'],
            false,
        ));
        // A přes secondary řádek taky.
        self::assertNotNull($validate->invoke(
            $action,
            ['code' => '42'] + $label + ['dphdp3_line_secondary' => '42'],
            false,
        ));

        // Kódy, které se svým řádkem shodují SPRÁVNĚ, projít musí — jinak by kontrola
        // zakázala běžnou konfiguraci číselníku.
        self::assertNull($validate->invoke($action, ['code' => '40'] + $label + ['dphdp3_line' => '40'], false));
        self::assertNull($validate->invoke($action, ['code' => '1', 'label' => 'T', 'direction' => 'sale', 'dphdp3_line' => '1'], false));
        // Kód 42 bez řádku (kanonický stav po migraci 0063) je v pořádku.
        self::assertNull($validate->invoke($action, ['code' => '42'] + $label + ['dphdp3_line' => null], false));
        // Stejnojmenný kód na JINÝ řádek se nehlídá — 42 na ř. 40 je věcné rozhodnutí účetní.
        self::assertNull($validate->invoke($action, ['code' => '42'] + $label + ['dphdp3_line' => '40'], false));
    }
}
