<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Submission\PayrollStatutoryAgendaCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('architecture')]
final class PayrollStatutoryAgendaI18nTest extends TestCase
{
    /**
     * Panel dalších povinností skládá popisky z kódů, které vrací tenhle
     * katalog: agenda, capability, vztah k JMHZ, důvod a kroky workflow.
     * Klíč se sestavuje z proměnné, takže statická i18n brána
     * (`web/scripts/check-i18n.mjs`) na něj nedosáhne a vue-i18n při chybějícím
     * překladu jen vypíše syrový klíč — účetní pak v prohlížeči vidí
     * `payroll.submissions.statutory.workflow.send_via_data_box`. Přesně tak
     * se do produkce dostaly nepřeložené kroky NEMPRI a HZUPN.
     *
     * Období jsou vybraná tak, aby padly všechny větve katalogu: legacy NEMPRI
     * do roku 2024, historický ELDP do roku 2025 a stav po náběhu JMHZ.
     */
    public function testEveryCatalogCodeHasCzechAndEnglishLabel(): void
    {
        $catalog = new PayrollStatutoryAgendaCatalog();
        $root = dirname(__DIR__, 3);

        $groups = [
            'agenda' => [],
            'capability' => [],
            'replacement_mode' => [],
            'reason' => [],
            'workflow' => [],
        ];
        foreach (['2024-06', '2025-06', '2026-02', '2026-08'] as $period) {
            foreach ($catalog->forPeriod($period)['agendas'] as $agenda) {
                $groups['agenda'][(string) $agenda['agenda_code']] = true;
                $groups['capability'][(string) $agenda['capability']] = true;
                $groups['replacement_mode'][(string) $agenda['replacement_mode']] = true;
                $groups['reason'][(string) $agenda['reason_code']] = true;
                foreach ($agenda['workflow_codes'] as $code) {
                    $groups['workflow'][(string) $code] = true;
                }
            }
        }

        foreach (['cs', 'en'] as $locale) {
            $messages = json_decode(
                (string) file_get_contents($root . "/web/src/i18n/{$locale}.json"),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $statutory = $messages['payroll']['submissions']['statutory'] ?? null;
            self::assertIsArray($statutory, "Chybí payroll.submissions.statutory v {$locale}.json");

            foreach ($groups as $group => $codes) {
                foreach (array_keys($codes) as $code) {
                    $label = $statutory[$group][$code] ?? null;
                    self::assertIsString(
                        $label,
                        "Chybí payroll.submissions.statutory.{$group}.{$code} v {$locale}.json",
                    );
                    self::assertNotSame(
                        '',
                        trim($label),
                        "Prázdný payroll.submissions.statutory.{$group}.{$code} v {$locale}.json",
                    );
                }
            }
        }
    }

    /**
     * Panel dřív tvrdil „Transport z MyÚčta: Není implementován" u všech agend
     * bez ohledu na `transport_capability`. Katalog přitom u NEMPRI a HZUPN
     * vrací `isds`. Popisek pro obě hodnoty musí existovat, jinak se ta lež
     * vrátí jinou cestou.
     */
    public function testBothTransportCapabilitiesHaveLabel(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['cs', 'en'] as $locale) {
            $messages = json_decode(
                (string) file_get_contents($root . "/web/src/i18n/{$locale}.json"),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $statutory = $messages['payroll']['submissions']['statutory'];
            foreach (['transport_isds', 'transport_not_supported'] as $key) {
                self::assertIsString(
                    $statutory[$key] ?? null,
                    "Chybí payroll.submissions.statutory.{$key} v {$locale}.json",
                );
            }
        }
    }
}
