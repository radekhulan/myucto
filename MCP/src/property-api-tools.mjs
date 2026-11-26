import { endpoint } from './endpoint-tool.mjs';

export const API_PROPERTY_TOOLS = [
  endpoint('list_property_receivable_parties', 'Osobní saldo pohledávek a kreditů SVJ',
    'Osobní saldo pohledávek a kreditů SVJ. Pouze čtení s oprávněním property v kontextu vybrané firmy.',
    '/property/catalog/payments/reconciliation/parties', {
    query: {
      date: { type: 'string', description: 'Rozhodné datum, ke kterému se vyhodnotí stav pohledávek a kreditů.', format: 'date' },
      limit: { type: 'integer', description: 'Nejvyšší počet záznamů.', minimum: 1, maximum: 100, default: 50 },
      after: {
        type: 'string',
        description: 'Kurzor next_cursor z předchozí stránky (otisk množiny osob posledního řádku).',
        pattern: '^[0-9a-f]{64}$',
      },
    },
    required: ['date'],
  }),
];
