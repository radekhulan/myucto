<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

/**
 * Doložení důchodu, který od 1. 1. 2025 podmiňuje čtvrtinu na manžela/partnera.
 *
 * Nařízení vlády č. 441/2024 Sb. změnilo § 1 nařízení vlády č. 595/2006 Sb.:
 * manžel ani partner povinného se do nezabavitelné částky nezapočítává
 * automaticky. Čtvrtina na něj náleží jen tehdy, „doloží-li povinný plátci
 * mzdy, že jemu nebo jeho manželovi nebo partnerovi byl přiznán starobní
 * důchod, invalidní důchod pro invaliditu druhého nebo třetího stupně nebo
 * sirotčí důchod". Vyživovaných dětí se změna netýká.
 *
 * Tři stavy místo příznaku ano/ne, protože „nikdo se nezeptal" a „zeptali
 * jsme se a doloženo není" mají mít různý dopad:
 *
 *  • {@see self::Documented} — důchod doložen, čtvrtina náleží;
 *  • {@see self::NotDocumented} — účetní zaznamenala, že doložen není.
 *    Povinný neunesl důkazní břemeno, čtvrtina nenáleží. Žádný blokátor,
 *    tohle je řádný a úplný stav evidence;
 *  • {@see self::Unknown} — záznam manžela vznikl dřív, než evidence
 *    existovala. Fail-closed: čtvrtina se nezapočítá a měsíc se srážkou
 *    spadne do ručního posouzení, aby účetní musela stav aktivně doplnit.
 *    Tiše změnit výpočet u historických záznamů nesmíme.
 *
 * Nezaměňovat s {@see PensionEvidence} — ta drží, zda je povinnému vyplácen
 * důchod pro výjimku z pravidla čtyř exekucí (§ 279 odst. 5 o. s. ř.), řeší
 * jiný právní institut a jiný druh důchodu.
 */
enum SpousePensionEvidence: string
{
    case Unknown = 'unknown';
    case NotDocumented = 'not_documented';
    case Documented = 'documented';
}
