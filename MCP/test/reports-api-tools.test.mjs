import { testEndpoints } from './support/endpoint-cases.mjs';

testEndpoints([
  ['list_tax_payment_alerts',
    {}, 'GET', '/crm/action-items/tax-balance', null],
  ['list_reports_oss_evidence',
    { year: 2020, quarter: 1 }, 'GET', '/reports/oss/evidence', { year: 2020, quarter: 1 }],
  ['reconcile_oss_submission',
    { year: 2020, quarter: 1 }, 'GET', '/reports/oss/reconciliation', { year: 2020, quarter: 1 }],
  ['list_reports_oss_submissions',
    {}, 'GET', '/reports/oss/submissions', null],
  ['list_reports_related_parties_adjustments',
    { year: 1 }, 'GET', '/reports/related-parties/adjustments', { year: 1 }],
  ['preview_s46_vat_restorations',
    { year: 1, month: 1 }, 'GET', '/reports/s46/restorations', { year: 1, month: 1 }],
  ['get_submitted_vat_control_evidence',
    { id: 1, section: 'all' }, 'GET', '/reports/submissions/1/kh-evidence', { section: 'all' }],
  ['list_tax_evidence_bank_rules',
    {}, 'GET', '/tax-evidence/bank-rules', null],
  ['get_tax_evidence_cash_journal_notes',
    { source: 'cash', id: 1 }, 'GET', '/tax-evidence/cash-journal/notes/cash/1', null],
  ['list_tax_foreign_income_catalog',
    {}, 'GET', '/tax/foreign-income/catalog', null],
]);
