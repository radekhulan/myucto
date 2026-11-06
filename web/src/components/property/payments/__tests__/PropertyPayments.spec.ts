import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { reactive } from 'vue'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import PropertyPaymentsView from '@/views/PropertyPaymentsView.vue'
import { ops, page, token } from '@/components/property/payments/operations/__tests__/helpers'
const calls = vi.hoisted(() => ({ context: vi.fn(), history: vi.fn(), source: vi.fn(), preview: vi.fn(), confirm: vi.fn(), candidates: vi.fn() }))
vi.mock('@/api/propertyPayments', () => ({ propertyPaymentsApi: calls }))
vi.mock('@/api/propertyPaymentOperations', async () => ({ propertyPaymentOperationsApi: (await import('@/components/property/payments/operations/__tests__/helpers')).ops }))
const store = reactive<{ currentSupplierId: number | null }>({ currentSupplierId: 1 })
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => store }))
const context = { as_of: '2026-02-01T12:00:00+01:00', candidates: [{ kind: 'bank', id: 7, date: '2026-02-01', amount: '100.00', currency: 'CZK', direction: 'in', partner_name: 'Synthetic payer', description: 'Synthetic receipt', variable_symbol: '12', existing_owner: false, available_cents: '10000', support: 'preview_required' }], candidate_coverage: { truncated: false, fx: 'incomplete' }, registers: [], units: [{ id: 12, revision: { state: { unit_no: '12' } } }], dimensions: [], capabilities: { manage: true, post: true, reverse: true, bank: true, cash: true } }
const plugins = () => [createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })]
const partyStub = { props: ['modelValue'], emits: ['update:modelValue'], template: '<input data-party :value="modelValue" @input="$emit(\'update:modelValue\', Number($event.target.value))" />' }
const actionsStub = { props: ['actions'], template: '<div><button v-for="a in actions" :key="a.key" :data-action="a.key" :disabled="a.disabled" @click="a.run">{{ a.label }}</button></div>' }
const inputStub = (attr: string) => ({ props: ['modelValue'], emits: ['update:modelValue'], template: `<input ${attr} :value="modelValue" @input="$emit('update:modelValue', $event.target.value)" />` })
const buttonActions = { props: ['actions'], template: '<div><button v-for="a in actions" :key="a.key" type="button" :data-action="a.key" :disabled="a.disabled" @click="a.run">{{ a.label }}</button></div>' }
const viewStubs = { PropertyPartyPicker: partyStub, ActionBar: buttonActions, DateInput: inputStub('data-date'), PropertyInstantInput: inputStub('data-instant') }
beforeEach(() => { vi.clearAllMocks(); Object.values(ops).forEach(fn => fn.mockReset()); store.currentSupplierId = 1; calls.context.mockResolvedValue(context); calls.history.mockResolvedValue({ items: [], next_cursor: null }) })

