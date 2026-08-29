import type { RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

export function createWorkspaceRoutes(): RouteRecordRaw[] {
  return [
      { path: '',                       name: 'home',           component: () => import('@/pages/Dashboard.vue') },
      // Klientský portál (Epic F6) — domov role client, náhled pro všechny role.
      { path: 'portal',                 name: 'portal',         component: () => import('@/pages/portal/PortalDashboard.vue'), meta: {  } },
      // Vyžádání chybějících dokladů (Fáze F, audit 2026-07) — klientský portál.
      { path: 'portal/document-requests', name: 'portal-document-requests', component: () => import('@/pages/portal/DocumentRequests.vue'), meta: {  } },
      { path: 'portal/purchase-invoice-submissions', name: 'portal-purchase-invoice-submissions', component: () => import('@/pages/portal/PurchaseInvoiceSubmissions.vue'), meta: { requiresSupplier: true } },
      { path: 'portal/settings', name: 'portal-company-settings', component: () => import('@/pages/portal/ClientCompanySettings.vue'), meta: { requiresSupplier: true } },
      { path: 'clients',                name: 'clients',        component: () => import('@/pages/clients/ClientList.vue'), meta: {  } },
      { path: 'clients/new',            name: 'client-new',     component: () => import('@/pages/clients/ClientForm.vue'), meta: { requiresSupplier: true } },
      { path: 'clients/:id(\\d+)',      name: 'client-detail',  component: () => import('@/pages/clients/ClientDetail.vue'), meta: {  } },
      { path: 'clients/:id(\\d+)/edit', name: 'client-edit',    component: () => import('@/pages/clients/ClientForm.vue'), meta: { requiresSupplier: true } },
      { path: 'projects',               name: 'projects',       component: () => import('@/pages/projects/ProjectList.vue') },
      { path: 'projects/new',           name: 'project-new',    component: () => import('@/pages/projects/ProjectForm.vue'), meta: { requiresSupplier: true } },
      { path: 'projects/:id(\\d+)',     name: 'project-detail', component: () => import('@/pages/projects/ProjectDetail.vue') },
      { path: 'projects/:id(\\d+)/edit', name: 'project-edit',  component: () => import('@/pages/projects/ProjectForm.vue'), meta: { requiresSupplier: true } },
      { path: 'invoices',               name: 'invoices',       component: () => import('@/pages/invoices/InvoiceList.vue'), meta: {  } },
      // AI import vydané faktury — prodejní zrcadlo /purchase-invoices/ai-import.
      // Extrakce (ISDOC priorita, AI fallback) → draft vydané faktury k revizi v editoru.
      // Oprávnění invoices.create (write) zrcadlí BE check v AiExtractPdfIssuedAction.
      { path: 'invoices/ai-import',      name: 'invoice-ai-import', component: () => import('@/pages/invoices/SalesAiImport.vue'), meta: { requiresSupplier: true } },
      { path: 'invoices/new',           name: 'invoice-new',    component: () => import('@/pages/invoices/InvoiceEditor.vue'), meta: { requiresSupplier: true } },
      { path: 'invoices/:id(\\d+)',     name: 'invoice-detail', component: () => import('@/pages/invoices/InvoiceDetail.vue'), meta: {  } },
      { path: 'invoices/:id(\\d+)/edit', name: 'invoice-edit',  component: () => import('@/pages/invoices/InvoiceEditor.vue'), meta: { requiresSupplier: true } },
      // Export/Import vydaných (reorg UX 2026-07) — nav pod Prodej; zrcadlí
      // purchase-invoices/export|import níže, sdílená stránka DataExchange.vue.
      {
        path: 'invoices/export', name: 'invoices-export',
        component: () => import('@/pages/admin/DataExchange.vue'), props: { scope: 'issued', mode: 'export' },
      },
      {
        path: 'invoices/import', name: 'invoices-import',
        component: () => import('@/pages/admin/DataExchange.vue'), props: { scope: 'issued', mode: 'import' },
        beforeEnter: () => (useAuthStore().isSuperadmin ? true : { path: '/invoices/export' }),
      },
      // Přijaté faktury (fáze 1 integrace forku)
      { path: 'purchase-invoices',                 name: 'purchase-invoices',        component: () => import('@/pages/purchase-invoices/InvoiceList.vue'), meta: {  } },
      { path: 'purchase-invoices/incoming',        name: 'purchase-invoice-submissions', component: () => import('@/pages/purchase-invoices/IncomingDocuments.vue'), meta: { requiresSupplier: true } },
      // Export/Import přijatých (reorg UX 2026-07) — nav pod Nákup; sdílená stránka
      // DataExchange.vue jen vybere ExportPurchase/ImportPurchase dle props.
      {
        path: 'purchase-invoices/export', name: 'purchase-invoices-export',
        component: () => import('@/pages/admin/DataExchange.vue'), props: { scope: 'purchase', mode: 'export' },
      },
      {
        path: 'purchase-invoices/import', name: 'purchase-invoices-import',
        component: () => import('@/pages/admin/DataExchange.vue'), props: { scope: 'purchase', mode: 'import' },
        // Import je jen pro superadmina (BE endpoint je adminOnly) — bez gate by nezapnutý
        // nav item stejně nešel rozkliknout, ale přímý odkaz by ukázal upload UI zbytečně;
        // zrcadlí dřívější tiché downgrade chování normalizeTab() v DataExchange.vue.
        beforeEnter: () => (useAuthStore().isSuperadmin ? true : { path: '/purchase-invoices/export' }),
      },
      { path: 'purchase-invoices/payment-orders',  name: 'purchase-invoices-payment-orders', component: () => import('@/pages/purchase-invoices/PaymentOrders.vue') },
      // AI import přijaté faktury (§12b) — extrakční flow vytažený z admin Integrations
      // (?tab=ai zůstává jen nastavení brány). Oprávnění purchase_invoices.scan zrcadlí
      // BE check v AiExtractPdfAction (účetní denní operativa, ne admin setup).
      { path: 'purchase-invoices/ai-import',       name: 'purchase-invoice-ai-import', component: () => import('@/pages/purchase-invoices/AiImport.vue'), meta: { requiresSupplier: true } },
      { path: 'purchase-invoices/new',             name: 'purchase-invoice-new',     component: () => import('@/pages/purchase-invoices/InvoiceEditor.vue'), meta: { requiresSupplier: true } },
      { path: 'purchase-invoices/:id(\\d+)',       name: 'purchase-invoice-detail',  component: () => import('@/pages/purchase-invoices/InvoiceDetail.vue'), meta: {  } },
      { path: 'purchase-invoices/:id(\\d+)/edit',  name: 'purchase-invoice-edit',    component: () => import('@/pages/purchase-invoices/InvoiceEditor.vue'), meta: { requiresSupplier: true } },
      // Dokumenty (sekce Dokumenty — plán source/11)
      { path: 'documents',              name: 'documents',        component: () => import('@/pages/documents/DocumentsBrowser.vue') },
      { path: 'documents/:id(\\d+)',    name: 'document-detail',  component: () => import('@/pages/documents/DocumentDetail.vue') },
      // Vyžádání chybějících dokladů (Fáze F, audit 2026-07) — účetní pohled.
      { path: 'document-requests',      name: 'document-requests', component: () => import('@/pages/documents/DocumentRequests.vue') },
      // Úplné mzdy — samostatný bounded context dostupný v obou účetních režimech.
      { path: 'payroll', name: 'payroll-dashboard', component: () => import('@/pages/payroll/PayrollDashboard.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      { path: 'payroll/runs', name: 'payroll-runs', component: () => import('@/pages/payroll/PayrollRuns.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      { path: 'payroll/payments', name: 'payroll-payments', component: () => import('@/pages/payroll/PayrollPayments.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      // MZ-18-W07 — reconciliation účetního můstku mezd (mzda ↔ deník ↔ platby).
      { path: 'payroll/posting-reconciliation', name: 'payroll-posting-reconciliation', component: () => import('@/pages/payroll/PayrollPostingReconciliation.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      { path: 'payroll/people', name: 'payroll-people', component: () => import('@/pages/payroll/PeopleList.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      // Karta člověka má vlastní adresu, aby na ni šlo odkázat z termínů, e-mailu
      // i z jiné agendy. Zůstává to ale JEDNA komponenta (viz komentář v
      // PeopleList.vue u `selectedDetail`) — rozpojení do samostatné stránky by
      // znamenalo přepsat panely, které si předávají stav. Adresa se proto
      // překlopí na tvar, který stránka umí, místo aby vznikl druhý pohled.
      {
        path: 'payroll/people/:id(\\d+)',
        name: 'payroll-person',
        redirect: to => ({ name: 'payroll-people', query: { person: String(to.params.id) } }),
      },
      { path: 'payroll/quick-inputs', name: 'payroll-quick-inputs', component: () => import('@/pages/payroll/PayrollQuickInputs.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      { path: 'payroll/components', name: 'payroll-components', component: () => import('@/pages/payroll/PayrollComponents.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      // Přehled čerpání ročních košů osvobození (§ 6 odst. 9 ZDP) za firmu. Jede
      // na `payroll` READ stejně jako seznam mzdových vstupů — je to jejich
      // součet za osobu a rok, ne nová třída údajů.
      { path: 'payroll/benefit-baskets', name: 'payroll-benefit-baskets', component: () => import('@/pages/payroll/PayrollBenefitBaskets.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      { path: 'payroll/time', name: 'payroll-time', component: () => import('@/pages/payroll/TimeAttendance.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      { path: 'payroll/absences', name: 'payroll-absences', component: () => import('@/pages/payroll/AbsenceManagement.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      { path: 'payroll/travel', name: 'payroll-travel', component: () => import('@/pages/payroll/PayrollTravel.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      { path: 'payroll/deduction-agreements', name: 'payroll-deduction-agreements', component: () => import('@/pages/payroll/DeductionAgreements.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      { path: 'payroll/enforcement', name: 'payroll-enforcement', component: () => import('@/pages/payroll/EnforcementCases.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      { path: 'payroll/enforcement/cooperation', name: 'payroll-enforcement-cooperation', component: () => import('@/pages/payroll/PayrollEnforcementCooperation.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      { path: 'payroll/insolvency', name: 'payroll-insolvency', component: () => import('@/pages/payroll/PayrollInsolvency.vue'), meta: { requiresSupplier: true, requiresPayroll: true, additionalPermissions: ['payroll.enforcement'] } },
      { path: 'payroll/documents', name: 'payroll-documents', component: () => import('@/pages/payroll/PayrollDocuments.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      // Roční zúčtování (§ 38ch ZDP) je vlastní agenda, ne záložka dokumentů:
      // z devadesáti procent je to evidence podkladů a rozhodnutí, jestli
      // zúčtování vůbec provést lze. Doklad je až výsledek.
      { path: 'payroll/annual-settlement', name: 'payroll-annual-settlement', component: () => import('@/pages/payroll/PayrollAnnualSettlement.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      { path: 'payroll/submissions', name: 'payroll-submissions', component: () => import('@/pages/payroll/PayrollSubmissions.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      // Deset záložek podání jsou fakticky deset obrazovek. Bez adresy se na ně
      // nedalo odkázat ani je uložit do záložek a po refreshi spadl uživatel zpět
      // na Transport. Neznámý `:tab` stránka překlopí zpět na výchozí záložku,
      // takže zastaralý odkaz nekončí prázdnem.
      { path: 'payroll/submissions/:tab([a-z_]+)', name: 'payroll-submissions-tab', component: () => import('@/pages/payroll/PayrollSubmissions.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      { path: 'payroll/settings', name: 'payroll-settings', component: () => import('@/pages/payroll/EmployerSettings.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      // Retenční lhůty ukazují katalog z kódu a pouštějí dvojí zápis — odchylku
      // firmy a zadržení výmazu. Jedou na `payroll.retention` — stejný klíč hlídá
      // RoutePermissionMap u /api/payroll/retention, takže menu nesvítí tam,
      // kde API vrátí 403.
      { path: 'payroll/retention', name: 'payroll-retention', component: () => import('@/pages/payroll/PayrollRetention.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      // Výmaz je samostatné právo (`payroll.erasure`), ne součást retence:
      // číst lhůty smí i ten, kdo nesmí odklepnout nevratné smazání osoby.
      { path: 'payroll/erasure', name: 'payroll-erasure', component: () => import('@/pages/payroll/PayrollErasure.vue'), meta: { requiresSupplier: true, requiresPayroll: true } },
      // Legislativní rulesety jsou GLOBÁLNÍ číselník (národní sazby a lhůty), ne
      // nastavení firmy — proto bez `requiresPayroll`, stejně jako daňové konstanty.
      { path: 'payroll/rulesets', name: 'payroll-rulesets', component: () => import('@/pages/payroll/PayrollRulesets.vue'), meta: { requiresSupplier: true } },
      // Účetnictví (Epic F1 — podvojné účetnictví; jen supplier.accounting_mode === 'double_entry')
      { path: 'accounting/accounts',      name: 'accounting-accounts',      component: () => import('@/pages/accounting/ChartOfAccounts.vue'), meta: { requiresDoubleEntry: true } },
      // Karta účtu — rozcestník drill-through (osnova → účet → analytiky → opis/kniha/deník).
      { path: 'accounting/accounts/:accountId(\\d+)', name: 'accounting-account-detail', component: () => import('@/pages/accounting/AccountDetail.vue'), meta: { requiresDoubleEntry: true } },
      // Předkontace, Kurzový režim, Repo sazba, Archiv účetnictví a Hromadný export
      // jsou teď záložky sjednocené stránky /utilities (Nástroje) — redirecty
      // zachovávají bookmarks i route names. Export/Import se odsud vyčlenilo (reorg UX
      // 2026-07) na samostatné routy pod Prodej/Nákup, viz níže.
      // Účetní období (Uzávěrka) — vytažené z Nástrojů do vlastní top-level položky menu.
      { path: 'accounting/periods',       name: 'accounting-periods',       component: () => import('@/pages/accounting/Periods.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/journal',       name: 'accounting-journal',       component: () => import('@/pages/accounting/Journal.vue'),         meta: { requiresDoubleEntry: true } },
      { path: 'accounting/journal/new',   name: 'accounting-journal-new',   component: () => import('@/pages/accounting/ManualEntry.vue'),     meta: { requiresDoubleEntry: true, requiresSupplier: true } },
      { path: 'accounting/payroll',       name: 'accounting-payroll',       component: () => import('@/pages/accounting/PayrollRecap.vue'),    meta: { requiresDoubleEntry: true, requiresSupplier: true } },
      { path: 'accounting/posting-rules', name: 'accounting-posting-rules', redirect: '/utilities?section=posting-rules' },
      // Účetní sestavy (Epic F2) — read-only, bez requiresWrite
      { path: 'accounting/general-ledger',   name: 'accounting-general-ledger',   component: () => import('@/pages/accounting/GeneralLedger.vue'),   meta: { requiresDoubleEntry: true } },
      { path: 'accounting/trial-balance',    name: 'accounting-trial-balance',    component: () => import('@/pages/accounting/TrialBalance.vue'),    meta: { requiresDoubleEntry: true } },
      { path: 'accounting/account-statement/:accountId(\\d+)', name: 'accounting-account-statement', component: () => import('@/pages/accounting/AccountStatement.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/balance-sheet',    name: 'accounting-balance-sheet',    component: () => import('@/pages/accounting/BalanceSheet.vue'),    meta: { requiresDoubleEntry: true } },
      { path: 'accounting/income-statement', name: 'accounting-income-statement', component: () => import('@/pages/accounting/IncomeStatement.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/income-statement-by-function', name: 'accounting-income-statement-by-function', component: () => import('@/pages/accounting/IncomeStatementByFunction.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/saldo',            name: 'accounting-saldo',            component: () => import('@/pages/accounting/Saldokonto.vue'),      meta: { requiresDoubleEntry: true } },
      // Featura E (REAL_data_followup_UX.md) — kontrola úplnosti dokladů proti bance (§24/1) + doklady po splatnosti.
      { path: 'accounting/document-completeness', name: 'accounting-document-completeness', component: () => import('@/pages/accounting/DocumentCompleteness.vue'), meta: { requiresDoubleEntry: true } },
      // Inventarizace rozvahových účtů (§29–30 ZoÚ, T2) — soupis KZ účtů tříd 0–4 k rozvahovému dni.
      { path: 'accounting/balance-inventory', name: 'accounting-balance-inventory', component: () => import('@/pages/accounting/BalanceInventory.vue'), meta: { requiresDoubleEntry: true } },
      // § 18 odst. 2 ZoÚ — přehled o peněžních tocích a o změnách vlastního kapitálu.
      { path: 'accounting/section18-statements', name: 'accounting-section18-statements', component: () => import('@/pages/accounting/Section18Statements.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/monthly-check',    name: 'accounting-monthly-check',    component: () => import('@/pages/accounting/MonthlyCheck.vue'),    meta: { requiresDoubleEntry: true } },
      // Evidenční podklad DPPO (Epic F4, R19) — odkaz z kroku uzávěrky „Daň z příjmů".
      { path: 'accounting/reports/tax-base-adjustments', name: 'accounting-tax-base-adjustments', component: () => import('@/pages/accounting/TaxBaseAdjustments.vue'), meta: { requiresDoubleEntry: true } },
      // Featura H (REAL_data_followup_UX.md) — jednotná fronta ručního doúčtování napříč zdroji.
      { path: 'accounting/manual-posting-queue', name: 'manual-posting-queue', component: () => import('@/pages/accounting/ManualPostingQueue.vue'), meta: { requiresDoubleEntry: true } },
      // Měsíční přehled klientovi (Fáze F, audit 2026-07, P3 návrh)
      { path: 'accounting/monthly-report',   name: 'accounting-monthly-report',   component: () => import('@/pages/accounting/MonthlyReport.vue'),   meta: { requiresDoubleEntry: true } },
      // Vzájemné zápočty + kurzový režim (Fáze F)
      { path: 'accounting/offsets',          name: 'accounting-offsets',          component: () => import('@/pages/accounting/Offsets.vue'),         meta: { requiresDoubleEntry: true } },
      { path: 'accounting/fx-rate-settings', name: 'accounting-fx-rate-settings', redirect: '/utilities?section=fx-rates' },
      { path: 'accounting/repo-rates',       name: 'accounting-repo-rates',       redirect: '/utilities?section=repo-rates' },
      // Majetek a odpisy (Epic F3)
      { path: 'accounting/assets',                name: 'accounting-assets',       component: () => import('@/pages/accounting/Assets.vue'),      meta: { requiresDoubleEntry: true } },
      { path: 'accounting/assets/new',            name: 'accounting-asset-new',    component: () => import('@/pages/accounting/AssetEditor.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/assets/:id(\\d+)',      name: 'accounting-asset-detail', component: () => import('@/pages/accounting/AssetDetail.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/assets/:id(\\d+)/edit', name: 'accounting-asset-edit',   component: () => import('@/pages/accounting/AssetEditor.vue'), meta: { requiresDoubleEntry: true } },
      // Drobný majetek (§DM) — evidence dle §28/5 ZoÚ. Vlastní stránka vedle DHM: jiný
      // režim (jednorázový náklad na 501 bez odpisů) i jiné oprávnění (`accounting`,
      // protože API /api/accounting/small-assets spadá pod fallback, ne pod `assets`).
      { path: 'accounting/small-assets',          name: 'accounting-small-assets', component: () => import('@/pages/accounting/SmallAssets.vue'), meta: { requiresDoubleEntry: true } },
      // Pravidla zaúčtování nákladů se přesunula pod Šablony (záložka „Pravidla nákladů").
      // Původní cesta se zachovává jako redirect kvůli starým odkazům/záložkám.
      { path: 'accounting/expense-rules', redirect: { path: '/templates', query: { section: 'expense' } } },
      // Uzávěrka období
      { path: 'accounting/periods/:id(\\d+)/closing', name: 'accounting-period-closing', component: () => import('@/pages/accounting/PeriodClosing.vue'),     meta: { requiresDoubleEntry: true } },
      // Uzávěrkový balíček — ZIP se všemi sestavami uzávěrky daného účetního období.
      { path: 'accounting/periods/:id(\\d+)/closing-package', name: 'accounting-closing-package', component: () => import('@/pages/accounting/ClosingPackage.vue'), meta: { requiresDoubleEntry: true } },
      // Příloha k účetní závěrce (§ 18/1/c) — editor sekcí; ukládá se per fiskální rok,
      // takže funguje i nad uzavřeným obdobím.
      { path: 'accounting/periods/:id(\\d+)/statement-notes', name: 'accounting-statement-notes', component: () => import('@/pages/accounting/StatementNotes.vue'), meta: { requiresDoubleEntry: true } },
      // Retenční lhůty § 31/32 ZoÚ + zadržení skartace (audit UI mezer 2026-07).
      { path: 'accounting/retention', name: 'accounting-retention', component: () => import('@/pages/accounting/Retention.vue'), meta: { requiresDoubleEntry: true } },
      // Přechodový můstek § 7b ↔ § 24 ZDP (přílohy č. 2 a 3) — read-only sestava.
      // Bez mode-guardu: v menu je jen u firem na daňové evidenci (chystaný přechod),
      // ale po přechodu na podvojné musí zůstat dostupná přes URL — BE si směr ohlídá.
      { path: 'accounting/transition-report', name: 'accounting-transition-report', component: () => import('@/pages/accounting/TransitionReport.vue') },
      // Staré odkazy na samostatný účetní archiv vedou do jediného exportního místa.
      { path: 'accounting/archive',                   name: 'accounting-archive',        redirect: '/admin/instance-export' },
      // Pokladna (mini-epic POKLADNA #14) — dostupná v OBOU účetních režimech: podvojné
      // účetnictví i daňová evidence (Epic DE §6, no-journal cash path). requiresCashMode
      // povolí double_entry i tax_evidence; ostatní /accounting/* zůstávají double_entry-only.
      { path: 'accounting/cash',      name: 'accounting-cash',      component: () => import('@/pages/accounting/CashRegister.vue'),       meta: { requiresCashMode: true } },
      { path: 'accounting/cash/new',  name: 'accounting-cash-new',  component: () => import('@/pages/accounting/CashDocumentEditor.vue'), meta: { requiresCashMode: true, requiresSupplier: true } },
      // Úprava rozpracovaného (draft) dokladu — vystavený se opravuje stornem, PUT ho odmítne.
      { path: 'accounting/cash/:id(\\d+)/edit', name: 'accounting-cash-edit', component: () => import('@/pages/accounting/CashDocumentEditor.vue'), meta: { requiresCashMode: true, requiresSupplier: true } },
      { path: 'accounting/cash/book', name: 'accounting-cash-book', component: () => import('@/pages/accounting/CashBook.vue'),           meta: { requiresCashMode: true } },
      // Daňová evidence (Epic DE) — jen supplier.accounting_mode === 'tax_evidence' (zrcadlo requiresDoubleEntry)
      { path: 'tax-evidence/cash-journal',         name: 'tax-evidence-cash-journal',         component: () => import('@/pages/tax-evidence/CashJournal.vue'),         meta: { requiresTaxEvidence: true } },
      { path: 'tax-evidence/receivables-payables', name: 'tax-evidence-receivables-payables', component: () => import('@/pages/tax-evidence/ReceivablesPayables.vue'), meta: { requiresTaxEvidence: true } },
      // Sklad (Epic SKLAD) — gate requiresStock (supplier.stock_enabled); role client nemá
      // clientAllowed → guard ho pošle na /portal (deny-by-default, žádný deniedRoles).
      { path: 'stock/items',            name: 'stock-items',           component: () => import('@/pages/stock/ItemList.vue'),     meta: { requiresStock: true } },
      { path: 'stock/items/new',        name: 'stock-item-new',        component: () => import('@/pages/stock/ItemEditor.vue'),   meta: { requiresStock: true, requiresSupplier: true } },
      { path: 'stock/items/:id(\\d+)',      name: 'stock-item-detail', component: () => import('@/pages/stock/ItemDetail.vue'),   meta: { requiresStock: true } },
      { path: 'stock/items/:id(\\d+)/edit', name: 'stock-item-edit',   component: () => import('@/pages/stock/ItemEditor.vue'),   meta: { requiresStock: true, requiresSupplier: true } },
      { path: 'stock/documents',            name: 'stock-documents',        component: () => import('@/pages/stock/DocumentList.vue'),   meta: { requiresStock: true } },
      { path: 'stock/documents/new',        name: 'stock-document-new',     component: () => import('@/pages/stock/DocumentEditor.vue'), meta: { requiresStock: true, requiresSupplier: true } },
      { path: 'stock/documents/:id(\\d+)',  name: 'stock-document-detail',  component: () => import('@/pages/stock/DocumentEditor.vue'), meta: { requiresStock: true } },
      // Objednávky dodavatelům (Epic SKLAD, fáze 4) — koncept/odeslání/potvrzení/příjem.
      { path: 'stock/purchase-orders',            name: 'stock-purchase-orders',        component: () => import('@/pages/stock/PurchaseOrderList.vue'),   meta: { requiresStock: true } },
      { path: 'stock/purchase-orders/new',        name: 'stock-purchase-order-new',     component: () => import('@/pages/stock/PurchaseOrderDetail.vue'), meta: { requiresStock: true, requiresSupplier: true } },
      { path: 'stock/purchase-orders/:id(\\d+)',  name: 'stock-purchase-order-detail',  component: () => import('@/pages/stock/PurchaseOrderDetail.vue'), meta: { requiresStock: true } },
      // „Co objednat" — návrh doplnění zásob se zohledněním rezervací a zboží na cestě.
      { path: 'stock/replenishment',              name: 'stock-replenishment',          component: () => import('@/pages/stock/Replenishment.vue'),       meta: { requiresStock: true } },
      { path: 'stock/warehouses',       name: 'stock-warehouses',      component: () => import('@/pages/stock/Warehouses.vue'),   meta: { requiresStock: true } },
      // „U dodavatele" — nabídky dodavatelů (zboží × dodavatel), fáze 3 epicu.
      { path: 'stock/vendor-offers',    name: 'stock-vendor-offers',   component: () => import('@/pages/stock/VendorOffers.vue'), meta: { requiresStock: true } },
      { path: 'stock/takes',            name: 'stock-takes',           component: () => import('@/pages/stock/TakeWizard.vue'),   meta: { requiresStock: true } },
      { path: 'stock/takes/:id(\\d+)',  name: 'stock-take-detail',     component: () => import('@/pages/stock/TakeWizard.vue'),   meta: { requiresStock: true } },
      { path: 'stock/reports',          name: 'stock-reports',         component: () => import('@/pages/stock/Reports.vue'),      meta: { requiresStock: true } },
      // E-shop — číselníky (Výrobci/Kategorie/Atributy/Tagy/Poplatky/Sklady) + import
      // jako záložky jedné stránky (?tab=…). Poslední položka sekce „Zboží".
      { path: 'eshop',               name: 'eshop',               component: () => import('@/pages/eshop/EshopPage.vue'),     meta: { requiresStock: true, requiresSupplier: true } },
      // Kniha jízd (logbook) — auta, jízdy, tankování
      { path: 'logbook',                name: 'logbook',          component: () => import('@/pages/logbook/LogbookPage.vue') },
      { path: 'stats',                  name: 'stats',           component: () => import('@/pages/Stats.vue') },
      { path: 'purchase-stats',         name: 'purchase-stats',  component: () => import('@/pages/PurchaseStats.vue') },
      // Sjednocená stránka „Bankovní účty" (Finance): výpisy + měny/účty + stavy + avíza.
      // Pravidla účtování (bank posting rules) se přesunula pod Šablony (záložka „Pravidla
      // účtování"), vedle Pravidel nákladů — jednotné místo pro všechna pravidla/šablony.
      // Starý ?tab=rules zůstává jako redirect kvůli starým odkazům/záložkám.
      {
        path: 'bank',
        name: 'bank-statements',
        component: () => import('@/pages/bank/BankPage.vue'),
        beforeEnter: to => to.query.tab === 'rules'
          ? { path: '/templates', query: { section: 'posting' } }
          : true,
      },
      { path: 'bank/:id(\\d+)',         name: 'bank-detail',     component: () => import('@/pages/bank/StatementDetail.vue') },
      // Admin (M6)
      { path: 'admin/activity-log',     name: 'activity-log',   component: () => import('@/pages/admin/ActivityLog.vue'), meta: {  } },
      { path: 'admin/sent-emails',      name: 'sent-emails',    component: () => import('@/pages/admin/SentEmails.vue'), meta: {  } },
      { path: 'admin/cron-jobs',        name: 'cron-jobs',      component: () => import('@/pages/admin/CronJobs.vue'),    meta: {  } },
      { path: 'admin/users',            name: 'admin-users',    component: () => import('@/pages/admin/Users.vue'),       meta: {  } },
      { path: 'admin/roles',            name: 'admin-roles',    component: () => import('@/pages/admin/Roles.vue'),       meta: { superadminOnly: true } },
      { path: 'admin/settings',         name: 'admin-settings', component: () => import('@/pages/admin/Settings.vue'),    meta: {  } },
      { path: 'admin/accounting-activation', name: 'accounting-activation', component: () => import('@/pages/admin/AccountingActivation.vue'), meta: {  } },
      { path: 'admin/branding',         name: 'admin-branding', component: () => import('@/pages/admin/Branding.vue'),    meta: {  } },
      // Bývalá stránka Systém → Bankovní účty je nyní součástí /bank (Finance) jako záložky.
      // Redirect zachovává bookmarks vč. původního ?tab=.
      {
        path: 'admin/bank-accounts',
        name: 'admin-bank-accounts',
        redirect: to => ({
          path: '/bank',
          query: { tab: ['accounts', 'balances', 'email'].includes(String(to.query.tab)) ? String(to.query.tab) : 'accounts' },
        }),
      },
      { path: 'admin/bank-email-notices', name: 'admin-bank-email-notices', redirect: '/bank?tab=email' },
      // Dodavatelé (multi-tenant firmy) — vlastní stránka, vytažená ze záložky v Codebooks
      // (reorg menu, audit 2026-07): Firma/Globální nastavení jsou rozdělené a Dodavatelé
      // patří jako samostatný bod pod Globální nastavení.
      { path: 'admin/suppliers',        name: 'admin-suppliers', component: () => import('@/pages/admin/SuppliersPage.vue'), meta: {  } },
      {
        path: 'admin/codebooks',
        name: 'admin-codebooks',
        component: () => import('@/pages/admin/Codebooks.vue'),
        meta: {  },
        beforeEnter: to => to.query.tab === 'tax_constants'
          ? { path: '/admin/tax-constants' }
          : true,
      },
      { path: 'admin/tax-constants',    name: 'admin-tax-constants', component: () => import('@/pages/admin/TaxConstants.vue'), meta: {  } },
      { path: 'admin/electronic-signatures', name: 'admin-electronic-signatures', component: () => import('@/pages/admin/ElectronicSignatures.vue'), meta: {  } },
      { path: 'admin/databox', name: 'admin-databox', component: () => import('@/pages/admin/DataBox.vue'), meta: { requiresPayroll: true } },
      { path: 'admin/isds-gateway', name: 'admin-isds-gateway', component: () => import('@/pages/admin/IsdsGatewaySettings.vue'), meta: {  } },
      { path: 'isds-gateway/callback', name: 'isds-gateway-callback', component: () => import('@/pages/IsdsGatewayCallback.vue'), meta: { requiresSupplier: true } },
      // Globální katalog šablon bankovních pravidel — systémová (ne per-firma) agenda,
      // proto vlastní routa pod Systém místo záložky v per-firma /templates.
      { path: 'admin/bank-rule-templates', name: 'admin-bank-rule-templates', component: () => import('@/pages/admin/BankRuleTemplates.vue'), meta: {  } },
      {
        path: 'templates', name: 'templates', component: () => import('@/pages/TemplatesPage.vue'),
        // Bookmark na bývalou superadmin záložku katalogu šablon → nová systémová routa.
        beforeEnter: to => (to.query.section === 'bank' ? { path: '/admin/bank-rule-templates' } : true),
      },
      // Nástroje (reorg menu, audit 2026-07) — archivy, kurzový režim, repo sazba,
      // předkontace a účetní období jako záložky jedné stránky (?section=…). Export/Import
      // se odsud vyčlenilo (reorg UX 2026-07) na /invoices/export|import a
      // /purchase-invoices/export|import — starý ?section=exchange níže jen redirectuje.
      {
        path: 'utilities',
        name: 'tools',
        component: () => import('@/pages/ToolsPage.vue'),
        beforeEnter: to => {
          if (to.query.section === 'journal-templates') {
            return { path: '/templates', query: Object.fromEntries(Object.entries(to.query).filter(([key]) => key !== 'section')) }
          }
          // Účetní období se vytáhla do vlastní routy /accounting/periods (Uzávěrka) —
          // starý ?section=periods bookmark zachováváme jako redirect.
          if (to.query.section === 'periods') {
            return { path: '/accounting/periods' }
          }
          if (to.query.section === 'exchange') {
            const tab = Array.isArray(to.query.tab) ? to.query.tab[0] : to.query.tab
            const target: Record<string, string> = {
              'export-issued': '/invoices/export', 'import-issued': '/invoices/import',
              'export-purchase': '/purchase-invoices/export', 'import-purchase': '/purchase-invoices/import',
            }
            return { path: target[String(tab)] ?? '/invoices/export' }
          }
          return true
        },
      },
      // Staré routy Export/Import (dřív sjednocené na /utilities?section=exchange, resp.
      // ?tab= 4 taby) → redirecty přímo na nové samostatné routy (zachovávají bookmarks
      // i route names).
      {
        path: 'exchange', name: 'data-exchange',
        redirect: to => {
          const tab = Array.isArray(to.query.tab) ? to.query.tab[0] : to.query.tab
          const target: Record<string, string> = {
            'export-issued': '/invoices/export', 'import-issued': '/invoices/import',
            'export-purchase': '/purchase-invoices/export', 'import-purchase': '/purchase-invoices/import',
          }
          return target[String(tab)] ?? '/invoices/export'
        },
      },
      { path: 'admin/export',           name: 'admin-export',    redirect: '/invoices/export' },
      {
        path: 'admin/import',
        name: 'admin-import',
        redirect: to => {
          const tb = Array.isArray(to.query.tab) ? to.query.tab[0] : to.query.tab
          return tb === 'purchase' ? '/purchase-invoices/import' : '/invoices/import'
        },
      },
      { path: 'admin/integrations',     name: 'admin-integrations', component: () => import('@/pages/admin/Integrations.vue'), meta: {  } },
      { path: 'crm',                    name: 'crm-dashboard',      component: () => import('@/pages/crm/CrmDashboard.vue') },
      { path: 'portfolio',              name: 'portfolio-overview', component: () => import('@/pages/portfolio/PortfolioOverview.vue') },
      { path: 'automation',             name: 'automation-cockpit', component: () => import('@/pages/automation/AutomationCockpit.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'reports/dph',            name: 'reports-dph',        component: () => import('@/pages/reports/DphPriznaniReport.vue') },
      { path: 'reports/kh',             name: 'reports-kh',         component: () => import('@/pages/reports/KontrolniHlaseniReport.vue') },
      { path: 'reports/dph-book',       name: 'reports-dph-book',   component: () => import('@/pages/reports/DphBookReport.vue') },
      { path: 'reports/s74b',           name: 'reports-s74b',       component: () => import('@/pages/reports/Section74b.vue') },
      { path: 'reports/related-parties', name: 'reports-related-parties', component: () => import('@/pages/reports/RelatedParties.vue') },
      // § 76 ZDPH — koeficient krácení nároku na odpočet (zálohový + roční vypořádání).
      { path: 'reports/vat-coefficient', name: 'reports-vat-coefficient', component: () => import('@/pages/reports/VatCoefficient.vue') },
      // § 46 ZDPH — věřitelská oprava základu daně u nedobytné pohledávky + obnovy § 46e.
      { path: 'reports/s46', name: 'reports-s46', component: () => import('@/pages/reports/Section46.vue') },
      { path: 'reports/vat-corrections', name: 'reports-vat-corrections', component: () => import('@/pages/reports/VatCorrections.vue') },
      { path: 'reports/shv',            name: 'reports-shv',        component: () => import('@/pages/reports/SouhrnneHlaseniReport.vue') },
      { path: 'reports/income-tax',     name: 'reports-income-tax', component: () => import('@/pages/reports/IncomeTaxReport.vue') },
      { path: 'reports/cnb-rate-audit', name: 'reports-cnb-rate-audit', component: () => import('@/pages/reports/CnbRateAudit.vue') },
      // FR3 (vendor audit 2026-08) — úplnost číselné řady vydaných dokladů (mezera = auditní signál pro FÚ).
      { path: 'reports/invoice-series-completeness', name: 'reports-invoice-series-completeness', component: () => import('@/pages/reports/InvoiceSeriesCompleteness.vue') },
      // § 38da a § 38e ZDP — písemnosti k příjmům daňových nerezidentů. Nejsou
      // pod mzdami: ze mzdy ani jedna povinnost nevzniká (viz komponenta).
      { path: 'reports/foreign-income', name: 'reports-foreign-income', component: () => import('@/pages/reports/ForeignIncomeNotices.vue') },
      { path: 'reports/submissions',    name: 'reports-submissions', component: () => import('@/pages/reports/TaxSubmissions.vue') },
      { path: 'reports/monthly-export', name: 'reports-monthly-export', component: () => import('@/pages/reports/MonthlyExportReport.vue') },
      { path: 'reports/oss',            name: 'reports-oss', component: () => import('@/pages/reports/OssReport.vue'), meta: { requiresOss: true } },
      { path: 'tax',                    name: 'tax-optimizer',      component: () => import('@/pages/tax/TaxOptimizer.vue') },
      { path: 'admin/email-templates',  name: 'admin-email-templates', component: () => import('@/pages/admin/EmailTemplates.vue'), meta: {  } },
      // Sekce E-maily — záložky: Odeslané / Šablony / Elektronické podpisy (vzor Codebooks)
      { path: 'admin/emails',           name: 'admin-emails',    component: () => import('@/pages/admin/Emails.vue'), meta: {  } },
      { path: 'admin/approvals',        name: 'admin-approvals', component: () => import('@/pages/admin/Approvals.vue'), meta: {  } },
      { path: 'admin/price-list',       name: 'admin-price-list', component: () => import('@/pages/admin/PriceList.vue'), meta: { requiresSupplier: true, requiresNoStock: true } },
      { path: 'admin/price-list/new',   name: 'admin-price-list-new', component: () => import('@/pages/admin/PriceListForm.vue'), meta: { requiresSupplier: true, requiresNoStock: true } },
      { path: 'admin/price-list/:id(\\d+)/edit', name: 'admin-price-list-edit', component: () => import('@/pages/admin/PriceListForm.vue'), meta: { requiresSupplier: true, requiresNoStock: true } },
      { path: 'recurring',              name: 'recurring',        component: () => import('@/pages/recurring/RecurringList.vue'), meta: {  } },
      { path: 'recurring/new',          name: 'recurring-new',    component: () => import('@/pages/recurring/RecurringForm.vue'), meta: { requiresSupplier: true } },
      { path: 'recurring/:id(\\d+)',    name: 'recurring-detail', component: () => import('@/pages/recurring/RecurringDetail.vue'), meta: {  } },
      { path: 'recurring/:id(\\d+)/edit', name: 'recurring-edit', component: () => import('@/pages/recurring/RecurringForm.vue'), meta: { requiresSupplier: true } },
      { path: 'admin/update',           name: 'admin-update',    component: () => import('@/pages/admin/Update.vue'),    meta: {  } },
      // Diagnostika a podpora — audit prostředí, podklad k incidentu, rozcestník. Admin only.
      { path: 'admin/diagnostics',      name: 'admin-diagnostics', component: () => import('@/pages/admin/Diagnostics.vue'), meta: {  } },
      { path: 'admin/support',          name: 'admin-support',     component: () => import('@/pages/admin/Support.vue'),     meta: {  } },
      // Kompletní export dat firmy (H-14) — stažení všeho v jednom archivu, pro archivaci
      // i pro odchod ze služby. API je pod /api/admin/, tedy admin only.
      { path: 'admin/instance-export',  name: 'admin-instance-export', component: () => import('@/pages/admin/InstanceExport.vue'), meta: { superadminOnly: true } },
      // Hosting (H-31) — shrnutí spravovaného provozu: tarif, místo, platnost, kam napsat.
      // Data jsou z /api/license/status (admin only), takže i routa je superadmin only.
      // V menu se položka objeví JEN při `app.managed`; na self-hosted instalaci se
      // stránka na přímou URL otevře taky, ale řekne, že tahle instalace neběží u nás.
      { path: 'hosting',             name: 'hosting',             component: () => import('@/pages/hosting/Hosting.vue'), meta: { superadminOnly: true } },
      // Aktivace (E4) — licenční model, obchodní podmínky, zakoupení/aktivace. Admin only.
      { path: 'activation/license',  name: 'activation-license',  component: () => import('@/pages/activation/Licence.vue'),          meta: {  } },
      { path: 'activation/terms',    name: 'activation-terms',    component: () => import('@/pages/activation/ObchodniPodminky.vue'), meta: {  } },
      { path: 'activation/purchase', name: 'activation-purchase', component: () => import('@/pages/activation/Zakoupeni.vue'),        meta: {  } },
      // /profile/totp je zachován pro BC (staré bookmarks, force-TOTP middleware redirect),
      // ale UI ho merge-uje do /profile/password (tabs). Redirect zachovává query stringy.
      { path: 'profile/totp',           name: 'profile-totp',          redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'totp' } }) },
      { path: 'profile/password',       name: 'profile-password',      component: () => import('@/pages/PasswordChange.vue'), meta: {  } },
      { path: 'profile/shortcuts',      name: 'profile-shortcuts',     redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'shortcuts' } }) },
      { path: 'profile/api-tokens',     name: 'profile-api-tokens',    component: () => import('@/pages/ApiTokens.vue') },
      { path: 'profile/mcp-server',     name: 'profile-mcp-server',    component: () => import('@/pages/McpServer.vue') },
      { path: 'profile/passkeys',       name: 'profile-passkeys',      redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'passkeys' } }) },
      { path: 'profile/session-lock',   name: 'profile-session-lock',  redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'session-lock' } }) },
      { path: 'profile/signing-profiles', name: 'profile-signing-profiles', redirect: '/admin/electronic-signatures' },
  ]
}
