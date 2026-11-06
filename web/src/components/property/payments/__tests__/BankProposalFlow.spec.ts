import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { reactive } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import PropertyPaymentsView from '@/views/PropertyPaymentsView.vue'
import { ops } from '@/components/property/payments/operations/__tests__/helpers'

const calls = vi.hoisted(() => ({ context: vi.fn(), history: vi.fn(), source: vi.fn(), preview: vi.fn(), confirm: vi.fn(), candidates: vi.fn(), bankProposalPlan: vi.fn(), bankProposals: vi.fn(), bankLinks: vi.fn() }))
vi.mock('@/api/propertyPayments', () => ({ propertyPaymentsApi: calls }))
vi.mock('@/api/propertyPaymentOperations', async () => ({ propertyPaymentOperationsApi: (await import('@/components/property/payments/operations/__tests__/helpers')).ops }))
const store = reactive<{ currentSupplierId: number | null }>({ currentSupplierId: 1 })
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => store }))

const tx = { kind: 'bank', id: 41, date: '2026-02-01', amount: '1500.00', currency: 'CZK', direction: 'in', partner_name: 'Synthetic owner', description: 'Synthetic contribution', variable_symbol: '5', specific_symbol: null, existing_owner: false, available_cents: '150000', support: 'preview_required' }
const context = { as_of: '2026-02-01T12:00:00+01:00', candidates: [], candidate_coverage: { truncated: false, fx: 'incomplete' }, registers: [], units: [{ id: 15, code: 'U05', revision: { state: { unit_no: '5' } } }], dimensions: [], capabilities: { manage: true, post: true, reverse: true, bank: true, cash: true } }
const request = { source: { kind: 'bank', id: 41 }, clearing_cells: [{ key: 'unresolved', amount_cents: '150000', dimensions: {} }],
  parts: [{ key: 'bank-proposal.41', request: { kind: 'allocation', amount_cents: '150000', accounting_date: '2026-02-01', reason: 'Úhrada jednotky 5, VS 5', debts: [77],
    payment: { payer_id: 7, debtor_id: 7, received_at: '2026-02-01T11:00:00.000000Z' } } }] }
const resolution = { bank_transaction_id: 41, resolver_version: 1, received_at: '2026-02-01T11:00:00.000000Z', status: 'identified', score: 100, unit_id: 15, debtor_id: 7, payer_id: 7, reasons: ['payer_not_proven'], candidates: [{ unit_id: 15, unit_no: '5', score: 100, signals: [{ signal: 'vs', identifier_kind: 'vs' }], debtor_ids: [7], ownership_review_required: false }] }
const ready = { status: 'ready', preview_hash: 'hash-from-plan', source_etag: 'etag', source: { kind: 'bank', id: 41, date: '2026-02-01', amount_cents: '150000', currency: 'CZK' }, snapshot: { settled_cents: '150000', available_cents: '0', cells: [], parts: [] } }

const plugins = (router: ReturnType<typeof createRouter>) => [router, createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })]
const buttonActions = { props: ['actions'], template: '<div><button v-for="a in actions" :key="a.key" type="button" :data-action="a.key" :disabled="a.disabled" @click="a.run">{{ a.label }}</button></div>' }
const stubs = { PropertyPartyPicker: true, ActionBar: buttonActions, PropertyInstantInput: true, PaymentSourceRevisionEditor: true, DebtPicker: true, PaymentFactsFields: true }
function makeRouter() {
  return createRouter({ history: createMemoryHistory(), routes: [{ path: '/property/payments', component: { template: '<div />' } }] })
}
beforeEach(() => {
  vi.clearAllMocks(); Object.values(ops).forEach(fn => fn.mockReset()); store.currentSupplierId = 1
  calls.context.mockResolvedValue(context); calls.history.mockResolvedValue({ items: [], next_cursor: null })
  calls.candidates.mockResolvedValue({ items: [tx], next_cursor: null, coverage: { eligibility: 'current_preview_required', fx: 'incomplete' } })
})

