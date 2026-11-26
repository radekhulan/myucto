import { testEndpoints } from './support/endpoint-cases.mjs';

testEndpoints([
  ['list_oss_member_state_vat_rates',
    { country: 'CZ' }, 'GET', '/codebooks/oss-member-state-rates', { country: 'CZ' }],
  ['list_public_holidays',
    { year: 1900 }, 'GET', '/codebooks/public-holidays', { year: 1900 }],
  ['list_codebooks_units',
    {}, 'GET', '/codebooks/units', null],
  ['list_effective_vat_rates',
    { country: 'CZ', active_on: '2026-02-01' }, 'GET', '/codebooks/vat-rates', { country: 'CZ', active_on: '2026-02-01' }],
  ['list_invoice_years',
    {}, 'GET', '/codebooks/years', null],
  ['list_settings_currencies',
    {}, 'GET', '/settings/currencies', null],
  ['search_nace_codes',
    { q: 'Test', limit: 1 }, 'GET', '/settings/nace-codes', { q: 'Test', limit: 1 }],
]);
