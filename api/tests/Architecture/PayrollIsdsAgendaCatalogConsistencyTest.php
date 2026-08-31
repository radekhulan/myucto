<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsAgendaCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollStatutoryAgendaCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Katalog schopností nesmí slibovat kanál, který nikam nevede.
 *
 * Přesně tohle se stalo NEMPRI a HZUPN: {@see PayrollStatutoryAgendaCatalog}
 * u nich uváděl `transport_capability => 'isds'` i workflow krok
 * `send_via_data_box`, panel po přípravě psal „Odešlete ho ve Stavu odeslání“
 * — a jediná obrazovka, která uměla odesílat, se ptala natvrdo na JMHZ. Účetní
 * tak měla připravené hlášení a neměla ho kde odeslat. Prozaický komentář by
 * to znovu nezachytil, proto je z toho spustitelná brána.
 *
 * Guard je OBOUSMĚRNÝ, protože obě lži jsou stejně drahé:
 *   * slib bez cesty pošle účetní hledat tlačítko, které neexistuje,
 *   * cesta bez slibu znamená, že katalog radí ruční postup u něčeho, co
 *     appka umí — a účetní zbytečně opisuje podání do datové schránky.
 */
#[Group('architecture')]
final class PayrollIsdsAgendaCatalogConsistencyTest extends TestCase
{
    /**
     * Období jsou vybraná tak, aby padly všechny větve katalogu schopností:
     * legacy NEMPRI do roku 2024, historický ELDP do roku 2025 a stav po
     * náběhu JMHZ.
     *
     * @var list<string>
     */
    private const PERIODS = ['2024-06', '2025-06', '2026-02', '2026-08'];

    public function testEveryAgendaPromisingIsdsHasARunnableDispatchPath(): void
    {
        $isds = new PayrollIsdsAgendaCatalog();
        $statutory = new PayrollStatutoryAgendaCatalog();
        $missing = [];

        foreach (self::PERIODS as $period) {
            foreach ($statutory->forPeriod($period)['agendas'] as $agenda) {
                if ($agenda['transport_capability'] !== 'isds') {
                    continue;
                }
                $code = (string) $agenda['agenda_code'];
                if (!$isds->has($code)) {
                    $missing[$code . ' (' . $period . ')'] = true;
                }
            }
        }

        self::assertSame(
            [],
            array_keys($missing),
            'Katalog schopností slibuje datovou schránku u agend, které'
                . ' PayrollIsdsAgendaCatalog nezná — zařazení do fronty pro ně'
                . ' skončí chybou. Buď je do katalogu doplňte i s dokladem,'
                . ' nebo u nich přiznejte transport_capability = not_supported.',
        );
    }

    /**
     * Opačný směr. Výjimka je jediná a odůvodněná: agenda, kterou appka
     * VŮBEC NEPŘIPRAVÍ (`capability = not_supported`), se do fronty nemá jak
     * dostat, takže na ni tenhle guard nesedí — u NEMPRI je to historická
     * varianta do roku 2024.
     */
    public function testNoWorkingIsdsPathIsHiddenBehindNotSupportedTransport(): void
    {
        $isds = new PayrollIsdsAgendaCatalog();
        $statutory = new PayrollStatutoryAgendaCatalog();
        $understated = [];

        foreach (self::PERIODS as $period) {
            foreach ($statutory->forPeriod($period)['agendas'] as $agenda) {
                if ($agenda['transport_capability'] === 'isds'
                    || $agenda['capability'] === 'not_supported'
                ) {
                    continue;
                }
                $code = (string) $agenda['agenda_code'];
                if ($isds->has($code)) {
                    $understated[$code . ' (' . $period . ')'] = true;
                }
            }
        }

        self::assertSame(
            [],
            array_keys($understated),
            'Tyhle agendy jdou odeslat datovou schránkou, ale katalog'
                . ' schopností to zamlčuje — účetní by je opisovala ručně.',
        );
    }

    /**
     * Doplňková pojistka: kdyby se některý z katalogů vyprázdnil, oba testy
     * výš by prošly a nehlídaly by nic.
     */
    public function testBothCatalogsAreNonEmpty(): void
    {
        self::assertNotSame([], (new PayrollIsdsAgendaCatalog())->codes());
        self::assertNotSame(
            [],
            (new PayrollStatutoryAgendaCatalog())->forPeriod('2026-08')['agendas'],
        );
    }
}
