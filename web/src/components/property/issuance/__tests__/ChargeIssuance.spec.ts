import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { nextTick, reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import View from '@/views/PropertyChargeIssuanceView.vue'
import LegalEditor from '../IssueLegalEditor.vue'
import NoticeEditor from '../IssueNoticeEditor.vue'
import { emptyIssueUnit, issueMoney } from '../issuanceModel'
import type { IssueContext, IssuedCharge, IssueUnit } from '@/api/propertyChargeIssuance'
import cs from '@/i18n/cs.json'
const calls = vi.hoisted(() => ({ list: vi.fn(), houses: vi.fn(), versions: vi.fn(), context: vi.fn(), preview: vi.fn(), issue: vi.fn(), detail: vi.fn() }))
vi.mock('@/api/propertyChargeIssuance', () => ({ propertyChargeIssuanceApi: calls }))
vi.mock('@/api/propertyRegistry', () => ({ propertyRegistryApi: { list: calls.houses } }))
vi.mock('@/api/propertyCharges', () => ({ propertyChargesApi: { versions: calls.versions } }))
const route = reactive({ query: {} as Record<string, string> })
vi.mock('vue-router', () => ({ useRoute: () => route }))
const store = reactive({ currentSupplierId: 1 })
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => store }))
const inputStub = { props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' }
const context = { as_of: '2026-01-01T00:00:00Z', version: { id: 1, request: { frequency: 'annual', decision_ref: 'Synthetic decision' }, preview: { calculation: { rates: [] } } },
  units: [{ id: 1, code: 'U01', revision: { state: { unit_no: 'Synthetic unit', services: [], ownerships: [{ group_key: 'sjm', members: [11, 12], share: { num: '1', den: '1' }, title_ref: 'Synthetic SJM title' }] } } }],
  parties: [{ id: 11, company_name: 'Synthetic Alice' }, { id: 12, company_name: 'Synthetic Bob' }], accounts: [], dimensions: [], history: [] } as unknown as IssueContext
const charge = { id: 1, batch_id: 1, unit_id: 1, source_key: 'opaque', revision_no: 1, row_version: 2, status: 'posted', amount_cents: '100',
  period_from: '2026-01-01', period_to: '2026-12-31', due_date: '2026-01-15', snapshot: { title: 'prescription', claim_origin_at: context.as_of,
    registry: { revision: context.units[0]!.revision }, liability_groups: [{ group_key: 'sjm', members: [11, 12], extent: { num: '1', den: '1' } }], lines: [], parties: context.parties } } as unknown as IssuedCharge
const globalOptions = () => ({ plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs } })], stubs: { PropertyInstantInput: inputStub, DateInput: inputStub, PropertyPartyPicker: true } })
async function click(wrapper: ReturnType<typeof mount>, label: string) { const button = wrapper.findAll('button').find(b => b.text() === label); expect(button).toBeDefined(); await button!.trigger('click'); await flushPromises() }
async function selected() {
  const wrapper = mount(View, { global: globalOptions() }); await flushPromises(); await wrapper.get('[data-house]').setValue('1'); await flushPromises(); await wrapper.get('[data-version]').setValue('1'); await flushPromises()
  await wrapper.get('[data-case-new] input').setValue(true); await flushPromises()
  for (const [field, value] of Object.entries({ case_ref: 'Synthetic case', occurrence_ref: 'Occurrence 1', title_ref: 'Synthetic title', decision_ref: 'Synthetic decision', decided_on: '2026-01-01', evidence_ref: 'Synthetic evidence' })) await wrapper.get(`[data-case="${field}"]`).setValue(value)
  return wrapper
}
beforeEach(() => {
  vi.clearAllMocks(); store.currentSupplierId = 1; route.query = {}
  calls.detail.mockResolvedValue({ charge, evidence: [] })
  calls.houses.mockResolvedValue([{ id: 1, code: 'LIP', revision: { state: { name: 'Synthetic house' } } }])
  calls.list.mockResolvedValue({ items: [charge], capabilities: { issue: true, post: true, reverse: true } })
  calls.versions.mockResolvedValue({ items: [context.version] }); calls.context.mockResolvedValue(context)
  calls.preview.mockImplementation((_supplier, request) => Promise.resolve({ preview_hash: 'frozen', snapshot: { request, charges: [], total_cents: '1' } }))
})
describe('charge issuance', () => {
  it('loads the original legal instant when correcting from the current registry', async () => {
    calls.context.mockResolvedValueOnce({ ...context, as_of: '2026-10-17T00:00:00Z' })
    const wrapper = await selected()
    calls.context.mockResolvedValueOnce(context)
    await click(wrapper, cs.propertyIssuance.difference)
    expect(calls.context).toHaveBeenLastCalledWith(1, 1, charge.snapshot.claim_origin_at)
    const previewButton = wrapper.findAll('button').find(b => b.text() === cs.propertyIssuance.preview)!
    expect(previewButton.attributes('disabled')).toBeUndefined()
    await click(wrapper, cs.propertyIssuance.preview)
    expect(calls.preview.mock.lastCall?.[1]).toMatchObject({ issue_mode: 'difference', claim_origin_at: charge.snapshot.claim_origin_at })
    wrapper.unmount()
  })
  it('explains an empty charge history instead of rendering a bare heading', async () => {
    calls.list.mockResolvedValue({ items: [], capabilities: { issue: true, post: true, reverse: true } }); calls.houses.mockResolvedValue([])
    const wrapper = mount(View, { global: globalOptions() }); await flushPromises()
    expect(wrapper.get('[data-testid=history-empty]').text()).toBe(cs.propertyIssuance.historyEmpty)
    expect(wrapper.get('[data-testid=houses-empty]').text()).toBe(cs.propertyIssuance.housesEmpty)
    expect(wrapper.find('[data-testid=history-loading]').exists()).toBe(false)
    wrapper.unmount()
  })
  it('opens the exact journal source even outside the list page', async () => {
    route.query = { charge_id: '91' }; calls.list.mockResolvedValue({ items: [], capabilities: { issue: false, post: false, reverse: false } })
    const wrapper = mount(View, { global: globalOptions() }); await flushPromises()
    expect(calls.detail).toHaveBeenCalledWith(1, 91)
    expect(wrapper.text()).toContain('Synthetic unit')
    wrapper.unmount()
  })
  it('discards a historical correction context answered after the tenant changes', async () => {
    const wrapper = await selected()
    let resolve!: (value: IssueContext) => void
    calls.context.mockImplementationOnce(() => new Promise(done => { resolve = done }))
    await click(wrapper, cs.propertyIssuance.difference)
    store.currentSupplierId = 2; await flushPromises()
    resolve(context); await flushPromises()
    expect(wrapper.find('[data-issue-reason]').exists()).toBe(false)
    expect(calls.preview).not.toHaveBeenCalled()
    wrapper.unmount()
  })
  it('ignores malformed journal source IDs and requests the new tenant independently', async () => {
    route.query = { charge_id: '91x' }; const wrapper = mount(View, { global: globalOptions() }); await flushPromises()
    expect(calls.detail).not.toHaveBeenCalled()
    route.query = { charge_id: '91' }; await flushPromises(); expect(calls.detail).toHaveBeenLastCalledWith(1, 91)
    store.currentSupplierId = 2; await flushPromises(); expect(calls.detail).toHaveBeenLastCalledWith(2, 91)
    wrapper.unmount()
  })
  it('keeps one SJM unit and requires explicit debtor evidence without guessed shares', async () => {
    const unit = reactive(emptyIssueUnit(context, 1))
    const wrapper = mount(LegalEditor, { props: { unit, context, supplierId: 1, at: context.as_of }, global: globalOptions() })
    expect(unit.liability_groups).toEqual([]); expect(wrapper.findAll('[data-testid="issue-legal-unit"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Synthetic Alice'); expect(wrapper.text()).toContain('Synthetic Bob')
    await click(wrapper, cs.propertyIssuance.addLiability)
    expect(unit.liability_groups).toHaveLength(1); expect(unit.liability_groups[0]).toMatchObject({ members: [], basis: '', mode: '', extent: { num: '', den: '' } })
    expect(issueMoney('999999999999999999999999999999')).toBe('9\u00a0999\u00a0999\u00a0999\u00a0999\u00a0999\u00a0999\u00a0999\u00a0999\u00a0999,99')
    expect(issueMoney('-1')).toBe('-0,01')
  })
  it('invalidates a preview on reason and accounting date changes', async () => {
    const wrapper = await selected(); await wrapper.get('input[type="checkbox"]').setValue(true)
    await click(wrapper, cs.propertyIssuance.preview); expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(true)
    await wrapper.get('[data-issue-reason]').setValue('Changed title'); await nextTick(); expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(false)
    await click(wrapper, cs.propertyIssuance.preview); await wrapper.get('[data-date="accounting_date"]').setValue('2026-02-01'); await nextTick()
    expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(false); expect(calls.issue).not.toHaveBeenCalled()
  })
  it('copies a reactive historical legal snapshot for correction without changing the original', async () => {
    const wrapper = await selected(); await click(wrapper, cs.propertyIssuance.difference)
    expect(wrapper.findAll('[data-testid="issue-legal-unit"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-testid="liability-group"]')).toHaveLength(1)
    expect(charge.snapshot.liability_groups[0]!.members).toEqual([11, 12])
  })
  it('discards old tenant capabilities and never exposes writes to a read-only tenant', async () => {
    let resolveOld!: (value: unknown) => void
    calls.list.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve })).mockResolvedValue({ items: [], capabilities: { issue: false, post: false, reverse: false } })
    const wrapper = mount(View, { global: globalOptions() }); store.currentSupplierId = 2; await flushPromises()
    resolveOld({ items: [charge], capabilities: { issue: true, post: true, reverse: true } }); await flushPromises()
    expect(wrapper.find('[data-house]').exists()).toBe(false); expect(wrapper.text()).not.toContain('Synthetic unit')
    expect(calls.list.mock.calls.map(args => args[0])).toEqual([1, 2]); expect(calls.issue).not.toHaveBeenCalled()
  })
  it('requires separate explicit delivery and non-monthly agreement scopes', async () => {
    const entry = reactive<IssueUnit['notices'][number]>({ component_id: 5, notice: { mode: '', title_ref: '' } })
    const wrapper = mount(NoticeEditor, { props: { entry, context, unitId: 1, supplierId: 1 }, global: globalOptions() })
    await wrapper.get('select').setValue('change')
    expect(entry.notice.agreement).toEqual({ effective_at: '', recipient_ids: [], evidence_ref: '', legal_basis: '' })
    await click(wrapper, cs.propertyIssuance.addDelivery)
    expect(entry.notice.deliveries![0]).toMatchObject({ recipient_id: 0, represented_ids: [], authority_ref: '', delivered_at: '' })
    const boxes = wrapper.findAll('input[type="checkbox"]'); expect(boxes).toHaveLength(4)
    await boxes[0]!.setValue(true)
    expect(entry.notice.deliveries![0]!.represented_ids).toEqual([11]); expect(entry.notice.agreement!.recipient_ids).toEqual([])
    await boxes[2]!.setValue(true); await boxes[3]!.setValue(true)
    expect(entry.notice.agreement!.recipient_ids).toEqual([11, 12]); expect(entry.notice.deliveries![0]!.represented_ids).toEqual([11])
    await wrapper.get('select').setValue('initial'); expect(entry.notice.agreement).toBeNull()
  })
  it('drops house, version and draft when the registry instant changes, so the user must reload', async () => {
    const wrapper = await selected(); expect((wrapper.get('[data-house]').element as HTMLSelectElement).value).toBe('1')
    await wrapper.findAll('input')[0]!.setValue('2025-01-01T00:00:00Z'); await flushPromises()
    expect((wrapper.get('[data-house]').element as HTMLSelectElement).value).toBe('0')
    expect(wrapper.find('[data-version]').exists() ? (wrapper.get('[data-version]').element as HTMLSelectElement).options.length : 1).toBe(1)
    expect(wrapper.find('[data-issue-reason]').exists()).toBe(false)
  })
  it('releases the busy state of an in-flight mutation when the registry instant changes', async () => {
    calls.issue.mockImplementation(() => new Promise(() => {}))
    const wrapper = await selected(); await wrapper.get('input[type="checkbox"]').setValue(true)
    await click(wrapper, cs.propertyIssuance.preview); await click(wrapper, cs.propertyIssuance.issue)
    const refresh = () => wrapper.findAll('button').find(b => b.text() === cs.propertyIssuance.refresh)!
    expect(refresh().attributes('disabled')).toBeDefined()
    await wrapper.findAll('input')[0]!.setValue('2025-01-01T00:00:00Z'); await flushPromises()
    expect(refresh().attributes('disabled')).toBeUndefined()
  })
  it('releases the busy state when the supplier scope is lost during a request', async () => {
    calls.issue.mockImplementation(() => new Promise(() => {}))
    const wrapper = await selected(); await wrapper.get('input[type="checkbox"]').setValue(true)
    await click(wrapper, cs.propertyIssuance.preview); await click(wrapper, cs.propertyIssuance.issue)
    store.currentSupplierId = null as unknown as number; await flushPromises()
    expect(wrapper.findAll('button').find(b => b.text() === cs.propertyIssuance.refresh)!.attributes('disabled')).toBeUndefined()
  })
  it('does not show a preview answered after the registry instant changed', async () => {
    let resolve!: (value: unknown) => void
    const wrapper = await selected(); await wrapper.get('input[type="checkbox"]').setValue(true)
    calls.preview.mockImplementationOnce((_s, request) => new Promise(done => { resolve = () => done({ preview_hash: 'old', snapshot: { request, charges: [], total_cents: '1' } }) }))
    await click(wrapper, cs.propertyIssuance.preview)
    await wrapper.findAll('input')[0]!.setValue('2025-01-01T00:00:00Z'); resolve(null); await flushPromises()
    expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(false)
  })
  it('invalidates a preview when the legal basis of a liability group changes', async () => {
    const wrapper = await selected(); await wrapper.get('input[type="checkbox"]').setValue(true)
    await click(wrapper, cs.propertyIssuance.addLiability)
    await click(wrapper, cs.propertyIssuance.preview); expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(true)
    await wrapper.get('[data-testid="liability-group"] select:nth-of-type(1)').setValue('debtor'); await nextTick()
    expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(false)
    await click(wrapper, cs.propertyIssuance.preview); expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(true)
    const basis = wrapper.findAll('[data-testid="liability-group"] select')[2]!; await basis.setValue('sjm_713'); await nextTick()
    expect(wrapper.find('[data-testid="issue-preview"]').exists()).toBe(false)
  })
})
