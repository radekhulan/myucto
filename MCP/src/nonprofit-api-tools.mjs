import { endpoint } from './endpoint-tool.mjs';

export const API_NONPROFIT_TOOLS = [
  endpoint('get_nonprofit_tax_assessment_summary', 'Podklady a poslední daňové posouzení činností',
    'Podklady a poslední daňové posouzení činností. Pouze čtení v kontextu vybrané firmy s oprávněním accounting.',
    '/accounting/periods/{id}/nonprofit-tax', {
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('get_nonprofit_tax_source_summary', 'Hlavička aktuálních účetních podkladů daňového posouzení',
    'Hlavička aktuálních účetních podkladů daňového posouzení.',
    '/accounting/periods/{period_id}/nonprofit-tax/source', {
    params: { period_id: { type: 'integer', description: 'ID účetního období.', minimum: 1 } },
  }),
];