describe('property payment workflow', () => {
  it('loads older history through the backend keyset cursor', async () => {
    calls.history.mockResolvedValueOnce({ items: [{ id: 30, kind: 'bank', source_id: 7, date: '2026-02-01', amount_cents: '10000', settled_cents: '10000', available_cents: '0', recorded_at: '2026-02-01T12:00:00Z' }], next_cursor: 20 })
      .mockResolvedValueOnce({ items: [{ id: 20, kind: 'cash', source_id: 8, date: '2026-01-31', amount_cents: '5000', settled_cents: '5000', available_cents: '0', recorded_at: '2026-01-31T12:00:00Z' }], next_cursor: null })
    const wrapper = mount(PropertyPaymentsView, { global: { plugins: plugins(), stubs: { ...viewStubs, PaymentSourceRevisionEditor: true } } })
    await flushPromises()
    expect(calls.history).toHaveBeenNthCalledWith(1, 1)
    await wrapper.get('[data-action="load-more-history"]').trigger('click'); await flushPromises()
    expect(calls.history).toHaveBeenNthCalledWith(2, 1, 20, 25)
    expect(wrapper.text()).toContain('2026-02-01')
    expect(wrapper.text()).toContain('2026-01-31')
    expect(wrapper.find('[data-action="load-more-history"]').exists()).toBe(false)
    wrapper.unmount()
  })
  it('loads the actual immutable cells of an existing source instead of asking for hidden IDs', async () => {
    calls.context.mockResolvedValue({ ...context, candidates: [{ ...context.candidates[0], existing_owner: true }] })
    calls.source.mockResolvedValue({ source_id: 9, source_version: 2, revision_id: null, kind: 'bank', id: 7, date: '2026-02-01', amount_cents: '10000', consumed_cents: '6000', available_cents: '4000', cells: [{ key: 'original', amount_cents: '10000', available_cents: '4000', dimensions: {}, dimension_splits: {} }] })
    const wrapper = mount(PropertyPaymentsView, { global: { plugins: plugins(), stubs: { PropertyPartyPicker: partyStub, ActionBar: actionsStub, PaymentSourceRevisionEditor: true } } })
    await flushPromises(); await wrapper.get('[data-source="bank:7"]').trigger('click'); await flushPromises()
    expect(calls.source).toHaveBeenCalledWith(1, 'bank', 7)
    const amount = wrapper.get<HTMLInputElement>('[data-field="cell-amount"]')
    expect(amount.element.value).toBe('100.00'); expect(amount.element.readOnly).toBe(true)
  })
  it('clears owned-source state for new cash and reloads it when returning to existing', async () => {
    const owned = { ...context.candidates[0]!, existing_owner: true }
    const details = { source_id: 9, source_version: 2, revision_id: null, kind: 'bank' as const, id: 7, date: '2026-02-01', amount_cents: '10000', consumed_cents: '6000', available_cents: '4000', cells: [{ key: 'original', amount_cents: '10000', available_cents: '4000', dimensions: { 1: 2 }, dimension_splits: {} }] }
    calls.context.mockResolvedValue({ ...context, candidates: [owned], dimensions: [{ id: 2, type_id: 1, type_name: 'Department', name: 'Sales' }] })
    calls.source.mockResolvedValue(details)
    const wrapper = mount(PropertyPaymentsView, { attachTo: document.body, global: { plugins: plugins(), stubs: { ...viewStubs, PaymentSourceRevisionEditor: { template: '<div data-source-revision />' } } } })
    await flushPromises(); await selectSource(wrapper)
    expect(wrapper.find('[data-source-revision]').exists()).toBe(true)
    expect(wrapper.get<HTMLInputElement>('[data-field=cell-amount]').element.readOnly).toBe(true)
    expect(wrapper.findAll('select').find(el => !el.attributes('data-field'))!.element.disabled).toBe(true)
    await wrapper.get('[data-field=source-mode]').setValue('new_cash')
    await wrapper.get('[data-field=cash-amount]').setValue('44')
    await flushPromises()
    expect(wrapper.find('[data-source-revision]').exists()).toBe(false)
    expect(wrapper.get<HTMLInputElement>('[data-field=cell-amount]').element.value).toBe('44')
    expect(wrapper.get<HTMLInputElement>('[data-field=cell-amount]').element.readOnly).toBe(false)
    expect(wrapper.findAll('select').find(el => !el.attributes('data-field'))!.element.disabled).toBe(false)
    await wrapper.get('[data-field=source-mode]').setValue('existing'); await flushPromises()
    expect(calls.source).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-source-revision]').exists()).toBe(true)
    expect(wrapper.get<HTMLInputElement>('[data-field=cell-amount]').element.readOnly).toBe(true)
    wrapper.unmount()
  })
  it('loads the posted EUR receipt before showing CZK cells and never treats EUR as CZK', async () => {
    calls.context.mockResolvedValue({ ...context, candidates: [{ ...context.candidates[0]!, currency: 'EUR', amount: '100.00', available_cents: '0' }] })
    let release!: (value: unknown) => void
    calls.source.mockReturnValueOnce(new Promise(resolve => { release = resolve }))
    const wrapper = mount(PropertyPaymentsView, { attachTo: document.body, global: { plugins: plugins(), stubs: { ...viewStubs, PaymentSourceRevisionEditor: { template: '<div data-source-revision />' } } } })
    await flushPromises(); await selectSource(wrapper)
    expect(calls.source).toHaveBeenCalledWith(1, 'bank', 7)
    expect(wrapper.find('[data-field=cell-amount]').exists()).toBe(false)
    expect(calls.preview).not.toHaveBeenCalled()
    release({ source_id: null, source_version: 0, revision_id: null, kind: 'bank', id: 7, date: '2026-02-01', amount_cents: '250000', consumed_cents: '0', available_cents: '250000',
      native: { currency: 'EUR', amount: '100.00', amount_minor: '10000', minor_unit_decimals: 2, fx_rate: '25.000000' },
      cells: [{ key: 'original', amount_cents: '250000', available_cents: '250000', dimensions: {}, dimension_splits: {} }] })
    await flushPromises()
    expect(wrapper.get<HTMLInputElement>('[data-field=cell-amount]').element.value).toBe('2500.00')
    expect(wrapper.get<HTMLInputElement>('[data-field=cell-amount]').element.readOnly).toBe(true)
    expect(wrapper.find('[data-source-revision]').exists()).toBe(false)
    expect(wrapper.find('[data-test=fx-source-help]').exists()).toBe(true)
    wrapper.unmount()
  })
  it('ignores an owned-source response that arrives after switching to new cash', async () => {
    const owned = { ...context.candidates[0]!, existing_owner: true }
    const details = { source_id: 9, source_version: 2, revision_id: null, kind: 'bank' as const, id: 7, date: '2026-02-01', amount_cents: '99000', consumed_cents: '0', available_cents: '99000', cells: [{ key: 'late', amount_cents: '99000', available_cents: '99000', dimensions: {}, dimension_splits: {} }] }
    let resolveSource!: (value: typeof details) => void
    calls.context.mockResolvedValue({ ...context, candidates: [owned] })
    calls.source.mockReturnValueOnce(new Promise(resolve => { resolveSource = resolve }))
    const wrapper = mount(PropertyPaymentsView, { attachTo: document.body, global: { plugins: plugins(), stubs: { ...viewStubs, PaymentSourceRevisionEditor: { template: '<div data-source-revision />' } } } })
    await flushPromises(); await selectSource(wrapper)
    await wrapper.get('[data-field=source-mode]').setValue('new_cash')
    await wrapper.get('[data-field=cash-amount]').setValue('31'); await flushPromises()
    resolveSource(details); await flushPromises()
    expect(wrapper.find('[data-source-revision]').exists()).toBe(false)
    expect(wrapper.get<HTMLInputElement>('[data-field=cell-amount]').element.value).toBe('31')
    expect(wrapper.get<HTMLInputElement>('[data-field=cell-amount]').element.readOnly).toBe(false)
    wrapper.unmount()
  })
  it('offers the real unposted bank source and requires explicit entitlement before preview', async () => {
    const wrapper = mount(PropertyPaymentsView, { global: { plugins: plugins(), stubs: { PropertyPartyPicker: partyStub, ActionBar: actionsStub } } })
    await flushPromises()
    expect(wrapper.text()).toContain('Synthetic payer')
    expect(calls.context).toHaveBeenCalledWith(1, expect.any(String))
    expect(calls.preview).not.toHaveBeenCalled()
    expect(wrapper.find('[data-field="entitlement-evidence"]').exists()).toBe(true)
  })
  it('never accepts a response from the previous company', async () => {
    let resolve!: (value: typeof context) => void
    calls.context.mockReturnValueOnce(new Promise(r => { resolve = r }))
    const wrapper = mount(PropertyPaymentsView, { global: { plugins: plugins(), stubs: { ActionBar: actionsStub } } })
    await flushPromises()
    store.currentSupplierId = 2
    calls.context.mockResolvedValue({ ...context, candidates: [] })
    await flushPromises()
    resolve(context)
    await flushPromises()
    expect(wrapper.text()).not.toContain('Synthetic payer')
  })
})

