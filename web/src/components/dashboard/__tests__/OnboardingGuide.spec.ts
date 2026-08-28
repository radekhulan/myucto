import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * Průvodce prvním nastavením na prázdném Přehledu.
 *
 * Co test hlídá:
 *   1. kroky, na které uživatel nemá právo, se nezobrazí (jinak by průvodce
 *      posílal účetní na stránku, kterou jí BE stejně zamítne),
 *   2. odškrtnutí i skrytí se ukládá do preference `onboarding.guide` — bez
 *      toho by se průvodce po refreshi vrátil celý,
 *   3. skrytý průvodce nemizí úplně: zůstane lišta, kterou se vrátí,
 *   4. viditelnost se hlásí ven (`update:visible`), protože Přehled podle ní
 *      rozhoduje, jestli pod průvodcem ještě ukázat prázdný stav.
 */

const m = vi.hoisted(() => ({
  getPreferenceKey: vi.fn(),
  putPreferenceKey: vi.fn(),
  canRead: vi.fn((_p: string) => true),
  canWrite: vi.fn((_p: string) => true),
  isSuperadmin: true,
  accountingMode: 'tax_evidence' as string,
  payrollEnabled: true as boolean,
  license: null as null | { max_companies: number | null; companies_active: number },
}))

vi.mock('@/api/preferences', () => ({
  preferencesApi: {
    getPreferenceKey: m.getPreferenceKey,
    putPreferenceKey: m.putPreferenceKey,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: m.canRead,
    canWrite: m.canWrite,
    get isSuperadmin() { return m.isSuperadmin },
    get license() { return m.license },
  }),
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({
    get currentSupplier() { return { accounting_mode: m.accountingMode, payroll_enabled: m.payrollEnabled } },
  }),
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import OnboardingGuide from '../OnboardingGuide.vue'

const MOUNT_GLOBAL = { stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } }

async function mountGuide() {
  const wrapper = mount(OnboardingGuide, { global: MOUNT_GLOBAL })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  m.getPreferenceKey.mockResolvedValue(undefined)
  m.putPreferenceKey.mockResolvedValue({})
  m.canRead.mockReturnValue(true)
  m.canWrite.mockReturnValue(true)
  m.isSuperadmin = true
  m.accountingMode = 'tax_evidence'
  m.payrollEnabled = true
  m.license = null
})

