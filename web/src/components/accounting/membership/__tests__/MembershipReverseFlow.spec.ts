import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Flow from '../MembershipReverseFlow.vue'
import { formatDateRange } from '../membershipFormat'
import type { ClaimRow, MembershipPreview } from '@/api/nonprofitMembership'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({ preview: vi.fn(), confirm: vi.fn() }))
vi.mock('@/api/nonprofitMembership', () => ({ membershipApi: api }))

const ActionBar = defineComponent({
  props: ['actions'],
  setup: p => () => h('div', (p.actions as Array<{ key: string; label: string; variant?: string; disabled?: boolean; show?: unknown; run: () => void }>)
    .filter(a => a.show !== false).map(a => h('button', { 'data-action': a.key, 'data-variant': a.variant, disabled: a.disabled, onClick: a.run }, a.label))),
})
const DateInput = defineComponent({
  props: ['modelValue'], emits: ['update:modelValue'],
  setup: (p, { emit, attrs }) => () => h('input', { ...attrs, value: p.modelValue, onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).value) }),
})
const period = { id: 11, supplier_id: 5, fiscal_year: 2026, starts_on: '2000-01-01', ends_on: '2099-12-31', status: 'open' as const, closed_at: null, created_at: '' }
const claim = (overrides: Partial<ClaimRow> = {}): ClaimRow => ({
  claim_id: 31, case_id: 44, operation_id: 8, member_id: 9, client_id: 3, amount_cents: 120050, reversal_claim_id: null,
  accounting_date: '2026-03-01', due_date: '2026-03-31', journal_entry_id: 100, receivable_line_id: 200, line_no: 1, account_id: 7,
  posted_at: '2026-03-01 10:00:00', reversed_by: null, title_key: 'DUES-2026', period_from: '2026-01-01', period_to: '2026-12-31',
  settlement: { status: 'unreconciled', paid_cents: null, open_cents: null, pairing_id: null, evidence: 'no_native_pairing' }, ...overrides,
})
const hash = 'b'.repeat(64)
const preview = (): MembershipPreview => ({
  request: {}, case_id: 44, expected_case_version: 2, preview_hash: hash, case: { id: 44, row_version: 2 },
  original_operation_id: 8, target_period: { id: 11, fiscal_year: 2026, starts_on: '2000-01-01', ends_on: '2099-12-31', status: 'open' },
  original: { id: 8, proof: { accounts: { receivable: { account_code: '311', name: 'Receivables' }, revenue: { account_code: '684', name: 'Dues' } } } },
})

function mounted(props: Record<string, unknown> = {}, locale = 'cs') {
  return mount(Flow, {
    props: { supplier: 5, canManage: true, member: { id: 9, member_number: 'M-1', label: 'Synthetic member' }, claim: claim(), periods: [period], ...props },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { cs, en } })], stubs: { ActionBar, DateInput } },
  })
}
async function fill(w: ReturnType<typeof mounted>) {
  await flushPromises()
  await w.get('[data-field="reverse-date"]').setValue('2026-10-19')
  await w.get('[data-field="reverse-reason"]').setValue('Duplicate charge')
}
async function review(w: ReturnType<typeof mounted>) {
  await w.get('[data-action="reverse-preview"]').trigger('click')
  await flushPromises()
  expect(w.find('[data-section="reverse-preview"]').exists()).toBe(true)
}

beforeEach(() => {
  vi.clearAllMocks()
  api.preview.mockImplementation(() => Promise.resolve(preview()))
  api.confirm.mockResolvedValue({ case: { id: 44, row_version: 3 }, operation: { id: 9, operation: 'reverse' }, idempotent_replay: false })
})

