import { testEndpoints } from './support/endpoint-cases.mjs';

testEndpoints([
  ['summarize_asset_accounts',
    {}, 'GET', '/accounting/assets/account-summary', null],
  ['download_asset_depreciation_card',
    { id: 1 }, 'DOWNLOAD', '/accounting/assets/1/depreciation-card', null],
  ['summarize_small_asset_expenses',
    { from: '2026-02-01', to: '2026-02-01' }, 'GET', '/accounting/reports/small-assets/expense-breakdown', { from: '2026-02-01', to: '2026-02-01' }],
]);
