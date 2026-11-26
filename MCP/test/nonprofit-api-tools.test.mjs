import { testEndpoints } from './support/endpoint-cases.mjs';

testEndpoints([
  ['get_nonprofit_tax_assessment_summary',
    { id: 1 }, 'GET', '/accounting/periods/1/nonprofit-tax', null],
  ['get_nonprofit_tax_source_summary',
    { period_id: 1 }, 'GET', '/accounting/periods/1/nonprofit-tax/source', null],
]);
