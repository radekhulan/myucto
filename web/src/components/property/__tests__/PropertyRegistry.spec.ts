import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { nextTick, reactive } from 'vue'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import PropertyInstantInput from '../PropertyInstantInput.vue'
import PropertyStateEditor from '../PropertyStateEditor.vue'
import PropertyRegistryView from '@/views/PropertyRegistryView.vue'
import type { UnitState } from '@/api/propertyRegistry'
import cs from '@/i18n/cs.json'

const calls = vi.hoisted(() => ({ profile: vi.fn(), list: vi.fn(), summary: vi.fn(), detail: vi.fn(), create: vi.fn(), profileSave: vi.fn() }))
vi.mock('@/api/propertyRegistry', () => ({ propertyRegistryApi: calls }))
const company = { id: 1, company_name: 'SVJ Synthetická 12', street: 'Synthetická 12/3', city: 'Testov', zip: '100 00' }
const store = reactive<{ currentSupplierId: number; currentSupplier: typeof company | null }>({ currentSupplierId: 1, currentSupplier: company })
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => store }))
const plugins = () => [createI18n({ legacy: false, locale: 'cs', messages: { cs } })]
const dateStub = { props: ['modelValue'], emits: ['update:modelValue'], template: '<input data-date :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' }
const partyStub = { props: ['modelValue'], emits: ['update:modelValue'], template: '<input data-party :value="modelValue" @input="$emit(\'update:modelValue\', Number($event.target.value))" />' }
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(r => { resolve = r }); return { promise, resolve } }
const profile = { profile: null, history: [], capabilities: { manage: true, confirm: true, export: false }, import_columns: [] }
const enabledProfile = { ...profile, profile: { id: 1, revision_no: 1, enabled: true, effective_from: '2026-01-01T00:00:00+01:00', effective_to: null, reason: 'R', title_ref: 'T' }, history: [{ id: 1, revision_no: 1, enabled: true, effective_from: '2026-01-01T00:00:00+01:00', effective_to: null, reason: 'R', title_ref: 'T' }] }

beforeEach(() => { vi.clearAllMocks(); store.currentSupplierId = 1; store.currentSupplier = company; calls.profile.mockResolvedValue(profile); calls.list.mockResolvedValue([]); calls.summary.mockResolvedValue({ scopes: [] }) })

