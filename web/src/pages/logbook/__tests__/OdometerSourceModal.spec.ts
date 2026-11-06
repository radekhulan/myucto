import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { Car, OdometerSourceAccount, OdometerSourceCar, OdometerSourceDefinition, OdometerSourceStatus } from '@/api/logbook'

const m = vi.hoisted(() => ({
  odometerSources: vi.fn(),
  odometerSourceStatus: vi.fn(),
  odometerSourceConnect: vi.fn(),
  odometerSourceContinue: vi.fn(),
  odometerSourceAttach: vi.fn(),
  odometerSourceSelectVehicle: vi.fn(),
  odometerSourceSync: vi.fn(),
  odometerSourceDisconnect: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

const steps: Record<string, string[]> = {
  'logbook.odosrc_providers.bmw_cardata.steps': ['Create a client', 'Paste its ID'],
}
const known = new Set([
  'logbook.odosrc_providers.bmw_cardata.description',
  'logbook.odosrc_err.reauth_required',
  'logbook.odosrc_err.expired_token',
  'logbook.odosrc_err.rate_limited',
  'logbook.odosrc_err.odometer_regression',
  'logbook.odosrc_providers.bmw_cardata.err.rate_limited',
])
vi.mock('@/api/logbook', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/logbook')>()),
  logbookApi: { ...m },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.toastSuccess, error: m.toastError }) }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => (params ? `${key} ${JSON.stringify(params)}` : key),
    te: (key: string) => known.has(key), tm: (key: string) => steps[key] ?? key, rt: (s: string) => s,
  }),
}))
vi.mock('@/composables/useFormat', () => ({ formatDateTime: (d: string) => d }))

import OdometerSourceModal from '@/pages/logbook/OdometerSourceModal.vue'

const car = { id: 3, registration: '1AB2345', name: 'iX2', vin: null } as Car
const bmw: OdometerSourceDefinition = { code: 'bmw_cardata', label: 'BMW CarData', fields: [{ key: 'client_id' }], daily_call_limit: 50 }
const other: OdometerSourceDefinition = { code: 'other_api', label: 'Other', fields: [{ key: 'api_key' }, { key: 'account' }], daily_call_limit: null }
const account: OdometerSourceAccount = {
  id: 7, provider: 'bmw_cardata', status: 'active', last_sync_at: null, last_sync_error_code: null,
  calls_today: 3, daily_call_limit: 50, cars: [{ car_id: 3, registration: '1AB2345', name: 'iX2' }],
}
const assigned: OdometerSourceCar = {
  account_id: 7, car_id: 3, vehicle_ref: 'WBA00000000000001', last_sync_at: '2099-03-02 03:30:00', last_sync_status: 'success',
  last_sync_error_code: null, last_odometer: 12345, last_value_at: '2099-03-01 18:00:00',
}
const none: OdometerSourceStatus = { car: null, account: null, accounts: [] }
const connected: OdometerSourceStatus = { car: assigned, account, accounts: [account] }

async function pick(w: ReturnType<typeof mount>, label: string) {
  await w.findAll('li button').find(b => b.text().includes(label))!.trigger('click')
}
function button(w: ReturnType<typeof mount>, key: string) {
  return w.findAll('button').find(b => b.text().includes(key))!
}

