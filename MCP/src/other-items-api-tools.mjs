import { endpoint } from './endpoint-tool.mjs';

export const API_OTHER_ITEMS_TOOLS = [
  endpoint('list_other_items_bank_candidates', 'Ostatní pohledávky a závazky k přiřazení bankovního pohybu',
    'Ostatní pohledávky a závazky k přiřazení bankovního pohybu. Protějšek `payment-candidates` z pohledu bankovního pohybu.',
    '/accounting/other-items/bank-candidates', {
    query: {
      bank_transaction_id: { type: 'integer', description: 'ID bankovního pohybu.', minimum: 1 },
      q: { type: 'string', description: 'Hledaný text.' },
      limit: { type: 'integer', description: 'Nejvyšší počet záznamů.', minimum: 1, maximum: 50, default: 25 },
    },
    required: ['bank_transaction_id'],
  }),
  endpoint('list_other_item_kind_posting_profiles', 'Účetní profily druhu',
    'Účetní profily druhu. Kontace vzniku a úhrad, kterou druh předává svým položkám, pokud položka nemá vlastní.',
    '/accounting/other-items/kinds/{id}/posting-profiles', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('list_other_items_settings', 'Nastavení ostatních pohledávek a závazků',
    'Nastavení ostatních pohledávek a závazků. Šablona složky v Dokumentech, kam se ukládají soubory nahrané k ostatní pohledávce nebo závazku. `{YYYY}` a `{MM}` se nahradí rokem a měsícem data vystavení dokladu. `is_default` značí výchozí složku.',
    '/accounting/other-items/settings'),
  endpoint('preview_other_item_recurrence_title', 'Náhled názvu nejbližší položky opakování',
    'Náhled názvu nejbližší položky opakování.',
    '/accounting/other-items/{item_id}/schedule/title-preview', {
    params: { item_id: { type: 'integer', description: 'ID ostatní pohledávky nebo závazku.', minimum: 1 } },
    query: {
      frequency: { type: 'string', description: 'Interval opakování dokladu: měsíčně (`monthly`), čtvrtletně (`quarterly`) nebo ročně (`yearly`).', enum: ['monthly', 'quarterly', 'yearly'] },
      title_template: {
        type: 'string',
        description: 'Šablona názvu pro náhled. Prázdný text ji vypne. Při vynechání se použije šablona stávajícího opakování, pokud existuje.',
        maxLength: 255,
      },
      title_sync_period: { type: 'boolean', description: 'U existujícího rozvrhu vynechání = uložená volba, u nového = false.' },
      ends_on: {
        type: 'string',
        description: 'Konec nového rozvrhu; délka názvu se kontroluje jen do něj. U existujícího rozvrhu se nepoužije.',
        format: 'date',
      },
    },
  }),
];
