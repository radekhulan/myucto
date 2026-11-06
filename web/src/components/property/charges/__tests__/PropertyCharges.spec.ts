import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { nextTick, reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ChargeRateEditor from '../ChargeRateEditor.vue'
import PropertyChargesView from '@/views/PropertyChargesView.vue'
import { chargeMoney } from '../chargeMoney'
import { chargeRounding, type ChargeRate } from '@/api/propertyCharges'
import cs from '@/i18n/cs.json'
const calls = vi.hoisted(() => ({ components: vi.fn(), context: vi.fn(), versions: vi.fn(), preview: vi.fn(), save: vi.fn(), createComponent: vi.fn(), list: vi.fn() }))
vi.mock('@/api/propertyCharges', async original => ({ ...(await original<Record<string, unknown>>()), propertyChargesApi: calls }))
vi.mock('@/api/propertyRegistry', () => ({ propertyRegistryApi: { list: calls.list } }))
const store = reactive({ currentSupplierId: 1 })
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => store }))
const plugins = () => [createI18n({ legacy: false, locale: 'cs', messages: { cs } })]
const instantStub = { props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' }
const units = [{ id: 1, code: 'U01', unit_no: 'Synthetic apartment', attributes: { share: { num: '1', den: '1' } }, services: [], revision_id: 1, state_hash: 'hash' }]
const component = { id: 1, code: 'ADMIN', name: 'Synthetic administration', kind: 'admin_advance', service_id: null, account_code: '', effective_at: '2026-01-01T00:00:00Z' }
const clickText = async (wrapper: ReturnType<typeof mount>, text: string) => {
  const button = wrapper.findAll('button').find(b => b.text() === text)
  expect(button).toBeDefined(); await button!.trigger('click'); await flushPromises()
}
beforeEach(() => {
  vi.clearAllMocks(); store.currentSupplierId = 1
  calls.list.mockResolvedValue([{ id: 1, code: 'LIP', kind: 'house', revision: { state: { name: 'Synthetic building' } } }])
  calls.components.mockResolvedValue({ items: [component], capabilities: { manage: true, confirm: true } })
  calls.versions.mockResolvedValue({ items: [], selected: null })
  calls.context.mockResolvedValue({ as_of: '2026-01-01T00:00:00Z', house: { id: 1, name: 'Synthetic building' }, units, services: [] })
})
describe('property charge versions', () => {
  it('keeps six decimal rate text and one unit row independently of ownership', async () => {
    const rate: ChargeRate = { component_id: 1, unit_ids: [], method: 'unit', mode: 'rate_per_basis', amount: '', rounding: chargeRounding, legal_basis: '', scope_ref: '', correction: false, correction_reason: '' }
    const wrapper = mount(ChargeRateEditor, { props: { modelValue: rate, units, components: [component] }, global: { plugins: plugins() } })
    await wrapper.get('[data-amount]').setValue('0.005001')
    expect(rate.amount).toBe('0.005001')
    await clickText(wrapper, 'Vybrat všechny jednotky')
    expect(rate.unit_ids).toEqual([1])
    expect(wrapper.text().match(/Synthetic apartment/g)).toHaveLength(1)
    expect(chargeMoney('99999999999999')).toBe('999\u00a0999\u00a0999\u00a0999,99')
    expect(chargeMoney('-1')).toBe('-0,01')
  })
  it('invalidates confirmed preview when a rate changes', async () => {
    calls.preview.mockImplementation((_supplier, request) => Promise.resolve({ preview_hash: 'hash', preview: { request, registry: { units }, previous_version_id: null, previous_units: [], overlapping_versions: [], previous_frozen_differences: [], same_basis_differences: null, same_basis_unavailable: null, calculation: { total_cents: '1', unit_totals: [], rates: [], issuance_facts: [] } } }))
    const wrapper = mount(PropertyChargesView, { global: { plugins: plugins(), stubs: { PropertyInstantInput: instantStub } } })
    await flushPromises(); await wrapper.get('[data-house]').setValue('1'); await flushPromises()
    await clickText(wrapper, 'Nový předpis záloh'); await clickText(wrapper, 'Přidat sazbu'); await wrapper.get('[data-amount]').setValue('0.01')
    await wrapper.findAll('select').find(select => select.find('option[value="monthly"]').exists())!.setValue('monthly')
    await clickText(wrapper, 'Zobrazit výpočet')
    expect(wrapper.text()).toContain('Uložit předpis')
    await wrapper.get('[data-amount]').setValue('0.02'); await nextTick()
    expect(wrapper.text()).not.toContain('Uložit předpis')
    expect(calls.preview).toHaveBeenCalledWith(1, expect.objectContaining({ basis_policy: 'fixed_registry_snapshot', period_policy: 'no_automatic_proration' }))
    expect(calls.save).not.toHaveBeenCalled()
  })
  it.each(['effective instant', 'reload'])('restores actions after %s invalidates a pending preview', async mode => {
    let finishOld!: () => void
    calls.preview.mockImplementationOnce((_supplier, request) => new Promise(resolve => {
      finishOld = () => resolve({ preview_hash: 'old-hash', preview: { request, registry: { units }, previous_version_id: null, previous_units: [], overlapping_versions: [], previous_frozen_differences: [], same_basis_differences: null, same_basis_unavailable: null, calculation: { total_cents: '1', unit_totals: [], rates: [], issuance_facts: [] } } })
    }))
    const wrapper = mount(PropertyChargesView, { global: { plugins: plugins(), stubs: { PropertyInstantInput: instantStub } } })
    await flushPromises(); await wrapper.get('[data-house]').setValue('1'); await flushPromises()
    await clickText(wrapper, 'Nový předpis záloh'); await clickText(wrapper, 'Přidat sazbu'); await wrapper.get('[data-amount]').setValue('0.01')
    await clickText(wrapper, 'Zobrazit výpočet')
    const previewButton = () => wrapper.findAll('button').find(button => button.text() === 'Zobrazit výpočet')!
    expect(previewButton().attributes('disabled')).toBeDefined()
    if (mode === 'effective instant') await wrapper.findAll('input')[0]!.setValue('2026-02-01T00:00:00Z')
    await clickText(wrapper, 'Načíst')
    finishOld(); await flushPromises()
    expect(wrapper.text()).not.toContain('Uložit předpis')
    await wrapper.get('[data-house]').setValue('1'); await flushPromises()
    await clickText(wrapper, 'Nový předpis záloh'); await clickText(wrapper, 'Přidat sazbu')
    expect(previewButton().attributes('disabled')).toBeUndefined()
    expect(calls.save).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('discards an old tenant response and uses explicit supplier arguments', async () => {
    let resolveOld!: (value: unknown) => void
    calls.components.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve })).mockResolvedValue({ items: [{ ...component, name: 'New tenant component' }], capabilities: { manage: false, confirm: false } })
    const wrapper = mount(PropertyChargesView, { global: { plugins: plugins(), stubs: { PropertyInstantInput: instantStub } } })
    store.currentSupplierId = 2; await flushPromises()
    resolveOld({ items: [{ ...component, name: 'Old tenant component' }], capabilities: { manage: true, confirm: true } }); await flushPromises()
    expect(wrapper.text()).toContain('New tenant component'); expect(wrapper.text()).not.toContain('Old tenant component')
    expect(calls.components.mock.calls.map(args => args[0])).toEqual([1, 2])
  })
})
