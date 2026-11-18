import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * Tlačítko ARES/VIES u výběru kontaktu na dokladu: porovná kartu s registrem
 * a zaškrtnuté rozdíly uloží částečným PUT. Registry se nevolají naživo.
 */
const m = vi.hoisted(() => ({
  get: vi.fn(),
  update: vi.fn(),
  lookupAres: vi.fn(),
  lookupVies: vi.fn(),
  lookupBank: vi.fn(),
  canWrite: vi.fn(() => true),
  success: vi.fn(),
}))

vi.mock('@/api/clients', () => ({
  clientsApi: { get: m.get, update: m.update, lookupAres: m.lookupAres, lookupVies: m.lookupVies, lookupBank: m.lookupBank },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/components/ui/Modal.vue', () => ({ default: { template: '<div data-test="modal"><slot /><slot name="footer" /></div>' } }))

import ClientRegistryCheck from '../ClientRegistryCheck.vue'

const stored = {
  id: 7, company_name: 'Testovací s.r.o.', ic: '12345678', dic: 'CZ12345678',
  street: 'Stará 1', city: 'Praha', zip: '110 00', country_iso2: 'CZ', is_vat_payer: false,
}
const aresData = {
  company_name: 'Testovací s.r.o.', ic: '12345678', dic: 'CZ12345678',
  street: 'Nová 2', city: 'Praha', zip: '11000', country_iso2: 'CZ', is_vat_payer: true,
}

function mountWith(client: Record<string, unknown> | null = stored) {
  return mount(ClientRegistryCheck, { props: { clientId: 7, client: client as never } })
}

beforeEach(() => {
  vi.clearAllMocks()
  m.canWrite.mockReturnValue(true)
  m.get.mockResolvedValue({ ...stored })
  m.lookupBank.mockResolvedValue({ found: true, unreliable: false, accounts: [], source: 'fresh' })
})

describe('ClientRegistryCheck', () => {
  it('bez práva upravit kontakt tlačítko nevykreslí', () => {
    m.canWrite.mockReturnValue(false)
    const w = mountWith()
    expect(w.find('[data-test="registry-check"]').exists()).toBe(false)
  })

  it('bez IČO i DIČ je tlačítko neaktivní s důvodem', () => {
    const w = mountWith({ ...stored, ic: null, dic: null })
    const btn = w.find('[data-test="registry-check"]')
    expect(btn.attributes('disabled')).toBeDefined()
    expect(btn.attributes('title')).toBe('client.registry_check.disabled_reason')
  })

  it('porovná s ARES a uloží jen zaškrtnuté rozdíly', async () => {
    m.lookupAres.mockResolvedValue({ found: true, source: 'fresh', data: aresData })
    m.update.mockResolvedValue({ ...stored, street: 'Nová 2', is_vat_payer: true })
    const w = mountWith()
    await w.find('[data-test="registry-check"]').trigger('click')
    await flushPromises()

    expect(m.lookupAres).toHaveBeenCalledWith('12345678')
    expect(m.lookupVies).not.toHaveBeenCalled()
    // PSČ se liší jen mezerou — není to rozdíl.
    expect(w.find('[data-test="registry-row-zip"]').find('input').exists()).toBe(false)
    expect(w.find('[data-test="registry-row-street"]').find('input').exists()).toBe(true)

    // Plátcovství odškrtneme, uloží se jen ulice.
    await w.find('[data-test="registry-row-is_vat_payer"]').find('input').trigger('change')
    await w.find('[data-test="registry-apply"]').trigger('click')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(7, { street: 'Nová 2' })
    expect(w.emitted('updated')?.[0]?.[1]).toEqual(['street'])
  })

  it('bez rozdílů ukáže, že údaje jsou aktuální, a nenabídne uložení', async () => {
    m.get.mockResolvedValue({ ...stored, street: 'Nová 2', is_vat_payer: true })
    m.lookupAres.mockResolvedValue({ found: true, source: 'cache', data: aresData })
    const w = mountWith()
    await w.find('[data-test="registry-check"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="registry-up-to-date"]').exists()).toBe(true)
    expect(w.find('[data-test="registry-apply"]').exists()).toBe(false)
  })

  it('zahraniční kontakt bez IČO ověří podle DIČ ve VIES', async () => {
    const foreign = { ...stored, ic: null, dic: 'SK2020202020', country_iso2: 'SK', street: 'Hlavná 1', city: 'Bratislava', zip: '81101', is_vat_payer: true }
    m.get.mockResolvedValue(foreign)
    m.lookupVies.mockResolvedValue({ valid: true, source: 'rest', name: 'Testovací s.r.o.', country: 'SK', parsed: { street: 'Hlavná 5', city: 'Bratislava', zip: '81101' } })
    const w = mountWith(foreign)
    expect(w.find('[data-test="registry-check"]').text()).toContain('VIES')
    await w.find('[data-test="registry-check"]').trigger('click')
    await flushPromises()
    expect(m.lookupVies).toHaveBeenCalledWith('SK2020202020')
    expect(m.lookupAres).not.toHaveBeenCalled()
    expect(w.find('[data-test="registry-row-street"]').find('input').exists()).toBe(true)
  })

  it('nedostupný registr ukáže hlášku místo pádu', async () => {
    m.lookupAres.mockImplementation(() => Promise.reject({ response: { data: { error: { message: 'ARES je dočasně nedostupný.' } } } }))
    const w = mountWith()
    await w.find('[data-test="registry-check"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="registry-error"]').text()).toBe('ARES je dočasně nedostupný.')
    expect(w.find('[data-test="registry-apply"]').exists()).toBe(false)
  })

  it('nenalezené IČO v ARES ohlásí', async () => {
    m.lookupAres.mockResolvedValue({ found: false, source: 'fresh' })
    const w = mountWith()
    await w.find('[data-test="registry-check"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="registry-error"]').text()).toBe('client.registry_check.ares_not_found')
  })
})
