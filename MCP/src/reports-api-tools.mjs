import { endpoint } from './endpoint-tool.mjs';

export const API_REPORTS_TOOLS = [
  endpoint('list_tax_payment_alerts', 'CRM upozornění na splatný daňový doplatek',
    'CRM upozornění na splatný daňový doplatek.',
    '/crm/action-items/tax-balance'),
  endpoint('list_reports_oss_evidence', 'Evidence OSS podle § 110f ZDPH / čl. 63c',
    'Evidence OSS podle § 110f ZDPH / čl. 63c. Evidenční záznamy období tak, jak je vyžaduje čl. 63c prováděcího nařízení (EU) 282/2011.',
    '/reports/oss/evidence', {
    query: {
      year: { type: 'integer', description: 'Rok.', minimum: 2020, maximum: 2050 },
      quarter: { type: 'integer', description: 'Číslo kalendářního čtvrtletí od 1 do 4.', minimum: 1, maximum: 4 },
    },
  }),
  endpoint('reconcile_oss_submission', 'Porovnání podaného přiznání OSS s aktuální evidencí',
    'Porovnání podaného přiznání OSS s aktuální evidencí.',
    '/reports/oss/reconciliation', {
    query: {
      year: { type: 'integer', description: 'Rok.', minimum: 2020, maximum: 2050 },
      quarter: { type: 'integer', description: 'Číslo kalendářního čtvrtletí od 1 do 4.', minimum: 1, maximum: 4 },
    },
  }),
  endpoint('list_reports_oss_submissions', 'Archiv OSS podání',
    'Vrátí archivovaná podání OSS aktuální firmy, nejnovější první, nejvýše 200 záznamů.',
    '/reports/oss/submissions'),
  endpoint('list_reports_related_parties_adjustments', 'Úpravy základu daně u spojených osob',
    'Úpravy základu daně u spojených osob.',
    '/reports/related-parties/adjustments', {
    query: { year: { type: 'integer', description: 'Rok.' } },
    required: ['year'],
  }),
  endpoint('preview_s46_vat_restorations', 'Náhled obnovení daně z pohledávek podle § 46',
    'Náhled obnovení daně z pohledávek podle § 46.',
    '/reports/s46/restorations', {
    query: {
      year: { type: 'integer', description: 'Rok.' },
      month: { type: 'integer', description: 'Měsíc od 1 do 12.', minimum: 1, maximum: 12 },
    },
    required: ['year', 'month'],
  }),
  endpoint('get_submitted_vat_control_evidence', 'Soupis dokladů podle podaného kontrolního hlášení s porovnáním proti aktuálním datům',
    'Soupis dokladů podle podaného kontrolního hlášení s porovnáním proti aktuálním datům. Jen interní role; klientovi jsou soupis zdrojových dokladů a porovnání s aktuálními daty nepřístupné.',
    '/reports/submissions/{id}/kh-evidence', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
    query: {
      section: {
        type: 'string',
        description: 'Oddíl kontrolního hlášení. `all` zahrne všechny oddíly.',
        enum: ['all', 'A.1', 'A.2', 'A.4', 'A.5', 'B.1', 'B.2', 'B.3'],
        default: 'all',
      },
    },
  }),
  endpoint('list_tax_evidence_bank_rules', 'Pravidla bankovních pohybů v daňové evidenci',
    'Pravidla bankovních pohybů v daňové evidenci.',
    '/tax-evidence/bank-rules'),
  endpoint('get_tax_evidence_cash_journal_notes', 'Poznámky k pohybu peněžního deníku',
    'Poznámky k pohybu peněžního deníku. Pohyb je dvojice typu řádku deníku a jeho `source_id`.',
    '/tax-evidence/cash-journal/notes/{source}/{id}', {
    params: {
      source: {
        type: 'string',
        description: 'Zdroj pohybu v peněžním deníku, například pokladna (`cash`), banka (`bank`) nebo úhrada faktury (`invoice_payment`).',
        enum: ['cash', 'bank', 'invoice_payment', 'purchase_invoice', 'gopay', 'credit_note_offset'],
      },
      id: { type: 'integer', description: 'ID záznamu.', minimum: 1 },
    },
  }),
  endpoint('list_tax_foreign_income_catalog', 'Číselníky oznámení příjmů do zahraničí',
    'Číselníky oznámení příjmů do zahraničí.',
    '/tax/foreign-income/catalog'),
];
