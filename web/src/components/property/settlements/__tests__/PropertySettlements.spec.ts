import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DocumentEditor from '../SettlementDocumentEditor.vue'
import DispositionEditor from '../SettlementDispositionEditor.vue'
import Workspace from '@/views/PropertySettlementsView.vue'
import { settlementCents, settlementDecimal } from '../settlementForm'
import type { SettlementDetail, SettlementDocumentRequest } from '@/api/propertySettlements'
import cs from '@/i18n/cs.json'
const calls = vi.hoisted(() => ({ list: vi.fn(), detail: vi.fn(), catalog: vi.fn(), prepare: vi.fn(), save: vi.fn(), disposition: vi.fn(), preview: vi.fn(), apply: vi.fn(), reverse: vi.fn() }))
vi.mock('@/api/propertySettlements', () => ({ propertySettlementsApi: calls }))
const registry = vi.hoisted(() => ({ list: vi.fn() }))
vi.mock('@/api/propertyRegistry', () => ({ propertyRegistryApi: registry }))
const store = reactive({ currentSupplierId: 1 }), route = reactive({ params: {} as Record<string, unknown> })
const push = vi.fn()
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => store }))
vi.mock('vue-router', () => ({ useRoute: () => route, useRouter: () => ({ push }) }))
const capabilities = { manage: true, post: true, reverse: true }
const amounts = { advance_applied_cents: '80000', new_claim_cents: '0', cancelled_unpaid_cents: '20000', new_credit_cents: '0', remaining_old_claim_cents: '20000' }
const selector = { kind: 'opening' as const, advance_item_id: 10, service_item_id: 11 }
const request: SettlementDocumentRequest = { unit_id: 7, service_id: 8, segment_key: 'WATER-2026', accounting_date: '2026-12-31', period_from: '2026-01-01', period_to: '2026-12-31', cutoff_at: '2026-01-01T00:00:00+01:00', known_at: '2027-01-01T12:00:00+01:00', recipient_ids: [31], evidence: 'Synthetic decision', selectors: [selector], cost_source_id: 99 }
const facts = { ...request, prescribed_cents: '100000', paid_cents: '60000', actual_cents: '80000', origins: [{ kind: 'opening_component', id: 10, prescribed_cents: '100000', carried_paid_cents: '60000', receivable_cents: '40000' }], amounts, cost: { source_id: 99 } }
const plan = { totals: { advance_applied_cents: '80000', reduction_cents: '20000', released_allocations_cents: '10000', new_credit_cents: '10000' }, lines: [{ account_code: '379.910', side: 'credit', amount: '100.00' }], reductions: [], credits: [{ amount_cents: '10000' }], new_claim: null }
const app = { id: 20, active: true, reversal_of: null, accounting_date: '2026-12-31', recorded_at: '2026-12-31T12:00:00Z', receipt: { preview: { plan } } }
function detail(active = false): SettlementDetail { return { id: 4, revision_no: 1, previous_id: null, document: structuredClone(facts), origin_receipt: { request: { selectors: [selector], accounting_date: request.accounting_date } }, unit: { id: 7, code: 'U7', label: 'Synthetic unit' }, service: { id: 8, code: 'WATER', label: 'Synthetic water' }, applications: active ? [structuredClone(app)] : [], current_summary: { document_id: 4, row_version: active ? 1 : 0, application_id: active ? 20 : null, active, accounting_date: active ? request.accounting_date : null, credits: active ? [{ id: 21, balance_cents: '10000', reserved_cents: '0', available_cents: '10000' }] : [], claims: [], scope: 'recorded_s8_claims_and_credits', complete_financial_position: false }, capabilities: { ...capabilities } } }
const actionStub = { props: ['actions'], template: '<div><button v-for="a in actions" :key="a.key" :data-action="a.key" :disabled="a.disabled" @click="a.run">{{ a.label }}</button></div>' }
const inputStub = { props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<label>{{label}}<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" /></label>' }
const partyStub = { props: ['modelValue', 'supplierId'], emits: ['selected', 'update:modelValue'], template: '<button type="button" data-action="pick-party" @click="$emit(\'selected\', {id:31,name:\'Synthetic recipient\'})">Pick synthetic recipient</button>' }
const entitlementStub = { props: ['value'], template: '<button type="button" data-action="fill-entitlement" @click="Object.assign(value, {party_ids:[31],evidence:\'Synthetic creditor title\',due_date:\'2027-04-30\',account_code:\'379.910\'})">Fill selected legal entitlement</button>' }
function global(stubs: Record<string, unknown> = {}) { return { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs }, missingWarn: false, fallbackWarn: false })], stubs: { ActionBar: actionStub, DateInput: inputStub, PropertyInstantInput: inputStub, PropertyPartyPicker: partyStub, ...stubs } } }
beforeEach(() => {
 vi.clearAllMocks(); store.currentSupplierId = 1; route.params = {}; push.mockResolvedValue(undefined); vi.spyOn(HTMLFormElement.prototype, 'reportValidity').mockReturnValue(true)
 registry.list.mockImplementation(async (_s: number, kind: string) => [{ id: kind === 'unit' ? 7 : 8, kind, code: kind, revision: { state: kind === 'unit' ? { unit_no: 'Synthetic unit' } : { name: 'Synthetic water' } } }])
 calls.list.mockResolvedValue({ items: [], next_cursor: null, capabilities }); calls.detail.mockResolvedValue(detail()); calls.catalog.mockResolvedValue({ items: [{ id: 10, selector, description: 'Synthetic opening water', accounting_date: '2026-01-01', period_from: '2026-01-01', period_to: '2026-12-31', prescribed_cents: '100000', carried_paid_cents: '60000', cost_source_id: 99, cost_cents: '80000' }], next_cursor: null }); calls.prepare.mockResolvedValue({ document: facts, preview_hash: 'server-document-hash' }); calls.save.mockResolvedValue({ id: 4 }); calls.preview.mockResolvedValue({ plan, preview_hash: 'server-application-hash', expected_version: 0 }); calls.apply.mockResolvedValue(app); calls.reverse.mockResolvedValue({ ...app, id: 22, active: false, reversal_of: app.id })
 calls.disposition.mockResolvedValue({ accounts: [], document_id: 4, row_version: 0, accounting_date: request.accounting_date, current_paid_cents: '90000', amounts: { ...amounts, new_credit_cents: '10000' }, origins: [{ origin_key: 'opening_component:10', kind: 'opening_component', prescribed_cents: '100000', carried_paid_cents: '60000', principal_cents: '40000', paid_cents: '30000', unpaid_cents: '10000' }], allocations: [{ id: 45, origin_key: 'opening_component:10', accounting_date: '2026-02-01', amount_cents: '30000', active: true, at_cutoff: false }], write_authorization: false })
})
describe('native settlement UI workflow', () => {
 it.each([{ name: 'opening', proof: selector }, { name: 'finalized lifecycle opening', proof: { ...selector, cost_line_id: 99 } }])('selects $name from server-labelled cards and preserves its exact proof and cost through preview/save', async ({ proof }) => {
  const expectedRequest = { ...request, selectors: [proof] }
  calls.catalog.mockResolvedValue({ items: [{ id: 10, selector: proof, description: 'Synthetic opening water', accounting_date: '2026-01-01', period_from: '2026-01-01', period_to: '2026-12-31', prescribed_cents: '100000', carried_paid_cents: '60000', cost_source_id: 99, cost_cents: '80000' }], next_cursor: null })
  const w = mount(DocumentEditor, { props: { supplier: 1, capabilities }, global: global() }); await flushPromises()
  await w.get('[data-field=unit]').setValue('7'); await w.get('[data-field=service]').setValue('8'); await w.get('[data-field=segment]').setValue(request.segment_key)
  for (const [field, value] of [['period-from', request.period_from], ['period-to', request.period_to], ['document-date', request.accounting_date], ['cutoff', request.cutoff_at], ['known', request.known_at]] as const) await w.get(`[data-field=${field}] input`).setValue(value)
  await w.get('[data-field=document-evidence]').setValue(request.evidence); await w.get('[data-action=pick-party]').trigger('click')
  const selects = w.findAll('select'); await selects[2]!.setValue('opening'); await w.get('[data-action=load-sources]').trigger('click'); await flushPromises(); expect(w.text()).toContain('Synthetic opening water'); await w.get('[data-field=source-select]').setValue(true); await w.get('[data-field=cost-select]').setValue(true)
  await w.get('[data-action=prepare-document]').trigger('click'); await flushPromises(); expect(calls.prepare).toHaveBeenCalledWith(1, expectedRequest, 0); expect(w.text()).not.toContain('server-document-hash'); expect(w.find('textarea').exists()).toBe(false)
  await w.get('[data-action=save-document]').trigger('click'); await flushPromises(); expect(calls.save).toHaveBeenCalledWith(1, expectedRequest, 0, 'server-document-hash', expect.any(String)); expect(w.emitted('saved')).toEqual([[4]]); w.unmount()
 })
 it('retains the server opening final-cost proof and cost source when preparing a revision', async () => {
  const previous = detail(), proof = { ...selector, cost_line_id: 99 }
  previous.origin_receipt.request.selectors = [proof]
  const w = mount(DocumentEditor, { props: { supplier: 1, capabilities, revision: previous }, global: global() }); await flushPromises()
  await w.get('[data-action=prepare-document]').trigger('click'); await flushPromises()
  expect(calls.prepare).toHaveBeenCalledWith(1, { ...request, selectors: [proof] }, previous.revision_no)
  await w.get('[data-action=save-document]').trigger('click'); await flushPromises()
  expect(calls.save).toHaveBeenCalledWith(1, { ...request, selectors: [proof] }, previous.revision_no, 'server-document-hash', expect.any(String))
  expect(previous.origin_receipt.request.selectors).toEqual([proof]); expect(calls.catalog).not.toHaveBeenCalled(); w.unmount()
 })
 it('accepts structured external evidence and derives only the document recipient union from explicit segment selections', async () => {
  const dossier = { schema: 1, ref: 'Synthetic external source', issuer: { name: 'Synthetic provider' }, issued_at: '2027-04-20T10:00:00+02:00', allocation_ref: 'Synthetic allocation', segments: [{ ordinal: 1, valid_from: '2026-01-01T00:00:00+01:00', valid_to: '2027-01-01T00:00:00+01:00', recipient_ids: [32, 31], basis_ref: 'Synthetic recipient title', allocation_ref: 'Synthetic allocation' }], deliveries: [], person_changes: [] }
  const fill = { props: ['modelValue'], emits: ['update:modelValue'], setup: () => ({ dossier }), template: `<button type="button" data-action="fill-external-dossier" @click="$emit('update:modelValue', dossier)">Fill synthetic external evidence</button>` }
  const w = mount(DocumentEditor, { props: { supplier: 1, capabilities, revision: detail() }, global: global({ SettlementDossierForm: fill }) }); await flushPromises(); await w.get('[data-field=attach-dossier]').setValue(true); await w.get('[data-action=fill-external-dossier]').trigger('click'); await w.get('[data-action=prepare-document]').trigger('click'); await flushPromises(); expect(calls.prepare).toHaveBeenCalledWith(1, expect.objectContaining({ recipient_ids: [31, 32], external_dossier: dossier, accounting_date: request.accounting_date, selectors: [selector], cost_source_id: 99 }), 1); expect(calls.apply).not.toHaveBeenCalled(); expect(calls.reverse).not.toHaveBeenCalled(); w.unmount()
 })
 it('uses actual late allocation choices and discards a stale application preview without automatically writing again', async () => {
  const w = mount(DispositionEditor, { props: { supplier: 1, detail: detail() }, global: global({ SettlementEntitlementEditor: entitlementStub }) }); await w.get('[data-action=load-current]').trigger('click'); await flushPromises(); expect(w.text()).toContain('Pozdější platba'); expect(w.text()).toContain('300,00')
  await w.get('[data-field=application-evidence]').setValue('Synthetic apply'); await w.get('[data-field=relief-enable]').setValue(true); await w.get('[data-field=relief-amount]').setValue('100,00'); await w.get('[data-action=fill-entitlement]').trigger('click'); await w.get('[data-action=preview-application]').trigger('click'); await flushPromises()
  expect(calls.preview).toHaveBeenCalledWith(1, 4, expect.objectContaining({ advances: [{ origin_key: 'opening_component:10', applied_cents: '80000' }], reliefs: [{ allocation_id: 45, amount_cents: '10000', entitlement: { party_ids: [31], evidence: 'Synthetic creditor title', due_date: '2027-04-30', account_code: '379.910' } }] }), 0)
  calls.apply.mockRejectedValue({ response: { status: 409, data: { error: { message: 'Synthetic stale current source' } } } }); await w.get('[data-action=apply-settlement]').trigger('click'); await flushPromises(); expect(w.get('[role=alert]').text()).toContain('nový náhled'); expect(w.find('[data-action=apply-settlement]').exists()).toBe(false); expect(calls.apply).toHaveBeenCalledTimes(1); expect(calls.preview).toHaveBeenCalledTimes(1); expect(w.get<HTMLButtonElement>('[data-action=preview-application]').element.disabled).toBe(true); w.unmount()
 })
 it('requires a fresh preview after edits, respects post rights and ignores old supplier preview responses', async () => {
  const d = detail(); d.capabilities.post = false; const w = mount(DispositionEditor, { props: { supplier: 1, detail: d }, global: global() }); await w.get('[data-action=load-current]').trigger('click'); await flushPromises(); await w.get('[data-field=application-evidence]').setValue('Synthetic apply'); await w.get('[data-action=preview-application]').trigger('click'); await flushPromises(); expect(w.get<HTMLButtonElement>('[data-action=apply-settlement]').element.disabled).toBe(true); await w.get('[data-field=advance-amount]').setValue('799.99'); expect(w.find('[data-action=apply-settlement]').exists()).toBe(false)
  let done!: (value: unknown) => void; calls.preview.mockReturnValue(new Promise(resolve => { done = resolve })); await w.get('[data-action=preview-application]').trigger('click'); await w.setProps({ supplier: 2 }); done({ plan, preview_hash: 'old-company-hash' }); await flushPromises(); expect(w.find('[data-action=apply-settlement]').exists()).toBe(false); expect(calls.apply).not.toHaveBeenCalled(); w.unmount()
 })
 it('reviews the exact active application, reverses it and opens a new document revision', async () => {
  route.params = { id: '4' }; calls.detail.mockResolvedValue(detail(true)); const w = mount(Workspace, { global: global({ SettlementDocumentEditor: { props: ['revision'], template: '<div data-section="revision-editor">{{revision?.revision_no}}</div>' }, SettlementDispositionEditor: true }) }); await flushPromises(); expect(w.get('[data-section=current-summary]').text()).toContain('100,00'); await w.get('[data-action=review-reversal]').trigger('click'); expect(w.find('[data-section=reverse-review]').exists()).toBe(true); await w.get('[data-field=reverse-reason]').setValue('Synthetic cancellation'); await w.get('[data-field=reverse-ack]').setValue(true)
  const inactive = detail(); inactive.current_summary.row_version = 2; inactive.applications = [{ ...app, active: false }]; calls.detail.mockResolvedValue(inactive); await w.get('[data-action=reverse-settlement]').trigger('click'); await flushPromises(); expect(calls.reverse).toHaveBeenCalledWith(1, 20, expect.objectContaining({ accounting_date: request.accounting_date, expected_version: 1, reason: 'Synthetic cancellation', idempotency_key: expect.any(String) })); await w.get('[data-action=new-revision]').trigger('click'); expect(w.get('[data-section=revision-editor]').text()).toBe('1'); w.unmount()
 })
 it('gates write actions by server capabilities and never displays an old company detail', async () => {
  calls.list.mockResolvedValue({ items: [], next_cursor: null, capabilities: { manage: false, post: false, reverse: false } }); const w = mount(Workspace, { global: global() }); await flushPromises(); expect(w.get<HTMLButtonElement>('[data-action=new-settlement]').element.disabled).toBe(true)
  let done!: (value: unknown) => void; calls.detail.mockReturnValue(new Promise(resolve => { done = resolve })); route.params = { id: '4' }; await flushPromises(); store.currentSupplierId = 2; route.params = {}; await flushPromises(); done(detail(true)); await flushPromises(); expect(w.find('[data-section=settlement-detail]').exists()).toBe(false); w.unmount()
 })
 it('renders real reversal history with lines-only reversal plans without inventing zero financial totals', async () => {
  route.params = { id: '4' }
  const reversalLines = [{ account_code: '379.910', side: 'debit', amount: '100.00' }]
  calls.detail.mockResolvedValue({ ...detail(), applications: [{ ...app, active: false }, { id: 22, active: false, reversal_of: 20, accounting_date: '2026-12-31', recorded_at: '2026-12-31T13:00:00Z', receipt: { preview: { plan: { lines: reversalLines } } } }] })
  const w = mount(Workspace, { global: global({ SettlementDispositionEditor: true }) }); await flushPromises()
  const plans = w.findAll('[data-section=settlement-plan]'); expect(plans).toHaveLength(2)
  expect(plans[0]!.find('dl').exists()).toBe(true); expect(plans[1]!.find('dl').exists()).toBe(false)
  expect(plans[1]!.text()).toContain('379.910'); expect(plans[1]!.text()).toContain('100.00'); w.unmount()
 })
 it('keeps decimal input exact beyond Number safe integer range and rejects rounding', () => {
  expect(settlementCents('9007199254740991,01')).toBe('900719925474099101'); expect(settlementDecimal('900719925474099101')).toBe('9007199254740991.01'); for (const value of ['1.001', '-1', '1e6', 'NaN']) expect(() => settlementCents(value)).toThrow()
 })
})
