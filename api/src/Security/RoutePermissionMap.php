<?php

declare(strict_types=1);

namespace MyInvoice\Security;

final class RoutePermissionMap
{
    public const PUBLIC = 'public';
    public const SELF_SERVICE = 'self_service';
    public const SUPERADMIN = 'superadmin';
    public const PERMISSION = 'permission';

    /** @var list<string> */
    private const PUBLIC_PATHS = [
        '/api/health', '/api/version', '/api/openapi.yaml', '/api/docs', '/api/reference', '/api/scalar',
        '/api/auth/setup-status', '/api/auth/setup-preflight', '/api/auth/setup', '/api/auth/setup-ares-lookup',
        '/api/auth/setup-crpdph-lookup', '/api/auth/login',
        '/api/auth/webauthn/login/options', '/api/auth/webauthn/login/verify',
        '/api/auth/forgot', '/api/auth/reset', '/api/auth/domain-context',
        '/api/auth/domain-login/start', '/api/auth/domain-login/exchange', '/api/csrf-token',
    ];

    /** @var list<string> */
    private const SELF_PATHS = [
        '/api/auth/logout', '/api/auth/me', '/api/auth/api-me', '/api/auth/change-password',
        '/api/auth/totp/status', '/api/auth/totp/setup', '/api/auth/totp/enable',
        '/api/auth/webauthn/credentials',
        '/api/auth/webauthn/register/options', '/api/auth/webauthn/register/verify',
        '/api/auth/webauthn/step-up/options', '/api/auth/webauthn/step-up/verify',
        '/api/auth/mfa/step-up/totp', '/api/auth/mfa/step-up/recovery',
        // Vlastní záložní kódy spravuje jen jejich majitel — generování si navíc
        // vynucuje čerstvý step-up skutečným faktorem (MfaRecoveryCodeAction).
        '/api/auth/mfa/recovery-codes',
        '/api/auth/session/status', '/api/auth/session/activity', '/api/auth/session/lock',
        '/api/auth/session/lock-preference',
        '/api/auth/domain-login/authorize',
        '/api/auth/session/unlock/options', '/api/auth/session/unlock/verify',
    ];

