import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import EetSettingsPage from '../EetSettingsPage.vue'
import type { EetDevice } from '@/api/eet'

const m = vi.hoisted(() => ({
  settings: vi.fn(),
  units: vi.fn(),
  devices: vi.fn(),
  certificates: vi.fn(),
  createDevice: vi.fn(),
  updateDevice: vi.fn(),
  listRegisters: vi.fn(),
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}))
vi.mock('@/api/eet', () => ({
  eetApi: {
    settings: m.settings, units: m.units, devices: m.devices, certificates: m.certificates,
    createDevice: m.createDevice, updateDevice: m.updateDevice,
  },
}))
vi.mock('@/api/cash', () => ({ cashApi: { listRegisters: m.listRegisters } }))
vi.mock('@/api/auth', () => ({ authApi: {} }))
vi.mock('@/security/webauthn', () => ({ getCredential: vi.fn(), isWebAuthnAvailable: () => false }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => m.toast }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (s: string) => s, formatDateTime: (s: string) => s }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key) }) }))
vi.mock('@/components/ui/Modal.vue', () => ({ default: { props: ['title', 'widthClass'], template: '<div data-test="modal"><slot /><slot name="footer" /></div>' } }))

const device = (over: Partial<EetDevice> = {}): EetDevice => ({
  id: 5, unit_id: 3, code: 'POK-1', name: 'Pohonné hmoty', cash_register_id: null, delegation: 'none', delegating_eic: null,
  active: true, sequence_year: null, next_sequence: 1, unit_number: 31, unit_name: 'Čerpací stanice',
  unit_valid_from: '2026-01-01', unit_valid_to: null, created_at: null, updated_at: null, ...over,
})

async function render() {
  const wrapper = mount(EetSettingsPage, { global: { stubs: { ActionBar: true, EmptyState: true, DateInput: true, ToggleSwitch: true } } })
  await flushPromises()
  return wrapper
}

function saveButton(wrapper: Awaited<ReturnType<typeof render>>) {
  const button = wrapper.findAll('[data-test="modal"] button').find(b => b.text().includes('common.save'))
  if (!button) throw new Error('Tlačítko uložit chybí.')
  return button
}

beforeEach(() => {
  vi.clearAllMocks()
  m.settings.mockResolvedValue({
    settings: {
      enabled: true, environment: 'playground', eic: 'CZ683555118', active_from: '2026-01-01', active_to: null, regime: 'standard',
      exemption_reason: null, default_device_id: null, response_timeout_ms: 3000, dispatch_blocked: false, row_version: 1,
      updated_by: null, updated_at: null,
    },
    activation_obstacles: [],
  })
  m.units.mockResolvedValue([{ id: 3, unit_number: 31, name: 'Čerpací stanice', kind: 'premises', valid_from: '2026-01-01', valid_to: null, notified_to_fs: true, created_at: null, updated_at: null }])
  m.certificates.mockResolvedValue([])
  m.listRegisters.mockResolvedValue([])
  m.updateDevice.mockResolvedValue(device())
})

describe('pověření pokladního zařízení EET', () => {
  it('u pověření jedním poplatníkem vyžaduje EIČ a pošle ho velkými písmeny', async () => {
    m.devices.mockResolvedValue([device()])
    const wrapper = await render()
    const edit = wrapper.find('[data-test="eet-devices"]').findAll('button').find(b => b.text().includes('common.edit'))
    await edit!.trigger('click')

    await wrapper.find('[data-test="eet-device-delegation"]').setValue('single')
    expect(saveButton(wrapper).attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="eet-device-delegating-eic"]').setValue(' cz00000019 ')
    expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()
    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(m.updateDevice).toHaveBeenCalledWith(5, expect.objectContaining({ delegation: 'single', delegating_eic: 'CZ00000019' }))
  })

  it('u pověření více poplatníky je EIČ nepovinné a bez pověření se neposílá', async () => {
    m.devices.mockResolvedValue([device({ delegation: 'multiple', delegating_eic: 'CZ8551015704' })])
    const wrapper = await render()
    expect(wrapper.find('[data-test="eet-device-delegation-badge"]').text()).toBe('eet.devices.delegation_modes.multiple')

    const edit = wrapper.find('[data-test="eet-devices"]').findAll('button').find(b => b.text().includes('common.edit'))
    await edit!.trigger('click')
    await wrapper.find('[data-test="eet-device-delegating-eic"]').setValue('')
    expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()

    await wrapper.find('[data-test="eet-device-delegation"]').setValue('none')
    expect(wrapper.find('[data-test="eet-device-delegating-eic"]').exists()).toBe(false)
    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(m.updateDevice).toHaveBeenCalledWith(5, expect.objectContaining({ delegation: 'none', delegating_eic: null }))
  })

  it('zařízení v pověření nejde nastavit jako výchozí ani přiřadit pokladně', async () => {
    m.listRegisters.mockResolvedValue([{ id: 9, name: 'Pokladna', account_code: '211', is_active: true }])
    m.devices.mockResolvedValue([device({ id: 5, cash_register_id: 9 }), device({ id: 6, code: 'POK-2', delegation: 'single', delegating_eic: 'CZ00000019' })])
    const wrapper = await render()
    const defaults = wrapper.find('[data-test="eet-devices"]').findAll('button').filter(b => b.text().includes('eet.devices.make_default'))
    // Desktop a mobilní řádek: výchozím jde udělat jen zařízení bez pověření.
    expect(defaults).toHaveLength(2)

    const edit = wrapper.find('[data-test="eet-devices"]').findAll('button').find(b => b.text().includes('common.edit'))
    await edit!.trigger('click')
    expect(wrapper.find('[data-test="eet-device-register"]').exists()).toBe(true)
    await wrapper.find('[data-test="eet-device-delegation"]').setValue('multiple')
    expect(wrapper.find('[data-test="eet-device-register"]').exists()).toBe(false)
    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(m.updateDevice).toHaveBeenCalledWith(5, expect.objectContaining({ delegation: 'multiple', cash_register_id: null }))
  })
})
