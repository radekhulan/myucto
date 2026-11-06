import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { reactive } from 'vue'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import PropertyOpeningView from '@/views/PropertyOpeningView.vue'
const calls = vi.hoisted(() => ({ list: vi.fn(), preview: vi.fn(), confirm: vi.fn() }))
vi.mock('@/api/propertyOpening', () => ({ propertyOpeningApi: calls }))
const registry = vi.hoisted(() => ({ list: vi.fn() }))
vi.mock('@/api/propertyRegistry', () => ({ propertyRegistryApi: registry }))
const store = reactive({ currentSupplierId: 1 })
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => store }))
const actions = { props: ['actions'], template: '<div><button v-for="a in actions" :key="a.key" :data-action="a.key" :disabled="a.disabled" @click="a.run">{{ a.label }}</button></div>' }
const request = { mode: 'post_new', accounting_date: '2026-01-01', effective_at: '2026-01-01T00:00:00+01:00', expected_revision: 0, evidence_ref: 'Synthetic title', reason: 'Synthetic opening', items: [{ kind: 'credit', case_ref: 'credit', occurrence_ref: '2026', unit_id: 1, components: [{ key: 'credit', role: 'credit', account_code: '379.910', amount_cents: '10000' }] }] }
const view = () => mount(PropertyOpeningView, { global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })], stubs: { ActionBar: actions, DateInput: true, PropertyInstantInput: true } } })
async function upload(wrapper: ReturnType<typeof view>, value: unknown) {
 const input = wrapper.get('input[type=file]'); Object.defineProperty(input.element, 'files', { configurable: true, value: [{ name: 'opening.json', size: 100, text: async () => JSON.stringify(value) }] }); await input.trigger('change'); await flushPromises()
}
beforeEach(() => { vi.resetAllMocks(); store.currentSupplierId = 1; calls.list.mockResolvedValue({ items: [], capabilities: { manage: true, post: true, revise: true } }); calls.preview.mockResolvedValue({ snapshot: { journal: { lines: [] }, reversal: null }, preview_hash: 'synthetic-hash' }); calls.confirm.mockResolvedValue({}); registry.list.mockImplementation(async (_supplier: number, kind: string) => kind === 'unit' ? [{ id: 1, code: 'U-1', kind: 'unit', revision: { state: { unit_no: 'Byt 12' } } }] : [{ id: 9, code: 'SRV', kind: 'service', revision: { state: { name: 'Teplá voda' } } }]) })
describe('opening takeover', () => {
 it('imports the own format, confirms the exact preview and clears it', async () => {
  const wrapper = view(); await flushPromises(); await upload(wrapper, { format: 'myucto.property.opening.v1', request }); await wrapper.get('[data-action=preview]').trigger('click'); await flushPromises(); await wrapper.get('[data-action=confirm]').trigger('click'); await flushPromises(); expect(calls.confirm).toHaveBeenCalledWith(1, expect.objectContaining(request), 'synthetic-hash', expect.any(String)); expect(wrapper.find('[data-action=confirm]').exists()).toBe(false); wrapper.unmount()
 })
 it('rejects malformed component arrays before rendering', async () => {
  const wrapper = view(); await flushPromises(); await upload(wrapper, { format: 'myucto.property.opening.v1', request: { ...request, items: [{ ...request.items[0], components: null }] } }); expect(wrapper.get('[role=alert]').text()).toContain('invalidFile'); expect(calls.preview).not.toHaveBeenCalled(); wrapper.unmount()
 })
 it('discards an old supplier preview and respects revoked posting permission', async () => {
  let resolve!: (value: unknown) => void; calls.preview.mockReturnValue(new Promise(done => { resolve = done })); const wrapper = view(); await flushPromises(); await upload(wrapper, { format: 'myucto.property.opening.v1', request }); await wrapper.get('[data-action=preview]').trigger('click'); calls.list.mockResolvedValue({ items: [], capabilities: { manage: true, post: false, revise: false } }); store.currentSupplierId = 2; await flushPromises(); resolve({ snapshot: { journal: { lines: [] } }, preview_hash: 'old' }); await flushPromises(); expect(wrapper.find('[data-action=confirm]').exists()).toBe(false); calls.preview.mockResolvedValue({ snapshot: { journal: { lines: [] } }, preview_hash: 'new' }); await wrapper.get('[data-action=preview]').trigger('click'); await flushPromises(); expect(wrapper.get<HTMLButtonElement>('[data-action=confirm]').element.disabled).toBe(true); expect(calls.confirm).not.toHaveBeenCalled(); wrapper.unmount()
 })
 it('shows registry names and koruna amounts and links an existing opening entry by choice', async () => {
  calls.list.mockResolvedValue({ items: [], bind_candidates: [{ journal_entry_id: 41, journal_version: 3, document_no: 'OZ-2026', entry_date: '2026-01-01', description: null }], capabilities: { manage: true, post: true, revise: true } })
  const wrapper = view(); await flushPromises(); await upload(wrapper, { format: 'myucto.property.opening.v1', request: { ...request, items: [{ ...request.items[0], service_id: 9 }] } })
  expect(wrapper.get('[data-cell=unit]').text()).toBe('Byt 12'); expect(wrapper.get('[data-cell=service]').text()).toBe('Teplá voda')
  expect(wrapper.get('[data-cell=component]').text()).toMatch(/100,00\s*Kč/); expect(wrapper.text()).not.toContain('10000')
  expect(wrapper.find('input[type=number]').exists()).toBe(false)
  await wrapper.get('[data-field=mode]').setValue('bind_existing'); await wrapper.get('[data-field=journal]').setValue('41')
  await wrapper.get('[data-action=preview]').trigger('click'); await flushPromises()
  expect(calls.preview).toHaveBeenCalledWith(1, expect.objectContaining({ mode: 'bind_existing', expected_revision: 0, journal_entry_id: 41, journal_version: 3, accounting_date: '2026-01-01' })); wrapper.unmount()
 })
})
