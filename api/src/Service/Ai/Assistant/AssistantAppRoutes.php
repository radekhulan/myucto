<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai\Assistant;

/**
 * Stránky aplikace, na které smí AI asistent v odpovědi odkazovat (cesta → popis).
 * Cesty musí existovat v routeru webu (`web/src/router`); hlídá to
 * `AssistantAppRoutesTest`. Odkaz s cestou začínající `/` otevře stránka asistenta
 * přímo v aplikaci.
 */
final class AssistantAppRoutes
{
    public const ROUTES = [
        '/invoices' => 'Faktury (vydané doklady)',
        '/invoices/new' => 'Nová faktura',
        '/recurring' => 'Pravidelné fakturace',
        '/clients' => 'Klienti',
        '/projects' => 'Zakázky',
        '/crm' => 'CRM a pohledávky po splatnosti',
        '/purchase-invoices' => 'Přijaté faktury',
        '/purchase-invoices/payment-orders' => 'Platební příkazy',
        '/purchase-approvals' => 'Schvalování přijatých dokladů',
        '/other-items' => 'Ostatní pohledávky a závazky',
        '/bank' => 'Banka (výpisy a párování plateb)',
        '/accounting/cash' => 'Pokladna',
        '/documents' => 'Dokumenty',
        '/accounting/journal' => 'Účetní deník',
        '/accounting/journal/new' => 'Nový ruční zápis do deníku',
        '/accounting/internal-documents' => 'Interní doklady',
        '/accounting/general-ledger' => 'Hlavní kniha',
        '/accounting/trial-balance' => 'Obratová předvaha',
        '/accounting/balance-sheet' => 'Rozvaha',
        '/accounting/income-statement' => 'Výsledovka (druhová)',
        '/accounting/saldo' => 'Saldokonto',
        '/accounting/accounts' => 'Účtový rozvrh',
        '/accounting/posting-rules' => 'Předkontace',
        '/accounting/periods' => 'Účetní období a uzávěrka',
        '/accounting/monthly-check' => 'Měsíční kontrola',
        '/accounting/document-completeness' => 'Úplnost dokladů',
        '/accounting/manual-posting-queue' => 'Ruční fronta doúčtování',
        '/accounting/assets' => 'Dlouhodobý majetek',
        '/accounting/offsets' => 'Zápočty',
        '/tax-evidence/cash-journal' => 'Peněžní deník (daňová evidence)',
        '/tax-evidence/receivables-payables' => 'Pohledávky a závazky (daňová evidence)',
        '/reports/dph' => 'Přiznání k DPH',
        '/reports/kh' => 'Kontrolní hlášení',
        '/reports/shv' => 'Souhrnné hlášení',
        '/reports/dph-book' => 'Kniha DPH',
        '/reports/vat-by-dimension' => 'DPH podle dimenze',
        '/reports/income-tax' => 'Daň z příjmů',
        '/reports/submissions' => 'Archiv podání',
        '/tax' => 'Daňový kalendář a přehledy',
        '/stats' => 'Statistiky tržeb',
        '/payroll' => 'Mzdy',
        '/stock/items' => 'Sklad (zboží a zásoby)',
        '/logbook' => 'Kniha jízd',
        '/admin/integrations' => 'Nastavení integrací (včetně AI)',
    ];

    /** Blok pro systémový prompt. */
    public static function promptBlock(): string
    {
        $lines = [];
        foreach (self::ROUTES as $path => $label) {
            $lines[] = '- ' . $label . ': `' . $path . '`';
        }
        return implode("\n", $lines);
    }
}