describe('bank proposal to unit payment', () => {
  it('preselects the bank movement, prefills the unit payment and confirms with the plan preview and token', async () => {
    calls.bankProposalPlan.mockResolvedValue({ status: 'proposal', bank_transaction_id: 41, resolution, request, preview: ready, review: null })
    calls.confirm.mockResolvedValue({ id: 9 })
    const router = makeRouter()
    await router.push('/property/payments?tab=receive&bank_transaction=41')
    const wrapper = mount(PropertyPaymentsView, { global: { plugins: plugins(router), stubs } })
    await flushPromises()
    expect(calls.candidates).toHaveBeenCalledWith(1, 'bank', 0, 1, { id: 41 })
    expect(calls.bankProposalPlan).toHaveBeenCalledWith(1, 41, {})
    expect(wrapper.get('[data-source="bank:41"]').attributes('aria-selected')).toBe('true')
    expect(wrapper.get<HTMLInputElement>('[data-field="alloc-amount"]').element.value).toBe('1500.00')
    expect(wrapper.get<HTMLSelectElement>('[data-field="alloc-unit"]').element.value).toBe('15')
    expect(wrapper.find('[data-field="credit-amount"]').exists()).toBe(false)
    expect(wrapper.find('[data-flag="proposal-ready"]').exists()).toBe(true)
    await wrapper.get('[data-action="confirm"]').trigger('click'); await flushPromises()
    expect(calls.preview).not.toHaveBeenCalled()
    expect(calls.confirm).toHaveBeenCalledWith(1, request, 'hash-from-plan', expect.any(String))
  })

  it('never preselects a unit when the proposal needs review and replans with the chosen unit', async () => {
    const review = { ...resolution, status: 'review_required', unit_id: null, debtor_id: null, payer_id: null, reasons: ['multiple_units'], candidates: [resolution.candidates[0], { ...resolution.candidates[0], unit_id: 16, unit_no: '6' }] }
    calls.bankProposalPlan.mockResolvedValueOnce({ status: 'proposal', bank_transaction_id: 41, resolution: review, request: null, preview: null, review: { code: 'unit_required' } })
      .mockResolvedValueOnce({ status: 'proposal', bank_transaction_id: 41, resolution, request, preview: ready, review: null })
    const router = makeRouter()
    await router.push('/property/payments?bank_transaction=41')
    const wrapper = mount(PropertyPaymentsView, { global: { plugins: plugins(router), stubs } })
    await flushPromises()
    expect(wrapper.find('[data-field="alloc-amount"]').exists()).toBe(false)
    expect(wrapper.find('[data-flag="proposal-review"]').exists()).toBe(true)
    await wrapper.get('[data-field="proposal-unit"]').setValue('15')
    await wrapper.get('[data-action="proposal-use-unit"]').trigger('click'); await flushPromises()
    expect(calls.bankProposalPlan).toHaveBeenLastCalledWith(1, 41, { unit_id: 15 })
    expect(wrapper.get<HTMLInputElement>('[data-field="alloc-amount"]').element.value).toBe('1500.00')
  })

  it('keeps the active tab in the URL and opens a queued proposal', async () => {
    calls.bankProposals.mockResolvedValue({ items: [{ bank_transaction_id: 41, status: 'identified', unit_id: 15, score: 100, row_version: 1, updated_at: '2026-02-01', resolution, transaction: { date: '2026-02-01', amount: '1500.00', variable_symbol: '5', specific_symbol: null, partner_name: 'Synthetic owner', description: null } }], next_cursor: null })
    calls.bankProposalPlan.mockResolvedValue({ status: 'proposal', bank_transaction_id: 41, resolution, request, preview: ready, review: null })
    const router = makeRouter()
    await router.push('/property/payments?tab=proposals')
    const wrapper = mount(PropertyPaymentsView, { global: { plugins: plugins(router), stubs } })
    await flushPromises()
    expect(wrapper.find('[data-section="bank-proposal-queue"]').exists()).toBe(true)
    await wrapper.get('[data-action="proposal-open"]').trigger('click'); await flushPromises()
    expect(router.currentRoute.value.query).toMatchObject({ tab: 'receive', bank_transaction: '41' })
    expect(calls.bankProposalPlan).toHaveBeenCalledWith(1, 41, {})
    expect(wrapper.get<HTMLInputElement>('[data-field="alloc-amount"]').element.value).toBe('1500.00')
  })

  it('shows a used movement instead of preparing a second payment', async () => {
    calls.candidates.mockResolvedValue({ items: [], next_cursor: null })
    calls.bankProposalPlan.mockResolvedValue({ status: 'used', bank_transaction_id: 41, link: { source: { id: 3, amount_cents: '150000', consumed_cents: '150000' }, workflow_id: 12 } })
    const router = makeRouter()
    await router.push('/property/payments?bank_transaction=41')
    const wrapper = mount(PropertyPaymentsView, { global: { plugins: plugins(router), stubs } })
    await flushPromises()
    expect(wrapper.find('[data-flag="proposal-used"]').exists()).toBe(true)
    expect(wrapper.find('[data-field="alloc-amount"]').exists()).toBe(false)
  })
})