describe('Membership reverse flow', () => {
  it('does not preview while the visible reversal date is invalid', async () => {
    const w = mounted()
    await fill(w)
    w.get<HTMLInputElement>('[data-field="reverse-date"]').element.setCustomValidity('Invalid visible date')
    await w.get('[data-action="reverse-preview"]').trigger('click')
    await flushPromises()
    expect(api.preview).not.toHaveBeenCalled()
    w.unmount()
  })

  it.each(['cs', 'en'])('renders translated %s texts and the picked claim without any hidden id field', async locale => {
    const w = mounted({}, locale)
    await fill(w)
    expect(w.text()).toContain((locale === 'cs' ? cs : en).nonprofitMembership.reverseTitle)
    expect(w.text()).not.toContain('nonprofitMembership.')
    expect(w.get('[data-section="reverse-claim"]').text()).toContain('DUES-2026')
    expect(w.find('[data-field="case-id"]').exists()).toBe(false)
    w.unmount()
  })

  it('previews the exact reversal keys from the picked claim and never autosaves', async () => {
    const w = mounted()
    await fill(w)
    expect(api.preview).not.toHaveBeenCalled()
    await review(w)
    expect(api.preview.mock.calls[0]).toEqual([5, { operation: 'reverse', request: { case_id: 44, accounting_date: '2026-10-19', reason: 'Duplicate charge' } }])
    expect(api.confirm).not.toHaveBeenCalled()
    expect(w.get('[data-section="reverse-plan"]').text()).toContain('684')
    w.unmount()
  })

  it('confirms with the previewed case version and hash after acknowledgement', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    expect(w.get('[data-action="confirm"]').attributes('disabled')).toBeDefined()
    await w.get('[data-field="ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.confirm.mock.calls[0]![1]).toEqual({
      operation: 'reverse', request: { case_id: 44, accounting_date: '2026-10-19', reason: 'Duplicate charge' },
      expected_case_version: 2, preview_hash: hash, idempotency_key: expect.stringMatching(/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,79}$/),
    })
    expect(w.emitted('done')).toHaveLength(1)
    w.unmount()
  })

  it('invalidates the preview when the reason changes', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    await w.get('[data-field="reverse-reason"]').setValue('Another reason')
    expect(w.find('[data-section="reverse-preview"]').exists()).toBe(false)
    expect(api.confirm).not.toHaveBeenCalled()
    w.unmount()
  })

  it('retries an unknown outcome with the identical payload and token', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    await w.get('[data-field="ack"]').setValue(true)
    api.confirm.mockRejectedValueOnce(new Error('timeout')).mockResolvedValueOnce({ case: { id: 44, row_version: 3 }, operation: { id: 9, operation: 'reverse' }, idempotent_replay: true })
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-section="uncertain"]').exists()).toBe(true)
    expect(w.get('fieldset').attributes('disabled')).toBeDefined()
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.confirm).toHaveBeenCalledTimes(2)
    expect(api.confirm.mock.calls[1]).toEqual(api.confirm.mock.calls[0])
    w.unmount()
  })

  it('discards a late preview after the supplier changed', async () => {
    const w = mounted()
    await fill(w)
    let resolve!: (value: MembershipPreview) => void
    api.preview.mockImplementationOnce(() => new Promise<MembershipPreview>(r => { resolve = r }))
    await w.get('[data-action="reverse-preview"]').trigger('click')
    await w.setProps({ supplier: 6 })
    resolve(preview())
    await flushPromises()
    expect(w.find('[data-section="reverse-preview"]').exists()).toBe(false)
    w.unmount()
  })

  it('does not offer a preview for an already reversed claim', async () => {
    const w = mounted({ claim: claim({ reversal_claim_id: 55, settlement: { status: 'reversed', paid_cents: null, open_cents: 0, pairing_id: null, evidence: 'membership_reversal_claim' } }) })
    await fill(w)
    expect(w.get('[data-action="reverse-preview"]').attributes('disabled')).toBeDefined()
    await w.get('[data-action="reverse-preview"]').trigger('click')
    expect(api.preview).not.toHaveBeenCalled()
    w.unmount()
  })

  it('shows an ambiguous payment as unknown, not as paid or unpaid', async () => {
    const w = mounted({ claim: claim({ settlement: { status: 'ambiguous', paid_cents: null, open_cents: null, pairing_id: 4, evidence: 'pairing_not_exact_native_payment' } }) })
    await fill(w)
    expect(w.get('[data-field="settlement-status"]').text()).toBe(cs.nonprofitMembership.settlement_ambiguous)
    expect(w.get('[data-field="settlement-unknown"]').text()).toBe(cs.nonprofitMembership.paymentUnknown)
    expect(w.find('[data-field="settlement-amounts"]').exists()).toBe(false)
    w.unmount()
  })

  it('renders no mutating action without write rights', async () => {
    const w = mounted({ canManage: false })
    await flushPromises()
    expect(w.find('[data-action="reverse-preview"]').exists()).toBe(false)
    expect(api.preview).not.toHaveBeenCalled()
    w.unmount()
  })

  it('previews as a neutral step and colours only the confirmation of the reversal as destructive', async () => {
    const w = mounted()
    await fill(w)
    expect(w.get('[data-action="reverse-preview"]').attributes('data-variant')).toBe('primary')
    await review(w)
    expect(w.get('[data-action="confirm"]').attributes('data-variant')).toBe('danger')
    w.unmount()
  })

  it('shows the claim and the period choice in the app date format', async () => {
    const w = mounted()
    await flushPromises()
    expect(w.get('[data-field="reverse-claim-period"]').text()).toBe(formatDateRange('2026-01-01', '2026-12-31'))
    expect(w.get('[data-section="reverse-claim"]').text()).not.toMatch(/\d{4}-\d{2}-\d{2}/)
    expect(w.get('[data-field="reverse-period"]').text()).toContain(formatDateRange('2000-01-01', '2099-12-31'))
    w.unmount()
  })

  it('does not show the internal case version in the reversal preview', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    expect(w.get('[data-section="reverse-preview"]').text()).not.toContain(cs.nonprofitMembership.caseVersion)
    expect(w.get('[data-section="reverse-preview"]').text()).not.toMatch(/verze|version/i)
    w.unmount()
  })
})