describe('property registry UI', () => {
  it('derives the Prague offset from the entered local date and time instead of asking for it', async () => {
    const wrapper = mount(PropertyInstantInput, { props: { modelValue: '', label: 'Účinnost', 'onUpdate:modelValue': value => { void wrapper.setProps({ modelValue: value }) } }, global: { plugins: plugins(), stubs: { DateInput: dateStub } } })
    expect(wrapper.find('input[placeholder="+02:00"]').exists()).toBe(false)
    await wrapper.get('[data-date]').setValue('2026-05-01')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([''])
    await wrapper.get('input[type="time"]').setValue('10:15')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['2026-05-01T10:15:00+02:00'])
    await wrapper.get('[data-date]').setValue('2026-01-10')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['2026-01-10T10:15:00+01:00'])
    await wrapper.setProps({ modelValue: '2026-06-02T13:20:30.123456Z' })
    expect((wrapper.get('[data-date]').element as HTMLInputElement).value).toBe('2026-06-02')
    expect((wrapper.get('input[type="time"]').element as HTMLInputElement).value).toBe('15:20')
    wrapper.unmount()
  })

  it('clears the whole instant instead of emitting a time-only remainder when the date is removed', async () => {
    const wrapper = mount(PropertyInstantInput, { props: { modelValue: '2026-06-02T13:20:30+02:00', label: 'Konec', 'onUpdate:modelValue': value => { void wrapper.setProps({ modelValue: value }) } }, global: { plugins: plugins(), stubs: { DateInput: dateStub } } })
    await wrapper.get('[data-date]').setValue('')
    await wrapper.get('input[type="time"]').setValue('')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([''])
    wrapper.unmount()
  })

  it('opens the first registry activation with prefilled evidence and blocks an invalid date', async () => {
    const wrapper = mount(PropertyRegistryView, { attachTo: document.body, global: { plugins: plugins(), stubs: { PropertyImportPanel: true } } })
    await flushPromises()
    const form = wrapper.get('[data-profile-form]')
    expect((form.get('[data-field="profile-title"]').element as HTMLInputElement).value).toBe(cs.propertyRegistry.defaults.profileTitle)
    expect((form.get('[data-field="profile-reason"]').element as HTMLInputElement).value).toBe(cs.propertyRegistry.defaults.profileReason)
    const [date] = form.findAll('input[inputmode="numeric"]')
    await date!.setValue('31.13.garbage')
    expect((form.element as HTMLFormElement).checkValidity()).toBe(false)
    await form.findAll('button').find(b => b.text() === cs.propertyRegistry.startProfile)!.trigger('click')
    await flushPromises()
    expect(calls.profileSave).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('prefills a new house from the company and derives the technical code and share group', async () => {
    calls.profile.mockResolvedValue(enabledProfile)
    calls.create.mockResolvedValue({ id: 5, revision: 1 })
    const wrapper = mount(PropertyRegistryView, { global: { plugins: plugins(), stubs: { PropertyImportPanel: true, PropertyInstantInput: true } } })
    await flushPromises()
    await wrapper.get('[data-create="house"]').trigger('click')
    expect((wrapper.get('[data-field="name"]').element as HTMLInputElement).value).toBe('Synthetická 12/3')
    expect((wrapper.get('[data-field="address.city"]').element as HTMLInputElement).value).toBe('Testov')
    await wrapper.get('[data-editor] form').trigger('submit')
    await flushPromises()
    expect(calls.create).toHaveBeenCalledWith(1, 'house', expect.objectContaining({
      code: 'Syntheticka-12-3', status: 'confirmed', effective_from: enabledProfile.profile.effective_from,
      reason: cs.propertyRegistry.defaults.reason.house, title_ref: cs.propertyRegistry.defaults.title.house,
      state: expect.objectContaining({ share_scope: 'Syntheticka-12-3', declaration_ref: cs.propertyRegistry.defaults.declaration, address: { street: 'Synthetická 12/3', city: 'Testov', postal_code: '100 00', country: 'CZ' } }),
    }))
    wrapper.unmount()
  })

  it('lists missing required house fields instead of sending a request the server rejects', async () => {
    calls.profile.mockResolvedValue(enabledProfile)
    store.currentSupplier = null
    const wrapper = mount(PropertyRegistryView, { global: { plugins: plugins(), stubs: { PropertyImportPanel: true, PropertyInstantInput: true } } })
    await flushPromises()
    await wrapper.get('[data-create="house"]').trigger('click')
    await wrapper.get('[data-field="name"]').setValue('Dům')
    await wrapper.get('[data-editor] form').trigger('submit')
    await flushPromises()
    expect(calls.create).not.toHaveBeenCalled()
    const missing = wrapper.get('[data-missing]').text()
    for (const field of ['street', 'city', 'postal_code'] as const) expect(missing).toContain(cs.propertyRegistry.address[field])
    expect(missing).not.toContain(cs.propertyRegistry.houseName)
    wrapper.unmount()
  })

  it('adds only attribute codes accepted by the registry backend', async () => {
    const state: UnitState = { unit_no: '12', kind: 'apartment', active: true, attributes: { share: { num: '1', den: '1' } }, ownerships: [], roles: [], identifiers: [], services: [] }
    const wrapper = mount(PropertyStateEditor, { props: { modelValue: state, kind: 'unit', supplierId: 1, services: [], partyLabels: {} }, global: { plugins: plugins() } })
    const code = wrapper.get(`input[aria-label="${cs.propertyRegistry.attributeCode}"]`)
    const add = wrapper.findAll('button').find(button => button.text() === cs.propertyRegistry.addAttribute)!
    await code.setValue('area:heated')
    await add.trigger('click')
    expect(state.attributes).not.toHaveProperty('area:heated')
    await code.setValue('area_heated')
    await add.trigger('click')
    expect(state.attributes.area_heated).toEqual({ num: '0', den: '1' })
    wrapper.unmount()
  })

  it('edits marital community as one interest and two members without duplicating or halving its share', async () => {
    const state: UnitState = { unit_no: '12', kind: 'apartment', active: true, attributes: { share: { num: '90', den: '1000' } }, ownerships: [], roles: [], identifiers: [], services: [] }
    const wrapper = mount(PropertyStateEditor, { props: { modelValue: state, kind: 'unit', supplierId: 1, services: [], partyLabels: {} }, global: { plugins: plugins(), stubs: { PropertyPartyPicker: partyStub } } })
    await wrapper.findAll('button').find(b => b.text() === cs.propertyRegistry.addOwner)!.trigger('click')
    const select = wrapper.findAll('select').find(s => s.find('option[value="marital_community"]').exists())!
    await select.setValue('marital_community')
    await wrapper.findAll('[data-party]')[0]!.setValue('11')
    await wrapper.findAll('[data-party]')[1]!.setValue('12')
    expect(state.ownerships).toHaveLength(1)
    expect(state.ownerships[0]?.members).toEqual([11, 12])
    expect(state.ownerships[0]?.share).toEqual({ num: '1', den: '1' })
    expect(state.roles).toEqual([])
    wrapper.unmount()
  })

  it('discards a previous company response and sends explicit company scope for all reads', async () => {
    const old = deferred<typeof profile>()
    calls.profile.mockImplementation((id: number) => id === 1 ? old.promise : Promise.resolve(profile))
    calls.list.mockImplementation((id: number, kind: string) => Promise.resolve(kind === 'house' ? [{ id: id * 10, kind: 'house', code: 'H', current_revision: 1, pending_revision_ids: [], revision: { state: { name: id === 1 ? 'Old company house' : 'New company house', active: true } } }] : []))
    const wrapper = mount(PropertyRegistryView, { global: { plugins: plugins(), stubs: { PropertyInstantInput: true, PropertyImportPanel: true } } })
    await nextTick(); store.currentSupplierId = 2; await flushPromises()
    expect(wrapper.text()).toContain('New company house')
    old.resolve(profile); await flushPromises()
    expect(wrapper.text()).not.toContain('Old company house')
    expect(wrapper.text()).toContain('New company house')
    expect(calls.profile.mock.calls.map(args => args[0])).toEqual([1, 2])
    expect(calls.list.mock.calls.filter(args => args[0] === 2).map(args => args[1])).toEqual(['house', 'unit', 'service'])
    wrapper.unmount()
  })

  it('keeps the setup guide with services and charges steps after the first unit', async () => {
    calls.profile.mockResolvedValue(enabledProfile)
    const object = (kind: string, id: number, state: Record<string, unknown>) => ({ id, kind, code: kind + id, current_revision: 1, pending_revision_ids: [], revision: { state: { active: true, ...state } } })
    const lists: Record<string, unknown[]> = { house: [object('house', 1, { name: 'Dům' })], unit: [object('unit', 2, { unit_no: '1' })], service: [] }
    calls.list.mockImplementation((_id: number, kind: string) => Promise.resolve(lists[kind] ?? []))
    const wrapper = mount(PropertyRegistryView, { global: { plugins: plugins(), stubs: { PropertyInstantInput: true, PropertyImportPanel: true } } })
    await flushPromises()
    expect(wrapper.text()).toContain(cs.propertySetup.services)
    expect(wrapper.text()).toContain(cs.propertySetup.charges)
    wrapper.unmount()
  })
})
