import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { formatDate } from '@/composables/useFormat'
import Flow from '../MembershipIssueFlow.vue'
import MembershipRates from '../MembershipRates.vue'
import type { MemberRevision, MembershipPreview } from '@/api/nonprofitMembership'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({ preview: vi.fn(), confirm: vi.fn(), classificationOptions: vi.fn() }))
const accounting = vi.hoisted(() => ({ listAccounts: vi.fn() }))
const dppo = vi.hoisted(() => ({ profile: vi.fn() }))
vi.mock('@/api/nonprofitMembership', () => ({ membershipApi: api }))
vi.mock('@/api/accounting', () => ({ accountingApi: accounting }))
vi.mock('@/api/nonprofitDppo', () => ({ nonprofitDppoApi: dppo }))

const ActionBar = defineComponent({
  props: ['actions'],
  setup: p => () => h('div', (p.actions as Array<{ key: string; label: string; variant?: string; loading?: boolean; disabled?: boolean; show?: unknown; run: () => void }>)
    .filter(a => a.show !== false).map(a => h('button', { 'data-action': a.key, 'data-variant': a.variant, 'data-loading': a.loading ? 'true' : undefined, disabled: a.disabled, onClick: a.run }, a.label))),
})
const RouterLink = defineComponent({
  props: ['to'],
  setup: (p, { slots, attrs }) => () => h('a', { ...attrs, 'data-to': JSON.stringify(p.to) }, slots.default?.()),
})
const DateInput = defineComponent({
  props: ['modelValue'], emits: ['update:modelValue'],
  setup: (p, { emit, attrs }) => () => h('input', { ...attrs, value: p.modelValue, onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).value) }),
})
const SearchableSelect = defineComponent({
  props: ['modelValue', 'options', 'disabled'], emits: ['update:modelValue'],
  setup: (p, { emit, attrs }) => () => {
    const options = p.options as Array<{ value: string | number; label: string }>
    return h('select', {
      ...attrs, value: p.modelValue ?? '', disabled: p.disabled,
      onChange: (e: Event) => {
        const hit = options.find(o => String(o.value) === (e.target as HTMLSelectElement).value)
        emit('update:modelValue', hit ? hit.value : null)
      },
    }, [h('option', { value: '' }), ...options.map(o => h('option', { value: String(o.value) }, o.label))])
  },
})

const period = { id: 11, supplier_id: 5, fiscal_year: 2026, starts_on: '2000-01-01', ends_on: '2099-12-31', status: 'open' as const, closed_at: null, created_at: '' }
const revision: MemberRevision = {
  id: 1, supplier_id: 5, member_id: 9, revision_no: 1, previous_id: null, effective_from: '2020-01-01', status: 'active',
  legal_basis: { organisation_form: 'association', relationship: 'nonreciprocal_membership', reference: 'Statutes', evidence: 'Application' },
  idempotency_key: 'k1', request_hash: 'a', parent_hash: 'b', record_hash: 'c', created_by: 1,
}
const account = (id: number, code: string, name: string, type: string) => ({ id, supplier_id: 5, account_code: code, name, account_type: type, normal_side: null, is_synthetic: false, parent_id: null, is_active: true, created_at: '' })
const legal = (entity = 'association', nonbusiness = true) => ({
  period, entity_types: [], kinds: [], capabilities: {},
  latest: { id: 77, period_id: 11, revision_no: 3, previous_id: null, record_hash: 'h', reason: 'r', created_by: 1,
    profile: { kind: 'x', entity_type: entity, main_activity_nonbusiness: nonbusiness, evidence: 'e', legal_basis: 'b' } },
})
const hash = 'a'.repeat(64)
const explicit = { origin: 'explicit' as const, ref: null }
const proofClassification = (tax = 1202): NonNullable<NonNullable<MembershipPreview['proof']>['classification']> => ({
  activity: { type_id: 101, value_id: 1001, semantic_key: 'main', origin: explicit },
  activity_kind: { type_id: 102, value_id: 1101, code: 'MEMBERSHIP', origin: explicit },
  tax_regime: { type_id: 103, value_id: tax, semantic_key: tax === 1201 ? 'subject' : 'out_of_scope', origin: explicit },
})
const previewResult = (version = 0): MembershipPreview => ({
  request: {}, case_id: null, expected_case_version: version, preview_hash: hash, case: null,
  proof: {
    member_revision: { id: 1, revision_no: 1, effective_from: '2020-01-01', status: 'active' },
    legal_profile: { id: 77, profile: { entity_type: 'association', main_activity_nonbusiness: true } },
    accounting_period: { id: 11, fiscal_year: 2026, starts_on: '2000-01-01', ends_on: '2099-12-31', status: 'open' },
    classification: proofClassification(),
  },
  journal_plan: [
    { account_code: '311', side: 'debit', amount: '1200.50', dimensions: { 101: 1001, 102: 1101, 103: 1202 }, dimension_origins: { 101: explicit, 102: explicit, 103: explicit } },
    { account_code: '684', side: 'credit', amount: '1200.50', dimensions: { 101: 1001, 102: 1101, 103: 1202 }, dimension_origins: { 101: explicit, 102: explicit, 103: explicit } },
  ],
})
const expectedRequest = {
  member_id: 9, title_key: 'DUES-2026', period_from: '2026-01-01', period_to: '2026-12-31', accounting_date: '2026-10-19', due_date: '2026-11-19',
  amount: '1200.50', receivable_account: '311', revenue_account: '684', title_reference: 'Statutes art. 4', evidence: 'Synthetic resolution',
  nonreciprocal: true, reason: 'Annual dues',
  classification: { activity_value_id: 1001, activity_kind_value_id: 1101, tax_regime_value_id: 1202 },
}
/** Tenantové osy dané firmy plus kontroly, které se nesmí nabídnout: cizí firma, neaktivní, nestandardní klíč. */
const dimType = (id: number, key: string, supplier = 5, active = true) => ({ id, supplier_id: supplier, system_key: key, is_active: active, name: key })
const dimValue = (id: number, typeId: number, name: string, semantic: string | null, supplier = 5, active = true) =>
  ({ id, type_id: typeId, supplier_id: supplier, semantic_key: semantic, is_active: active, name, code: name })