const debt = { id: 4, charge_id: 2, unit_id: 12, unit_label: 'Jednotka 12', title_ref: 'Advance 01/2026', due_date: '2026-01-15', amount_cents: '10000', remaining_cents: '10000' }
const debtDetail = { ...debt, legal: { basis: 'statutory', evidence: 'Law', regime: 'general' }, security: { kind: 'unsecured', evidence: 'None' }, groups: [], lines: [{ id: 11, label: 'Advance', amount_cents: '10000', remaining_cents: '10000' }], accessories: [] }
const previewResult = { status: 'ready', preview_hash: 'workflow-hash', source_etag: 'e', source: { kind: 'bank', id: 7, date: '2026-02-01', amount_cents: '10000', currency: 'CZK' }, snapshot: { settled_cents: '10000', available_cents: '0', cells: [], parts: [] } }
async function ready(overrides: Record<string, unknown> = {}) {
  calls.context.mockResolvedValue({ ...context, ...overrides })
  const wrapper = mount(PropertyPaymentsView, { attachTo: document.body, global: { plugins: plugins(), stubs: viewStubs } })
  await flushPromises()
  return wrapper
}
type Ready = Awaited<ReturnType<typeof ready>>
async function selectSource(wrapper: Ready, value = 'bank:7') { await wrapper.get(`[data-source="${value}"]`).trigger('click'); await flushPromises() }
async function fillCredit(wrapper: Ready, amount = '100') {
  await wrapper.get('[data-field="credit-amount"]').setValue(amount); await wrapper.get('[data-field="unit"]').setValue('12')
  await wrapper.get('[data-party]').setValue('5'); await wrapper.get('[data-field="entitlement-evidence"]').setValue('Synthetic entitlement')
  await wrapper.get('[data-field="due-date"]').setValue('2026-03-01'); await wrapper.get('[data-field="reason"]').setValue('Synthetic credit')
}
const removeFirstPart = async (wrapper: Ready) => { await wrapper.findAll('button').find(b => b.text() === 'propertyPayments.removePart')!.trigger('click') }

