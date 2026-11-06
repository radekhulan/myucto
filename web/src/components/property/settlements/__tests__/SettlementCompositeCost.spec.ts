import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { beforeEach, expect, it, vi } from 'vitest'
import DocumentEditor from '../SettlementDocumentEditor.vue'
import cs from '@/i18n/cs.json'
const calls = vi.hoisted(() => ({ catalog: vi.fn(), prepare: vi.fn(), save: vi.fn() }))
vi.mock('@/api/propertySettlements', () => ({ propertySettlementsApi: calls }))
const registry = vi.hoisted(() => ({ list: vi.fn() }))
vi.mock('@/api/propertyRegistry', () => ({ propertyRegistryApi: registry }))
const capabilities = { manage: true, post: true, reverse: true }
const request = { segment_key: 'WATER-2026', period_from: '2026-01-01', period_to: '2026-12-31', cutoff_at: '2026-12-31T23:59:59+01:00', evidence: 'Synthetic complete external service title' }
const action = { props: ['actions'], template: '<div><button v-for="a in actions" :key="a.key" :data-action="a.key" :disabled="a.disabled" @click="a.run">{{ a.label }}</button></div>' }
const input = { props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<label>{{label}}<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" /></label>' }
const party = { emits: ['selected'], template: '<button type="button" data-action="pick-party" @click="$emit(\'selected\', {id:31,name:\'Synthetic recipient\'})">Synthetic selection</button>' }
function global() { return { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs } })], stubs: { ActionBar: action, DateInput: input, PropertyInstantInput: input, PropertyPartyPicker: party } } }
beforeEach(() => { vi.clearAllMocks(); vi.spyOn(HTMLFormElement.prototype, 'reportValidity').mockReturnValue(true); registry.list.mockImplementation(async (_s: number, kind: string) => [{ id: kind === 'unit' ? 7 : 8, kind, code: kind, revision: { state: kind === 'unit' ? { unit_no: 'Synthetic unit' } : { name: 'Synthetic water' } } }]); calls.prepare.mockResolvedValue({ document: { prescribed_cents: '13200000', paid_cents: '0', actual_cents: '13200000' }, preview_hash: 'server-document-hash' }); calls.save.mockResolvedValue({ id: 4 }) })
it('preserves explicit composite sources through native preview and save', async () => {
 const parts = [{ kind: 'document' as const, id: 41 }, { kind: 'lifecycle' as const, id: 52 }], selected = { kind: 's3_line' as const, id: 10 }, proof = { ...selected, cost_sources: parts }
 const origin = { id: 10, selector: selected, description: 'Synthetic native service', accounting_date: '2026-01-01', period_from: '2026-01-01', period_to: '2026-12-31', prescribed_cents: '13200000', carried_paid_cents: '0', cost_source_id: null, cost_cents: null }
 const cost = { ...origin, id: 99, selector: proof, description: 'Synthetic known80k and final52k', cost_source_id: 99, cost_cents: '13200000', cost_parts: [{ amount_cents: '8000000', accounting_date: '2026-12-31' }, { amount_cents: '5200000', accounting_date: '2027-04-01' }] }
 calls.catalog.mockImplementation(async (_s: number, kind: string) => ({ items: [kind === 's3' ? origin : cost], next_cursor: null }))
 const w = mount(DocumentEditor, { props: { supplier: 1, capabilities }, global: global() }); await flushPromises()
 await w.get('[data-field=unit]').setValue('7'); await w.get('[data-field=service]').setValue('8'); await w.get('[data-field=segment]').setValue(request.segment_key)
 for (const [field, value] of [['period-from', request.period_from], ['period-to', request.period_to], ['document-date', '2027-04-01'], ['cutoff', request.cutoff_at], ['known', '2027-04-01T12:00:00+02:00']] as const) await w.get(`[data-field=${field}] input`).setValue(value)
 await w.get('[data-field=document-evidence]').setValue(request.evidence); await w.get('[data-action=pick-party]').trigger('click'); await w.get('[data-action=load-sources]').trigger('click'); await flushPromises(); await w.get('[data-field=source-select]').setValue(true); await w.get('[data-action=load-costs]').trigger('click'); await flushPromises(); await w.get('[data-field=cost-select]').setValue(true); await w.get('[data-action=prepare-document]').trigger('click'); await flushPromises()
 expect(calls.prepare).toHaveBeenCalledWith(1, expect.objectContaining({ selectors: [proof], cost_source_id: 99 }), 0); expect(w.text()).toContain('01. 04. 2027'); expect(w.text()).toContain('80\u00a0000,00')
 await w.get('[data-action=save-document]').trigger('click'); await flushPromises(); expect(calls.save).toHaveBeenCalledWith(1, expect.objectContaining({ selectors: [proof] }), 0, 'server-document-hash', expect.any(String)); expect(cost.selector.cost_sources).toEqual(parts); expect(w.find('textarea[data-field=json]').exists()).toBe(false); w.unmount()
})
