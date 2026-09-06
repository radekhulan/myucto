<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

enum InsolvencyMode: string
{
    case None = 'none';
    case AlertOnly = 'alert_only';
    case ApprovedStandard = 'approved_standard';
    case CourtDeterminedAmount = 'court_determined_amount';

    /**
     * Režimy, ve kterých plátce mzdy sráží PRO INSOLVENČNÍHO SPRÁVCE, ne pro
     * exekutora — a tedy potřebuje neměnný platební pokyn.
     *
     * § 406 odst. 3 písm. d) zákona č. 182/2006 Sb. (insolvenční zákon):
     * v rozhodnutí o schválení oddlužení plněním splátkového kalendáře se
     * zpeněžením majetkové podstaty insolvenční soud „přikáže plátci mzdy
     * dlužníka, aby po doručení rozhodnutí o schválení oddlužení prováděl ze
     * mzdy dlužníka stanovené srážky a nevyplácel sražené částky dlužníku."
     * Podle § 406 odst. 5 IZ se to rozhodnutí doručuje plátci mzdy do vlastních
     * rukou a „částky sražené z dlužníkovy mzdy zasílá plátce mzdy dlužníka
     * insolvenčnímu správci, a to bez zřetele k tomu, že rozhodnutí o schválení
     * oddlužení dosud není v právní moci".
     *
     * Rozsah srážky je podle § 398 odst. 3 IZ týž jako při výkonu rozhodnutí
     * pro přednostní pohledávku, ledaže soud podle § 398 odst. 5 IZ na žádost
     * dlužníka (§ 391 odst. 2 IZ — „o stanovení nižších než zákonem určených
     * měsíčních splátek") určí jinou výši měsíčních splátek — to je právě
     * {@see self::CourtDeterminedAmount}.
     *
     * Zdroj: https://www.zakonyprolidi.cz/cs/2006-182#p406 (§ 406),
     * https://www.zakonyprolidi.cz/cs/2006-182#p398 (§ 398),
     * https://www.zakonyprolidi.cz/cs/2006-182#p391 (§ 391).
     *
     * Obě větve tedy poukazují na TÝŽ účet insolvenčního správce a liší se jen
     * VÝŠÍ srážky. Proto je to jedno volatelné pravidlo a ne dvě kopie
     * podmínky `mode === ApprovedStandard` rozeseté po výpočtu, repository
     * a materializaci platebních závazků.
     */
    public function redirectsPaymentToAdministrator(): bool
    {
        return $this === self::ApprovedStandard
            || $this === self::CourtDeterminedAmount;
    }
}
