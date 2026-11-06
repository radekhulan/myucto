import { mount, flushPromises } from '@vue/test-utils'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import PagedCollection from '../PagedCollection.vue'
import ReservationForm from '../ReservationForm.vue'
import SetoffForm from '../SetoffForm.vue'
import CorrectionForm from '../CorrectionForm.vue'
import ReverseForm from '../ReverseForm.vue'
import AboExport from '../AboExport.vue'
import ReservationCancelForm from '../ReservationCancelForm.vue'
import CorrectionsHistoryTab from '../CorrectionsHistoryTab.vue'
import DebtsCreditsTab from '../DebtsCreditsTab.vue'
import { i18nPlugin, ops, page, stubs, token } from './helpers'
import type { Credit, Reservation } from '@/api/propertyPaymentOperations'

vi.mock('@/api/propertyPaymentOperations', async () => ({ propertyPaymentOperationsApi: (await import('./helpers')).ops }))
const global = () => ({ plugins: [i18nPlugin()], stubs })
const credit: Credit = { id: 5, row_version: 7, unit_id: 1, unit_label: 'Jednotka 1', amount_cents: '10000', balance_cents: '10000', reserved_cents: '0', available_cents: '10000', due_date: '2026-03-01', parties: [{ id: 21, label: 'Owner A' }, { id: 22, label: 'Owner B' }], operation_id: 3 }
const reservation: Reservation = { id: 9, credit_id: 5, row_version: 4, status: 'reserved', amount_cents: '5000', paid_cents: '0', remaining_cents: '5000', unit_id: 1, unit_label: 'Jednotka 1', parties: [{ id: 21, label: 'Owner A' }], request: { amount_cents: '5000', party_ids: [21], disposition: 'refund', delivery_method: 'bank', recipient_account: '1000000005/0100' } }
beforeEach(() => { vi.clearAllMocks(); Object.values(ops).forEach(fn => fn.mockReset()) })
afterEach(() => { vi.unstubAllGlobals() })

