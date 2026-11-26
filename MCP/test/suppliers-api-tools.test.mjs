import { testEndpoints } from './support/endpoint-cases.mjs';

testEndpoints([
  ['get_supplier',
    { id: 1 }, 'GET', '/suppliers/1', null],
]);
