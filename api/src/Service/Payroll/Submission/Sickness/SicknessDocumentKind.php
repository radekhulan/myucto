<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;

/**
 * Který ze dvou tiskopisů se z případu staví.
 *
 * Jde o DVĚ různá podání s různým právním základem, ne o dvě fáze jednoho:
 *
 * * **NEMPRI** — oznámení zaměstnavatele o žádosti zaměstnance o dávku
 *   a podklady pro výpočet podle § 97 odst. 1 a 2 zák. č. 187/2006 Sb.
 *   Podává se na začátku; u nemocenského „neprodleně po uplynutí prvních
 *   14 dnů trvání dočasné pracovní neschopnosti nebo trvání nařízené
 *   karantény" (§ 97 odst. 2 věta druhá).
 * * **HZUPN** — hlášení zaměstnavatele při ukončení pracovní neschopnosti,
 *   tedy oznámení skutečností, které mohou mít vliv na výplatu dávek podle
 *   § 97 odst. 3 („neprodleně"). Nese údaje pro výplatu POSLEDNÍ dávky:
 *   návrat do práce, odpracované hodiny v poslední den neschopnosti a dny,
 *   ve kterých se během neschopnosti pracovalo.
 *
 * Každý má vlastní XSD, vlastní kořen a vlastní verzi payloadu; otisky drží
 * {@see CsszSchemaCatalog}.
 */
enum SicknessDocumentKind: string
{
    case Nempri = 'nempri';
    case Hzupn = 'hzupn';

    /** Klíč do {@see CsszSchemaCatalog}. */
    public function documentType(): string
    {
        return match ($this) {
            self::Nempri => CsszSchemaCatalog::NEMPRI25,
            self::Hzupn => CsszSchemaCatalog::HZUPN20,
        };
    }

    /** `agenda_code` v evidenci povinností a v odchozí frontě. */
    public function agendaCode(): string
    {
        return match ($this) {
            self::Nempri => 'NEMPRI',
            self::Hzupn => 'HZUPN',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Nempri => 'Oznámení zaměstnavatele o žádosti zaměstnance o dávku',
            self::Hzupn => 'Hlášení zaměstnavatele při ukončení pracovní neschopnosti',
        };
    }
}
