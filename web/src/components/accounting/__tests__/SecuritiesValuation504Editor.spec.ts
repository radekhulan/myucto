import { afterEach, expect, it } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import SecuritiesValuation504Editor from '../SecuritiesValuation504Editor.vue'
import DateInput from '@/components/ui/DateInput.vue'
import type { SecuritiesAssessment, SecuritiesEvidence } from '@/api/securities504'
import cs from '@/i18n/cs.json'

const wrappers: VueWrapper[] = []
const evidence = (): SecuritiesEvidence => ({
  profile: { scope: 'full', category: 'micro', micro_exception: 'none', as_of: '2025-12-31', reference: 'SYN-CATEGORY', scope_reference: 'SYN-BOOKS', inception_reference: 'SYN-INCORPORATION', first_period_start: '2025-01-01',
    category_facts: [{ period_id: 8, starts_on: '2025-01-01', ends_on: '2025-12-31', assets_net: '24000.00', net_turnover: '0.00', average_employees: '0', statement_reference: 'SYN-STATEMENT', employees_reference: 'SYN-PAYROLL' }] },
  valuations: [{ instrument: { kind: 'equity', identity: 'SYN-HOLDING', classification: 'trading', method: 'cost_equity', reference: 'SYN-ACQUISITION', classification_reference: 'SYN-POLICY', designated_on: '2025-06-01' }, as_of: '2025-12-31',
    evidence: { reference: 'SYN-CUSTODY', valuation_date: '2025-12-31', quantity: '10.000000', acquisition_date: '2025-06-01', acquisition_cost_czk: '24000.00', acquired_quantity: '10.000000', unit_cost_foreign: '100.000000', recoverability_date: '2025-12-31', recoverability_reference: 'SYN-ISSUER', recoverable_unit_value_foreign: '100.000000' },
    members: [{ source_cell_hash: 'synthetic-cell', book_czk: '24000.00', book_foreign: '1000.00' }], target_czk: '24000.00', posted_proof: [] }],
})
const assessment = (): SecuritiesAssessment => ({ complete: true, as_of: '2025-12-31', inventory_hash: 'synthetic-hash', evidence: evidence(), findings: [], opening_entry_ids: [], valuations: [], posted_entries: [], legal_basis: { valuation_rules: 'cz_504_2024', category_rules: 'act_316_2025_II_2', period_start_from: '2024-01-01', period_end_from: '2024-12-31', period_end_to: '2026-12-31' }, available_accounts: [], monetary_members: [],
  profile_periods: [{ id: 8, starts_on: '2025-01-01', ends_on: '2025-12-31', fiscal_year: 2025 }], profile_history: [],
  cells: [{ cell_key: 'synthetic-cell', source_hash: 'synthetic-source', line_id: 71, entry_id: 31, entry_date: '2025-06-01', account_id: 1, account_code: '251', currency_code: 'EUR', dimensions: { '1': 11, '2': 21, '3': 31, '4': 41, '5': 51 }, cost_center: null, project_id: null, book_czk: '24000.00', book_foreign: '1000.00' }] })
function render(value = assessment(), disabled = false) {
  const w = mount(SecuritiesValuation504Editor, { props: { assessment: value, disabled, dimensionLabel: dimensions => Object.values(dimensions).join('/') }, global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs } })] } })
  wrappers.push(w)
  return w
}
afterEach(() => wrappers.splice(0).forEach(w => w.unmount()))

it('preserves exact amounts and the complete joint inventory tuple', async () => {
  const w = render()
  expect(w.text()).toContain('11/21/31/41/51')
  const target = w.findAll('input').find(input => input.element.value === '24000.00' && input.attributes('inputmode') === 'decimal')!
  await target.trigger('input')
  await flushPromises()
  const event = w.emitted('change')!.at(-1)!
  expect(event[1]).toBe(true)
  expect((event[0] as SecuritiesEvidence).valuations[0]!.members[0]).toEqual({ source_cell_hash: 'synthetic-cell', book_czk: '24000.00', book_foreign: '1000.00' })
})

it('rejects a visible invalid date even when DateInput retains its previous model value', async () => {
  const w = render()
  const date = w.findAllComponents(DateInput).find(component => component.props('modelValue') === '2025-12-31')!
  await date.get('input[type="text"]').setValue('31.02.2025')
  await flushPromises()
  const event = w.emitted('change')!.at(-1)!
  expect(event[1]).toBe(false)
})

it('requires explicit category and instrument decisions when starting new evidence', async () => {
  const value = assessment()
  value.evidence = null
  value.complete = false
  const w = render(value)
  await w.get('button').trigger('click')
  await flushPromises()
  const event = w.emitted('change')!.at(-1)!
  expect(event[1]).toBe(false)
  expect((event[0] as SecuritiesEvidence).profile.category).toBe('')
  expect((event[0] as SecuritiesEvidence).valuations).toEqual([])
})

it('disables editing and replaces the entire evidence when its source scope changes', async () => {
  const w = render(assessment(), true)
  expect(w.get('fieldset').attributes('disabled')).toBeDefined()
  const changed = assessment()
  changed.cells = []
  changed.evidence = null
  await w.setProps({ assessment: changed })
  await flushPromises()
  expect(w.text()).not.toContain('SYN-HOLDING')
  expect(w.find('form').exists()).toBe(false)
})

it('does not publish a pending event after its editor is unmounted', async () => {
  const w = render()
  await flushPromises()
  const before = w.emitted('change')?.length ?? 0
  const input = w.findAll('input')[0]!.element
  input.dispatchEvent(new Event('input', { bubbles: true }))
  w.unmount()
  await flushPromises()
  expect(w.emitted('change')?.length ?? 0).toBe(before)
})

it('can request an expanded account inventory before choosing its source cells', async () => {
  const value = assessment()
  value.cells = []
  value.evidence = null
  value.available_accounts = [{ id: 9, account_code: '315', account_type: 'asset' }]
  const w = render(value)
  await w.get('button').trigger('click')
  await w.get('[data-testid="inventory-accounts"]').setValue(['9'])
  await flushPromises()
  const event = w.emitted('change')!.at(-1)!
  expect(event[1]).toBe(true)
  expect((event[0] as SecuritiesEvidence).inventory_account_ids).toEqual([9])
  expect((event[0] as SecuritiesEvidence).valuations).toEqual([])
})

it('accepts a refreshed empty inventory after optional accounts were removed', async () => {
  const value = assessment()
  value.evidence!.valuations = []
  value.evidence!.inventory_account_ids = [9]
  value.available_accounts = [{ id: 9, account_code: '315', account_type: 'asset' }]
  const w = render(value)
  await w.get('[data-testid="inventory-accounts"]').setValue([])
  await flushPromises()
  expect(w.emitted('change')!.at(-1)![1]).toBe(true)
  const refreshed = { ...value, cells: [], evidence: structuredClone(value.evidence!) }
  refreshed.evidence.inventory_account_ids = []
  await w.setProps({ assessment: refreshed })
  await flushPromises()
  expect(w.emitted('change')!.at(-1)).toEqual([refreshed.evidence, true])
})

it('requires evidence while inventory cells remain', async () => {
  const value = assessment()
  value.evidence!.valuations = []
  const w = render(value)
  await w.get('[data-testid="inventory-accounts"]').setValue([])
  await flushPromises()
  expect(w.emitted('change')!.at(-1)![1]).toBe(false)
})