describe('source pagination beyond the context page', () => {
  const row = (kind: 'bank' | 'cash', id: number) => ({ ...context.candidates[0]!, kind, id, partner_name: `Payer ${kind}${id}` })
  const truncated = { limit_per_kind: 100, truncated: true, eligibility: 'current_preview_required', fx: 'incomplete' }
  it('continues each kind by keyset cursor until none is returned and keeps the chosen source', async () => {
    calls.candidates.mockResolvedValueOnce({ items: [row('bank', 7), row('bank', 8)], next_cursor: 8, coverage: {} }).mockResolvedValueOnce({ items: [row('bank', 9)], next_cursor: null, coverage: {} })
    const wrapper = await ready({ candidate_coverage: truncated })
    await selectSource(wrapper)
    await wrapper.get('[data-action="source-more-bank"]').trigger('click'); await flushPromises()
    expect(calls.candidates).toHaveBeenNthCalledWith(1, 1, 'bank', 0, 100)
    expect(wrapper.text()).toContain('Payer bank8')
    expect(wrapper.text().match(/Synthetic payer/g)).toHaveLength(1)
    expect(wrapper.get('[data-source="bank:7"]').attributes('aria-selected')).toBe('true')
    await wrapper.get('[data-action="source-more-bank"]').trigger('click'); await flushPromises()
    expect(calls.candidates).toHaveBeenNthCalledWith(2, 1, 'bank', 8, 100)
    expect(wrapper.text()).toContain('Payer bank9')
    expect(wrapper.find('[data-action="source-more-bank"]').exists()).toBe(false)
    expect(wrapper.find('[data-action="source-more-cash"]').exists()).toBe(true)
    wrapper.unmount()
  })
  it('offers no further loading when the context is complete', async () => {
    const wrapper = await ready()
    expect(wrapper.find('[data-section="source-more"]').exists()).toBe(false)
    wrapper.unmount()
  })
  it('drops a page that answers after the company or as-of changed', async () => {
    let resolve!: (value: unknown) => void
    calls.candidates.mockReturnValueOnce(new Promise(r => { resolve = r }))
    const wrapper = await ready({ candidate_coverage: truncated })
    await wrapper.get('[data-action="source-more-bank"]').trigger('click')
    store.currentSupplierId = 2; await flushPromises()
    resolve({ items: [row('bank', 99)], next_cursor: null, coverage: {} }); await flushPromises()
    expect(wrapper.text()).not.toContain('Payer bank99')
    store.currentSupplierId = 1
    wrapper.unmount()
  })
})

