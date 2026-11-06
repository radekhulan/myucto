import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import View from '@/views/PropertyChargeIssuanceView.vue'
import type { CaseFamily, CaseIdentity, IssueContext, IssuedCharge } from '@/api/propertyChargeIssuance'
import cs from '@/i18n/cs.json'
const calls = vi.hoisted(() => ({ list: vi.fn(), houses: vi.fn(), versions: vi.fn(), context: vi.fn(), preview: vi.fn(), issue: vi.fn(), detail: vi.fn() }))
vi.mock('@/api/propertyChargeIssuance', () => ({ propertyChargeIssuanceApi: calls }))
vi.mock('@/api/propertyRegistry', () => ({ propertyRegistryApi: { list: calls.houses } }))
vi.mock('@/api/propertyCharges', () => ({ propertyChargesApi: { versions: calls.versions } }))
const route = reactive({ query: {} as Record<string, string> })
vi.mock('vue-router', () => ({ useRoute: () => route }))
const store = reactive({ currentSupplierId: 1 })
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => store }))
const inputStub = { props: ['modelValue', 'readonly'], emits: ['update:modelValue'], template: '<input :value="modelValue" :readonly="readonly" @input="$emit(\'update:modelValue\', $event.target.value)" />' }
const identity: CaseIdentity = { schema: 1, case_ref: 'Case A', occurrence_ref: 'Occurrence A', title_ref: 'Title A', decision_ref: 'Decision A', decided_on: '2025-12-31', evidence_ref: 'Evidence A' }
const family = (over: Partial<CaseFamily> = {}): CaseFamily => ({ supplier_id: 1, unit_id: 1, source_key: 'key-a', root_charge_id: 7, latest_charge_id: 7, latest_revision: 1, status: 'posted', row_version: 3,
  case_identity: identity, case_distinction: null, identity_status: 'explicit', ...over })
const base = { as_of: '2026-01-01T00:00:00Z', version: { id: 1, request: { frequency: 'annual', decision_ref: 'Synthetic decision' }, preview: { calculation: { rates: [] } } },
  units: [{ id: 1, code: 'U01', revision: { state: { unit_no: 'Synthetic unit', services: [], ownerships: [] } } }], parties: [], accounts: [], dimensions: [], history: [] }
const withFamilies = (...case_families: CaseFamily[]) => ({ ...base, case_families }) as unknown as IssueContext
const chargeOf = (f: CaseFamily, over: Record<string, unknown> = {}) => ({ id: f.latest_charge_id, batch_id: 1, unit_id: f.unit_id, source_key: f.source_key, revision_no: f.latest_revision, row_version: f.row_version, status: f.status,
  amount_cents: '100', period_from: '2026-01-01', period_to: '2026-12-31', due_date: '2026-01-15', snapshot: { title: 'prescription', claim_origin_at: base.as_of, registry: { revision: base.units[0]!.revision },
    liability_groups: [], lines: [], parties: [], ...(f.case_identity ? { case_identity: f.case_identity } : {}), ...(f.case_distinction ? { case_distinction: f.case_distinction } : {}) }, ...over }) as unknown as IssuedCharge
