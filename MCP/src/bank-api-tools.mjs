import { endpoint } from './endpoint-tool.mjs';

export const API_BANK_TOOLS = [
  endpoint('list_bank_accounts', 'Registr vlastních bankovních účtů firmy',
    'Registr vlastních bankovních účtů firmy. Vrací účty používané pro rozpoznání převodů mezi vlastními účty.',
    '/accounting/bank-accounts'),
  endpoint('get_bank_account_dimensions', 'Výchozí dimenze vlastního bankovního účtu',
    'Výchozí dimenze vlastního bankovního účtu. Hodnoty dimenzí (Firma → Dimenze), které zaúčtování doplní pohybu na tomto účtu, když hodnotu typu nemá sám ani z hrazených dokladů (úrok, srážková daň, poplatek).',
    '/accounting/bank-accounts/{id}/dimensions', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('list_bank_posting_rules', 'Seznam pravidel účtování opakovaných transakcí',
    'Seznam pravidel účtování opakovaných transakcí.',
    '/accounting/bank-posting-rules', {
    query: {
      direction: { type: 'string', description: 'Směr pohybu: příchozí (`incoming`) nebo odchozí (`outgoing`).', enum: ['incoming', 'outgoing'] },
      active: { type: 'boolean', description: 'Aktivní.' },
      page: { type: 'integer', description: 'Číslo stránky od 1.', minimum: 1 },
      per_page: { type: 'integer', description: 'Počet záznamů na stránce.', minimum: 1, maximum: 100 },
    },
  }),
  endpoint('get_bank_posting_rule_history', 'Auditní historie pravidla a jeho korekcí',
    'Auditní historie pravidla a jeho korekcí.',
    '/accounting/bank-posting-rules/{id}/history', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
    query: {
      page: { type: 'integer', description: 'Číslo stránky od 1.', minimum: 1 },
      per_page: { type: 'integer', description: 'Počet záznamů na stránce.', minimum: 1, maximum: 100 },
    },
  }),
  endpoint('list_bank_rule_templates', 'Globální šablony bankovních pravidel s rozřešenými identifikátory firmy',
    'Globální šablony bankovních pravidel s rozřešenými identifikátory firmy.',
    '/accounting/bank-rule-templates'),
  endpoint('list_gopay_clearings', 'Seznam importovaných GoPay vyúčtování (XML) a výpisů (XLS/XLSX)',
    'Seznam importovaných GoPay vyúčtování (XML) a výpisů (XLS/XLSX).',
    '/accounting/gopay/clearings'),
  endpoint('get_gopay_clearing', 'Detail GoPay vyúčtování včetně jednotlivých pohybů',
    'Detail GoPay vyúčtování včetně jednotlivých pohybů.',
    '/accounting/gopay/clearings/{id}', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('list_gopay_pending', 'Nezaúčtované pohyby GoPay',
    'Nezaúčtované pohyby GoPay.',
    '/accounting/gopay/pending'),
  endpoint('download_bank_statement_pdf', 'Stáhne přiložené PDF výpisu',
    'Stáhne přiložené PDF výpisu. Číslo účtu se prepend­uje do názvu souboru, pokud v něm ještě není (odlišení výpisů z různých účtů).',
    '/bank-statements/{id}/pdf', {
    file: true,
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
];