describe('receive and pay workflow', () => {
  it('stops a native invalid form before any API call', async () => {
    const wrapper = await ready()
    await wrapper.get('[data-action="preview"]').trigger('click'); await flushPromises()
    expect(calls.preview).not.toHaveBeenCalled()
    wrapper.unmount()
  })
  it('shows a read-only user no mutation form', async () => {
    const wrapper = await ready({ capabilities: { manage: false, post: false, reverse: false, bank: false, cash: false } })
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('[data-action="preview"]').exists()).toBe(false)
    expect(wrapper.find('[data-flag="read-only"]').exists()).toBe(true)
    wrapper.unmount()
  })
  it('keeps one token for an exact retry', async () => {
    calls.preview.mockResolvedValue(previewResult)
    calls.confirm.mockRejectedValueOnce({ response: { data: { error: { message: 'Temporary problem.' } } } }).mockResolvedValue({ id: 1 })
    calls.history.mockResolvedValue({ items: [], next_cursor: null })
    const wrapper = await ready(); await selectSource(wrapper); await fillCredit(wrapper)
    await wrapper.get('[data-action="preview"]').trigger('click'); await flushPromises()
    await wrapper.get('[data-action="confirm"]').trigger('click'); await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('Temporary problem.')
    await wrapper.get('[data-action="confirm"]').trigger('click'); await flushPromises()
    const [first, second] = calls.confirm.mock.calls.map(c => c[3] as string)
    expect(first).toMatch(token); expect(second).toBe(first)
    expect(calls.confirm.mock.calls[1]![1]).toBe(calls.confirm.mock.calls[0]![1])
    wrapper.unmount()
  })
  it('creates a new token after an edit invalidates the preview', async () => {
    calls.preview.mockResolvedValue(previewResult); calls.confirm.mockRejectedValue({}); calls.history.mockResolvedValue({ items: [], next_cursor: null })
    const wrapper = await ready(); await selectSource(wrapper); await fillCredit(wrapper)
    await wrapper.get('[data-action="preview"]').trigger('click'); await flushPromises()
    await wrapper.get('[data-action="confirm"]').trigger('click'); await flushPromises()
    await wrapper.get('[data-field="reason"]').setValue('Edited reason')
    expect(wrapper.find('[data-action="confirm"]').exists()).toBe(false)
    await wrapper.get('[data-action="preview"]').trigger('click'); await flushPromises()
    await wrapper.get('[data-action="confirm"]').trigger('click'); await flushPromises()
    const [first, second] = calls.confirm.mock.calls.map(c => c[3] as string)
    expect(second).not.toBe(first)
    wrapper.unmount()
  })
  it('previews a third-party payment of a debt as one claim without crediting anyone', async () => {
    calls.preview.mockResolvedValue(previewResult)
    ops.list.mockResolvedValue(page([debt])); ops.detail.mockResolvedValue(debtDetail)
    const wrapper = await ready(); await selectSource(wrapper)
    await wrapper.get('[data-action="add-allocation"]').trigger('click'); await flushPromises()
    await removeFirstPart(wrapper)
    await wrapper.get('[data-field="alloc-amount"]').setValue('100'); await wrapper.get('[data-field="alloc-reason"]').setValue('Synthetic debt payment')
    await wrapper.get('[data-field="debt-select"]').setValue(true); await flushPromises()
    await wrapper.get('[data-field="payer"] input').setValue('31'); await wrapper.get('[data-field="debtor"] input').setValue('32')
    await wrapper.get('[data-field="received-at"] input').setValue('2026-02-01T10:00:00+01:00')
    await wrapper.get('[data-field="third-basis"]').setValue('guarantor'); await wrapper.get('[data-field="third-evidence"]').setValue('Guarantee agreement')
    await wrapper.get('[data-action="preview"]').trigger('click'); await flushPromises()
    const parts = calls.preview.mock.calls[0]![1].parts
    expect(parts).toHaveLength(1)
    expect(parts[0].request).toEqual({ kind: 'allocation', amount_cents: '10000', accounting_date: '2026-02-01', reason: 'Synthetic debt payment', debts: [4], payment: { payer_id: 31, debtor_id: 32, received_at: '2026-02-01T10:00:00+01:00', third_party: { basis: 'guarantor', evidence: 'Guarantee agreement' } } })
    expect(wrapper.find('[data-field="choice-on"]').exists()).toBe(false)
    wrapper.unmount()
  })
  it('refuses to preview a payment whose received date differs from the source date', async () => {
    ops.list.mockResolvedValue(page([debt])); ops.detail.mockResolvedValue(debtDetail)
    const wrapper = await ready(); await selectSource(wrapper)
    await wrapper.get('[data-action="add-allocation"]').trigger('click'); await flushPromises(); await removeFirstPart(wrapper)
    await wrapper.get('[data-field="alloc-amount"]').setValue('100'); await wrapper.get('[data-field="alloc-reason"]').setValue('Payment')
    await wrapper.get('[data-field="debt-select"]').setValue(true); await flushPromises()
    await wrapper.get('[data-field="payer"] input').setValue('31'); await wrapper.get('[data-field="debtor"] input').setValue('31')
    await wrapper.get('[data-field="received-at"] input').setValue('2026-02-02T10:00:00+01:00')
    await wrapper.get('[data-action="preview"]').trigger('click'); await flushPromises()
    expect(calls.preview).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toBe('propertyPayments.formErrors.receivedDate')
    wrapper.unmount()
  })
  const cashReservation = { id: 9, credit_id: 5, row_version: 4, status: 'reserved', amount_cents: '5000', paid_cents: '0', remaining_cents: '5000', unit_id: 12, unit_label: 'Jednotka 12', parties: [{ id: 21, label: 'Owner A' }], request: { amount_cents: '5000', party_ids: [21], disposition: 'refund', delivery_method: 'cash', recipient_id: 21, recipient_evidence: 'ID' } }
  async function refundTo(wrapper: Ready) {
    await wrapper.get('[data-action="add-refund"]').trigger('click'); await flushPromises(); await removeFirstPart(wrapper)
    await wrapper.get('[data-field="reservation-select"]').setValue(true); await wrapper.get('[data-field="refund-amount"]').setValue('30'); await wrapper.get('[data-field="refund-reason"]').setValue('Partial refund')
    await wrapper.get('[data-field="receipt-person"] input').setValue('21'); await wrapper.get('[data-field="receipt-name"]').setValue('Owner A'); await wrapper.get('[data-field="receipt-evidence"]').setValue('Signed receipt')
  }
  it('previews a partial cash refund of an existing cash document with the receiving person', async () => {
    calls.preview.mockResolvedValue(previewResult); ops.list.mockResolvedValue(page([cashReservation]))
    const wrapper = await ready({ candidates: [{ ...context.candidates[0], kind: 'cash', id: 12, direction: 'out' }] })
    await selectSource(wrapper, 'cash:12'); await refundTo(wrapper)
    await wrapper.get('[data-action="preview"]').trigger('click'); await flushPromises()
    expect(calls.preview.mock.calls[0]![1].parts[0].request).toEqual({ kind: 'refund', amount_cents: '3000', accounting_date: '2026-02-01', reason: 'Partial refund', reservation_id: 9, expected_reservation_version: 4, cash_receipt: { cash_document_id: 12, recipient_id: 21, recipient_name: 'Owner A', evidence: 'Signed receipt' } })
    wrapper.unmount()
  })
  it('sends a null cash document id for a new outgoing cash draft', async () => {
    calls.preview.mockResolvedValue(previewResult); ops.list.mockResolvedValue(page([cashReservation]))
    const wrapper = await ready({ registers: [{ id: 3, name: 'Main cash', currency_code: 'CZK' }] })
    await wrapper.get('[data-field="source-mode"]').setValue('new_cash')
    await wrapper.get('[data-field="cash-direction"]').setValue('out'); await wrapper.get('[data-field="cash-register"]').setValue('3'); await wrapper.get('[data-field="cash-date"]').setValue('2026-02-01')
    await wrapper.get('[data-field="cash-payer"]').setValue('Owner A'); await wrapper.get('[data-field="cash-amount"]').setValue('30'); await wrapper.get('[data-field="cash-description"]').setValue('Refund')
    await refundTo(wrapper)
    await wrapper.get('[data-action="preview"]').trigger('click'); await flushPromises()
    const request = calls.preview.mock.calls[0]![1]
    expect(request.source.draft.doc_type).toBe('out')
    expect(request.parts[0].request.cash_receipt.cash_document_id).toBeNull()
    wrapper.unmount()
  })
  it('ignores a context answer that arrives after the as-of instant changed', async () => {
    let resolve!: (value: typeof context) => void
    let resolveCurrent!: (value: typeof context) => void
    calls.context.mockReturnValueOnce(new Promise(r => { resolve = r }))
    const wrapper = mount(PropertyPaymentsView, { attachTo: document.body, global: { plugins: plugins(), stubs: viewStubs } })
    await flushPromises()
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-instant]').setValue('2026-01-01T00:00:00+01:00'); await flushPromises()
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
    calls.context.mockReturnValueOnce(new Promise(r => { resolveCurrent = r }))
    await wrapper.get('button').trigger('click'); await flushPromises()
    expect(calls.context).toHaveBeenCalledTimes(2)
    expect(calls.context).toHaveBeenLastCalledWith(1, '2026-01-01T00:00:00+01:00')
    resolve(context); await flushPromises()
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).not.toContain('Synthetic payer')
    resolveCurrent({ ...context, candidates: [] }); await flushPromises()
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
    expect(wrapper.text()).not.toContain('Synthetic payer')
    wrapper.unmount()
  })
  it('clears pending context loading when no company is selected', async () => {
    let resolve!: (value: typeof context) => void
    calls.context.mockReturnValueOnce(new Promise(r => { resolve = r }))
    const wrapper = mount(PropertyPaymentsView, { attachTo: document.body, global: { plugins: plugins(), stubs: viewStubs } })
    await flushPromises()
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
    store.currentSupplierId = null; await flushPromises()
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
    resolve(context); await flushPromises()
    expect(wrapper.text()).not.toContain('Synthetic payer')
    store.currentSupplierId = 2; await flushPromises()
    expect(calls.context).toHaveBeenLastCalledWith(2, expect.any(String))
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })
  it('disables the tabs, the as-of instant and refresh while a preview is in flight', async () => {
    let resolve!: (value: unknown) => void
    calls.preview.mockReturnValue(new Promise(r => { resolve = r }))
    const wrapper = await ready(); await selectSource(wrapper); await fillCredit(wrapper)
    await wrapper.get('[data-action="preview"]').trigger('click'); await flushPromises()
    expect(wrapper.get<HTMLButtonElement>('[data-tab="debts"]').element.disabled).toBe(true)
    expect(wrapper.get<HTMLButtonElement>('[data-action="refresh"]').element.disabled).toBe(true)
    expect(wrapper.get<HTMLFieldSetElement>('fieldset').element.disabled).toBe(true)
    resolve(previewResult); await flushPromises()
    expect(wrapper.get<HTMLButtonElement>('[data-tab="debts"]').element.disabled).toBe(false)
    wrapper.unmount()
  })
  it('loads the debts, corrections and card tabs only after they are opened', async () => {
    ops.list.mockResolvedValue(page([])); ops.card.mockResolvedValue({})
    const wrapper = await ready()
    expect(ops.list).not.toHaveBeenCalled()
    await wrapper.get('[data-tab="debts"]').trigger('click'); await flushPromises()
    expect(ops.list).toHaveBeenCalledWith(1, 'debts', { limit: 25 })
    expect(ops.list).not.toHaveBeenCalledWith(1, 'operations', expect.anything())
    await wrapper.get('[data-tab="corrections"]').trigger('click'); await flushPromises()
    expect(ops.list).toHaveBeenCalledWith(1, 'operations', { limit: 25 })
    expect(ops.card).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