    /**
     * Specific rules precede module fallbacks.
     * @var list<array{0:string,1:string,2:string,3:AccessLevel}>
     */
    private const RULES = [
        ['GET', '#^/api/settings/domains(/|$)#', 'settings.domains', AccessLevel::READ],
        ['*', '#^/api/settings/domains(/|$)#', 'settings.domains', AccessLevel::WRITE],
        ['GET', '#^/api/auth/tokens(/|$)#', 'profile.tokens', AccessLevel::READ],
        ['*', '#^/api/auth/tokens(/|$)#', 'profile.tokens', AccessLevel::WRITE],
        // Log volání API vlastními tokeny — čtení sdílí oprávnění se správou tokenů.
        ['GET', '#^/api/auth/api-log$#', 'profile.tokens', AccessLevel::READ],

        ['GET', '#^/api/invoices/[0-9]+/stock-documents(/|$)#', 'stock', AccessLevel::READ],
        ['*', '#^/api/invoices/[0-9]+/stock-documents(/|$)#', 'stock', AccessLevel::WRITE],
        ['*', '#^/api/invoices/[0-9]+/book$#', 'accounting.journal.post', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/[0-9]+/issue(-final)?$#', 'invoices.issue', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/[0-9]+/(send|send-test)$#', 'invoices.send', AccessLevel::WRITE],
        ['POST', '#^/api/invoices(/[0-9]+)?/(reminder|reminder-test)$#', 'invoices.reminder', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/[0-9]+/(mark-paid|unmark-paid|payments)(/|$)#', 'invoices.mark_paid', AccessLevel::WRITE],
        ['DELETE', '#^/api/invoices/[0-9]+/payments(/|$)#', 'invoices.mark_paid', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/[0-9]+/cancel$#', 'invoices.cancel', AccessLevel::WRITE],
        // Obnova snapshotů stran u vystaveného dokladu — sdílí oprávnění s editací
        // faktury; navíc je v akci tvrdý admin-only check (superadmin).
        ['POST', '#^/api/invoices/[0-9]+/rebuild-snapshots$#', 'invoices.create', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/[0-9]+/clone$#', 'invoices.clone', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/bulk-reminder$#', 'invoices.reminder', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/bulk-reissue$#', 'invoices.clone', AccessLevel::WRITE],
        ['DELETE', '#^/api/invoices/[0-9]+$#', 'invoices.delete', AccessLevel::WRITE],
        ['*', '#^/api/invoices/[0-9]+/(request-approval|request-approval-test|approval-status)$#', 'invoices.approval', AccessLevel::WRITE],
        ['POST', '#^/api/invoices$#', 'invoices.create', AccessLevel::WRITE],
        ['GET', '#^/api/invoices(/|$)#', 'invoices', AccessLevel::READ],
        ['*', '#^/api/invoices(/|$)#', 'invoices', AccessLevel::WRITE],

        // Čtení fallbackového ceníku používá editor faktur. Správa je níže v match()
        // vyhrazena superadminovi a API samo navíc odmítne firmy s aktivním skladem.
        ['GET', '#^/api/price-list-items(/|$)#', 'invoices', AccessLevel::READ],

        ['GET', '#^/api/purchase-invoices/payment-orders(/|$)#', 'purchase_invoices.payment_orders', AccessLevel::READ],
        ['*', '#^/api/purchase-invoices/payment-orders(/|$)#', 'purchase_invoices.payment_orders', AccessLevel::WRITE],
        ['POST', '#^/api/purchase-invoices/scan-inbox$#', 'purchase_invoices.scan', AccessLevel::WRITE],
        ['POST', '#^/api/purchase-invoices/import-structured$#', 'purchase_invoices.create', AccessLevel::WRITE],
        ['GET', '#^/api/purchase-invoices/[0-9]+/documents(/|$)#', 'documents', AccessLevel::READ],
        ['*', '#^/api/purchase-invoices/[0-9]+/documents(/|$)#', 'documents', AccessLevel::WRITE],
        ['GET', '#^/api/purchase-invoices/[0-9]+/stock-receipts?(/|$)#', 'stock', AccessLevel::READ],
        ['*', '#^/api/purchase-invoices/[0-9]+/stock-receipts?(/|$)#', 'stock', AccessLevel::WRITE],
        ['POST', '#^/api/purchase-invoices/[0-9]+/transition$#', 'purchase_invoices.transition', AccessLevel::WRITE],
        ['DELETE', '#^/api/purchase-invoices/[0-9]+/(link-advance|advance-suggestion|pdf)$#', 'purchase_invoices', AccessLevel::WRITE],
        ['DELETE', '#^/api/purchase-invoices/[0-9]+(/|$)#', 'purchase_invoices.delete', AccessLevel::WRITE],
        ['POST', '#^/api/purchase-invoices$#', 'purchase_invoices.create', AccessLevel::WRITE],
        ['GET', '#^/api/purchase-invoices(/|$)#', 'purchase_invoices', AccessLevel::READ],
        ['*', '#^/api/purchase-invoices(/|$)#', 'purchase_invoices', AccessLevel::WRITE],

        ['GET', '#^/api/purchase-invoice-submissions(/|$)#', 'documents.inbox', AccessLevel::READ],
        ['DELETE', '#^/api/purchase-invoice-submissions/[0-9]+$#', 'documents.inbox.delete', AccessLevel::WRITE],
        ['*', '#^/api/purchase-invoice-submissions(/|$)#', 'documents.inbox', AccessLevel::WRITE],

        ['POST', '#^/api/recurring$#', 'recurring.create', AccessLevel::WRITE],
        ['POST', '#^/api/recurring/[0-9]+/(run|run-now|generate)$#', 'recurring.run', AccessLevel::WRITE],
        ['POST', '#^/api/recurring/[0-9]+/(pause|resume)$#', 'recurring.pause', AccessLevel::WRITE],
        ['DELETE', '#^/api/recurring/[0-9]+$#', 'recurring.delete', AccessLevel::WRITE],
        ['GET', '#^/api/recurring(/|$)#', 'recurring', AccessLevel::READ],
        ['*', '#^/api/recurring(/|$)#', 'recurring', AccessLevel::WRITE],

        ['POST', '#^/api/clients$#', 'clients.create', AccessLevel::WRITE],
        ['GET', '#^/api/clients/[0-9]+/projects$#', 'projects', AccessLevel::READ],
        ['DELETE', '#^/api/clients/[0-9]+$#', 'clients.archive', AccessLevel::WRITE],
        ['*', '#^/api/clients/[0-9]+/(archive|unarchive|restore)$#', 'clients.archive', AccessLevel::WRITE],
        ['GET', '#^/api/clients/[0-9]+/work-report-link(/|$)#', 'clients.public_links', AccessLevel::READ],
        ['*', '#^/api/clients/[0-9]+/work-report-link(/|$)#', 'clients.public_links', AccessLevel::WRITE],
        ['GET', '#^/api/clients(/|$)#', 'clients', AccessLevel::READ],
        ['*', '#^/api/clients(/|$)#', 'clients', AccessLevel::WRITE],
        ['POST', '#^/api/projects$#', 'projects.create', AccessLevel::WRITE],
        ['DELETE', '#^/api/projects/[0-9]+$#', 'projects.archive', AccessLevel::WRITE],
        ['*', '#^/api/projects/[0-9]+/(archive|restore)$#', 'projects.archive', AccessLevel::WRITE],
        ['GET', '#^/api/projects(/|$)#', 'projects', AccessLevel::READ],
        ['*', '#^/api/projects(/|$)#', 'projects', AccessLevel::WRITE],

        ['*', '#^/api/bank-transactions/[0-9]+/match(/|$)#', 'bank.match', AccessLevel::WRITE],
        ['*', '#^/api/bank-match-suggestions/[0-9]+/(accept|reject)$#', 'bank.match', AccessLevel::WRITE],
        ['POST', '#^/api/bank-transactions/[0-9]+/post$#', 'bank.post', AccessLevel::WRITE],
        ['POST', '#^/api/bank-transactions/[0-9]+/ai-suggest$#', 'bank.post', AccessLevel::WRITE],
        ['GET', '#^/api/bank-ai-suggestion-availability$#', 'bank.post', AccessLevel::WRITE],
        ['GET', '#^/api/purchase-ai-suggestion-availability$#', 'accounting', AccessLevel::WRITE],
        ['POST', '#^/api/bank-transactions/[0-9]+/unpost$#', 'bank.unpost', AccessLevel::WRITE],

        // Průřezová fronta podání (datová schránka i EPO). Oprávnění je stejné
        // jako u trezoru certifikátů: kdo smí spravovat podpisové prostředky,
        // smí i odesílat podání — a naopak nikdo jiný.
        // `defect-notices` = výzvy k odstranění vad podle § 74 daňového řádu.
        // Patří sem, protože se týkají osudu odeslaného podání, ne mzdové agendy.
        // `gateway` = návrat z odesílací brány ISDS. Je to sice „callback"
        // adresa, kterou zná ISDS, ale VEŘEJNÁ NENÍ: `appToken` z přesměrování
        // slouží jen k dohledání rozpracovaného podání a o oprávnění rozhoduje
        // přihlášená relace. Proto stejné právo jako zbytek fronty — odeslání
        // datové zprávy je právní úkon a nesmí ho vyvolat kdokoliv s odkazem.
        ['GET', '#^/api/submissions/(outbox|inbox|recipients|receipts|defect-notices|gateway)(/|$)#', 'settings.signing', AccessLevel::READ],
        ['*', '#^/api/submissions/(outbox|inbox|recipients|receipts|defect-notices|gateway)(/|$)#', 'settings.signing', AccessLevel::WRITE],

        // Retence a výmaz. Dvě různá práva ZÁMĚRNĚ: nastavit lhůtu nebo zadržet
        // výmaz je správa evidence, kdežto schválit a provést výmaz je nevratný
        // zásah do osobních údajů. Kdo lhůty spravuje, nemusí být tentýž člověk,
        // který odklepne, že se data smažou.
        //
        // Konkrétní pravidla musí předcházet obecnému `/api/payroll/*` fallbacku
        // i pravidlu na `/people`, jinak by `retention` spadlo do modulového práva.
        ['GET', '#^/api/payroll/retention(/(assessment|holds))?$#', 'payroll.retention', AccessLevel::READ],
        ['*', '#^/api/payroll/retention/(holds(/[0-9]+)?|policies/[a-z_]+)$#', 'payroll.retention', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/retention/erasure(/[0-9]+)?$#', 'payroll.erasure', AccessLevel::READ],
        ['POST', '#^/api/payroll/retention/erasure(/[0-9]+/(approve|reject|execute))?$#', 'payroll.erasure', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/people$#', 'payroll', AccessLevel::READ],
        ['POST', '#^/api/payroll/people$#', 'payroll.person.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/people/[0-9]+$#', 'payroll', AccessLevel::READ],
        // Smazání omylem založené osoby je opak jejího založení, ne přísnější úkon —
        // stejné právo jako POST /people. Před skutečnými pohyby chrání blokátory
        // v `PayrollEmployeeDeletionRepository`, ne zvláštní oprávnění.
        ['DELETE', '#^/api/payroll/people/[0-9]+$#', 'payroll.person.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/people/[0-9]+/profile$#', 'payroll', AccessLevel::READ],
        ['*', '#^/api/payroll/people/[0-9]+/profile$#', 'payroll.person.write', AccessLevel::WRITE],
        // Výplatní pravidlo je součást osobní karty (stejná rodina jako výplatní
        // účty a jejich ověření níže), proto `payroll.person.write` na zápis
        // a obecné `payroll` na čtení.
        ['GET', '#^/api/payroll/people/[0-9]+/payout-rules$#', 'payroll', AccessLevel::READ],
        ['*', '#^/api/payroll/people/[0-9]+/payout-rules(/(apply-defaults|[0-9]+))?$#', 'payroll.person.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/people/[0-9]+/dependants$#', 'payroll', AccessLevel::READ],
        ['*', '#^/api/payroll/people/[0-9]+/dependants(/[0-9]+(/claims(/[0-9]+)?)?)?$#', 'payroll.person.write', AccessLevel::WRITE],
        // Zákonná evidence osoby (prohlášení k dani, daňová rezidence, sociální
        // a zdravotní příslušnost, sleva pracujícího důchodce). Jsou to právní
        // skutečnosti vedené na OSOBĚ, ne na pracovním vztahu — jedna osoba jich
        // může mít víc a evidence platí napříč. Proto stejné právo jako profil
        // a vyživované osoby, ne `payroll.employment.write`.
        ['GET', '#^/api/payroll/people/[0-9]+/statutory-evidence$#', 'payroll', AccessLevel::READ],
        ['PUT', '#^/api/payroll/people/[0-9]+/statutory-evidence$#', 'payroll.person.write', AccessLevel::WRITE],
        ['PUT', '#^/api/payroll/people/[0-9]+/quick-edit$#', 'payroll.person.write', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/people/[0-9]+/sensitive-reveal$#', 'payroll.person.read_sensitive', AccessLevel::READ],
        ['POST', '#^/api/payroll/people/[0-9]+/employments$#', 'payroll.employment.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/jmhz/employment-evidence-options$#', 'payroll', AccessLevel::READ],
        ['GET', '#^/api/payroll/jmhz/municipalities$#', 'payroll', AccessLevel::READ],
        ['GET', '#^/api/payroll/jmhz/identities/[0-9]+$#', 'payroll', AccessLevel::READ],
        ['PUT', '#^/api/payroll/jmhz/identities/[0-9]+$#', 'payroll.employment.write', AccessLevel::WRITE],
        // Klasifikace zaměstnání ČSÚ je veřejná referenční data, ne data nájemce —
        // stejná úroveň jako sousední našeptávač obcí.
        ['GET', '#^/api/payroll/cz-isco$#', 'payroll', AccessLevel::READ],
        // Rozcestník karty zaměstnance. Vstupní branou je obecné `payroll` (kdo smí
        // na kartu, smí vidět, že agendy existují); citlivější agendy uvnitř si
        // PayrollEmploymentAgendaSummaryAction filtruje po jedné vlastním právem.
        ['GET', '#^/api/payroll/employments/[0-9]+/agenda-summary$#', 'payroll', AccessLevel::READ],
        ['*', '#^/api/payroll/employments/[0-9]+/(terms|transitions/[a-z_]+|checklist/[a-z0-9_]+)$#', 'payroll.employment.write', AccessLevel::WRITE],
        // Totéž právo jako založení vztahu (POST /people/{id}/employments výše).
        ['DELETE', '#^/api/payroll/employments/[0-9]+$#', 'payroll.employment.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/time/month$#', 'payroll', AccessLevel::READ],
        ['*', '#^/api/payroll/time/calendars/[0-9]+$#', 'payroll.time.write', AccessLevel::WRITE],
        ['*', '#^/api/payroll/time/(shifts|entries|imports(?:/preview)?)$#', 'payroll.time.write', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/time/months/[0-9]{4}-[0-9]{2}/approve$#', 'payroll.approve', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/time/months/[0-9]{4}-[0-9]{2}/reopen$#', 'payroll.reopen', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/runs$#', 'payroll', AccessLevel::READ],
        ['GET', '#^/api/payroll/runs/[0-9]+$#', 'payroll', AccessLevel::READ],
        ['POST', '#^/api/payroll/runs$#', 'payroll.inputs.write', AccessLevel::WRITE],
        ['DELETE', '#^/api/payroll/runs/[0-9]+$#', 'payroll.inputs.write', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/runs/[0-9]+/commands/calculate$#', 'payroll.calculate', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/runs/[0-9]+/commands/(review|request_correction)$#', 'payroll.review', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/runs/[0-9]+/commands/approve$#', 'payroll.approve', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/runs/[0-9]+/commands/reopen$#', 'payroll.reopen', AccessLevel::WRITE],
        // Zaúčtování a platební příkazy běhu nesmí spadnout pod catch-all
        // `payroll.inputs.write` — to je právo na zápis mzdových vstupů, ne na
        // účetní zápis v hlavní knize ani na platební ledger.
        ['POST', '#^/api/payroll/runs/[0-9]+/commands/post$#', 'payroll.post', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/runs/[0-9]+/commands/(prepare_payments|mark_paid)$#', 'payroll.payments', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/runs/[0-9]+/commands/[a-z_]+$#', 'payroll.inputs.write', AccessLevel::WRITE],
        // Schválení a odvolání výjimky u validace běhu je věcně část schválení
        // mzdy („vím o vadě a přesto se vyplácí"), proto `payroll.approve`.
        ['POST', '#^/api/payroll/runs/[0-9]+/validations/[0-9]+/override$#', 'payroll.approve', AccessLevel::WRITE],
        ['DELETE', '#^/api/payroll/runs/[0-9]+/validations/[0-9]+/override$#', 'payroll.approve', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/payments/liabilities$#', 'payroll.payments', AccessLevel::READ],
        ['GET', '#^/api/payroll/payments/(payer-options|batches|reconciliation)$#', 'payroll.payments', AccessLevel::READ],
        // Nabídka pickeru je týž obsah jako `reconciliation`, jen zúžený —
        // samostatné právo by zamklo hledání a nechalo otevřený celý seznam.
        ['GET', '#^/api/payroll/payments/reconciliation/options$#', 'payroll.payments', AccessLevel::READ],
        ['POST', '#^/api/payroll/payments/batches$#', 'payroll.payments', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/payments/reconciliation/(matches|reversals|incoming-refunds|incoming-refund-reversals)$#', 'payroll.payments', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/payments/batches/[0-9]+/exports$#', 'payroll.payments', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/payments/exports/[0-9]+/download-grants$#', 'payroll.payments', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/payments/exports/download$#', 'payroll.payments', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/revisions/[0-9]+/payments/(?:liabilities|net-wage-liabilities)$#', 'payroll.payments', AccessLevel::WRITE],
        // MZ-18-W07 — reconciliation je read-only, gatovaná stejným právem jako zaúčtování mezd.
        ['GET', '#^/api/payroll/posting/reconciliation$#', 'payroll.post', AccessLevel::READ],
        ['POST', '#^/api/payroll/people/[0-9]+/accounts/[0-9]+/verify$#', 'payroll.person.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/regzel/(?:profile|snapshots(?:/[0-9]+/xml)?)$#', 'payroll.submissions', AccessLevel::READ],
        ['*', '#^/api/payroll/submissions/regzel/(?:profile|prepare)$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/overview$#', 'payroll.submissions', AccessLevel::READ],
        ['POST', '#^/api/payroll/submissions/isds-gateway/(?:outbox/[0-9]+|callback)$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/inbox$#', 'payroll.submissions', AccessLevel::READ],
        ['POST', '#^/api/payroll/submissions/inbox/[0-9]+/(?:acknowledge|snooze)$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/jmhz-pvpoj/[0-9]+(?:/download)?$#', 'payroll.submissions', AccessLevel::READ],
        ['GET', '#^/api/payroll/submissions/jmhz-ordinary-evidence/[0-9]+$#', 'payroll.submissions', AccessLevel::READ],
        ['POST', '#^/api/payroll/submissions/jmhz-ordinary-evidence/[0-9]+/[0-9]+$#', 'payroll.submissions', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/submissions/jmhz-preparation/[0-9]+$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/jmhz-xml-dry-run/[0-9]+$#', 'payroll.submissions', AccessLevel::READ],
        ['POST', '#^/api/payroll/submissions/jmhz-freeze/[0-9]+$#', 'payroll.submissions', AccessLevel::WRITE],
        // Evidenční list důchodového pojištění je podání jako každé jiné:
        // náhled READ, příprava WRITE. Odeslání tudy nevede.
        ['GET', '#^/api/payroll/submissions/eldp$#', 'payroll.submissions', AccessLevel::READ],
        ['POST', '#^/api/payroll/submissions/eldp$#', 'payroll.submissions', AccessLevel::WRITE],
        // Registrace zaměstnance je podání jako každé jiné, proto stejné právo
        // jako zbytek `/submissions/*`: náhled READ, zmrazení WRITE. Vlastní
        // právo by rozdělilo jednu roli („kdo podává za firmu") na dvě, které
        // by se v praxi vždy přidělovaly společně.
        ['GET', '#^/api/payroll/submissions/registration/[0-9]+$#', 'payroll.submissions', AccessLevel::READ],
        ['POST', '#^/api/payroll/submissions/registration/[0-9]+$#', 'payroll.submissions', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/submissions/registration-transport/[0-9]+$#', 'payroll.submissions', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/submissions/registration-transport/[0-9]+/poll$#', 'payroll.submissions', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/submissions/registration-transport/[0-9]+/close$#', 'payroll.submissions', AccessLevel::WRITE],
        // Záměr uplatňovat slevu na pojistném (OZUSPOJ). Zápis výsledku od ČSSZ
        // je WRITE stejně jako příprava podání — mění doloženost nároku, tedy
        // i výši odvedeného pojistného.
        ['GET', '#^/api/payroll/submissions/discount-intents$#', 'payroll.submissions', AccessLevel::READ],
        ['POST', '#^/api/payroll/submissions/discount-intents$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/discount-intents/[0-9]+/preview$#', 'payroll.submissions', AccessLevel::READ],
        ['POST', '#^/api/payroll/submissions/discount-intents/[0-9]+/(?:prepare|end|receipt)$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/signing-profile$#', 'payroll.submissions', AccessLevel::READ],
        ['*', '#^/api/payroll/submissions/signing-profile$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/jmhz-transport$#', 'payroll.submissions', AccessLevel::READ],
        ['GET', '#^/api/payroll/submissions/jmhz-transport/[0-9]+$#', 'payroll.submissions', AccessLevel::READ],
        ['POST', '#^/api/payroll/submissions/jmhz-transport/[0-9]+/close$#', 'payroll.submissions', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/submissions/[0-9]+/jmhz-transport$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/[0-9]+/jmhz-cancel-components$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/[0-9]+/jmhz-content-correction-preparations$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/[0-9]+/jmhz-content-correction$#', 'payroll.submissions', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/submissions/[0-9]+/jmhz-content-correction$#', 'payroll.submissions', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/submissions/[0-9]+/jmhz-cancel(?:-components)?$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/jmhz-protocol-import$#', 'payroll.submissions', AccessLevel::READ],
        ['POST', '#^/api/payroll/submissions/jmhz-protocol-import$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/[0-9]+$#', 'payroll.submissions', AccessLevel::READ],
        ['POST', '#^/api/payroll/submissions/[0-9]+/artifacts/[0-9]+/download-grant$#', 'payroll.submissions', AccessLevel::READ],
        ['GET', '#^/api/payroll/submissions/[0-9]+/artifacts/[0-9]+/download$#', 'payroll.submissions', AccessLevel::READ],
        ['GET', '#^/api/payroll/submissions/health-overviews/[0-9]+(?:/[0-9]{3}/download)?$#', 'payroll.submissions', AccessLevel::READ],
        ['GET', '#^/api/payroll/submissions/health-notifications/capability$#', 'payroll.submissions', AccessLevel::READ],
        ['GET', '#^/api/payroll/submissions/health-notifications/duties$#', 'payroll.submissions', AccessLevel::READ],
        ['POST', '#^/api/payroll/submissions/health-notifications/duties/obligations$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/submissions/health-notifications/duties/[0-9]+$#', 'payroll.submissions', AccessLevel::READ],
        ['POST', '#^/api/payroll/submissions/health-notifications/duties/[0-9]+/obligations$#', 'payroll.submissions', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/submissions/health-notifications/payment-overview/[0-9]+/[0-9]{3}/prepare$#', 'payroll.submissions', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/settings/policies(?:/[0-9]+)?$#', 'payroll.settings', AccessLevel::READ],
        ['POST', '#^/api/payroll/settings/policies$#', 'payroll.settings', AccessLevel::WRITE],
        ['PUT', '#^/api/payroll/settings/policies/[0-9]+$#', 'payroll.settings', AccessLevel::WRITE],
        // Totéž právo jako založení verze — smazání omylem založené budoucí verze
        // je opak jejího založení, ne přísnější úkon.
        ['DELETE', '#^/api/payroll/settings/policies/[0-9]+$#', 'payroll.settings', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/setup-check$#', 'payroll.settings', AccessLevel::READ],
        ['GET', '#^/api/payroll/documents$#', 'payroll.documents', AccessLevel::READ],
        ['GET', '#^/api/payroll/documents/annual$#', 'payroll.documents', AccessLevel::READ],
        ['POST', '#^/api/payroll/people/[0-9]+/documents/payroll-sheet/[0-9]{4}$#', 'payroll.documents', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/people/[0-9]+/documents/tax-certificate/(advance|withholding)/[0-9]{4}$#', 'payroll.documents', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/runs/[0-9]+/revisions/[0-9]+/documents/monthly-bundle$#', 'payroll.documents', AccessLevel::WRITE],
        // Roční zúčtování (§ 38ch ZDP). Čtení a evidence žádosti pod
        // `payroll.documents`, ale samotné PROVEDENÍ pod `payroll.approve`:
        // je to právní úkon plátce daně, po kterém se zaměstnanci vyplácí
        // přeplatek — stejná váha jako schválení mzdového běhu.
        ['GET', '#^/api/payroll/annual-settlements/[0-9]{4}$#', 'payroll.documents', AccessLevel::READ],
        ['GET', '#^/api/payroll/annual-settlements/[0-9]{4}/people/[0-9]+$#', 'payroll.documents', AccessLevel::READ],
        ['PUT', '#^/api/payroll/annual-settlements/[0-9]{4}/people/[0-9]+/request$#', 'payroll.documents', AccessLevel::WRITE],
        // Zadání potvrzení od jiného plátce je taky `payroll.approve`: ta čísla
        // jdou přímo do úhrnu, ze kterého vychází přeplatek, takže kdo je smí
        // zadat, ten rozhoduje o penězích stejně jako ten, kdo zúčtování provede.
        ['PUT', '#^/api/payroll/annual-settlements/[0-9]{4}/people/[0-9]+/certificates$#', 'payroll.approve', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/annual-settlements/[0-9]{4}/people/[0-9]+/settle$#', 'payroll.approve', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/employments/[0-9]+/documents/exit$#', 'payroll.documents', AccessLevel::READ],
        ['POST', '#^/api/payroll/employments/[0-9]+/documents/exit/(employment-certificate|average-earnings-certificate|average-earnings-statement)$#', 'payroll.documents', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/runs/[0-9]+/revisions/[0-9]+/documents/batch$#', 'payroll.documents', AccessLevel::WRITE],
        ['POST', '#^/api/payroll/documents/[0-9]+/download-grant$#', 'payroll.documents', AccessLevel::READ],
        ['GET', '#^/api/payroll/documents/[0-9]+/download$#', 'payroll.documents', AccessLevel::READ],
        // Legislativní rulesety jsou GLOBÁLNÍ číselník: čtení pod payroll.rulesets,
        // zápis navíc jen superadmin (tvrdý check v PayrollRulesetAction).
        ['GET', '#^/api/payroll/rulesets(?:/|$)#', 'payroll.rulesets', AccessLevel::READ],
        ['*', '#^/api/payroll/rulesets(?:/|$)#', 'payroll.rulesets', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/capabilities$#', 'payroll', AccessLevel::READ],
        ['GET', '#^/api/payroll/components$#', 'payroll', AccessLevel::READ],
        ['GET', '#^/api/payroll/components/(?:jmhz-targets|jmhz-mappings|[0-9]+/jmhz-mapping)$#', 'payroll', AccessLevel::READ],
        ['*', '#^/api/payroll/components/[0-9]+/jmhz-mapping$#', 'payroll.inputs.write', AccessLevel::WRITE],
        ['*', '#^/api/payroll/components(?:/[0-9]+)?$#', 'payroll.inputs.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/risky-savings$#', 'payroll', AccessLevel::READ],
        ['PUT', '#^/api/payroll/risky-savings/evidence$#', 'payroll.inputs.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/travel/trips(?:/[0-9]+/calculation)?$#', 'payroll', AccessLevel::READ],
        // Zrušení schválené cesty bere zpět schválení — proto stejné právo jako
        // schválit a vyúčtovat. Smazání KONCEPTU spadá pod `payroll.inputs.write`
        // v catch-all pravidle níž: kdo cestu založil, musí umět svůj překlep uklidit.
        ['POST', '#^/api/payroll/travel/trips/[0-9]+/(approve|materialize|cancel)$#', 'payroll.approve', AccessLevel::WRITE],
        ['*', '#^/api/payroll/travel(?:/.*)?$#', 'payroll.inputs.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/deduction-agreements(?:/|$)#', 'payroll', AccessLevel::READ],
        ['*', '#^/api/payroll/deduction-agreements(?:/|$)#', 'payroll.inputs.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/revisions/[0-9]+/net-results/[0-9]+$#', 'payroll', AccessLevel::READ],
        ['GET', '#^/api/payroll/revisions/[0-9]+/insurance-breakdowns/[0-9]+$#', 'payroll', AccessLevel::READ],
        ['GET', '#^/api/payroll/enforcement(?:/|$)#', 'payroll.enforcement', AccessLevel::READ],
        ['*', '#^/api/payroll/enforcement(?:/|$)#', 'payroll.enforcement', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/inputs$#', 'payroll', AccessLevel::READ],
        ['POST', '#^/api/payroll/inputs/[0-9]+/approve$#', 'payroll.approve', AccessLevel::WRITE],
        ['*', '#^/api/payroll/inputs(?:/.*)?$#', 'payroll.inputs.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/quick-inputs$#', 'payroll', AccessLevel::READ],
        ['PUT', '#^/api/payroll/quick-inputs$#', 'payroll.inputs.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/recurring-components$#', 'payroll', AccessLevel::READ],
        ['*', '#^/api/payroll/recurring-components(?:/.*)?$#', 'payroll.inputs.write', AccessLevel::WRITE],
        ['*', '#^/api/payroll/input-imports/(preview|apply)$#', 'payroll.inputs.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/settings/activation$#', 'payroll.settings', AccessLevel::READ],
        ['*', '#^/api/payroll/settings/activation$#', 'payroll.settings', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/settings/account-options$#', 'payroll.settings', AccessLevel::READ],
        ['GET', '#^/api/payroll/settings/employer$#', 'payroll.settings', AccessLevel::READ],
        ['*', '#^/api/payroll/settings/employer$#', 'payroll.settings', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/settings/institution-accounts(?:/[0-9]+)?$#', 'payroll.settings', AccessLevel::READ],
        ['*', '#^/api/payroll/settings/institution-accounts(?:/[0-9]+)?$#', 'payroll.settings', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/settings/dimensions(?:/[0-9]+)?$#', 'payroll.settings', AccessLevel::READ],
        ['POST', '#^/api/payroll/settings/dimensions$#', 'payroll.settings', AccessLevel::WRITE],
        ['PUT', '#^/api/payroll/settings/dimensions/[0-9]+$#', 'payroll.settings', AccessLevel::WRITE],
        ['DELETE', '#^/api/payroll/settings/dimensions/[0-9]+$#', 'payroll.settings', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/employments/[0-9]+/dimensions$#', 'payroll.employment.write', AccessLevel::READ],
        ['POST', '#^/api/payroll/employments/[0-9]+/dimensions$#', 'payroll.employment.write', AccessLevel::WRITE],
        ['PUT', '#^/api/payroll/employments/[0-9]+/dimensions/[0-9]+$#', 'payroll.employment.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll/time(/|$)#', 'payroll', AccessLevel::READ],
        ['*', '#^/api/payroll/time(/|$)#', 'payroll.time.write', AccessLevel::WRITE],
        ['GET', '#^/api/payroll(/|$)#', 'payroll', AccessLevel::READ],
        ['*', '#^/api/payroll(/|$)#', 'payroll', AccessLevel::WRITE],

        ['GET', '#^/api/accounting/bank-posting-(rules|suggestions|unposted)(/|$)#', 'bank.rules', AccessLevel::READ],
        ['*', '#^/api/accounting/bank-posting-(rules|suggestions|unposted)(/|$)#', 'bank.rules', AccessLevel::WRITE],
        ['GET', '#^/api/accounting/(bank-rule-templates|auto-posting-policy)(/|$)#', 'bank.rules', AccessLevel::READ],
        ['*', '#^/api/accounting/(bank-rule-templates|auto-posting-policy)(/|$)#', 'bank.rules', AccessLevel::WRITE],
        ['POST', '#^/api/automation/wizard/apply$#', 'bank.rules', AccessLevel::WRITE],
        ['GET', '#^/api/automation(/|$)#', 'accounting', AccessLevel::READ],
        ['POST', '#^/api/ai/suggestions/[0-9]+/(accept|reject)$#', 'accounting.journal.post', AccessLevel::WRITE],
        ['GET', '#^/api/accounting/bank-accounts(/|$)#', 'accounting', AccessLevel::READ],
        ['*', '#^/api/accounting/bank-accounts(/|$)#', 'accounting', AccessLevel::WRITE],
        ['POST', '#^/api/bank-statements/(upload|upload-pdf|scan)$#', 'bank.import', AccessLevel::WRITE],
        ['GET', '#^/api/(bank-statements|bank-transactions)(/|$)#', 'bank', AccessLevel::READ],
        ['*', '#^/api/(bank-statements|bank-transactions)(/|$)#', 'bank', AccessLevel::WRITE],

        ['GET', '#^/api/document-requests(/|$)#', 'documents.requests', AccessLevel::READ],
        ['*', '#^/api/document-requests(/|$)#', 'documents.requests', AccessLevel::WRITE],
        ['POST', '#^/api/(documents|document-folders)(/|$)#', 'documents.upload', AccessLevel::WRITE],
        ['*', '#^/api/documents/[0-9]+/(move|links)(/|$)#', 'documents.move', AccessLevel::WRITE],
        ['DELETE', '#^/api/(documents|document-folders)(/|$)#', 'documents.delete', AccessLevel::WRITE],
        ['POST', '#^/api/documents/[0-9]+/restore$#', 'documents.restore', AccessLevel::WRITE],
        ['GET', '#^/api/(documents|document-folders)(/|$)#', 'documents', AccessLevel::READ],
        ['*', '#^/api/(documents|document-folders)(/|$)#', 'documents', AccessLevel::WRITE],

        ['GET', '#^/api/accounting/periods(/|$)#', 'accounting', AccessLevel::READ],
        ['*', '#^/api/accounting/periods/[0-9]+/(closing|close|open-next|revert)(/|$)#', 'accounting.periods.close', AccessLevel::WRITE],
        ['*', '#^/api/accounting/periods(/|$)#', 'accounting.periods.manage', AccessLevel::WRITE],
        ['*', '#^/api/accounting/journal/(post|transfer)|^/api/accounting/journal/post-(invoice|purchase)/#', 'accounting.journal.post', AccessLevel::WRITE],
        ['GET', '#^/api/accounting/journal-templates(/|$)#', 'accounting.templates', AccessLevel::READ],
        ['*', '#^/api/accounting/journal-templates(/|$)#', 'accounting.templates', AccessLevel::WRITE],
        // Mzdová rekapitulace: náhled je POST (nese vstupy v těle), ale nic nemění →
        // READ. Na této úrovni závisí demo brána; při budoucím zápisu změnit na WRITE.
        // Zaúčtování sdílí právo s ostatním účtováním deníku.
        ['*', '#^/api/accounting/payroll/preview$#', 'accounting', AccessLevel::READ],
        ['*', '#^/api/accounting/payroll/post$#', 'accounting.journal.post', AccessLevel::WRITE],
        // Zaměstnanci starší agendy sdílejí tabulku `payroll_employees` s novým mzdovým
        // modulem. Z CESTY se stav modulu poznat nedá, takže gate zůstává `accounting`
        // (shodně s POST, které tutéž kartu zakládá) — je ale zapsaný VÝSLOVNĚ, aby ho
        // nedržel jen univerzální fallback níže. Patří-li osoba novému modulu, žádá si
        // PayrollEmployeeAction::delete() navíc `payroll.person.write`, protože pak jde
        // o smazání celé mzdové karty.
        ['GET', '#^/api/accounting/payroll/employees(/|$)#', 'accounting', AccessLevel::READ],
        ['*',   '#^/api/accounting/payroll/employees(/|$)#', 'accounting', AccessLevel::WRITE],
        // Zúčtování DPH (migrace 1332): náhled je čtení, spuštění zakládá účetní zápis
        // v deníku → stejné právo jako ostatní účtování (ne pouhé `accounting` WRITE).
        ['GET', '#^/api/accounting/vat-clearing(/|$)#', 'accounting', AccessLevel::READ],
        ['*',   '#^/api/accounting/vat-clearing(/|$)#', 'accounting.journal.post', AccessLevel::WRITE],
        ['GET', '#^/api/accounting/offsets(/|$)#', 'accounting.offsets', AccessLevel::READ],
        ['*', '#^/api/accounting/offsets(/|$)#', 'accounting.offsets', AccessLevel::WRITE],
        // Zápočet faktury proti účtu — stejné právo jako vzájemné zápočty.
        ['GET', '#^/api/accounting/settlements(/|$)#', 'accounting.offsets', AccessLevel::READ],
        ['*', '#^/api/accounting/settlements(/|$)#', 'accounting.offsets', AccessLevel::WRITE],
        ['GET', '#^/api/accounting/journal(/|$)#', 'accounting', AccessLevel::READ],
        ['*', '#^/api/accounting/journal(/|$)#', 'accounting.journal.write', AccessLevel::WRITE],
        ['GET', '#^/api/accounting(?:$|/(?!cash-|assets|bank-posting-))#', 'accounting', AccessLevel::READ],
        ['*', '#^/api/accounting(?:$|/(?!cash-|assets|bank-posting-))#', 'accounting', AccessLevel::WRITE],

        ['*', '#^/api/tax-evidence/classification(/|$)#', 'tax_evidence.classification.write', AccessLevel::WRITE],
        ['GET', '#^/api/tax-evidence/.*/export$#', 'tax_evidence.export', AccessLevel::READ],
        ['GET', '#^/api/tax-evidence(/|$)#', 'tax_evidence', AccessLevel::READ],
        ['*', '#^/api/tax-evidence(/|$)#', 'tax_evidence', AccessLevel::WRITE],
        ['*', '#^/api/(reports|tax-return)/.*/finalize$#', 'reports.finalize', AccessLevel::WRITE],
        ['*', '#^/api/(reports|tax-return)/.*/reopen$#', 'reports.reopen', AccessLevel::WRITE],
        // Featura A — rekonciliace proti podanému přiznání je POST (upload), ale read-only
        // (nic neukládá/neúčtuje) → jen READ, ne module-fallback WRITE níže. Na této
        // úrovni závisí demo brána; začne-li endpoint data ukládat, musí být WRITE.
        ['POST', '#^/api/tax-return/.*/reconcile$#', 'reports', AccessLevel::READ],
        ['GET', '#^/api/reports/submissions/settings$#', 'reports.submit', AccessLevel::WRITE],
        ['GET', '#^/api/reports/submissions/[0-9]+/artifacts/[0-9]+/download$#', 'reports.export', AccessLevel::READ],
        ['GET', '#^/api/reports/submissions(/|$)#', 'reports', AccessLevel::READ],
        ['*', '#^/api/reports/submissions(/|$)#', 'reports.submit', AccessLevel::WRITE],
        ['GET', '#^/api/reports/monthly-export(/|$)#', 'reports.export', AccessLevel::READ],
        ['*', '#^/api/reports/monthly-export(/|$)#', 'reports.export', AccessLevel::WRITE],
        ['GET', '#^/api/reports/closing-package(/|$)#', 'reports.export', AccessLevel::READ],
        ['*', '#^/api/reports/closing-package(/|$)#', 'reports.export', AccessLevel::WRITE],
        ['GET', '#^/api/(reports|tax-return)(/|$).*(xml|export|pdf|download)#', 'reports.export', AccessLevel::READ],
        ['GET', '#^/api/(reports|tax-return|tax)(/|$)#', 'reports', AccessLevel::READ],
        ['*', '#^/api/(reports|tax-return|tax)(/|$)#', 'reports', AccessLevel::WRITE],

        ['GET', '#^/api/accounting/cash-(documents|registers)(/|$)#', 'cash', AccessLevel::READ],
        ['*', '#^/api/accounting/cash-documents(/|$)#', 'cash.document.write', AccessLevel::WRITE],
        // M-8: routa uzavření/uzamčení pokladny (inventarizace § 29–30 ZoÚ) zatím
        // NEEXISTUJE — pravidlo je připravené pro ni. Právo `cash.close` samo mrtvé
        // není: gatuje tvrdé smazání zaúčtovaného dokladu (`DELETE …?force=1`, H-4),
        // které se kontroluje v CashDocumentAction — cesta je totiž shodná s běžným
        // mazáním draftu, takže se od sebe podle URL odlišit nedají.
        ['*', '#^/api/accounting/cash-registers/[0-9]+/(close|lock)$#', 'cash.close', AccessLevel::WRITE],
        ['*', '#^/api/accounting/cash-(documents|registers)(/|$)#', 'cash', AccessLevel::WRITE],
        ['GET', '#^/api/accounting/assets(/|$)#', 'assets', AccessLevel::READ],
        ['*', '#^/api/accounting/assets/[0-9]+/(depreciation|improvements)(/|$)#', 'assets.depreciation', AccessLevel::WRITE],
        ['*', '#^/api/accounting/assets/[0-9]+/(dispose|sale)$#', 'assets.dispose', AccessLevel::WRITE],
        ['*', '#^/api/accounting/assets(/|$)#', 'assets.write', AccessLevel::WRITE],

        ['GET', '#^/api/stock(/|$)#', 'stock', AccessLevel::READ],
        // Objednávky dodavatelům. MUSÍ být před `^/api/stock/.*/close$` — jinak by
        // „zavřít nedodaný zbytek objednávky" spadlo pod skladovou uzávěrku
        // (`stock.close`), což je úplně jiné oprávnění — i před catch-all `^/api/stock`.
        ['*', '#^/api/stock/purchase-orders(/|$)#', 'stock.orders.write', AccessLevel::WRITE],
        ['*', '#^/api/stock/items(/|$)#', 'stock.items.write', AccessLevel::WRITE],
        ['*', '#^/api/stock/documents(/|$)#', 'stock.documents.write', AccessLevel::WRITE],
        ['*', '#^/api/stock/.*/close$#', 'stock.close', AccessLevel::WRITE],
        ['*', '#^/api/stock/takes(/|$)#', 'stock.take', AccessLevel::WRITE],
        // „U dodavatele" — nabídky dodavatelů; musí být PŘED catch-all pravidlem
        // ^/api/stock, jinak by zápis spadl pod obecné právo `stock`.
        ['*', '#^/api/stock/vendor-offers(/|$)#', 'stock.vendors.write', AccessLevel::WRITE],
        ['*', '#^/api/stock(/|$)#', 'stock', AccessLevel::WRITE],
        ['GET', '#^/api/eshop(/|$)#', 'eshop', AccessLevel::READ],
        ['*', '#^/api/eshop(/|$)#', 'eshop.write', AccessLevel::WRITE],
        ['POST', '#^/api/logbook/.*/import#', 'logbook.import', AccessLevel::WRITE],
        ['DELETE', '#^/api/logbook(/|$)#', 'logbook.delete', AccessLevel::WRITE],
        ['GET', '#^/api/logbook(/|$)#', 'logbook', AccessLevel::READ],
        ['*', '#^/api/logbook(/|$)#', 'logbook.write', AccessLevel::WRITE],

        ['GET', '#^/api/settings/currencies(/|$)#', 'settings.bank_accounts', AccessLevel::READ],
        ['*', '#^/api/settings/(bank-accounts|currencies)(/|$)#', 'settings.bank_accounts', AccessLevel::WRITE],
        ['GET', '#^/api/settings/email-branding/preview$#', 'settings.branding', AccessLevel::READ],
        ['*', '#^/api/settings/(email-branding|supplier/logo)(/|$)#', 'settings.branding', AccessLevel::WRITE],
        ['GET', '#^/api/settings/ai-assist$#', 'settings.ai_provider', AccessLevel::READ],
        ['*', '#^/api/settings/.*/ai|^/api/settings/ai#', 'settings.ai_provider', AccessLevel::WRITE],
        // Trezor certifikátů se řídí oprávněním k podpisům, ne k firmě. Bez
        // tohohle pravidla propadne na obecný fallback `/api/settings` a hlídá
        // ho jiné oprávnění, než jaké kontroluje sama akce — dvě různá pravidla
        // na tentýž endpoint se dřív nebo později rozejdou.
        ['GET', '#^/api/settings/certificates(/|$)#', 'settings.signing', AccessLevel::READ],
        ['*', '#^/api/settings/certificates(/|$)#', 'settings.signing', AccessLevel::WRITE],
        // Datová schránka ze stejného důvodu jako trezor: certifikát ke schránce
        // je stejná třída tajemství jako podpisový klíč a akce samy kontrolují
        // `settings.signing`. Bez tohohle pravidla by na ně padl obecný fallback
        // `/api/settings` s jiným oprávněním.
        ['GET', '#^/api/settings/databox(/|$)#', 'settings.signing', AccessLevel::READ],
        ['*', '#^/api/settings/databox(/|$)#', 'settings.signing', AccessLevel::WRITE],
        ['GET', '#^/api/settings/(signing|pdf-signing)(/|$)#', 'settings.signing', AccessLevel::READ],
        ['*', '#^/api/settings/(signing|pdf-signing)(/|$)#', 'settings.signing', AccessLevel::WRITE],
        ['GET', '#^/api/settings/accounting-activation/status$#', 'settings.company', AccessLevel::READ],
        ['*', '#^/api/settings/accounting-activation(/|$)#', 'accounting.periods.manage', AccessLevel::WRITE],
        ['GET', '#^/api/settings(/|$)#', 'settings.company', AccessLevel::READ],
        ['*', '#^/api/settings(/|$)#', 'settings.company.write', AccessLevel::WRITE],

        ['GET', '#^/api/dashboard(/|$)#', 'dashboard', AccessLevel::READ],
        ['GET', '#^/api/(portfolio|crm)(/|$)#', 'dashboard.portfolio', AccessLevel::READ],
        ['*', '#^/api/(portfolio|crm)(/|$)#', 'dashboard.portfolio', AccessLevel::WRITE],
        ['GET', '#^/api/(codebooks|expense-categories|revenue-categories|vat-classifications)(/|$)#', 'settings.company', AccessLevel::READ],
        ['*', '#^/api/(codebooks|expense-categories|revenue-categories|vat-classifications)(/|$)#', 'settings.company.write', AccessLevel::WRITE],
        ['GET', '#^/api/(suppliers|search|slug)(/|$)#', 'profile', AccessLevel::READ],
        ['GET', '#^/api/branding-profiles$#', 'profile', AccessLevel::READ],
        ['*', '#^/api/user/(filters|preferences)(/|$)#', 'profile', AccessLevel::WRITE],
        ['GET', '#^/api/portal/purchase-invoice-submissions(/|$)#', 'documents.submit', AccessLevel::READ],
        ['POST', '#^/api/portal/purchase-invoice-submissions(/|$)#', 'documents.submit', AccessLevel::WRITE],
        ['GET', '#^/api/portal/document-requests$#', 'documents.submit', AccessLevel::READ],
        ['POST', '#^/api/portal/document-requests/[0-9]+/upload$#', 'documents.submit', AccessLevel::WRITE],
        ['GET', '#^/api/portal(/|$)#', 'profile', AccessLevel::READ],
        ['GET', '#^/api/(work-reports)(/|$)#', 'projects', AccessLevel::READ],
        ['*', '#^/api/(work-reports)(/|$)#', 'projects', AccessLevel::WRITE],
    ];

    /**
     * Výjimky ze superadmin fallbacku pro `/api/admin/*` — routy, které jsou prací
     * s daty firmy, ne konfigurací systému, a jejichž Action vrstva už oprávnění
     * sama deklaruje (`RequestAuthorization::allows`). Bez tohoto seznamu je
     * fallback níže zkratoval dřív, než se guard v akci vůbec dostal ke slovu:
     * `utilities.import` byl fakticky mrtvý klíč, protože ho nešlo uplatnit.
     *
     * Klíč i úroveň musí odpovídat guardu v příslušné akci — jinak by middleware
     * pustil dál request, který akce stejně odmítne (nebo naopak).
     *
     * Fail-closed: co se sem netrefí, spadne dál na SUPERADMIN jako dosud.
     * Vědomě sem nepatří konfigurace samotného systému (`/api/maintenance/*`,
     * uživatelé, role, licence) ani zbytek `/api/admin/*`.
     *
     * @var list<array{0:string,1:string,2:string,3:AccessLevel}>
     */
    private const ADMIN_RULES = [
        // Import dokladů (Pohoda XML / ISDOC, iDoklad, Fakturoid) — ImportAction,
        // Start{Idoklad,Fakturoid}ImportAction, ImportJobStatus/Cancel/DeleteImportJobAction.
        ['POST',   '#^/api/admin/import$#', 'utilities.import', AccessLevel::WRITE],
        ['POST',   '#^/api/admin/imports/(idoklad|fakturoid)/start$#', 'utilities.import', AccessLevel::WRITE],
        ['GET',    '#^/api/admin/imports/[0-9]+$#', 'utilities.import', AccessLevel::READ],
        ['POST',   '#^/api/admin/imports/[0-9]+/cancel$#', 'utilities.import', AccessLevel::WRITE],
        ['DELETE', '#^/api/admin/imports/[0-9]+$#', 'utilities.import', AccessLevel::WRITE],
        // Credentials importních integrací — {Idoklad,Fakturoid}CredentialsAction hlídají
        // WRITE i u `status` (odpověď prozrazuje, že je integrace nastavená).
        ['*', '#^/api/admin/imports/(idoklad|fakturoid)/credentials$#', 'utilities.import', AccessLevel::WRITE],
        // AI extrakce z PDF — sdílí oprávnění s tou stranou dokladů, do které zapisuje
        // (AiExtractPdfAction = přijaté, AiExtractPdfIssuedAction = vystavené).
        ['POST', '#^/api/admin/imports/ai-extract-pdf$#', 'purchase_invoices.scan', AccessLevel::WRITE],
        ['POST', '#^/api/admin/imports/ai-extract-pdf-issued$#', 'invoices.create', AccessLevel::WRITE],
        // Klíče k AI poskytovatelům sem vědomě NEPATŘÍ — zůstávají superadmin-only
        // (F7, viz F7NestedRbacTest). Guard `settings.ai_provider` v akcích je druhá
        // vrstva pro volání mimo middleware, ne pozvánka pustit sem účetní.
    ];

    public function match(string $method, string $path): ?RoutePermission
    {
        $method = strtoupper($method);
        if (in_array($path, self::PUBLIC_PATHS, true) || str_starts_with($path, '/api/public/')) {
            return new RoutePermission(self::PUBLIC);
        }
        if (in_array($path, self::SELF_PATHS, true)) {
            return new RoutePermission(self::SELF_SERVICE);
        }
        // Správa vlastních přístupových klíčů (přejmenování, smazání) je self-service —
        // vazba na přihlášeného uživatele se kontroluje až v akci podle ID klíče.
        if (preg_match('#^/api/auth/webauthn/credentials/[0-9]+$#', $path) === 1) {
            return new RoutePermission(self::SELF_SERVICE);
        }
        if ($path === '/api/auth/setup-sample') {
            return new RoutePermission(self::SUPERADMIN);
        }
        if (str_starts_with($path, '/api/admin/') || str_starts_with($path, '/api/maintenance/')) {
            foreach (self::ADMIN_RULES as [$ruleMethod, $pattern, $key, $level]) {
                if (($ruleMethod === '*' || $ruleMethod === $method) && preg_match($pattern, $path) === 1) {
                    return new RoutePermission(self::PERMISSION, $key, $level);
                }
            }
            return new RoutePermission(self::SUPERADMIN);
        }
        // ⚠️ Dunning stav (co dlužím, dokdy, kde zaplatit) je jediná výjimka
        // z licenční superadmin brány: admin, který instalaci spravuje, musí
        // vidět, že se nezdařila platba — jinak se to dozví až tím, že aplikace
        // přestane fungovat. Rozsah drží {@see BillingSnapshot::dunning()},
        // klientské účty odmítá sama akce ({@see LicenseBillingAction}).
        if ($method === 'GET' && $path === '/api/license/billing') {
            return new RoutePermission(self::SELF_SERVICE);
        }
        // Licencování a aktivace (E4) — admin only.
        if (str_starts_with($path, '/api/license/')) {
            return new RoutePermission(self::SUPERADMIN);
        }
        if ($method !== 'GET' && preg_match('#^/api/price-list-items(/|$)#', $path) === 1) {
            return new RoutePermission(self::SUPERADMIN);
        }
        if ($method !== 'GET' && preg_match('#^/api/suppliers(/[0-9]+)?$#', $path) === 1) {
            return new RoutePermission(self::SUPERADMIN);
        }
        foreach (self::RULES as [$ruleMethod, $pattern, $key, $level]) {
            if (($ruleMethod === '*' || $ruleMethod === $method) && preg_match($pattern, $path) === 1) {
                return new RoutePermission(self::PERMISSION, $key, $level);
            }
        }
        return null;
    }
}

final class RoutePermission
{
    public function __construct(
        public readonly string $kind,
        public readonly ?string $key = null,
        public readonly AccessLevel $minimum = AccessLevel::NONE,
    ) {}
}