describe('OdometerSourceModal.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    m.odometerSources.mockResolvedValue([bmw])
    m.odometerSourceStatus.mockResolvedValue(none)
  })
  afterEach(() => { vi.useRealTimers() })

  it('connects through a device code and polls until confirmed', async () => {
    m.odometerSourceConnect.mockResolvedValue({ state: 'device_code', user_code: 'ABCD1234', url: 'https://login.example.test/link?user_code=ABCD1234', interval: 5, expires_in: 300 })
    m.odometerSourceContinue
      .mockResolvedValueOnce({ state: 'waiting', interval: 5 })
      .mockResolvedValueOnce({ state: 'connected', ...connected })
    const w = mount(OdometerSourceModal, { props: { car } })
    await flushPromises()
    await pick(w, 'BMW CarData')

    await w.find('input').setValue(' 00000000-0000-4000-8000-000000000001 ')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(m.odometerSourceConnect).toHaveBeenCalledWith(3, 'bmw_cardata', { client_id: '00000000-0000-4000-8000-000000000001' }, undefined)
    expect(w.text()).toContain('ABCD1234')
    expect(w.find('a').attributes('href')).toBe('https://login.example.test/link?user_code=ABCD1234')
    expect(m.odometerSourceContinue).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(5000)
    expect(m.odometerSourceContinue).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(5000)
    await flushPromises()

    expect(m.odometerSourceContinue).toHaveBeenCalledTimes(2)
    expect(w.text()).toContain('WBA00000000000001')
    expect(w.emitted('changed')).toHaveLength(1)
  })

  it('renders the form of the selected source from the catalog', async () => {
    m.odometerSources.mockResolvedValue([bmw, other])
    m.odometerSourceConnect.mockResolvedValue({ state: 'redirect', url: 'https://login.example.test/oauth', expires_in: 600 })
    const w = mount(OdometerSourceModal, { props: { car } })
    await flushPromises()

    await pick(w, 'Other')
    const inputs = w.findAll('input')
    expect(inputs).toHaveLength(2)
    await inputs[0].setValue('k')
    await inputs[1].setValue('a')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(m.odometerSourceConnect).toHaveBeenCalledWith(3, 'other_api', { api_key: 'k', account: 'a' }, undefined)
    expect(w.find('a').attributes('href')).toBe('https://login.example.test/oauth')
    expect(w.text()).toContain('logbook.odosrc_redirect_hint')
  })

  it('offers the source choice first even with a single source', async () => {
    const w = mount(OdometerSourceModal, { props: { car } })
    await flushPromises()

    expect(w.find('form').exists()).toBe(false)
    expect(w.text()).toContain('logbook.odosrc_providers.bmw_cardata.description')
    await pick(w, 'BMW CarData')
    expect(w.findAll('ol li').map(li => li.text())).toEqual(['Create a client', 'Paste its ID'])

    await button(w, 'logbook.odosrc_other_source').trigger('click')
    expect(w.find('form').exists()).toBe(false)
    expect(w.findAll('li button')).toHaveLength(1)
  })

  it('attaches the car to an already connected account without a new sign-in', async () => {
    const otherCarAccount = { ...account, cars: [{ car_id: 9, registration: '9ZZ9999', name: null }] }
    m.odometerSourceStatus.mockResolvedValue({ car: null, account: null, accounts: [otherCarAccount] })
    m.odometerSourceAttach.mockResolvedValue({ state: 'connected', ...connected })
    const w = mount(OdometerSourceModal, { props: { car } })
    await flushPromises()

    expect(w.text()).toContain('9ZZ9999')
    await pick(w, '9ZZ9999')
    await flushPromises()

    expect(m.odometerSourceAttach).toHaveBeenCalledWith(3, 7)
    expect(m.odometerSourceConnect).not.toHaveBeenCalled()
    expect(w.text()).toContain('WBA00000000000001')
    expect(w.emitted('changed')).toHaveLength(1)
  })

  it('asks for the vehicle of the account when pairing is ambiguous', async () => {
    m.odometerSourceStatus.mockResolvedValue({ car: null, account: null, accounts: [account] })
    m.odometerSourceAttach.mockResolvedValue({ state: 'select_vehicle', account_id: 7, vehicles: [
      { ref: 'WBA00000000000001', vin: 'WBA00000000000001', label: null, primary: true },
      { ref: 'WBA00000000000002', vin: 'WBA00000000000002', label: null, primary: false },
    ] })
    m.odometerSourceSelectVehicle.mockResolvedValue({ state: 'connected', ...connected })
    const w = mount(OdometerSourceModal, { props: { car } })
    await flushPromises()
    await pick(w, '1AB2345')
    await flushPromises()

    await w.findAll('input[type="radio"]')[1].setValue(true)
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(m.odometerSourceSelectVehicle).toHaveBeenCalledWith(3, 7, 'WBA00000000000002')
  })

  it('signs in again to the same account and keeps its cars', async () => {
    m.odometerSourceStatus.mockResolvedValue({ ...connected, account: { ...account, status: 'error', last_sync_error_code: 'reauth_required' } })
    m.odometerSourceConnect.mockResolvedValue({ state: 'device_code', user_code: 'X', url: 'https://login.example.test/l', interval: 5, expires_in: 300 })
    const w = mount(OdometerSourceModal, { props: { car } })
    await flushPromises()

    expect(w.text()).toContain('logbook.odosrc_err.reauth_required')
    await button(w, 'logbook.odosrc_reconnect').trigger('click')
    expect(w.text()).toContain('logbook.odosrc_reauth_hint')
    await w.find('form input').setValue('00000000-0000-4000-8000-000000000001')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(m.odometerSourceConnect).toHaveBeenCalledWith(3, 'bmw_cardata', { client_id: '00000000-0000-4000-8000-000000000001' }, 7)
  })

  it('shows a skipped reading as a warning and fetches on demand', async () => {
    m.odometerSourceStatus.mockResolvedValue({ ...connected, car: { ...assigned, last_sync_status: 'warning', last_sync_error_code: 'odometer_regression' } })
    m.odometerSourceSync.mockResolvedValue({ reading: { odometer: 12400, reading_date: '2099-03-02', reading_time: '08:00' }, ...connected })
    const w = mount(OdometerSourceModal, { props: { car } })
    await flushPromises()

    expect(w.find('.bg-warning-50').text()).toContain('logbook.odosrc_err.odometer_regression')
    expect(w.find('.bg-warning-50').text()).toContain('logbook.odosrc_warning_hint')
    await button(w, 'logbook.odosrc_sync').trigger('click')
    await flushPromises()

    expect(m.odometerSourceSync).toHaveBeenCalledWith(3)
    expect(w.find('.bg-warning-50').exists()).toBe(false)
    expect(w.emitted('changed')).toHaveLength(1)
  })

  it('shows missing data as a warning without the hint about trips', async () => {
    m.odometerSourceStatus.mockResolvedValue({ ...connected, car: { ...assigned, last_sync_status: 'warning', last_sync_error_code: 'no_odometer_data' } })
    const w = mount(OdometerSourceModal, { props: { car } })
    await flushPromises()

    expect(w.find('.bg-danger-50').exists()).toBe(false)
    expect(w.find('.bg-warning-50').text()).toContain('no_odometer_data')
    expect(w.find('.bg-warning-50').text()).not.toContain('logbook.odosrc_warning_hint')
  })

  it('prefers the source-specific error text over the generic one', async () => {
    m.odometerSourceStatus.mockResolvedValue({ ...connected, account: { ...account, status: 'error', last_sync_error_code: 'rate_limited' } })
    const w = mount(OdometerSourceModal, { props: { car } })
    await flushPromises()

    expect(w.text()).toContain('logbook.odosrc_providers.bmw_cardata.err.rate_limited')
    expect(w.text()).not.toContain('logbook.odosrc_err.rate_limited')
  })

  it('warns that disconnecting the last car disconnects the account', async () => {
    m.odometerSourceStatus.mockResolvedValue(connected)
    m.odometerSourceDisconnect.mockResolvedValue({ disconnected: true })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const w = mount(OdometerSourceModal, { props: { car } })
    await flushPromises()

    await button(w, 'logbook.odosrc_disconnect').trigger('click')
    await flushPromises()

    expect(confirm.mock.calls[0][0]).toContain('logbook.odosrc_disconnect_last_confirm')
    expect(m.odometerSourceDisconnect).toHaveBeenCalledWith(3)
    confirm.mockRestore()
  })

  it('stops polling when the sign-in expires', async () => {
    m.odometerSourceConnect.mockResolvedValue({ state: 'device_code', user_code: 'X', url: 'https://login.example.test/l', interval: 5, expires_in: 8 })
    m.odometerSourceContinue.mockResolvedValue({ state: 'waiting', interval: 5 })
    const w = mount(OdometerSourceModal, { props: { car } })
    await flushPromises()
    await pick(w, 'BMW CarData')
    await w.find('input').setValue('00000000-0000-4000-8000-000000000001')
    await w.find('form').trigger('submit')
    await flushPromises()

    await vi.advanceTimersByTimeAsync(20000)
    await flushPromises()

    expect(m.odometerSourceContinue).toHaveBeenCalledTimes(1)
    expect(m.toastError).toHaveBeenCalledWith('logbook.odosrc_err.expired_token')
    expect(w.find('form').exists()).toBe(true)
  })
})
