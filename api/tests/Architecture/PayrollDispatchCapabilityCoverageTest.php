<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsAgendaCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollAgendaGroupCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapabilityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Nová mzdová agenda nesmí ve frontě odchozích podání skončit bez odpovědi.
 *
 * Fronta ukazuje VŠECHNO připravené napříč agendami. Kdyby nová agenda neměla
 * v {@see PayrollDispatchCapabilityCatalog} záznam, spadla by do fail-closed
 * větve „neumíme" s OBECNÝM důvodem — což je sice bezpečné, ale účetní by se
 * z toho nedozvěděla nic konkrétního a my bychom o mezeře nevěděli.
 *
 * Tenhle test proto vychází z {@see PayrollAgendaGroupCatalog}: co je zařazené
 * do mzdové skupiny (ČSSZ / zdravotní pojišťovny), musí mít i výslovné
 * rozhodnutí, jestli to umíme odeslat.
 */
final class PayrollDispatchCapabilityCoverageTest extends TestCase
{
    /**
     * Agendy, které mzdové skupiny znají, ale vlastní záznam mít NEMAJÍ.
     *
     * Všechny pocházejí z `PayrollAgendaGroupCatalog::LEGACY_BASES` — jsou to
     * kódy, které zapsala starší verze aplikace nebo přišly zvenčí (protokoly
     * ČSSZ, ruční evidence povinnosti). DNES JE NIC NEZAKLÁDÁ, takže vlastní
     * odesílací kanál pro ně nemá co doložit; zařazení do skupiny jim zůstává
     * jen proto, aby historické řádky nezmizely z přehledu.
     *
     * Ve frontě je obslouží fail-closed větev katalogu, která u nich řekne
     * „neumíme odeslat" i s důvodem — což tenhle test níž ověřuje, aby výjimka
     * neznamenala „na tyhle řádky kašleme".
     *
     * @var list<string>
     */
    private const DELIBERATE_GAPS = [
        'PREZAM',
        'OREZAM',
        'ZREZAM',
        'DZMH',
        'HEALTH_HOZ',
        'HEALTH_PPZ',
    ];

    /**
     * Výjimka neznamená mlčení: i historický kód musí ve frontě dostat větu,
     * proč ho aplikace neodešle.
     */
    public function testLegacyAgendasStillExplainThemselves(): void
    {
        $catalog = new PayrollDispatchCapabilityCatalog();
        foreach (self::DELIBERATE_GAPS as $code) {
            $capability = $catalog->forAgenda($code);
            self::assertFalse(
                $capability->isDispatchable(),
                "Historická agenda {$code} se tváří jako odesílatelná.",
            );
            self::assertNotNull(
                $capability->reason,
                "Historická agenda {$code} nemá ve frontě žádné vysvětlení.",
            );
        }
    }

    public function testEveryPayrollAgendaHasExplicitDispatchDecision(): void
    {
        $catalog = new PayrollDispatchCapabilityCatalog();
        $known = array_map(
            PayrollDispatchCapabilityCatalog::canonical(...),
            $catalog->codes(),
        );

        $bases = array_merge(
            PayrollAgendaGroupCatalog::basesOf(
                PayrollAgendaGroupCatalog::GROUP_CSSZ,
            ),
            PayrollAgendaGroupCatalog::basesOf(
                PayrollAgendaGroupCatalog::GROUP_HEALTH,
            ),
        );
        // Pojistka proti vakuově zelenému testu: kdyby se katalog skupin
        // rozbil a nevrátil nic, prošlo by prázdno.
        self::assertGreaterThanOrEqual(
            8,
            count($bases),
            'Sken skupin agend nic nenašel — test by nekontroloval nic.',
        );

        $missing = [];
        foreach ($bases as $base) {
            if (in_array($base, self::DELIBERATE_GAPS, true)) {
                continue;
            }
            // Základ kódu bez ročníku se musí trefit do některého známého
            // záznamu — porovnává se PŘEDPONA, protože katalog nese kódy
            // s ročníkem (`JMHZ25`), kdežto skupiny drží základ (`JMHZ`).
            $matched = false;
            foreach ($known as $code) {
                if ($code === $base || str_starts_with($code, $base)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $missing[] = $base;
            }
        }

        self::assertSame(
            [],
            $missing,
            'Tyhle mzdové agendy nemají ve frontě odchozích podání výslovné'
            . ' rozhodnutí, jestli je umíme odeslat. Doplň je do'
            . ' PayrollDispatchCapabilityCatalog — i když je odeslat neumíme,'
            . ' musí u nich stát důvod.',
        );
    }

    /**
     * Katalog nesmí slibovat datovou schránku tam, kde ji nemá doloženou.
     *
     * Tohle je tvrzení O BĚHU, ne přání: kdyby tu byla agenda navíc, fronta by
     * nabídla tlačítko, které skončí na `payroll_isds_agenda_undocumented`.
     */
    public function testIsdsModeOnlyForDocumentedDataBoxes(): void
    {
        $catalog = new PayrollDispatchCapabilityCatalog();
        $isds = new PayrollIsdsAgendaCatalog();

        $checked = 0;
        foreach ($catalog->codes() as $code) {
            $capability = $catalog->forAgenda($code);
            if ($capability->mode !== PayrollDispatchCapabilityCatalog::MODE_ISDS_PAYROLL
                && $capability->alternateMode !== PayrollDispatchCapabilityCatalog::MODE_ISDS_PAYROLL
            ) {
                continue;
            }
            ++$checked;
            self::assertTrue(
                $isds->has($code),
                "Agenda {$code} má ve frontě režim ISDS, ale doloženou"
                . ' datovou schránku v PayrollIsdsAgendaCatalog nemá.',
            );
        }

        self::assertGreaterThanOrEqual(
            3,
            $checked,
            'Test neověřil žádnou ISDS agendu.',
        );
    }
}
