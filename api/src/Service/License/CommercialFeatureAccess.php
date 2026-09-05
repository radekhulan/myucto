<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

/**
 * Hranice mezi bezplatným základem a komerčními moduly MyÚčta.
 *
 * Zdarma zůstává to, co uživatel dostal už v MyInvoice (MIT): fakturace,
 * klienti, dokumenty, banka a pokladna, přiznání k DPH i kontrolní hlášení
 * a celé nastavení firmy. Za licencí jsou čtyři nadstavbové moduly —
 * ÚČETNICTVÍ, MZDY, SKLAD (a e-shop) a OSS — plus věci, které se o ně opírají
 * (zaúčtování dokladů, automatizace, přehled firem, korekce odpočtu).
 *
 * Prvních 60 dní běží instalace v trialu, kde je dostupné všechno; po jeho
 * vypršení (a stejně tak u degradované licence) se tyhle cesty uzavřou.
 */
final class CommercialFeatureAccess
{
    /** @var list<string> */
    private const RESTRICTED_API_PATTERNS = [
        '#^/api/(stock|eshop)(/|$)#',
        // Mzdy jsou komerční modul celé, včetně capabilities a nastavení —
        // frontend si podle 403 schová sekci stejně jako u skladu.
        '#^/api/payroll(/|$)#',
        // OSS (One Stop Shop) — evidence, přiznání i hromadné zařazení faktur.
        // Číselník sazeb členských států zůstává volný: je to jen data.
        '#^/api/reports/oss(/|$)#',
        '#^/api/invoices/bulk-oss(/|$)#',
        // Daňová evidence je druhá tvář téhož modulu „Vést účetnictví" —
        // peněžní deník a přehled pohledávek/závazků stojí a padají s ním.
        '#^/api/tax-evidence(/|$)#',
        // Přiznání k dani z příjmů (DPPO/DPFO) včetně příloh, přehledů pro
        // pojišťovny a záloh. Bez účetnictví nedává smysl: základ daně se
        // počítá z výsledku hospodaření nebo z peněžního deníku, a obojí je
        // za licencí. Vydávat přiznání nad daty, která nemá čím naplnit,
        // by znamenalo tvářit se, že to jde.
        '#^/api/tax-return(/|$)#',
        // Daňový optimalizátor a daňový profil — srovnání režimů a predikce
        // limitů. `-` za `tax` sem nespadá, takže /api/tax-evidence
        // a /api/tax-return si tenhle vzorec nepřebírají.
        '#^/api/tax(/|$)#',
        '#^/api/invoices/[0-9]+/stock-documents(/|$)#',
        '#^/api/invoices/[0-9]+/book$#',
        '#^/api/purchase-invoices/[0-9]+/stock-receipts?(/|$)#',
        '#^/api/purchase-invoices/[0-9]+/ai-suggest$#',
        '#^/api/bank-transactions/[0-9]+/(post|unpost|ai-suggest)$#',
        // ⚠️ Pokladní doklad se VEDE zdarma, ale ZAÚČTOVAT ho je účetnictví.
        //
        // Výjimka pro `cash-*` níž je celoprefixová, takže z ní vypadávalo
        // i zaúčtování a jeho storno — a ty zakládají zápis v deníku. Zákazník
        // po vypršení licence tedy zapisoval do knihy, kterou si nesmí přečíst
        // (GET /api/accounting/journal vrací 403). Bankovní protějšek o řádek
        // výš i zaúčtování faktury zamčené odjakživa byly.
        //
        // Samostatný vzorec, ne zúžení té výjimky: vyhodnocuje se nezávisle
        // a vyhrává, takže se do negativního lookaheadu nemusí přidávat další
        // patro závorek, ve kterém se za rok nikdo nevyzná.
        '#^/api/accounting/cash-documents/[0-9]+/(post|reverse)$#',
        '#^/api/bank-ai-suggestion-availability$#',
        '#^/api/accounting(?:$|/(?!cash-(?:documents|registers)(?:/|$)|bank-accounts(?:/|$)))#',
        '#^/api/(automation|portfolio)(/|$)#',
        '#^/api/ai/suggestions(/|$)#',
        '#^/api/purchase-ai-suggestion-availability$#',
        '#^/api/settings/accounting-activation(/|$)#',
        '#^/api/reports/(s74b|s43|s46|s79|related-parties|closing-package)(/|$)#',
        // ⚠️ Archiv podání NENÍ zamčený celý — viz {@see TaxSubmissionAccess}.
        // Bezplatná část výslovně zahrnuje DPH i kontrolní hlášení a zákazník
        // se k jejich XML musí dostat; dělicí čára je až u ODESLÁNÍ. Přímé
        // podání do EPO včetně certifikátů a potvrzenek je služba, kterou
        // provozujeme my, a rozhodnutí podle KONKRÉTNÍHO výkazu dělá akce,
        // protože cesta sama o typu výkazu nic neví.
        '#^/api/reports/submissions/settings(/|$)#',
        '#^/api/reports/submissions/epo-credentials(/|$)#',
        '#^/api/reports/submissions/[0-9]+/epo-#',
    ];

    public function __construct(private readonly LicenseService $license) {}

    public function isAvailable(): bool
    {
        try {
            return $this->license->current()->hasCommercialFeatures();
        } catch (\Throwable) {
            return true;
        }
    }

    public static function restrictsApiPath(string $path): bool
    {
        foreach (self::RESTRICTED_API_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function restrictsPayrollPath(string $path): bool
    {
        return preg_match('#^/api/(?:payroll|accounting/payroll)(?:/|$)#', $path) === 1;
    }
}