const overview = (supplier = 5) => ({
  enabled: true, group: null,
  types: [
    dimType(101, 'activity', supplier), dimType(102, 'activity_kind', supplier), dimType(103, 'tax_regime', supplier),
    dimType(201, 'activity', supplier + 1), dimType(203, 'tax_regime', supplier, false), dimType(204, 'cost_center', supplier),
  ],
  values: [
    dimValue(1001, 101, 'Main activity', 'main', supplier), dimValue(1002, 101, 'Economic activity', 'economic', supplier),
    dimValue(1003, 101, 'Custom activity', 'custom', supplier), dimValue(1004, 101, 'Retired main', 'main', supplier, false),
    dimValue(1005, 101, 'Foreign main', 'main', supplier + 1), dimValue(1006, 201, 'Foreign type main', 'main', supplier),
    dimValue(1101, 102, 'Membership kind', null, supplier), dimValue(1102, 102, 'Retired kind', null, supplier, false),
    dimValue(1103, 102, 'Foreign kind', null, supplier + 1),
    dimValue(1201, 103, 'Taxable', 'subject', supplier), dimValue(1202, 103, 'Outside scope', 'out_of_scope', supplier),
    dimValue(1203, 103, 'Exempt label', 'exempt', supplier), dimValue(1204, 103, 'Custom regime', 'special', supplier),
    dimValue(1205, 103, 'Foreign taxable', 'subject', supplier + 1), dimValue(1206, 204, 'Cost centre', null, supplier),
  ],
})

function mounted(props: Record<string, unknown> = {}, locale = 'cs') {
  return mount(Flow, {
    props: { supplier: 5, canManage: true, member: { id: 9, member_number: 'M-1', label: 'Synthetic member' }, history: [revision], periods: [period], ...props },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { cs, en } })], stubs: { ActionBar, DateInput, SearchableSelect, RouterLink, MembershipRates: true } },
  })
}
async function fill(w: ReturnType<typeof mounted>, omit: string[] = []) {
  await flushPromises()
  const values: Record<string, string> = {
    'accounting-date': '2026-10-19', 'due-date': '2026-11-19', 'period-from': '2026-01-01', 'period-to': '2026-12-31',
    'title-key': 'DUES-2026', amount: '1200,50', receivable: '311', revenue: '684',
    activity: '1001', 'activity-kind': '1101', 'tax-regime': '1202',
    'title-reference': 'Statutes art. 4', evidence: 'Synthetic resolution', reason: 'Annual dues',
  }
  for (const [name, value] of Object.entries(values)) if (!omit.includes(name)) await w.get(`[data-field="${name}"]`).setValue(value)
  await w.get('[data-field="nonreciprocal"]').setValue(true)
}
async function review(w: ReturnType<typeof mounted>) {
  await w.get('[data-action="issue-preview"]').trigger('click')
  await flushPromises()
  expect(w.find('[data-section="issue-preview"]').exists()).toBe(true)
}
const token = /^[A-Za-z0-9][A-Za-z0-9_.:-]{0,79}$/

beforeEach(() => {
  vi.clearAllMocks()
  accounting.listAccounts.mockResolvedValue([account(1, '311', 'Receivables', 'asset'), account(2, '684', 'Membership dues', 'revenue'), account(3, '501', 'Material', 'expense')])
  dppo.profile.mockResolvedValue(legal())
  api.classificationOptions.mockImplementation((supplier: number) => Promise.resolve(overview(supplier)))
  api.preview.mockImplementation(() => Promise.resolve(previewResult()))
  api.confirm.mockResolvedValue({ case: { id: 4, row_version: 1 }, operation: { id: 8, operation: 'issue' }, idempotent_replay: false })
})

