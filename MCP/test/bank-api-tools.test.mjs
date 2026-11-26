import { testEndpoints } from './support/endpoint-cases.mjs';

testEndpoints([
  ['list_bank_accounts',
    {}, 'GET', '/accounting/bank-accounts', null],
  ['get_bank_account_dimensions',
    { id: 1 }, 'GET', '/accounting/bank-accounts/1/dimensions', null],
  ['list_bank_posting_rules',
    { direction: 'incoming', active: false, page: 1, per_page: 1 }, 'GET', '/accounting/bank-posting-rules', { direction: 'incoming', active: 0, page: 1, per_page: 1 }],
  ['get_bank_posting_rule_history',
    { id: 1, page: 1, per_page: 1 }, 'GET', '/accounting/bank-posting-rules/1/history', { page: 1, per_page: 1 }],
  ['list_bank_rule_templates',
    {}, 'GET', '/accounting/bank-rule-templates', null],
  ['list_gopay_clearings',
    {}, 'GET', '/accounting/gopay/clearings', null],
  ['get_gopay_clearing',
    { id: 1 }, 'GET', '/accounting/gopay/clearings/1', null],
  ['list_gopay_pending',
    {}, 'GET', '/accounting/gopay/pending', null],
  ['download_bank_statement_pdf',
    { id: 1 }, 'DOWNLOAD', '/bank-statements/1/pdf', null],
]);
