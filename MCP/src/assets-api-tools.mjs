import { endpoint } from './endpoint-tool.mjs';

export const API_ASSETS_TOOLS = [
  endpoint('summarize_asset_accounts', 'Účty neodpisovaného majetku a jejich souhrnné karty',
    'Účty neodpisovaného majetku a jejich souhrnné karty. Účty 031/032 (a analytiky) se zůstatkem v deníku nebo se souhrnnou kartou: zůstatek, počet pohybů, počet vlastních karet (`eligible` = účet bez aktivních karet), id a hodnota souhrnné karty.',
    '/accounting/assets/account-summary'),
  endpoint('download_asset_depreciation_card', 'Inventární karta majetku ke stažení (§29–30 ZoÚ). PDF',
    'Inventární karta majetku ke stažení (§29–30 ZoÚ). PDF.',
    '/accounting/assets/{id}/depreciation-card', {
    file: true,
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('summarize_small_asset_expenses', 'Rozpad nákladu 501 drobného majetku podle druhu za období',
    'Rozpad nákladu 501 drobného majetku podle druhu za období. Jen podvojné účetnictví; v daňové evidenci vrátí `wrong_accounting_mode` (403).',
    '/accounting/reports/small-assets/expense-breakdown', {
    query: {
      from: { type: 'string', description: 'Začátek období.', format: 'date' },
      to: { type: 'string', description: 'Konec období.', format: 'date' },
    },
    required: ['from', 'to'],
  }),
];