describe('Membership issue flow', () => {
  it('applies explicit rate defaults, permits overrides and keeps the rate reference only in the request', async () => {
    const w = mounted()
    await fill(w)
    w.findComponent(MembershipRates).vm.$emit('selected', { id: 42, supplier_id: 5, name: 'Synthetic standard', amount: '500.00',
      receivable_account: '311', revenue_account: '684', title_key: 'synthetic-standard', period_months: 6,
      title_reference: 'Synthetic resolution', evidence: 'Synthetic evidence', is_active: true, row_version: 1,
      classification: { activity_value_id: 1001, activity_kind_value_id: 1101, tax_regime_value_id: 1202 } })
    await flushPromises()
    expect((w.get('[data-field="amount"]').element as HTMLInputElement).value).toBe('500.00')
    expect((w.get('[data-field="period-to"]').element as HTMLInputElement).value).toBe('2026-06-30')
    await w.get('[data-field="amount"]').setValue('625')
    await review(w)
    expect(api.preview.mock.calls.at(-1)?.[1].request).toMatchObject({ rate_id: 42, amount: '625.00' })
    w.unmount()
  })

  it('does not preview a stale valid model while a visible date input is invalid', async () => {
    const w = mounted()
    await fill(w)
    w.get<HTMLInputElement>('[data-field="accounting-date"]').element.setCustomValidity('Invalid visible date')
    await w.get('[data-action="issue-preview"]').trigger('click')
    await flushPromises()
    expect(api.preview).not.toHaveBeenCalled()
    w.unmount()
  })

  it.each(['cs', 'en'])('renders only translated %s texts and offers just chart accounts of the right type', async locale => {
    const w = mounted({}, locale)
    await fill(w)
    const messages = (locale === 'cs' ? cs : en).nonprofitMembership
    expect(w.text()).toContain(messages.issueTitle)
    expect(w.text()).not.toContain('nonprofitMembership.')
    expect(w.get('[data-field="receivable"]').text()).toContain('311 Receivables')
    expect(w.get('[data-field="receivable"]').text()).not.toContain('684')
    expect(w.get('[data-field="revenue"]').text()).toContain('684 Membership dues')
    expect(w.get('[data-field="revenue"]').text()).not.toContain('501')
    w.unmount()
  })

  it('previews only on explicit request with the exact backend keys and never autosaves', async () => {
    const w = mounted()
    await fill(w)
    expect(api.preview).not.toHaveBeenCalled()
    expect(api.confirm).not.toHaveBeenCalled()
    await review(w)
    expect(api.preview).toHaveBeenCalledTimes(1)
    expect(api.preview.mock.calls[0]).toEqual([5, { operation: 'issue', request: expectedRequest }])
    expect(Object.keys(api.preview.mock.calls[0]![1].request).sort()).toEqual([
      'accounting_date', 'amount', 'classification', 'due_date', 'evidence', 'member_id', 'nonreciprocal', 'period_from', 'period_to',
      'reason', 'receivable_account', 'revenue_account', 'title_key', 'title_reference',
    ])
    expect(Object.keys(api.preview.mock.calls[0]![1].request.classification).sort()).toEqual(['activity_kind_value_id', 'activity_value_id', 'tax_regime_value_id'])
    expect(api.confirm).not.toHaveBeenCalled()
    expect(w.get('[data-section="issue-plan"]').text()).toContain('311')
    expect(w.get('[data-section="issue-plan"]').text()).toContain('684')
    expect(w.text()).toContain(cs.nonprofitMembership.taxUnclassified)
    w.unmount()
  })

  it('offers only membership revenue 684 and its analytics as the revenue account', async () => {
    accounting.listAccounts.mockResolvedValue([account(1, '311', 'Receivables', 'asset'), account(2, '684', 'Membership dues', 'revenue'),
      account(4, '684.100', 'Membership dues analytic', 'revenue'), account(5, '649', 'Other revenue', 'revenue')])
    const w = mounted()
    await fill(w)
    const revenue = w.get('[data-field="revenue"]').text()
    expect(revenue).toContain('684 Membership dues')
    expect(revenue).toContain('684.100 Membership dues analytic')
    expect(revenue).not.toContain('649')
    w.unmount()
  })

  it('shows the closing deferral proposal when the period crosses the balance sheet date', async () => {
    api.preview.mockImplementation(() => Promise.resolve({ ...previewResult(),
      deferral: { mechanism: 'closing.deferred_revenue_accrual', balance_sheet_date: '2026-12-31', deferred_amount: '600.25' } }))
    const w = mounted()
    await fill(w)
    await review(w)
    const deferral = w.get('[data-section="issue-deferral"]').text()
    expect(deferral).toContain(formatDate('2026-12-31'))
    expect(deferral).not.toContain('2026-12-31')
    expect(deferral).toContain('600')
    w.unmount()
  })

  it('shows no deferral proposal for a period inside the accounting period', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    expect(w.find('[data-section="issue-deferral"]').exists()).toBe(false)
    w.unmount()
  })

  it('needs acknowledgement and then confirms the exact previewed request once', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    expect(w.get('[data-action="confirm"]').attributes('data-variant')).toBe('success')
    expect(w.get('[data-action="confirm"]').attributes('disabled')).toBeDefined()
    await w.get('[data-field="ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.confirm).toHaveBeenCalledTimes(1)
    expect(api.confirm.mock.calls[0]![0]).toBe(5)
    expect(api.confirm.mock.calls[0]![1]).toEqual({
      operation: 'issue', request: expectedRequest, expected_case_version: 0, preview_hash: hash, idempotency_key: expect.stringMatching(token),
    })
    expect(w.emitted('done')).toHaveLength(1)
    w.unmount()
  })

  it('shows progress on the preview and on the confirmation while the server answers', async () => {
    let answerPreview: (value: MembershipPreview) => void = () => {}
    api.preview.mockImplementation(() => new Promise<MembershipPreview>(resolve => { answerPreview = resolve }))
    const w = mounted()
    await fill(w)
    expect(w.get('[data-action="issue-preview"]').attributes('data-loading')).toBeUndefined()
    await w.get('[data-action="issue-preview"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-action="issue-preview"]').attributes('data-loading')).toBe('true')
    answerPreview(previewResult())
    await flushPromises()
    api.confirm.mockReturnValue(new Promise(() => {}))
    await w.get('[data-field="ack"]').setValue(true)
    expect(w.get('[data-action="confirm"]').attributes('data-loading')).toBeUndefined()
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-action="confirm"]').attributes('data-loading')).toBe('true')
    w.unmount()
  })

  it('keeps expected_case_version of an existing case from the preview', async () => {
    api.preview.mockImplementation(() => Promise.resolve(previewResult(3)))
    const w = mounted()
    await fill(w)
    await review(w)
    await w.get('[data-field="ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.confirm.mock.calls[0]![1]).toMatchObject({ expected_case_version: 3, preview_hash: hash })
    w.unmount()
  })

  it('invalidates the preview and the frozen request when any input changes', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    await w.get('[data-field="amount"]').setValue('999')
    expect(w.find('[data-section="issue-preview"]').exists()).toBe(false)
    expect(w.find('[data-action="confirm"]').exists()).toBe(false)
    expect(api.confirm).not.toHaveBeenCalled()
    await review(w)
    expect(api.preview).toHaveBeenCalledTimes(2)
    expect(api.preview.mock.calls[1]![1].request.amount).toBe('999.00')
    await w.get('[data-field="ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.confirm.mock.calls[0]![1].request.amount).toBe('999.00')
    w.unmount()
  })

  it('invalidates the preview when the accounting period changes', async () => {
    const second = { ...period, id: 12, starts_on: '2000-01-01', ends_on: '2099-12-31' }
    const w = mounted({ periods: [period, second] })
    await fill(w)
    await review(w)
    await w.get('[data-field="issue-period"]').setValue('12')
    await flushPromises()
    expect(w.find('[data-section="issue-preview"]').exists()).toBe(false)
    expect(dppo.profile).toHaveBeenLastCalledWith(5, 12)
    w.unmount()
  })

  it('retries an uncertain outcome with the same frozen payload and token and keeps inputs locked', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    await w.get('[data-field="ack"]').setValue(true)
    api.confirm.mockRejectedValueOnce(new Error('network')).mockResolvedValueOnce({ case: { id: 4, row_version: 1 }, operation: { id: 8, operation: 'issue' }, idempotent_replay: true })
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.confirm).toHaveBeenCalledTimes(1)
    expect(w.get('[data-section="uncertain"]').text()).toContain(cs.nonprofitMembership.uncertain)
    expect(w.get('[data-section="uncertain"]').classes()).toEqual(expect.arrayContaining(['rounded-lg', 'bg-warning-50']))
    expect(w.get('fieldset').attributes('disabled')).toBeDefined()
    expect(w.get('[data-field="ack"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-action="issue-preview"]').exists()).toBe(false)
    expect(w.get('[data-action="confirm"]').text()).toBe(cs.nonprofitMembership.retrySame)
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.confirm).toHaveBeenCalledTimes(2)
    expect(api.confirm.mock.calls[1]).toEqual(api.confirm.mock.calls[0])
    expect(w.emitted('done')).toHaveLength(1)
    w.unmount()
  })

  it('treats a 5xx like an uncertain result but a 409 as a definite refusal without retry', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    await w.get('[data-field="ack"]').setValue(true)
    api.confirm.mockRejectedValueOnce({ response: { status: 503 } })
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-section="uncertain"]').exists()).toBe(true)
    api.confirm.mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'nonprofit_membership_preview_stale', message: 'Synthetic stale preview' } } } })
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.confirm).toHaveBeenCalledTimes(2)
    expect(w.find('[data-section="uncertain"]').exists()).toBe(false)
    expect(w.find('[data-section="issue-preview"]').exists()).toBe(false)
    expect(w.get('[data-section="issue-error"]').text()).toContain('Synthetic stale preview')
    w.unmount()
  })

  it('lets the user discard an uncertain request and then needs a new preview with a new token', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    await w.get('[data-field="ack"]').setValue(true)
    api.confirm.mockRejectedValueOnce(new Error('network'))
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    await w.get('[data-action="discard"]').trigger('click')
    expect(w.find('[data-section="issue-preview"]').exists()).toBe(false)
    expect(w.get('fieldset').attributes('disabled')).toBeUndefined()
    await review(w)
    await w.get('[data-field="ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.confirm).toHaveBeenCalledTimes(2)
    expect(api.confirm.mock.calls[1]![1].idempotency_key).not.toBe(api.confirm.mock.calls[0]![1].idempotency_key)
    w.unmount()
  })

  it('discards a preview that answers after the supplier changed', async () => {
    const w = mounted()
    await fill(w)
    let resolve!: (value: MembershipPreview) => void
    api.preview.mockImplementationOnce(() => new Promise<MembershipPreview>(r => { resolve = r }))
    await w.get('[data-action="issue-preview"]').trigger('click')
    await w.setProps({ supplier: 6 })
    await flushPromises()
    resolve(previewResult())
    await flushPromises()
    expect(w.find('[data-section="issue-preview"]').exists()).toBe(false)
    expect(api.confirm).not.toHaveBeenCalled()
    expect(accounting.listAccounts).toHaveBeenLastCalledWith({ supplierId: 6 })
    w.unmount()
  })

  it('discards a preview that answers after an input changed meanwhile', async () => {
    const w = mounted()
    await fill(w)
    let resolve!: (value: MembershipPreview) => void
    api.preview.mockImplementationOnce(() => new Promise<MembershipPreview>(r => { resolve = r }))
    await w.get('[data-action="issue-preview"]').trigger('click')
    await w.get('[data-field="reason"]').setValue('Edited while waiting')
    resolve(previewResult())
    await flushPromises()
    expect(w.find('[data-section="issue-preview"]').exists()).toBe(false)
    w.unmount()
  })

  it.each([
    ['no profile at all', null, 'profileMissing'],
    ['a foundation', legal('foundation', true), 'prerequisiteProfileNotAssociation'],
    ['an association with business main activity', legal('association', false), 'prerequisiteProfileNotAssociation'],
  ])('does not preview with %s as legal profile', async (_name, profile, message) => {
    dppo.profile.mockResolvedValue(profile === null ? { ...legal(), latest: null } : profile)
    const w = mounted()
    await fill(w)
    expect(w.get('[data-action="issue-preview"]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-section="issue-prerequisites"]').text()).toContain((cs.nonprofitMembership as unknown as Record<string, string>)[message as string])
    expect(w.find('[data-section="issue-problem"]').exists()).toBe(false)
    await w.get('[data-action="issue-preview"]').trigger('click')
    expect(api.preview).not.toHaveBeenCalled()
    w.unmount()
  })

  it('requires the explicit nonreciprocal acknowledgement and both chosen accounts', async () => {
    const w = mounted()
    await fill(w)
    await w.get('[data-field="nonreciprocal"]').setValue(false)
    expect(w.get('[data-action="issue-preview"]').attributes('disabled')).toBeDefined()
    await w.get('[data-field="nonreciprocal"]').setValue(true)
    expect(w.get('[data-action="issue-preview"]').attributes('disabled')).toBeUndefined()
    await w.get('[data-field="revenue"]').setValue('311')
    expect(w.get('[data-action="issue-preview"]').attributes('disabled')).toBeDefined()
    w.unmount()
  })

  it.each(['0', '-5', '1.001', '10 000'])('rejects the amount %s', async amount => {
    const w = mounted()
    await fill(w)
    await w.get('[data-field="amount"]').setValue(amount)
    expect(w.get('[data-action="issue-preview"]').attributes('disabled')).toBeDefined()
    w.unmount()
  })

  it('warns when the membership is not active on the accounting date but leaves the decision to the server', async () => {
    const w = mounted({ history: [{ ...revision, effective_from: '2999-01-01' }] })
    await fill(w)
    expect(w.get('[data-section="issue-status-warning"]').text()).toContain(cs.nonprofitMembership.issueMemberNoRevision)
    w.unmount()
  })

  it('shows the server profile next to the loaded one and warns about a mismatch', async () => {
    api.preview.mockImplementation(() => Promise.resolve({ ...previewResult(), proof: { ...previewResult().proof, legal_profile: { id: 78, profile: { entity_type: 'association', main_activity_nonbusiness: true } } } }))
    const w = mounted()
    await fill(w)
    await review(w)
    expect(w.find('[data-section="profile-mismatch"]').exists()).toBe(true)
    w.unmount()
  })

  it('renders no mutating action without write rights', async () => {
    const w = mounted({ canManage: false })
    await fill(w).catch(() => undefined)
    expect(w.find('[data-action="issue-preview"]').exists()).toBe(false)
    expect(api.preview).not.toHaveBeenCalled()
    expect(api.confirm).not.toHaveBeenCalled()
    w.unmount()
  })
})

