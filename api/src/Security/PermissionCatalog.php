<?php

declare(strict_types=1);

namespace MyInvoice\Security;

final class PermissionCatalog
{
    public const VERSION = '2026-08-domains-document-submissions-health-evidence-v1';

    /** @var list<string> */
    private const GROUPS = [
        'dashboard', 'clients', 'projects', 'invoices', 'purchase_invoices', 'recurring',
        'bank', 'documents', 'accounting', 'tax_evidence', 'reports', 'payroll', 'cash', 'assets',
        'stock', 'eshop', 'logbook', 'settings', 'utilities', 'profile',
    ];

    /**
     * @return array<string, array{key:string,group:string,label:string,description:string,role_types:list<string>,kind:string}>
     */
    public function all(): array
    {
        static $definitions;
        if ($definitions !== null) return $definitions;

        $staffOnly = ['staff'];
        $both = ['staff', 'client'];
        $clientOnly = ['client'];
        $rows = [
            ['dashboard', 'dashboard', 'Dashboard', $staffOnly],
            ['dashboard.portfolio', 'dashboard', 'Přehled firem', $staffOnly],
            ['clients', 'clients', 'Klienti a dodavatelé', $both],
            ['clients.create', 'clients', 'Vytvořit klienta', $both],
            ['clients.archive', 'clients', 'Archivovat klienta', $both],
            ['clients.public_links', 'clients', 'Veřejné odkazy', $staffOnly],
            ['projects', 'projects', 'Zakázky', $staffOnly],
            ['projects.create', 'projects', 'Vytvořit zakázku', $staffOnly],
            ['projects.archive', 'projects', 'Archivovat zakázku', $staffOnly],
            ['invoices', 'invoices', 'Vydané faktury', $both],
            ['invoices.create', 'invoices', 'Vytvořit fakturu', $both],
            ['invoices.issue', 'invoices', 'Vydat fakturu', $both],
            ['invoices.send', 'invoices', 'Odeslat fakturu', $both],
            ['invoices.reminder', 'invoices', 'Odeslat upomínku', $both],
            ['invoices.mark_paid', 'invoices', 'Změnit úhradu', $both],
            ['invoices.cancel', 'invoices', 'Stornovat fakturu', $both],
            ['invoices.clone', 'invoices', 'Klonovat fakturu', $both],
            ['invoices.delete', 'invoices', 'Smazat fakturu', $both],
            ['invoices.approval', 'invoices', 'Schvalování faktur', $both],
            ['purchase_invoices', 'purchase_invoices', 'Přijaté faktury', $both],
            ['purchase_invoices.create', 'purchase_invoices', 'Vytvořit přijatou fakturu', $both],
            ['purchase_invoices.transition', 'purchase_invoices', 'Změnit stav přijaté faktury', $both],
            ['purchase_invoices.scan', 'purchase_invoices', 'Skenovat schránku', $staffOnly],
            ['purchase_invoices.payment_orders', 'purchase_invoices', 'Platební příkazy', $staffOnly],
            ['purchase_invoices.delete', 'purchase_invoices', 'Smazat přijatou fakturu', $both],
            ['recurring', 'recurring', 'Pravidelná fakturace', $both],
            ['recurring.create', 'recurring', 'Vytvořit šablonu', $both],
            ['recurring.run', 'recurring', 'Spustit fakturaci', $both],
            ['recurring.pause', 'recurring', 'Pozastavit fakturaci', $both],
            ['recurring.delete', 'recurring', 'Smazat šablonu', $both],
            ['bank', 'bank', 'Banka', $staffOnly],
            ['bank.import', 'bank', 'Importovat výpis', $staffOnly],
            ['bank.match', 'bank', 'Párovat platby', $staffOnly],
            ['bank.post', 'bank', 'Zaúčtovat banku', $staffOnly],
            ['bank.unpost', 'bank', 'Odúčtovat banku', $staffOnly],
            ['bank.rules', 'bank', 'Pravidla účtování', $staffOnly],
            ['documents', 'documents', 'Dokumenty', $staffOnly],
            ['documents.upload', 'documents', 'Nahrát dokument', $staffOnly],
            ['documents.move', 'documents', 'Přesunout dokument', $staffOnly],
            ['documents.delete', 'documents', 'Smazat dokument', $staffOnly],
            ['documents.restore', 'documents', 'Obnovit dokument', $staffOnly],
            ['documents.requests', 'documents', 'Požadavky klientovi', $staffOnly],
            ['documents.inbox', 'documents', 'Příchozí doklady', $staffOnly],
            ['documents.inbox.delete', 'documents', 'Trvale vyřadit z příchozí fronty', $staffOnly],
            ['documents.submit', 'documents', 'Předávat doklady účetní', $clientOnly],
            ['accounting', 'accounting', 'Účetnictví', $staffOnly],
            ['accounting.journal.write', 'accounting', 'Zápisy v deníku', $staffOnly],
            ['accounting.journal.post', 'accounting', 'Zaúčtovat doklad', $staffOnly],
            ['accounting.periods.manage', 'accounting', 'Spravovat období', $staffOnly],
            ['accounting.periods.close', 'accounting', 'Uzavřít období', $staffOnly],
            ['accounting.periods.close_override', 'accounting', 'Uzavřít období přes nezaúčtované doklady', $staffOnly],
            ['accounting.offsets', 'accounting', 'Vzájemné zápočty', $staffOnly],
            ['accounting.templates', 'accounting', 'Účetní šablony', $staffOnly],
            ['tax_evidence', 'tax_evidence', 'Daňová evidence', $staffOnly],
            ['tax_evidence.classification.write', 'tax_evidence', 'Klasifikovat pohyby', $staffOnly],
            ['tax_evidence.export', 'tax_evidence', 'Exportovat daňovou evidenci', $staffOnly],
            ['reports', 'reports', 'Daně a výkazy', $staffOnly],
            ['reports.finalize', 'reports', 'Finalizovat výkaz', $staffOnly],
            ['reports.submit', 'reports', 'Elektronicky podat', $staffOnly],
            ['reports.reopen', 'reports', 'Znovu otevřít výkaz', $staffOnly],
            ['reports.export', 'reports', 'Exportovat výkaz', $staffOnly],
            ['payroll', 'payroll', 'Mzdy', $staffOnly],
            ['payroll.settings', 'payroll', 'Nastavení mezd', $staffOnly],
            ['payroll.person.read_sensitive', 'payroll', 'Odhalit citlivé osobní údaje', $staffOnly],
            ['payroll.person.write', 'payroll', 'Spravovat zaměstnance', $staffOnly],
            ['payroll.employment.write', 'payroll', 'Spravovat pracovní vztahy', $staffOnly],
            ['payroll.time.write', 'payroll', 'Spravovat docházku a absence', $staffOnly],
            ['payroll.inputs.write', 'payroll', 'Spravovat mzdové vstupy', $staffOnly],
            ['payroll.calculate', 'payroll', 'Vypočítat mzdy', $staffOnly],
            ['payroll.review', 'payroll', 'Zkontrolovat mzdový běh', $staffOnly],
            ['payroll.approve', 'payroll', 'Schválit mzdový běh', $staffOnly],
            ['payroll.reopen', 'payroll', 'Odemknout schválený mzdový běh', $staffOnly],
            ['payroll.post', 'payroll', 'Zaúčtovat mzdy', $staffOnly],
            ['payroll.payments', 'payroll', 'Spravovat výplaty a odvody', $staffOnly],
            ['payroll.submissions', 'payroll', 'Spravovat mzdová podání', $staffOnly],
            ['payroll.health_evidence', 'payroll', 'Spravovat důkazy zdravotního pojištění', $staffOnly],
            ['payroll.enforcement', 'payroll', 'Spravovat exekuční srážky', $staffOnly],
            ['payroll.enforcement.cooperation', 'payroll', 'Vyřizovat součinnost exekutorům', $staffOnly],
            ['payroll.insolvency', 'payroll', 'Spravovat insolvence zaměstnanců', $staffOnly],
            ['payroll.reports', 'payroll', 'Mzdové sestavy a exporty', $staffOnly],
            ['payroll.rulesets', 'payroll', 'Spravovat legislativní pravidla mezd', $staffOnly],
            ['payroll.documents', 'payroll', 'Mzdové dokumenty', $staffOnly],
            ['payroll.retention', 'payroll', 'Retenční lhůty a zadržení výmazu', $staffOnly],
            ['payroll.erasure', 'payroll', 'Schválit a provést výmaz osobních údajů', $staffOnly],
            ['cash', 'cash', 'Pokladna', $staffOnly],
            ['cash.document.write', 'cash', 'Pokladní doklady', $staffOnly],
            ['cash.close', 'cash', 'Uzavřít pokladnu a trvale mazat doklady', $staffOnly],
            ['assets', 'assets', 'Majetek', $staffOnly],
            ['assets.write', 'assets', 'Spravovat majetek', $staffOnly],
            ['assets.depreciation', 'assets', 'Odpisy', $staffOnly],
            ['assets.dispose', 'assets', 'Vyřadit majetek', $staffOnly],
            ['stock', 'stock', 'Sklad', $staffOnly],
            ['stock.items.write', 'stock', 'Skladové karty', $staffOnly],
            ['stock.documents.write', 'stock', 'Skladové doklady', $staffOnly],
            ['stock.orders.write', 'stock', 'Objednávky dodavatelům', $staffOnly],
            ['stock.vendors.write', 'stock', 'Nabídky dodavatelů', $staffOnly],
            ['stock.take', 'stock', 'Inventura', $staffOnly],
            ['stock.close', 'stock', 'Skladová uzávěrka', $staffOnly],
            ['eshop', 'eshop', 'E-shop číselníky', $staffOnly],
            ['eshop.write', 'eshop', 'Spravovat e-shop číselníky', $staffOnly],
            ['logbook', 'logbook', 'Kniha jízd', $staffOnly],
            ['logbook.write', 'logbook', 'Spravovat knihu jízd', $staffOnly],
            ['logbook.import', 'logbook', 'Importovat jízdy', $staffOnly],
            ['logbook.delete', 'logbook', 'Mazat jízdy', $staffOnly],
            ['settings.company', 'settings', 'Nastavení firmy', $both],
            ['settings.company.write', 'settings', 'Měnit nastavení firmy', $staffOnly],
            ['settings.domains', 'settings', 'Spravovat klientské domény', $staffOnly],
            ['settings.bank_accounts', 'settings', 'Bankovní účty firmy', $staffOnly],
            ['settings.branding', 'settings', 'Branding firmy', $staffOnly],
            ['settings.ai_provider', 'settings', 'AI poskytovatel', $staffOnly],
            ['settings.signing', 'utilities', 'Elektronické podepisování', $staffOnly],
            ['utilities', 'utilities', 'Nástroje', $staffOnly],
            ['utilities.export', 'utilities', 'Export dat', $staffOnly],
            ['utilities.import', 'utilities', 'Import dat', $staffOnly],
            ['utilities.archives', 'utilities', 'ZIP archivy', $staffOnly],
            ['profile', 'profile', 'Osobní profil', $both],
            ['profile.tokens', 'profile', 'API tokeny', $staffOnly],
        ];

        $definitions = [];
        foreach ($rows as [$key, $group, $label, $types]) {
            $definitions[$key] = [
                'key' => $key,
                'group' => $group,
                'label' => $label,
                'description' => str_contains($key, '.')
                    ? 'Povoluje akci „' . $label . '“.'
                    : 'Zpřístupňuje modul „' . $label . '“.',
                'role_types' => $types,
                'kind' => str_contains($key, '.') ? 'action' : 'module',
            ];
        }
        return $definitions;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function allowsRoleType(string $key, string $roleType): bool
    {
        $definition = $this->all()[$key] ?? null;
        return $definition !== null && in_array($roleType, $definition['role_types'], true);
    }

    /**
     * Kompatibilní matice původních rolí pro přímé volání Actions v testech a
     * dalších middleware-less adaptérech. Produkční request používá vždy roli z DB.
     *
     * @return array<string, int>
     */
    public function legacyPreset(string $systemKey): array
    {
        if ($systemKey === 'accountant') {
            $none = [
                // Neměnný originál je auditní stopa; trvalé vyřazení je zásah pro správce,
                // který si ho může kterékoli roli přidělit v editoru rolí.
                'documents.inbox.delete',
                'accounting.periods.close', 'accounting.periods.manage',
                'settings.ai_provider', 'settings.bank_accounts', 'settings.branding',
                'settings.company.write', 'settings.domains', 'utilities.import',
                'payroll.settings', 'payroll.person.read_sensitive', 'payroll.approve',
                'payroll.reopen', 'payroll.health_evidence', 'payroll.enforcement', 'payroll.enforcement.cooperation', 'payroll.insolvency', 'payroll.rulesets',
                // Výmaz osobních údajů je nevratný a právně významný — patří ke
                // schválení běhu, ne k běžné mzdové práci. Retenční lhůty naopak
                // ve výchozím stavu zůstávají: prodloužit lhůtu nebo zadržet výmaz
                // je konzervativní směr, kterým se nic neztratí.
                'payroll.erasure',
            ];
            $read = [
                'dashboard', 'dashboard.portfolio', 'tax_evidence', 'tax_evidence.export',
                'reports.export', 'settings.company', 'utilities',
                'utilities.export', 'utilities.archives', 'profile.tokens',
            ];
            $permissions = [];
            foreach ($this->all() as $key => $definition) {
                if (!in_array('staff', $definition['role_types'], true) || in_array($key, $none, true)) continue;
                $permissions[$key] = in_array($key, $read, true) ? AccessLevel::READ->value : AccessLevel::WRITE->value;
            }
            return $permissions;
        }

        $keys = match ($systemKey) {
            'readonly' => [
                'dashboard', 'dashboard.portfolio', 'clients', 'projects', 'invoices',
                'purchase_invoices', 'recurring', 'bank', 'documents', 'documents.requests', 'documents.inbox',
                'accounting', 'tax_evidence', 'tax_evidence.export', 'reports', 'reports.export',
                'cash', 'assets', 'stock', 'eshop', 'logbook', 'settings.company', 'utilities',
                'utilities.export', 'utilities.archives', 'profile', 'profile.tokens',
            ],
            'client' => [
                'clients', 'clients.create', 'clients.archive', 'invoices', 'invoices.create',
                'invoices.issue', 'invoices.send', 'invoices.reminder', 'invoices.mark_paid',
                'invoices.cancel', 'invoices.clone', 'invoices.delete', 'invoices.approval',
                'purchase_invoices', 'purchase_invoices.create', 'purchase_invoices.transition',
                'purchase_invoices.delete', 'documents.submit', 'recurring', 'recurring.create', 'recurring.run',
                'recurring.pause', 'recurring.delete', 'settings.company', 'profile',
            ],
            default => [],
        };

        $permissions = [];
        foreach ($keys as $key) {
            $permissions[$key] = in_array($key, ['profile'], true)
                || $systemKey === 'client' && $key !== 'settings.company'
                ? AccessLevel::WRITE->value
                : AccessLevel::READ->value;
        }
        return $permissions;
    }

    /** @return list<string> */
    public function groups(): array
    {
        return self::GROUPS;
    }
}
