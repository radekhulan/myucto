import { endpoint } from './endpoint-tool.mjs';

export const API_CODEBOOKS_TOOLS = [
  endpoint('list_oss_member_state_vat_rates', 'Sazby DPH členských států pro OSS (kontrolní číselník)',
    'Sazby DPH členských států pro OSS (kontrolní číselník). GLOBÁLNÍ číselník (ne per-supplier), proti kterému se ověřuje sazba na OSS řádku dokladu. není to nabídka sazeb PRO doklad (ta je v `/codebooks/vat-rates`).',
    '/codebooks/oss-member-state-rates', {
    query: { country: { type: 'string', description: 'Dvoupísmenný kód členského státu EU, například DE.', minLength: 2, maxLength: 2 } },
  }),
  endpoint('list_public_holidays', 'Číselník státních a ostatních svátků (z. č. 245/2000 Sb.)',
    'Číselník státních a ostatních svátků (z. č. 245/2000 Sb.). GLOBÁLNÍ číselník (ne per-supplier).',
    '/codebooks/public-holidays', {
    query: { year: { type: 'integer', description: 'Rok.', minimum: 1900, maximum: 2200 } },
  }),
  endpoint('list_codebooks_units', 'Seznam měrných jednotek',
    'Seznam měrných jednotek.',
    '/codebooks/units'),
  endpoint('list_effective_vat_rates', 'Seznam DPH sazeb',
    'Seznam DPH sazeb.',
    '/codebooks/vat-rates', {
    query: {
      country: { type: 'string', pattern: '^(?:[A-Z]{2}|ALL)$', description: 'Dvojpísmenný kód země nebo ALL. Výchozí CZ.' },
      active_on: { type: 'string', format: 'date', description: 'Platnost sazby k datu. Výchozí dnes.' },
    },
  }),
  endpoint('list_invoice_years', 'Roky vydaných a přijatých faktur',
    'Vrátí roky, ve kterých má aktuální firma vystavené vydané nebo přijaté faktury.',
    '/codebooks/years'),
  endpoint('list_settings_currencies', 'Měny nakonfigurované pro aktuálního dodavatele',
    'Měny nakonfigurované pro aktuálního dodavatele. Per-supplier override hlavního codebooku. obsahuje i bank-accounts per currency.',
    '/settings/currencies'),
  endpoint('search_nace_codes', 'Vyhledávání ekonomických činností NACE pro EPO',
    'Vyhledávání ekonomických činností NACE pro EPO.',
    '/settings/nace-codes', {
    query: {
      q: { type: 'string', description: 'Hledaný text.' },
      limit: { type: 'integer', description: 'Nejvyšší počet záznamů.', default: 20, minimum: 1, maximum: 100 },
    },
  }),
];