describe('paginated lists', () => {
  it('requests only the next cursor and stops when the server returns none', async () => {
    ops.list.mockResolvedValueOnce(page([{ id: 1 }, { id: 2 }], 2)).mockResolvedValueOnce(page([{ id: 3 }]))
    const wrapper = mount(PagedCollection, { props: { supplier: 1, collection: 'debts', unitId: 4 }, slots: { row: '<template #row="{ item }"><p data-row>{{ item.id }}</p></template>' }, global: global() })
    await flushPromises()
    expect(ops.list).toHaveBeenNthCalledWith(1, 1, 'debts', { unit_id: 4, limit: 25 })
    expect(wrapper.findAll('[data-row]')).toHaveLength(2)
    await wrapper.get('[data-action="load-more"]').trigger('click'); await flushPromises()
    expect(ops.list).toHaveBeenNthCalledWith(2, 1, 'debts', { unit_id: 4, after_id: 2, limit: 25 })
    expect(wrapper.findAll('[data-row]').map(r => r.text())).toEqual(['1', '2', '3'])
    expect(wrapper.find('[data-action="load-more"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('propertyPayments.coverageIncomplete')
  })
  it('drops a page that answers after the company changed', async () => {
    let resolve!: (value: unknown) => void
    ops.list.mockReturnValueOnce(new Promise(r => { resolve = r })).mockResolvedValueOnce(page([{ id: 9 }]))
    const wrapper = mount(PagedCollection, { props: { supplier: 1, collection: 'debts' }, slots: { row: '<template #row="{ item }"><p data-row>{{ item.id }}</p></template>' }, global: global() })
    await wrapper.setProps({ supplier: 2 }); await flushPromises()
    resolve(page([{ id: 1 }])); await flushPromises()
    expect(wrapper.findAll('[data-row]').map(r => r.text())).toEqual(['9'])
  })
})

describe('credit reservation', () => {
  async function fill(wrapper: ReturnType<typeof mount>, amount = '30') {
    await wrapper.get('[data-field="reserve-amount"]').setValue(amount)
    await wrapper.get('[data-field="reserve-disposition"]').setValue('Refund of overpayment')
    await wrapper.get('[data-field="reserve-account"]').setValue('1000000005/0100')
  }
  it('reserves a partial amount for the whole entitlement group with the credit version', async () => {
    ops.reserveCredit.mockResolvedValue({ id: 1 })
    const wrapper = mount(ReservationForm, { props: { supplier: 1, credit, canManage: true }, global: global() })
    await fill(wrapper)
    await wrapper.get('[data-action="reserve"]').trigger('click'); await flushPromises()
    expect(ops.reserveCredit).toHaveBeenCalledWith(1, 5, 7, { amount_cents: '3000', party_ids: [21, 22], disposition: 'Refund of overpayment', delivery_method: 'bank', recipient_account: '1000000005/0100' }, expect.stringMatching(token))
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })
  it('keeps the same token for an exact retry and issues a new one after an edit', async () => {
    ops.reserveCredit.mockRejectedValue({ response: { data: { error: { message: 'Temporary server problem.' } } } })
    const wrapper = mount(ReservationForm, { props: { supplier: 1, credit, canManage: true }, global: global() })
    await fill(wrapper)
    await wrapper.get('[data-action="reserve"]').trigger('click'); await flushPromises()
    await wrapper.get('[data-action="reserve"]').trigger('click'); await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('Temporary server problem.')
    await fill(wrapper, '31')
    await wrapper.get('[data-action="reserve"]').trigger('click'); await flushPromises()
    const [first, second, third] = ops.reserveCredit.mock.calls.map(c => c[4] as string)
    expect(second).toBe(first); expect(third).not.toBe(first)
    expect(ops.reserveCredit.mock.calls[1]![3]).toEqual(ops.reserveCredit.mock.calls[0]![3])
  })
  it('does not call the API from a natively invalid form', async () => {
    const wrapper = mount(ReservationForm, { props: { supplier: 1, credit, canManage: true }, attachTo: document.body, global: global() })
    await wrapper.get('[data-action="reserve"]').trigger('click'); await flushPromises()
    expect(ops.reserveCredit).not.toHaveBeenCalled()
    wrapper.unmount()
  })
  it('refuses to write without the manage capability', async () => {
    const wrapper = mount(ReservationForm, { props: { supplier: 1, credit, canManage: false }, global: global() })
    expect(wrapper.get<HTMLButtonElement>('[data-action="reserve"]').element.disabled).toBe(true)
    expect(ops.reserveCredit).not.toHaveBeenCalled()
  })
})

describe('setoff', () => {
  const debt = { id: 4, charge_id: 2, unit_id: 1, unit_label: 'Jednotka 1', title_ref: 'Advance 01/2026', due_date: '2026-01-15', amount_cents: '10000', remaining_cents: '10000' }
  async function mounted() {
    ops.list.mockResolvedValue(page([debt])); ops.detail.mockResolvedValue({ ...debt, legal: {}, security: {}, groups: [], lines: [], accessories: [] })
    const wrapper = mount(SetoffForm, { props: { supplier: 1, credit, canManage: true, canPost: true }, attachTo: document.body, global: global() })
    await flushPromises()
    return wrapper
  }
  async function fill(wrapper: Awaited<ReturnType<typeof mounted>>, tick: boolean) {
    await wrapper.get('[data-field="setoff-amount"]').setValue('10'); await wrapper.get('[data-field="setoff-date"]').setValue('2026-03-01'); await wrapper.get('[data-field="setoff-reason"]').setValue('Setoff')
    const box = wrapper.get('[data-field="debt-select"]'); await box.setValue(true); await flushPromises()
    await wrapper.get('[data-field="payer"] input').setValue('31'); await wrapper.get('[data-field="debtor"] input').setValue('31')
    await wrapper.get('[data-field="received-at"] input').setValue('2026-03-01T10:00:00+01:00')
    for (const [field, value] of [['scope', '10'], ['mutuality', 'Same group'], ['eligibility', 'Due and mature'], ['declaration', 'Letter'], ['delivery', 'Data box'], ['effect', 'On delivery']]) await wrapper.get(`[data-field="setoff-${field}"]`).setValue(value)
    await wrapper.get('[data-field="setoff-delivered-at"] input').setValue('2026-03-01T09:00:00+01:00')
    if (tick) { await wrapper.get('[data-field="setoff-certain"]').setValue(true); await wrapper.get('[data-field="setoff-unconditional"]').setValue(true) }
  }
  it('begins with unchecked legal boxes and blocks the preview until they are confirmed', async () => {
    const wrapper = await mounted()
    expect(wrapper.get<HTMLInputElement>('[data-field="setoff-certain"]').element.checked).toBe(false)
    expect(wrapper.get<HTMLInputElement>('[data-field="setoff-unconditional"]').element.checked).toBe(false)
    await fill(wrapper, false)
    await wrapper.get('[data-action="setoff-preview"]').trigger('click'); await flushPromises()
    expect(ops.setoffPreview).not.toHaveBeenCalled()
    wrapper.unmount()
  })
  it('previews and confirms exactly the displayed request with one token', async () => {
    ops.setoffPreview.mockResolvedValue({ preview_hash: 'setoff-hash', snapshot: { applied_cents: '1000', unknown_part: null } })
    ops.setoffConfirm.mockResolvedValue({ id: 3 })
    const wrapper = await mounted(); await fill(wrapper, true)
    await wrapper.get('[data-action="setoff-preview"]').trigger('click'); await flushPromises()
    const request = ops.setoffPreview.mock.calls[0]![1]
    expect(request).toMatchObject({ kind: 'setoff', credit_id: 5, expected_credit_version: 7, amount_cents: '1000', debts: [4], legal: { certain: true, unconditional: true, scope_cents: '1000', mutuality_evidence: 'Same group' }, payment: { payer_id: 31, debtor_id: 31 } })
    expect(wrapper.text()).toContain('propertyPayments.unknown')
    await wrapper.get('[data-action="setoff-confirm"]').trigger('click'); await flushPromises()
    expect(ops.setoffConfirm).toHaveBeenCalledWith(1, request, 'setoff-hash', expect.stringMatching(token))
    wrapper.unmount()
  })
  it('drops the preview and issues a new token after the legal text is edited', async () => {
    ops.setoffPreview.mockResolvedValue({ preview_hash: 'h', snapshot: {} })
    const wrapper = await mounted(); await fill(wrapper, true)
    await wrapper.get('[data-action="setoff-preview"]').trigger('click'); await flushPromises()
    await wrapper.get('[data-field="setoff-mutuality"]').setValue('Other group')
    expect(wrapper.find('[data-action="setoff-confirm"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('corrections', () => {
  const adjustment = { id: 8, row_version: 2, unit_id: 1, unit_label: 'Jednotka 1', accounting_date: '2026-02-20', due_date: '2026-03-01', status: 'draft', amount_cents: '-2500', previous_charge_id: 3 }
  const options = { charge: { ...adjustment, lines: [{ id: 61, label: 'Advance difference', amount_cents: '-2500', component_id: 1, registered_debt_id: null }], parties: [] }, original_lines: [{ id: 50, label: 'Advance 01/2026', amount_cents: '10000' }], allocations: [{ id: 70, original_line_id: 50, label: 'Payment 01.02.', amount_cents: '10000', available_relief_cents: '2500' }], parties: [{ id: 21, label: 'Owner A' }] }
  it('anchors the negative line to the paid original line and previews the exact request', async () => {
    ops.list.mockResolvedValue(page([adjustment, { ...adjustment, id: 9, amount_cents: '100' }])); ops.correctionOptions.mockResolvedValue(options)
    ops.correctionPreview.mockResolvedValue({ preview_hash: 'c-hash', snapshot: { relief_cents: '2500' } }); ops.correctionConfirm.mockResolvedValue({ id: 1 })
    const wrapper = mount(CorrectionForm, { props: { supplier: 1, unitId: 0, canManage: true, canPost: true }, attachTo: document.body, global: global() })
    await flushPromises()
    expect(wrapper.findAll('[data-field="adjustment-select"]')).toHaveLength(1)
    await wrapper.get('[data-field="adjustment-select"]').trigger('change'); await flushPromises()
    expect(ops.correctionOptions).toHaveBeenCalledWith(1, 8)
    expect(wrapper.get<HTMLInputElement>('[data-field="anchor-amount"]').element.value).toBe('25.00')
    await wrapper.get('[data-field="anchor-original"]').setValue('50')
    await wrapper.get('[data-field="anchor-evidence"]').setValue('Bank statement')
    await wrapper.get('[data-field="relief-amount"]').setValue('25')
    await wrapper.get('[data-field="correction-date"]').setValue('2026-03-02'); await wrapper.get('[data-field="correction-reason"]').setValue('Overstated advance')
    await wrapper.get('[data-action="correction-preview"]').trigger('click'); await flushPromises()
    const request = ops.correctionPreview.mock.calls[0]![1]
    expect(request).toEqual({ adjustment_charge_id: 8, expected_version: 2, accounting_date: '2026-03-02', reason: 'Overstated advance', anchors: [{ adjustment_line_id: 61, original_line_id: 50, amount_cents: '2500', evidence: 'Bank statement' }], reliefs: [{ allocation_id: 70, amount_cents: '2500' }] })
    await wrapper.get('[data-action="correction-confirm"]').trigger('click'); await flushPromises()
    expect(ops.correctionConfirm).toHaveBeenCalledWith(1, request, 'c-hash', expect.stringMatching(token))
    wrapper.unmount()
  })
  it('refuses a relief above the currently available relief', async () => {
    ops.list.mockResolvedValue(page([adjustment])); ops.correctionOptions.mockResolvedValue(options)
    const wrapper = mount(CorrectionForm, { props: { supplier: 1, unitId: 0, canManage: true, canPost: true }, attachTo: document.body, global: global() })
    await flushPromises(); await wrapper.get('[data-field="adjustment-select"]').trigger('change'); await flushPromises()
    await wrapper.get('[data-field="anchor-original"]').setValue('50'); await wrapper.get('[data-field="anchor-evidence"]').setValue('Statement')
    await wrapper.get('[data-field="relief-amount"]').setValue('25.01')
    await wrapper.get('[data-field="correction-date"]').setValue('2026-03-02'); await wrapper.get('[data-field="correction-reason"]').setValue('Reason')
    await wrapper.get('[data-action="correction-preview"]').trigger('click'); await flushPromises()
    expect(ops.correctionPreview).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toBe('propertyPayments.formErrors.reliefBound')
    wrapper.unmount()
  })
})

describe('reversal', () => {
  const operation = { id: 3, kind: 'allocation', status: 'posted', row_version: 6, amount_cents: '1000', accounting_date: '2026-02-01', reason: 'Payment', source_label: null, unit_labels: ['Jednotka 1'], credit_id: null }
  it('reverses an operation with the current version for an administrator', async () => {
    ops.reverseOperation.mockResolvedValue({ id: 3 })
    const wrapper = mount(ReverseForm, { props: { supplier: 1, kind: 'operation', row: operation, canReverse: true }, global: global() })
    await wrapper.get('[data-field="reverse-date"]').setValue('2026-03-05'); await wrapper.get('[data-field="reverse-reason"]').setValue('Wrong unit')
    await wrapper.get('[data-action="reverse-submit"]').trigger('click'); await flushPromises()
    expect(ops.reverseOperation).toHaveBeenCalledWith(1, 3, { expected_version: 6, accounting_date: '2026-03-05', reason: 'Wrong unit' }, expect.stringMatching(token))
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })
  it('reverses a correction through its own route with effect time and evidence', async () => {
    ops.reverseCorrection.mockResolvedValue({ id: 1 })
    const wrapper = mount(ReverseForm, { props: { supplier: 1, kind: 'correction', row: { id: 12, status: 'posted', row_version: 3, adjustment_charge_id: 8, operation_id: 3, credit_id: null, relief_cents: '2500', accounting_date: '2026-03-02', reason: 'x', unit_id: 1, unit_label: 'Jednotka 1' }, canReverse: true }, global: global() })
    await wrapper.get('[data-field="reverse-date"]').setValue('2026-03-05'); await wrapper.get('[data-field="reverse-reason"]').setValue('Mistake')
    await wrapper.get('[data-field="reverse-effective"] input').setValue('2026-03-05T08:00:00+01:00'); await wrapper.get('[data-field="reverse-evidence"]').setValue('Approval')
    await wrapper.get('[data-action="reverse-submit"]').trigger('click'); await flushPromises()
    expect(ops.reverseCorrection).toHaveBeenCalledWith(1, 12, { expected_version: 3, accounting_date: '2026-03-05', effective_at: '2026-03-05T08:00:00+01:00', reason: 'Mistake', evidence: 'Approval' }, expect.stringMatching(token))
    expect(ops.reverseOperation).not.toHaveBeenCalled()
  })
  it('hides reversal and correction entry from users without the capability', async () => {
    ops.list.mockResolvedValue(page([operation]))
    const wrapper = mount(CorrectionsHistoryTab, { props: { supplier: 1, units: [], canManage: false, canPost: false, canReverse: false }, global: global() })
    await flushPromises()
    expect(wrapper.find('[data-action="operation-reverse"]').exists()).toBe(false)
    expect(wrapper.find('[data-tab="new"]').exists()).toBe(false)
    const admin = mount(CorrectionsHistoryTab, { props: { supplier: 1, units: [], canManage: true, canPost: true, canReverse: true }, global: global() })
    await flushPromises()
    expect(admin.find('[data-action="operation-reverse"]').exists()).toBe(true)
    expect(admin.find('[data-tab="new"]').exists()).toBe(true)
  })
  it('does not offer the generic reversal for correction operations', async () => {
    ops.list.mockResolvedValue(page([{ ...operation, kind: 'correction' }]))
    const wrapper = mount(CorrectionsHistoryTab, { props: { supplier: 1, units: [], canManage: true, canPost: true, canReverse: true }, global: global() })
    await flushPromises()
    expect(wrapper.find('[data-action="operation-reverse"]').exists()).toBe(false)
  })
})

describe('reservations and ABO', () => {
  it('previews, confirms and downloads the exact windows-1250 bytes from content_base64', async () => {
    const bytes = new Uint8Array([0x31, 0xe8, 0xf8, 0x9e, 0x0d, 0x0a, 0xff])
    ops.bankAccounts.mockResolvedValue([{ id: 3, label: 'Main account', account_number: '1000000005', bank_code: '0100' }])
    ops.aboPreview.mockResolvedValue({ preview_hash: 'abo-hash', snapshot: { total_cents: '5000' } })
    ops.aboConfirm.mockResolvedValue({ id: 1, filename: 'refunds.abo', content_base64: btoa(String.fromCharCode(...bytes)), encoding: 'windows-1250', reservation_ids: [9], reservation_statuses: { 9: 'submitted' } })
    const parts: BlobPart[][] = []
    vi.stubGlobal('Blob', class { constructor(p: BlobPart[], public options: unknown) { parts.push(p) } })
    URL.createObjectURL = vi.fn(() => 'blob:abo'); URL.revokeObjectURL = vi.fn()
    const wrapper = mount(AboExport, { props: { supplier: 1, reservations: [reservation], selected: [9], canManage: true, canPost: true }, attachTo: document.body, global: global() })
    await flushPromises()
    expect(ops.bankAccounts).toHaveBeenCalledWith(1)
    await wrapper.get('[data-field="abo-account"]').setValue('3'); await wrapper.get('[data-field="abo-due"]').setValue('2026-03-10'); await wrapper.get('[data-field="abo-reason"]').setValue('Credit refunds')
    await wrapper.get('[data-action="abo-preview"]').trigger('click'); await flushPromises()
    expect(ops.aboPreview).not.toHaveBeenCalled()
    await wrapper.get('[data-field="abo-vs"]').setValue('2026001'); await wrapper.get('[data-field="abo-ks"]').setValue('308')
    await wrapper.get('[data-action="abo-preview"]').trigger('click'); await flushPromises()
    const request = ops.aboPreview.mock.calls[0]![1]
    expect(request).toEqual({ reservation_ids: [9], expected_versions: { 9: 4 }, payment_symbols: { 9: { variable_symbol: '2026001', constant_symbol: '308' } }, bank_account_id: 3, due_date: '2026-03-10', reason: 'Credit refunds' })
    await wrapper.get('[data-action="abo-confirm"]').trigger('click'); await flushPromises()
    expect(ops.aboConfirm).toHaveBeenCalledWith(1, request, 'abo-hash', expect.stringMatching(token))
    expect([...(parts[0]![0] as Uint8Array)]).toEqual([...bytes])
    expect(URL.createObjectURL).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[role="status"]').text()).toBe('propertyPayments.abo.done')
    expect(wrapper.get('[data-row="abo-status"]').attributes('data-status')).toBe('submitted')
    wrapper.unmount()
  })
  it('requires an explicit without-VS choice, which starts unchecked and sends the flag instead of a symbol', async () => {
    ops.bankAccounts.mockResolvedValue([{ id: 3, label: null, account_number: '1000000005', bank_code: '0100' }])
    ops.aboPreview.mockResolvedValue({ preview_hash: 'h', snapshot: {} })
    const wrapper = mount(AboExport, { props: { supplier: 1, reservations: [reservation], selected: [9], canManage: true, canPost: true }, attachTo: document.body, global: global() })
    await flushPromises()
    expect(wrapper.get<HTMLInputElement>('[data-field="abo-no-vs"]').element.checked).toBe(false)
    await wrapper.get('[data-field="abo-account"]').setValue('3'); await wrapper.get('[data-field="abo-due"]').setValue('2026-03-10'); await wrapper.get('[data-field="abo-reason"]').setValue('Refunds')
    await wrapper.get('[data-field="abo-no-vs"]').setValue(true)
    expect(wrapper.get<HTMLInputElement>('[data-field="abo-vs"]').element.disabled).toBe(true)
    await wrapper.get('[data-action="abo-preview"]').trigger('click'); await flushPromises()
    expect(ops.aboPreview.mock.calls[0]![1].payment_symbols).toEqual({ 9: { allow_missing_variable_symbol: true } })
    wrapper.unmount()
  })
  it('shows a cancelled retry result as cancelled and never downloads it as a new order', async () => {
    ops.bankAccounts.mockResolvedValue([{ id: 3, label: 'Main', account_number: '1000000005', bank_code: '0100' }])
    ops.aboPreview.mockResolvedValue({ preview_hash: 'h', snapshot: {} })
    ops.aboConfirm.mockResolvedValue({ id: 1, filename: 'old.abo', content_base64: btoa('x'), encoding: 'windows-1250', reservation_ids: [9], reservation_statuses: { 9: 'cancelled' } })
    URL.createObjectURL = vi.fn(() => 'blob:abo'); URL.revokeObjectURL = vi.fn()
    const wrapper = mount(AboExport, { props: { supplier: 1, reservations: [reservation], selected: [9], canManage: true, canPost: true }, attachTo: document.body, global: global() })
    await flushPromises()
    await wrapper.get('[data-field="abo-account"]').setValue('3'); await wrapper.get('[data-field="abo-due"]').setValue('2026-03-10'); await wrapper.get('[data-field="abo-reason"]').setValue('Refunds'); await wrapper.get('[data-field="abo-vs"]').setValue('1')
    await wrapper.get('[data-action="abo-preview"]').trigger('click'); await flushPromises()
    await wrapper.get('[data-action="abo-confirm"]').trigger('click'); await flushPromises()
    expect(URL.createObjectURL).not.toHaveBeenCalled()
    expect(wrapper.find('[data-flag="abo-cancelled"]').exists()).toBe(true)
    expect(wrapper.find('[data-action="abo-download"]').exists()).toBe(false)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    wrapper.unmount()
  })
  it('downloads the immutable export again through the download route with the current statuses', async () => {
    const bytes = new Uint8Array([0x41, 0xe8])
    ops.bankAccounts.mockResolvedValue([{ id: 3, label: 'Main', account_number: '1000000005', bank_code: '0100' }])
    ops.aboPreview.mockResolvedValue({ preview_hash: 'h', snapshot: {} })
    ops.aboConfirm.mockResolvedValue({ id: 77, filename: 'a.abo', content_base64: btoa('A'), encoding: 'windows-1250', reservation_ids: [9], reservation_statuses: { 9: 'submitted' } })
    ops.aboDownload.mockResolvedValue({ id: 77, filename: 'a.abo', content_base64: btoa(String.fromCharCode(...bytes)), encoding: 'windows-1250', reservation_ids: [9], reservation_statuses: { 9: 'paid' } })
    const parts: BlobPart[][] = []
    vi.stubGlobal('Blob', class { constructor(p: BlobPart[]) { parts.push(p) } })
    URL.createObjectURL = vi.fn(() => 'blob:abo'); URL.revokeObjectURL = vi.fn()
    const wrapper = mount(AboExport, { props: { supplier: 1, reservations: [reservation], selected: [9], canManage: true, canPost: true }, attachTo: document.body, global: global() })
    await flushPromises()
    await wrapper.get('[data-field="abo-account"]').setValue('3'); await wrapper.get('[data-field="abo-due"]').setValue('2026-03-10'); await wrapper.get('[data-field="abo-reason"]').setValue('Refunds'); await wrapper.get('[data-field="abo-vs"]').setValue('1')
    await wrapper.get('[data-action="abo-preview"]').trigger('click'); await flushPromises()
    await wrapper.get('[data-action="abo-confirm"]').trigger('click'); await flushPromises()
    await wrapper.get('[data-action="abo-download"]').trigger('click'); await flushPromises()
    expect(ops.aboDownload).toHaveBeenCalledWith(1, 77)
    expect([...(parts[1]![0] as Uint8Array)]).toEqual([...bytes])
    expect(wrapper.get('[data-row="abo-status"]').attributes('data-status')).toBe('paid')
    wrapper.unmount()
  })
  it('does not offer the export to a user without the manage capability', async () => {
    ops.bankAccounts.mockResolvedValue([])
    const panel = mount(AboExport, { props: { supplier: 1, reservations: [reservation], selected: [9], canManage: false, canPost: false }, global: global() })
    await flushPromises()
    expect(panel.get<HTMLButtonElement>('[data-action="abo-preview"]').element.disabled).toBe(true)
    expect(panel.find('[data-flag="no-accounts"]').exists()).toBe(true)
  })
  it('cancels an unpaid remainder and cancels a submitted order only with external evidence through the real endpoint', async () => {
    ops.cancelReservation.mockResolvedValue({ id: 9 })
    const plain = mount(ReservationCancelForm, { props: { supplier: 1, reservation, mode: 'cancel', canManage: true }, attachTo: document.body, global: global() })
    await plain.get('[data-field="cancel-reason"]').setValue('Owner withdrew'); await plain.get('[data-action="cancel-submit"]').trigger('click'); await flushPromises()
    expect(ops.cancelReservation).toHaveBeenCalledWith(1, 9, 4, 'Owner withdrew', expect.stringMatching(token))
    ops.cancelSubmission.mockResolvedValue({ id: 9 })
    const submitted = mount(ReservationCancelForm, { props: { supplier: 1, reservation: { ...reservation, status: 'submitted' }, mode: 'submission', canManage: true }, attachTo: document.body, global: global() })
    await submitted.get('[data-field="cancel-reason"]').setValue('Bank instruction withdrawn')
    await submitted.get('[data-action="cancel-submit"]').trigger('click'); await flushPromises()
    expect(ops.cancelSubmission).not.toHaveBeenCalled()
    await submitted.get('[data-field="cancel-evidence"]').setValue('Bank confirmation')
    await submitted.get('[data-action="cancel-submit"]').trigger('click'); await flushPromises()
    expect(ops.cancelSubmission).toHaveBeenCalledWith(1, 9, 4, 'Bank instruction withdrawn', 'Bank confirmation', expect.stringMatching(token))
    expect(submitted.emitted('changed')).toHaveLength(1)
    plain.unmount(); submitted.unmount()
  })
  it('shows the server error of a failed submission cancellation and saves nothing', async () => {
    ops.cancelSubmission.mockRejectedValue({ response: { status: 409, data: { error: { message: 'Order was already paid.' } } } })
    const submitted = mount(ReservationCancelForm, { props: { supplier: 1, reservation: { ...reservation, status: 'submitted' }, mode: 'submission', canManage: true }, global: global() })
    await submitted.get('[data-field="cancel-reason"]').setValue('x'); await submitted.get('[data-field="cancel-evidence"]').setValue('y')
    await submitted.get('[data-action="cancel-submit"]').trigger('click'); await flushPromises()
    expect(submitted.get('[role="alert"]').text()).toBe('Order was already paid.')
    expect(submitted.emitted('changed')).toBeUndefined()
  })
})

describe('read-only tabs', () => {
  it('hides registration for read-only users', async () => {
    ops.list.mockResolvedValue(page([]))
    const wrapper = mount(DebtsCreditsTab, { props: { supplier: 1, units: [], canManage: false, canPost: false }, global: global() })
    await flushPromises()
    expect(wrapper.find('[data-tab="register"]').exists()).toBe(false)
    expect(wrapper.find('[data-tab="credits"]').exists()).toBe(true)
  })
})