describe('Membership issue flow: explicit accounting classification', () => {
  const optionLabels = (w: ReturnType<typeof mounted>, field: string) => w.get(`[data-field="${field}"]`).findAll('option').map(o => o.text()).filter(Boolean)

  it('offers only active values of this company on its three system axes with standard semantic keys', async () => {
    const w = mounted()
    await flushPromises()
    expect(api.classificationOptions).toHaveBeenCalledWith(5)
    expect(optionLabels(w, 'activity')).toEqual([cs.dimensions.system_values.activity.main, cs.dimensions.system_values.activity.economic])
    expect(optionLabels(w, 'activity-kind')).toEqual(['Membership kind'])
    expect(optionLabels(w, 'tax-regime')).toEqual([cs.dimensions.system_values.tax_regime.subject, cs.dimensions.system_values.tax_regime.out_of_scope, cs.dimensions.system_values.tax_regime.exempt])
    w.unmount()
  })

  it('never pre-selects a classification and does not preview without all three choices', async () => {
    const w = mounted()
    await fill(w, ['activity', 'activity-kind', 'tax-regime'])
    for (const field of ['activity', 'activity-kind', 'tax-regime']) expect((w.get(`[data-field="${field}"]`).element as HTMLSelectElement).value).toBe('')
    expect(w.get('[data-action="issue-preview"]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-section="issue-problem"]').text()).toContain(cs.nonprofitMembership.classificationRequired)
    await w.get('[data-action="issue-preview"]').trigger('click')
    expect(api.preview).not.toHaveBeenCalled()
    w.unmount()
  })

  it.each(['activity', 'activity-kind', 'tax-regime'])('does not preview when only %s is not chosen', async omitted => {
    const w = mounted()
    await fill(w, [omitted])
    expect(w.get('[data-action="issue-preview"]').attributes('disabled')).toBeDefined()
    await w.get('[data-action="issue-preview"]').trigger('click')
    await flushPromises()
    expect(api.preview).not.toHaveBeenCalled()
    w.unmount()
  })

  it.each([
    ['the tax regime axis is missing', (o: ReturnType<typeof overview>) => ({ ...o, types: o.types.filter(t => t.id !== 103) })],
    ['the axis of another company only', (o: ReturnType<typeof overview>) => ({ ...o, types: o.types.map(t => (t.id === 102 ? { ...t, supplier_id: 6 } : t)) })],
    ['the axis is inactive', (o: ReturnType<typeof overview>) => ({ ...o, types: o.types.map(t => (t.id === 101 ? { ...t, is_active: false } : t)) })],
    ['the activity axis has no standard value', (o: ReturnType<typeof overview>) => ({ ...o, values: o.values.filter(v => v.type_id !== 101 || v.semantic_key === 'custom') })],
    ['the activity kind axis has no active value', (o: ReturnType<typeof overview>) => ({ ...o, values: o.values.filter(v => v.type_id !== 102 || v.id === 1102) })],
  ])('does not preview when %s', async (_name, mutate) => {
    api.classificationOptions.mockResolvedValue(mutate(overview()))
    const w = mounted()
    await fill(w).catch(() => undefined)
    expect(w.find('[data-prerequisite="dimensions"]').exists()).toBe(true)
    expect(w.get('[data-action="issue-preview"]').attributes('disabled')).toBeDefined()
    await w.get('[data-action="issue-preview"]').trigger('click')
    await flushPromises()
    expect(api.preview).not.toHaveBeenCalled()
    w.unmount()
  })

  it('shows a loading state and blocks the preview until the lookup answers', async () => {
    let resolve!: (value: ReturnType<typeof overview>) => void
    api.classificationOptions.mockImplementationOnce(() => new Promise(r => { resolve = r }))
    const w = mounted()
    await flushPromises()
    expect(w.find('[data-section="classification-loading"]').exists()).toBe(true)
    expect(w.get('[data-action="issue-preview"]').attributes('disabled')).toBeDefined()
    resolve(overview())
    await flushPromises()
    expect(w.find('[data-section="classification-loading"]').exists()).toBe(false)
    expect(optionLabels(w, 'activity')).toHaveLength(2)
    w.unmount()
  })

  it('shows an error with a retry button, never previews meanwhile and recovers after the retry', async () => {
    api.classificationOptions.mockRejectedValueOnce(new Error('network'))
    const w = mounted()
    await fill(w, ['activity', 'activity-kind', 'tax-regime'])
    expect(w.get('[data-section="classification-error"]').text()).toContain(cs.nonprofitMembership.classificationError)
    expect(w.get('[data-action="issue-preview"]').attributes('disabled')).toBeDefined()
    await w.get('[data-action="issue-preview"]').trigger('click')
    expect(api.preview).not.toHaveBeenCalled()
    await w.get('[data-action="classification-retry"]').trigger('click')
    await flushPromises()
    expect(api.classificationOptions).toHaveBeenCalledTimes(2)
    expect(w.find('[data-section="classification-error"]').exists()).toBe(false)
    for (const [field, value] of [['activity', '1001'], ['activity-kind', '1101'], ['tax-regime', '1202']]) await w.get(`[data-field="${field}"]`).setValue(value)
    await review(w)
    expect(api.preview.mock.calls[0]![1].request.classification).toEqual(expectedRequest.classification)
    w.unmount()
  })

  it('drops the preview as soon as any classification choice changes', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    await w.get('[data-field="tax-regime"]').setValue('1201')
    expect(w.find('[data-section="issue-preview"]').exists()).toBe(false)
    await review(w)
    expect(api.preview.mock.calls[1]![1].request.classification.tax_regime_value_id).toBe(1201)
    await w.get('[data-field="activity"]').setValue('1002')
    expect(w.find('[data-section="issue-preview"]').exists()).toBe(false)
    expect(api.confirm).not.toHaveBeenCalled()
    w.unmount()
  })

  it('confirms the previewed request including the three chosen ids', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    await w.get('[data-field="ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.confirm.mock.calls[0]![1].request.classification).toEqual({ activity_value_id: 1001, activity_kind_value_id: 1101, tax_regime_value_id: 1202 })
    w.unmount()
  })

  it('discards options and choices of the old company and ignores its late answer', async () => {
    const w = mounted()
    await fill(w)
    expect((w.get('[data-field="activity"]').element as HTMLSelectElement).value).toBe('1001')
    let resolveNew!: (value: ReturnType<typeof overview>) => void
    api.classificationOptions.mockImplementationOnce(() => new Promise(r => { resolveNew = r }))
    await w.setProps({ supplier: 6 })
    await flushPromises()
    expect(api.classificationOptions).toHaveBeenLastCalledWith(6)
    expect(optionLabels(w, 'activity')).toEqual([])
    expect((w.get('[data-field="activity"]').element as HTMLSelectElement).value).toBe('')
    expect(w.get('[data-action="issue-preview"]').attributes('disabled')).toBeDefined()
    resolveNew(overview(6))
    await flushPromises()
    expect(optionLabels(w, 'activity')).toEqual([cs.dimensions.system_values.activity.main, cs.dimensions.system_values.activity.economic])
    expect((w.get('[data-field="tax-regime"]').element as HTMLSelectElement).value).toBe('')
    w.unmount()
  })

  it('ignores a classification answer of the previous company that arrives after the change', async () => {
    let resolveOld!: (value: ReturnType<typeof overview>) => void
    api.classificationOptions.mockImplementationOnce(() => new Promise(r => { resolveOld = r }))
    const w = mounted()
    await flushPromises()
    await w.setProps({ supplier: 6 })
    await flushPromises()
    const stale = overview(5)
    stale.values = stale.values.filter(v => v.id !== 1002)
    resolveOld(stale)
    await flushPromises()
    expect(optionLabels(w, 'activity')).toEqual([cs.dimensions.system_values.activity.main, cs.dimensions.system_values.activity.economic])
    expect(api.classificationOptions).toHaveBeenLastCalledWith(6)
    w.unmount()
  })

  it('shows the three verified proof values by id and not the values of the form', async () => {
    api.preview.mockImplementation(() => Promise.resolve({ ...previewResult(), proof: { ...previewResult().proof, classification: proofClassification(1201) } }))
    const w = mounted()
    await fill(w)
    await review(w)
    const block = w.get('[data-section="issue-classification"]')
    expect(block.get('[data-row="activity"]').text()).toContain(cs.dimensions.system_values.activity.main)
    expect(block.get('[data-row="activity"]').text()).not.toContain('Main activity')
    expect(block.get('[data-row="activity-kind"]').text()).toContain('Membership kind')
    expect(block.get('[data-row="activity-kind"]').text()).toContain('MEMBERSHIP')
    expect(block.get('[data-row="tax-regime"]').text()).toContain(cs.dimensions.system_values.tax_regime.subject)
    expect(block.get('[data-row="tax-regime"]').text()).not.toContain('Taxable')
    expect(block.get('[data-row="tax-regime"]').text()).not.toContain('Outside scope')
    expect(block.text()).toContain(cs.nonprofitMembership.classificationOriginExplicit)
    w.unmount()
  })

  it('shows a readable id with a translation when the label of a proven value is not loaded', async () => {
    const unknown = proofClassification()
    unknown.tax_regime.value_id = 9999
    api.preview.mockImplementation(() => Promise.resolve({ ...previewResult(), proof: { ...previewResult().proof, classification: unknown } }))
    const w = mounted()
    await fill(w)
    await review(w)
    const text = w.get('[data-row="tax-regime"]').text()
    expect(text).toContain('9999')
    expect(text).toContain(cs.nonprofitMembership.classificationUnknownValue.replace('{id}', '9999'))
    expect(text).not.toContain('Outside scope')
    w.unmount()
  })

  it('warns when the preview carries no verified classification and keeps the tax regime advisory', async () => {
    api.preview.mockImplementation(() => Promise.resolve({ ...previewResult(), proof: { ...previewResult().proof, classification: undefined } }))
    const w = mounted()
    await fill(w)
    await review(w)
    expect(w.find('[data-section="classification-proof-missing"]').exists()).toBe(true)
    expect(w.find('[data-row]').exists()).toBe(false)
    expect(w.get('[data-section="classification-tax-advisory"]').text()).toBe(cs.nonprofitMembership.classificationTaxAdvisory)
    await w.get('[data-field="ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.confirm).not.toHaveBeenCalled()
    w.unmount()
  })

  it.each(['different-choice', 'zero-type', 'zero-value', 'implicit-origin', 'empty-kind'])('blocks confirmation for an invalid classification proof: %s', async mutation => {
    const classification = proofClassification()
    if (mutation === 'different-choice') classification.tax_regime.value_id = 1200
    if (mutation === 'zero-type') classification.activity.type_id = 0
    if (mutation === 'zero-value') classification.activity.value_id = 0
    if (mutation === 'implicit-origin') Object.assign(classification.activity.origin, { origin: 'inferred' })
    if (mutation === 'empty-kind') classification.activity_kind.code = ''
    api.preview.mockImplementation(() => Promise.resolve({ ...previewResult(), proof: { ...previewResult().proof, classification } }))
    const w = mounted()
    await fill(w)
    await review(w)
    await w.get('[data-field="ack"]').setValue(true)
    expect(w.get('[data-action="confirm"]').attributes('disabled')).toBeDefined()
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.confirm).not.toHaveBeenCalled()
    w.unmount()
  })

  it.each(['cs', 'en'])('renders every classification text translated in %s', async locale => {
    const w = mounted({}, locale)
    await fill(w)
    await review(w)
    const messages = (locale === 'cs' ? cs : en).nonprofitMembership
    expect(w.text()).toContain(messages.classificationActivity)
    expect(w.text()).toContain(messages.classificationKind)
    expect(w.text()).toContain(messages.classificationTaxRegime)
    expect(w.text()).toContain(messages.classificationReviewTitle)
    expect(w.text()).toContain(messages.classificationTaxAdvisory)
    expect(w.text()).not.toContain('nonprofitMembership.')
    w.unmount()
  })

  it('shows the proven member revision with its effective date', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    const proof = w.get('[data-field="proof-revision"]').text()
    expect(proof).toContain(formatDate('2020-01-01'))
    expect(w.get('[data-field="review-amount"]').classes()).toContain('tabular-nums')
    expect(w.get('[data-section="issue-preview"]').text()).not.toMatch(/\d{4}-\d{2}-\d{2}/)
    w.unmount()
  })

  it('shows a failed legal profile lookup in the shared error box', async () => {
    dppo.profile.mockRejectedValue({ response: { status: 500, data: {} } })
    const w = mounted()
    await flushPromises()
    const box = w.get('[data-section="profile-error"]')
    expect(box.text()).toBe(cs.nonprofitMembership.profileError)
    expect(box.classes()).toEqual(expect.arrayContaining(['bg-danger-50', 'text-danger-700']))
    w.unmount()
  })

  it('lists every missing input at once only after the user starts filling the form', async () => {
    const w = mounted()
    await flushPromises()
    expect(w.find('[data-section="issue-problem"]').exists()).toBe(false)
    await w.get('[data-field="title-key"]').setValue('CP 2027')
    const items = w.findAll('[data-section="issue-problem"] li').map(li => li.text())
    expect(items).toContain(cs.nonprofitMembership.issueTitleKeyInvalid)
    expect(items).toContain(cs.nonprofitMembership.issueAmountInvalid)
    expect(items).toContain(cs.nonprofitMembership.issueAccountsInvalid)
    expect(items).toContain(cs.nonprofitMembership.issueEvidenceRequired)
    expect(items).toContain(cs.nonprofitMembership.issueNonreciprocalRequired)
    expect(w.get('[data-section="issue-problem"]').classes()).toContain('bg-warning-50')
    w.unmount()
  })

  it('warns when the membership does not cover the whole dues period but leaves the decision to the server', async () => {
    const w = mounted({ history: [{ ...revision, effective_from: '2026-03-01' }] })
    await fill(w, ['accounting-date'])
    await w.get('[data-field="accounting-date"]').setValue('2026-10-19')
    const warning = w.get('[data-section="issue-coverage-warning"]').text()
    expect(warning).toContain(formatDate('2026-01-01'))
    expect(warning).toContain(formatDate('2026-12-31'))
    expect(w.get('[data-action="issue-preview"]').attributes('disabled')).toBeUndefined()
    await w.get('[data-field="period-from"]').setValue('2026-03-01')
    expect(w.find('[data-section="issue-coverage-warning"]').exists()).toBe(false)
    w.unmount()
  })

  it('lists no prerequisite when the company is ready', async () => {
    const w = mounted()
    await flushPromises()
    expect(w.find('[data-section="issue-prerequisites"]').exists()).toBe(false)
    w.unmount()
  })

  it('points to the tax assessment of the period when the legal profile is missing', async () => {
    dppo.profile.mockResolvedValue({ ...legal(), latest: null })
    const w = mounted()
    await flushPromises()
    const item = w.get('[data-prerequisite="profile"]')
    expect(item.text()).toContain(cs.nonprofitMembership.profileMissing)
    expect(JSON.parse(item.get('[data-action="prerequisite-link"]').attributes('data-to')!)).toEqual({ name: 'accounting-nonprofit-tax', params: { id: 11 } })
    w.unmount()
  })

  it('points to the dimensions when the company has no classification axes', async () => {
    api.classificationOptions.mockResolvedValue({ enabled: true, group: null, types: [], values: [] })
    const w = mounted()
    await flushPromises()
    const item = w.get('[data-prerequisite="dimensions"]')
    expect(JSON.parse(item.get('[data-action="prerequisite-link"]').attributes('data-to')!)).toEqual({ name: 'company-dimensions' })
    w.unmount()
  })

  it('points to the chart of accounts when no membership revenue account exists', async () => {
    accounting.listAccounts.mockResolvedValue([account(1, '311', 'Receivables', 'asset'), account(3, '602', 'Sales', 'revenue')])
    const w = mounted()
    await flushPromises()
    const item = w.get('[data-prerequisite="revenue-account"]')
    expect(item.text()).toContain(cs.nonprofitMembership.prerequisiteRevenueAccount)
    expect(JSON.parse(item.get('[data-action="prerequisite-link"]').attributes('data-to')!)).toEqual({ name: 'accounting-accounts' })
    w.unmount()
  })

  it('explains the charge label and shows no internal ids, English types or debit shortcuts', async () => {
    api.preview.mockImplementation(() => Promise.resolve(previewResult(2)))
    const w = mounted()
    await fill(w)
    expect(w.get('[data-field="title-key-help"]').text()).toBe(cs.nonprofitMembership.titleKeyHelp)
    expect(w.get('[data-section="issue-profile"]').text()).not.toMatch(/association|č\. 77/)
    await review(w)
    expect(w.get('[data-field="review-case"]').text()).toBe(cs.nonprofitMembership.caseExisting)
    expect(w.get('[data-field="proof-profile"]').text()).not.toContain('77')
    expect(w.get('[data-section="issue-plan"]').text()).toContain('Má dáti')
    w.unmount()
  })

  it('points to the accounting periods when no period is open and does not pretend to load a profile', async () => {
    const w = mounted({ periods: [{ ...period, status: 'closed' }] })
    await flushPromises()
    const item = w.get('[data-prerequisite="period"]')
    expect(item.text()).toContain(cs.nonprofitMembership.prerequisiteNoOpenPeriod)
    expect(JSON.parse(item.get('[data-action="prerequisite-link"]').attributes('data-to')!)).toEqual({ name: 'accounting-periods' })
    await w.get('[data-field="title-key"]').setValue('CP-2027')
    const list = w.get('[data-section="issue-problem"]').text()
    expect(list).not.toContain(cs.nonprofitMembership.profileLoading)
    expect(list).not.toContain(cs.nonprofitMembership.issueNeedPeriod)
    expect(w.get('[data-action="issue-preview"]').attributes('disabled')).toBeDefined()
    w.unmount()
  })

  it('shows a failed profile lookup once, in its error box and not again in the list', async () => {
    dppo.profile.mockRejectedValue({ response: { status: 500, data: {} } })
    const w = mounted()
    await flushPromises()
    await w.get('[data-field="title-key"]').setValue('CP-2027')
    expect(w.get('[data-section="profile-error"]').text()).toBe(cs.nonprofitMembership.profileError)
    expect(w.get('[data-section="issue-problem"]').text()).not.toContain(cs.nonprofitMembership.profileError)
    w.unmount()
  })

  it('does not repeat a missing prerequisite in the list below the form', async () => {
    api.classificationOptions.mockResolvedValue({ enabled: true, group: null, types: [], values: [] })
    const w = mounted()
    await flushPromises()
    await w.get('[data-field="title-key"]').setValue('CP-2027')
    expect(w.get('[data-section="issue-prerequisites"]').text()).toContain(cs.nonprofitMembership.classificationMissing)
    expect(w.get('[data-section="issue-problem"]').text()).not.toContain(cs.nonprofitMembership.classificationMissing)
    expect(w.findAll('[role="alert"]').map(el => el.text()).join(' ')).not.toContain(cs.nonprofitMembership.classificationMissing)
    w.unmount()
  })
})