const rejection = (status: number, code: string, details: Record<string, unknown> = {}) => Object.assign(new Error(code), { response: { status, data: { error: { code, message: `server ${code}`, details } } } })
const globalOptions = () => ({ plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs } })], stubs: { PropertyInstantInput: inputStub, DateInput: inputStub, PropertyPartyPicker: true } })
async function click(wrapper: ReturnType<typeof mount>, label: string) { const button = wrapper.findAll('button').find(b => b.text() === label); expect(button).toBeDefined(); await button!.trigger('click'); await flushPromises() }
const previewButton = (w: ReturnType<typeof mount>) => w.findAll('button').find(b => b.text() === cs.propertyIssuance.preview)!
async function open() {
  const wrapper = mount(View, { global: globalOptions() }); await flushPromises(); await wrapper.get('[data-house]').setValue('1'); await flushPromises(); await wrapper.get('[data-version]').setValue('1'); await flushPromises(); return wrapper
}
async function pickFamily(wrapper: ReturnType<typeof mount>, index: number) { await wrapper.findAll('[data-case-family] input')[index]!.setValue(true); await flushPromises() }
async function fillNew(wrapper: ReturnType<typeof mount>, values: Partial<CaseIdentity> = {}) {
  await wrapper.get('[data-case-new] input').setValue(true); await flushPromises()
  for (const [field, value] of Object.entries({ ...identity, ...values })) if (field !== 'schema') await wrapper.get(`[data-case="${field}"]`).setValue(value)
}
const hasCaseKeys = (value: unknown) => JSON.stringify(value).includes('case_identity') || JSON.stringify(value).includes('case_distinction')
beforeEach(() => {
  vi.resetAllMocks(); store.currentSupplierId = 1; route.query = {}
  calls.houses.mockResolvedValue([{ id: 1, code: 'LIP', revision: { state: { name: 'Synthetic house' } } }])
  calls.list.mockResolvedValue({ items: [], capabilities: { issue: true, post: true, reverse: true } })
  calls.versions.mockResolvedValue({ items: [base.version] }); calls.context.mockResolvedValue(withFamilies(family()))
  calls.detail.mockImplementation((_s, id) => Promise.resolve({ charge: chargeOf(family({ latest_charge_id: id })), evidence: [] }))
  calls.preview.mockImplementation((_s, request) => Promise.resolve({ preview_hash: 'frozen', snapshot: { request, charges: [], total_cents: '1' } }))
})
describe('explicit case choice', () => {
  it('shows no draft and no generated reference until the user explicitly chooses', async () => {
    const wrapper = await open()
    expect(wrapper.find('[data-issue-reason]').exists()).toBe(false); expect(wrapper.find('[data-case="case_ref"]').exists()).toBe(false)
    await wrapper.get('[data-case-new] input').setValue(true); await flushPromises()
    for (const field of ['case_ref', 'occurrence_ref', 'title_ref', 'decision_ref', 'decided_on', 'evidence_ref']) expect((wrapper.get(`[data-case="${field}"]`).element as HTMLInputElement).value).toBe('')
    expect(wrapper.text()).toContain(cs.propertyIssuance.case.assessmentHelp)
    expect(wrapper.text()).not.toMatch(/[0-9a-f]{8}-[0-9a-f]{4}-/)
    wrapper.unmount()
  })
  it('requires every initial case field and sends them trimmed', async () => {
    const wrapper = await open(); await fillNew(wrapper, { occurrence_ref: '' }); await wrapper.get('input[type="checkbox"]').setValue(true)
    expect(previewButton(wrapper).attributes('disabled')).toBeDefined(); expect(wrapper.find('[data-testid="case-incomplete"]').exists()).toBe(true)
    await wrapper.get('[data-case="occurrence_ref"]').setValue('  Occurrence A  ')
    expect(previewButton(wrapper).attributes('disabled')).toBeUndefined(); await click(wrapper, cs.propertyIssuance.preview)
    expect(calls.preview.mock.lastCall?.[1].case_identity).toEqual(identity)
    expect(calls.preview.mock.lastCall?.[1].case_identity.case_ref).not.toBe(calls.preview.mock.lastCall?.[1].source_key)
    wrapper.unmount()
  })
  it('continues an explicit family from its actual latest posted charge with immutable identity', async () => {
    const wrapper = await open(); await pickFamily(wrapper, 0)
    expect(calls.detail).toHaveBeenCalledWith(1, 7)
    expect(wrapper.text()).toContain(cs.propertyIssuance.issueModes.difference)
    expect((wrapper.get('[data-case="case_ref"]').element as HTMLInputElement).readOnly).toBe(true)
    await click(wrapper, cs.propertyIssuance.preview)
    expect(calls.preview.mock.lastCall?.[1]).toMatchObject({ source_key: 'key-a', issue_mode: 'difference', case_identity: identity })
    expect(calls.preview.mock.lastCall?.[1].units[0]).not.toHaveProperty('case_distinction')
    wrapper.unmount()
  })
  it('copies the root distinction exactly and opens a replacement for a cancelled family', async () => {
    const distinction = { against_source_keys: ['key-z'], legal_reason: 'Reason Z', evidence_ref: 'Evidence Z' }
    const f = family({ status: 'cancelled', case_distinction: distinction, latest_revision: 2, row_version: 5, latest_charge_id: 9 })
    calls.context.mockResolvedValue(withFamilies(f)); calls.detail.mockResolvedValue({ charge: chargeOf(f), evidence: [] })
    const wrapper = await open(); await pickFamily(wrapper, 0)
    expect(wrapper.text()).toContain(cs.propertyIssuance.issueModes.replacement)
    await click(wrapper, cs.propertyIssuance.preview)
    expect(calls.preview.mock.lastCall?.[1].units[0].case_distinction).toEqual(distinction)
    expect(calls.preview.mock.lastCall?.[1].case_identity).toEqual(identity)
    wrapper.unmount()
  })
  it('keeps a legacy family absent, never null or guessed', async () => {
    const legacy = family({ case_identity: null, identity_status: 'legacy_unclassified', latest_charge_id: 8 })
    calls.context.mockResolvedValue(withFamilies(legacy)); calls.detail.mockResolvedValue({ charge: chargeOf(legacy), evidence: [] })
    const wrapper = await open(); expect(wrapper.text()).toContain(cs.propertyIssuance.case.legacyFamily)
    await pickFamily(wrapper, 0)
    expect(wrapper.find('[data-testid="case-legacy-continuation"]').exists()).toBe(true); expect(wrapper.find('[data-case="case_ref"]').exists()).toBe(false)
    await click(wrapper, cs.propertyIssuance.preview)
    expect(hasCaseKeys(calls.preview.mock.lastCall?.[1])).toBe(false)
    wrapper.unmount()
  })
  it('opens the existing unposted charge instead of a new draft', async () => {
    const f = family({ status: 'draft', latest_charge_id: 11 }); calls.context.mockResolvedValue(withFamilies(f)); calls.detail.mockResolvedValue({ charge: chargeOf(f), evidence: [] })
    const wrapper = await open(); await pickFamily(wrapper, 0)
    expect(wrapper.find('[data-testid="case-notice"]').exists()).toBe(true); expect(wrapper.find('[data-issue-reason]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Case A'); wrapper.unmount()
  })
  it('refuses a family whose latest charge changed between context and detail', async () => {
    calls.detail.mockResolvedValue({ charge: chargeOf(family({ row_version: 4 })), evidence: [] })
    const wrapper = await open(); await pickFamily(wrapper, 0)
    expect(wrapper.find('[data-issue-reason]').exists()).toBe(false); expect(wrapper.get('[role="alert"]').text()).toBe(cs.propertyIssuance.case.staleFamily)
    wrapper.unmount()
  })
})
describe('overlap evidence', () => {
  const a = family({ source_key: 'key-b', latest_charge_id: 21, case_identity: { ...identity, case_ref: 'Case B' } })
  const b = family({ source_key: 'key-c', latest_charge_id: 22, case_identity: { ...identity, case_ref: 'Case C' } })
  const required = () => rejection(422, 'property_charge_case_distinction_required', { unit_id: 1, case_candidates: [{ ...a, reasons: ['period_overlap'] }, { ...b, reasons: ['period_overlap', 'same_decision_occurrence'] }] })
  async function rejected() {
    const wrapper = await open(); await fillNew(wrapper); await wrapper.get('input[type="checkbox"]').setValue(true); await wrapper.get('[data-issue-reason]').setValue('Substantive draft reason')
    calls.preview.mockRejectedValueOnce(required()); await click(wrapper, cs.propertyIssuance.preview); return wrapper
  }
  it('clears obsolete distinctions after an edited period has no overlapping cases', async () => {
    const wrapper = await rejected()
    for (const box of wrapper.findAll('[data-against]')) await box.setValue(true)
    await wrapper.get('[data-distinction="legal_reason"]').setValue('Separate claim')
    await wrapper.get('[data-distinction="evidence_ref"]').setValue('Separate evidence')
    calls.preview.mockRejectedValueOnce(rejection(422, 'property_charge_case_distinction_required', { unit_id: 1, case_candidates: [] }))
    await click(wrapper, cs.propertyIssuance.preview)
    expect(wrapper.findAll('[data-testid="case-candidate"]')).toHaveLength(0)
    expect(wrapper.find('[data-testid="case-distinction"]').exists()).toBe(false)
    expect((wrapper.get('[data-issue-reason]').element as HTMLTextAreaElement).value).toBe('Substantive draft reason')
    expect(previewButton(wrapper).attributes('disabled')).toBeUndefined()
    await click(wrapper, cs.propertyIssuance.preview)
    expect(calls.preview.mock.lastCall?.[1].units[0]).not.toHaveProperty('case_distinction')
    wrapper.unmount()
  })
  it('renders only the exact server candidates, preserves the draft and collects per-unit evidence', async () => {
    const wrapper = await rejected()
    expect((wrapper.get('[data-issue-reason]').element as HTMLTextAreaElement).value).toBe('Substantive draft reason')
    expect(wrapper.findAll('[data-testid="case-candidate"]')).toHaveLength(2); expect(wrapper.text()).toContain('Case B'); expect(wrapper.text()).toContain('Case C')
    const boxes = wrapper.findAll('[data-against]'); expect(boxes).toHaveLength(2); expect(boxes.every(b => !(b.element as HTMLInputElement).checked)).toBe(true)
    expect(previewButton(wrapper).attributes('disabled')).toBeDefined()
    await boxes[1]!.setValue(true); await wrapper.get('[data-distinction="legal_reason"]').setValue('Separate claim'); await wrapper.get('[data-distinction="evidence_ref"]').setValue('Separate evidence')
    expect(previewButton(wrapper).attributes('disabled')).toBeDefined()
    await boxes[0]!.setValue(true); expect(previewButton(wrapper).attributes('disabled')).toBeUndefined()
    await click(wrapper, cs.propertyIssuance.preview)
    expect(calls.preview.mock.lastCall?.[1].units[0].case_distinction).toEqual({ against_source_keys: ['key-b', 'key-c'], legal_reason: 'Separate claim', evidence_ref: 'Separate evidence' })
    wrapper.unmount()
  })
  it('drops selected keys the server no longer lists and never re-ticks them', async () => {
    const wrapper = await rejected(); const boxes = wrapper.findAll('[data-against]'); await boxes[0]!.setValue(true); await boxes[1]!.setValue(true)
    await wrapper.get('[data-distinction="legal_reason"]').setValue('R'); await wrapper.get('[data-distinction="evidence_ref"]').setValue('E')
    calls.preview.mockRejectedValueOnce(rejection(422, 'property_charge_case_distinction_required', { unit_id: 1, case_candidates: [{ ...b, reasons: ['period_overlap'] }] }))
    await click(wrapper, cs.propertyIssuance.preview)
    const next = wrapper.findAll('[data-against]'); expect(next).toHaveLength(1); expect((next[0]!.element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.findAll('[data-testid="case-candidate"]')).toHaveLength(1); wrapper.unmount()
  })
  it('offers the existing family when the evidenced case already exists and keeps the draft meanwhile', async () => {
    const wrapper = await open(); await fillNew(wrapper); await wrapper.get('input[type="checkbox"]').setValue(true)
    calls.preview.mockRejectedValueOnce(rejection(409, 'property_charge_case_exists', { unit_id: 1, source_key: 'key-a' }))
    await click(wrapper, cs.propertyIssuance.preview)
    expect(wrapper.find('[data-testid="case-exists"]').exists()).toBe(true); expect(wrapper.find('[data-case="case_ref"]').exists()).toBe(true)
    await click(wrapper, cs.propertyIssuance.case.useExisting)
    expect(calls.detail).toHaveBeenLastCalledWith(1, 7); expect(wrapper.text()).toContain(cs.propertyIssuance.issueModes.difference)
    expect(wrapper.find('[data-testid="case-exists"]').exists()).toBe(false); wrapper.unmount()
  })
})
describe('stale, busy and retry', () => {
  async function previewed() {
    const wrapper = await open(); await fillNew(wrapper); await wrapper.get('input[type="checkbox"]').setValue(true); await wrapper.get('[data-issue-reason]').setValue('Keep me')
    await click(wrapper, cs.propertyIssuance.preview); return wrapper
  }
  it('invalidates the preview when a case field is edited', async () => {
    const wrapper = await previewed(); expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(true)
    await wrapper.get('[data-case="evidence_ref"]').setValue('Changed'); expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(false); wrapper.unmount()
  })
  it('requires a new preview and keeps the draft when a case arrives between preview and issue', async () => {
    const wrapper = await previewed(); calls.issue.mockRejectedValueOnce(rejection(409, 'property_charge_case_exists', { unit_id: 1, source_key: 'key-a' }))
    await click(wrapper, cs.propertyIssuance.issue)
    expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(false); expect((wrapper.get('[data-issue-reason]').element as HTMLTextAreaElement).value).toBe('Keep me')
    expect(wrapper.find('[data-testid="case-exists"]').exists()).toBe(true); expect(calls.issue).toHaveBeenCalledTimes(1)
    await click(wrapper, cs.propertyIssuance.preview); expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(true); wrapper.unmount()
  })
  it('retries a transport failure with the exact same preview and token', async () => {
    const wrapper = await previewed(); calls.issue.mockRejectedValueOnce(new Error('network')).mockResolvedValue({ id: 1 })
    await click(wrapper, cs.propertyIssuance.issue); expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(true)
    await click(wrapper, cs.propertyIssuance.issue)
    expect(calls.issue).toHaveBeenCalledTimes(2); expect(calls.issue.mock.calls[1]![1]).toBe(calls.issue.mock.calls[0]![1]); expect(calls.issue.mock.calls[1]![2]).toBe(calls.issue.mock.calls[0]![2]); wrapper.unmount()
  })
  it('discards tenant-specific drafts, candidates and tokens when the tenant changes', async () => {
    const wrapper = await previewed(); calls.list.mockResolvedValue({ items: [], capabilities: { issue: false, post: false, reverse: false } })
    store.currentSupplierId = 2; await flushPromises()
    expect(wrapper.find('[data-case="case_ref"]').exists()).toBe(false); expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(false); expect(wrapper.find('[data-testid="case-picker"]').exists()).toBe(false)
    expect(calls.issue).not.toHaveBeenCalled(); wrapper.unmount()
  })
  it('discards an existing-family selection answered after the registry instant changed', async () => {
    let resolve!: (value: IssueContext) => void
    const wrapper = await open(); calls.context.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    await wrapper.findAll('[data-case-family] input')[0]!.setValue(true)
    await wrapper.findAll('input')[0]!.setValue('2025-01-01T00:00:00Z'); resolve(withFamilies(family())); await flushPromises()
    expect(wrapper.find('[data-issue-reason]').exists()).toBe(false); expect(calls.detail).not.toHaveBeenCalled(); wrapper.unmount()
  })
  it('shows the case evidence to a read-only user without any mutation control', async () => {
    const f = family(); calls.list.mockResolvedValue({ items: [chargeOf(f)], capabilities: { issue: false, post: false, reverse: false } }); calls.detail.mockResolvedValue({ charge: chargeOf(f), evidence: [] })
    route.query = { charge_id: '7' }; const wrapper = mount(View, { global: globalOptions() }); await flushPromises()
    expect(wrapper.find('[data-testid="history-case"]').text()).toContain('Case A'); expect(wrapper.get('[data-testid="case-summary"]').text()).toContain('Evidence A')
    expect(wrapper.find('[data-testid="case-picker"]').exists()).toBe(false); expect(wrapper.findAll('button').map(b => b.text())).not.toContain(cs.propertyIssuance.difference); wrapper.unmount()
  })
})
