import { endpoint } from './endpoint-tool.mjs';

export const API_SUPPLIERS_TOOLS = [
  endpoint('get_supplier', 'Detail konkrétního dodavatele',
    'Vrátí detail jedné firmy, ke které má uživatel přístup.',
    '/suppliers/{id}', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
];
