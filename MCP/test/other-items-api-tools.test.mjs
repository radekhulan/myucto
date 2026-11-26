import { testEndpoints } from './support/endpoint-cases.mjs';

testEndpoints([
  ['list_other_items_bank_candidates',
    { bank_transaction_id: 1, q: 'Test', limit: 1 }, 'GET', '/accounting/other-items/bank-candidates', { bank_transaction_id: 1, q: 'Test', limit: 1 }],
  ['list_other_item_kind_posting_profiles',
    { id: 1 }, 'GET', '/accounting/other-items/kinds/1/posting-profiles', null],
  ['list_other_items_settings',
    {}, 'GET', '/accounting/other-items/settings', null],
  ['preview_other_item_recurrence_title',
    { item_id: 1, frequency: 'monthly', title_template: 'Test', title_sync_period: false, ends_on: '2026-02-01' }, 'GET', '/accounting/other-items/1/schedule/title-preview', { frequency: 'monthly', title_template: 'Test', title_sync_period: 0, ends_on: '2026-02-01' }],
]);
