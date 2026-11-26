import { testEndpoints } from './support/endpoint-cases.mjs';

testEndpoints([
  ['list_property_receivable_parties',
    { date: '2026-02-01', limit: 1, after: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' }, 'GET', '/property/catalog/payments/reconciliation/parties', { date: '2026-02-01', limit: 1, after: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' }],
]);