describe('OnboardingGuide', () => {
  it('vykreslí všechny kroky a ohlásí se jako viditelný', async () => {
    const wrapper = await mountGuide()

    expect(wrapper.findAll('article')).toHaveLength(11)
    expect(wrapper.emitted('update:visible')?.at(-1)).toEqual([true])
    expect(wrapper.text()).toContain('dashboard.onboarding.title')
  })

  it('další firmy nabídne jen licenci na víc firem, dokud v ní zbývá místo', async () => {
    expect((await mountGuide()).text()).not.toContain('dashboard.onboarding.steps.suppliers.title')

    m.license = { max_companies: 1, companies_active: 1 }
    expect((await mountGuide()).text()).not.toContain('dashboard.onboarding.steps.suppliers.title')

    m.license = { max_companies: 5, companies_active: 5 }   // licence na víc firem, ale plno
    expect((await mountGuide()).text()).not.toContain('dashboard.onboarding.steps.suppliers.title')

    m.license = { max_companies: 5, companies_active: 1 }
    expect((await mountGuide()).text()).toContain('dashboard.onboarding.steps.suppliers.title')

    m.license = { max_companies: null, companies_active: 3 } // neomezeně
    expect((await mountGuide()).text()).toContain('dashboard.onboarding.steps.suppliers.title')
  })

  it('číselné řady deníku nabídne jen podvojnému účetnictví', async () => {
    expect((await mountGuide()).text()).not.toContain('dashboard.onboarding.steps.series.title')

    m.accountingMode = 'double_entry'
    const wrapper = await mountGuide()

    expect(wrapper.text()).toContain('dashboard.onboarding.steps.series.title')
    expect(wrapper.findAll('article')).toHaveLength(12)
  })

  /**
   * Datovou schránku firma zřizuje kvůli mzdovým podáním. S vypnutými mzdami
   * krok mizí stejně jako položka v menu — jinak by průvodce posílal uživatele
   * nastavovat kanál, kterým nemá co odeslat.
   */
  it('datovou schránku nabídne jen se zapnutými mzdami', async () => {
    expect((await mountGuide()).text()).toContain('dashboard.onboarding.steps.databox.title')

    m.payrollEnabled = false
    const wrapper = await mountGuide()
    expect(wrapper.text()).not.toContain('dashboard.onboarding.steps.databox.title')
    expect(wrapper.findAll('article')).toHaveLength(10)
  })

  it('vynechá kroky, na které uživatel nemá právo', async () => {
    m.isSuperadmin = false
    m.canRead.mockImplementation((p: string) => p === 'settings.company')
    m.canWrite.mockImplementation((p: string) => p === 'clients')

    const wrapper = await mountGuide()

    const text = wrapper.text()
    expect(text).toContain('dashboard.onboarding.steps.company.title')
    expect(text).toContain('dashboard.onboarding.steps.client.title')
    expect(text).not.toContain('dashboard.onboarding.steps.bank.title')      // canRead('bank') = false
    expect(text).not.toContain('dashboard.onboarding.steps.users.title')     // jen superadmin
    expect(text).not.toContain('dashboard.onboarding.steps.invoice.title')   // canWrite('invoices') = false
  })

  it('načte uložený stav — odškrtnuté kroky zůstanou odškrtnuté', async () => {
    m.getPreferenceKey.mockResolvedValue({ hidden: false, done: ['company', 'bank'] })

    const wrapper = await mountGuide()

    expect(wrapper.findAll('button[aria-pressed="true"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('dashboard.onboarding.progress')
  })

  it('odškrtnutí kroku uloží preferenci', async () => {
    const wrapper = await mountGuide()

    await wrapper.findAll('button[aria-pressed]')[0].trigger('click')

    expect(m.putPreferenceKey).toHaveBeenCalledWith('onboarding.guide', { hidden: false, done: ['company'] })
    expect(wrapper.findAll('button[aria-pressed="true"]')).toHaveLength(1)
  })

  it('odškrtnutí jde vzít zpět', async () => {
    const wrapper = await mountGuide()
    const toggle = () => wrapper.findAll('button[aria-pressed]')[0]

    await toggle().trigger('click')
    await toggle().trigger('click')

    expect(m.putPreferenceKey).toHaveBeenLastCalledWith('onboarding.guide', { hidden: false, done: [] })
    expect(wrapper.findAll('button[aria-pressed="true"]')).toHaveLength(0)
  })

  it('skrytí uloží stav, ohlásí neviditelnost a nabídne návrat', async () => {
    const wrapper = await mountGuide()

    const hide = wrapper.findAll('button').find(b => b.text().includes('dashboard.onboarding.hide'))!
    await hide.trigger('click')

    expect(m.putPreferenceKey).toHaveBeenCalledWith('onboarding.guide', { hidden: true, done: [] })
    expect(wrapper.emitted('update:visible')?.at(-1)).toEqual([false])
    expect(wrapper.findAll('article')).toHaveLength(0)
    expect(wrapper.text()).toContain('dashboard.onboarding.show')
  })

  it('uložený skrytý stav vykreslí jen lištu pro návrat a vrátí se na kliknutí', async () => {
    m.getPreferenceKey.mockResolvedValue({ hidden: true, done: [] })
    const wrapper = await mountGuide()

    expect(wrapper.emitted('update:visible')?.at(-1)).toEqual([false])
    expect(wrapper.findAll('article')).toHaveLength(0)

    await wrapper.find('button').trigger('click')

    expect(wrapper.findAll('article')).toHaveLength(11)
    expect(wrapper.emitted('update:visible')?.at(-1)).toEqual([true])
  })

  it('selhání uložení nesmí shodit průvodce', async () => {
    m.putPreferenceKey.mockRejectedValue(new Error('offline'))
    const wrapper = await mountGuide()

    await wrapper.findAll('button[aria-pressed]')[0].trigger('click')
    await flushPromises()

    expect(wrapper.findAll('button[aria-pressed="true"]')).toHaveLength(1)
  })
})
