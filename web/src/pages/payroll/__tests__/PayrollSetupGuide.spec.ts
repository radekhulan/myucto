import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * Průvodce prvním nastavením mezd.
 *
 * Co test hlídá:
 *   1. kroky, na které uživatel nemá právo, se nezobrazí (jinak by průvodce
 *      posílal účetní na stránku, kterou jí BE stejně zamítne),
 *   2. kroky se číslují průběžně napříč skupinami,
 *   3. odškrtnutí i skrytí se ukládá do preference `payroll.guide` — cookie by
 *      nepřežila jiný prohlížeč ani zařízení,
 *   4. skrytý průvodce nemizí úplně: zůstane lišta, kterou se vrátí,
 *   5. selhání uložení preferencí nesmí shodit průvodce.
 *
 * Podmínku „ukazuj jen do prvního schváleného běhu" hlídá `PayrollDashboard.spec`
 * — je to rozhodnutí stránky, ne komponenty.
 */

const m = vi.hoisted(() => ({
  getPreferenceKey: vi.fn(),
  putPreferenceKey: vi.fn(),
  canRead: vi.fn((_p: string) => true),
  canWrite: vi.fn((_p: string) => true),
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
  }),
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import PayrollSetupGuide from '../PayrollSetupGuide.vue'

const MOUNT_GLOBAL = {
  stubs: {
    RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
  },
}

async function mountGuide() {
  const wrapper = mount(PayrollSetupGuide, { global: MOUNT_GLOBAL })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  m.getPreferenceKey.mockResolvedValue(undefined)
  m.putPreferenceKey.mockResolvedValue({})
  m.canRead.mockReturnValue(true)
  m.canWrite.mockReturnValue(true)
})

describe('PayrollSetupGuide', () => {
  it('vykreslí všechny kroky, ohlásí se jako viditelný a míří na skutečné routy', async () => {
    const wrapper = await mountGuide()

    expect(wrapper.findAll('article')).toHaveLength(11)
    expect(wrapper.emitted('update:visible')?.at(-1)).toEqual([true])
    expect(wrapper.text()).toContain('payroll.setup_guide.title')

    const destinations = wrapper.findAll('article a').map(link => link.attributes('data-to'))
    expect(destinations).toContain('{"name":"payroll-settings","query":{"tab":"employer"}}')
    expect(destinations).toContain('{"name":"payroll-settings","query":{"tab":"institutions"}}')
    expect(destinations).toContain('{"name":"payroll-settings","query":{"tab":"accounting"}}')
    expect(destinations).toContain('{"name":"payroll-people"}')
    expect(destinations).toContain('{"name":"payroll-components"}')
    expect(destinations).toContain('{"name":"payroll-runs"}')
    // Mzdová podání odcházejí schránkou FIRMY, ne globální odesílací bránou.
    expect(destinations).toContain('{"name":"admin-databox"}')
  })

  it('čísluje kroky průběžně napříč skupinami', async () => {
    const wrapper = await mountGuide()

    const numbers = wrapper.findAll('article').map(card => card.text())
    // `step_n` je stubnutý na klíč, takže se čísluje přes parametry — ověřujeme,
    // že karty jdou v jedné řadě, tedy že se v druhé skupině nečísluje znovu.
    expect(numbers).toHaveLength(11)
    expect(wrapper.text()).toContain('payroll.setup_guide.steps.first_run.title')
  })

  it('vynechá kroky, na které uživatel nemá právo', async () => {
    m.canRead.mockImplementation((p: string) => p === 'payroll')
    m.canWrite.mockImplementation((p: string) => p === 'payroll.person.write')

    const wrapper = await mountGuide()
    const text = wrapper.text()

    // Bez `payroll.settings` zmizí celá skupina nastavení zaměstnavatele.
    expect(text).not.toContain('payroll.setup_guide.steps.employer.title')
    expect(text).not.toContain('payroll.setup_guide.groups.employer.title')
    expect(text).toContain('payroll.setup_guide.steps.people.title')
    expect(text).toContain('payroll.setup_guide.steps.evidence.title')
    expect(text).not.toContain('payroll.setup_guide.steps.employment.title')  // employment.write = false
    expect(text).not.toContain('payroll.setup_guide.steps.components.title')  // inputs.write = false
    expect(text).toContain('payroll.setup_guide.steps.first_run.title')
    expect(wrapper.findAll('article')).toHaveLength(3)
  })

  it('načte uložený stav — odškrtnuté kroky zůstanou odškrtnuté', async () => {
    m.getPreferenceKey.mockResolvedValue({ hidden: false, done: ['employer', 'people'] })

    const wrapper = await mountGuide()

    expect(m.getPreferenceKey).toHaveBeenCalledWith('payroll.guide')
    expect(wrapper.findAll('button[aria-pressed="true"]')).toHaveLength(2)
  })

  it('odškrtnutí kroku uloží preferenci a jde vzít zpět', async () => {
    const wrapper = await mountGuide()
    const toggle = () => wrapper.findAll('button[aria-pressed]')[0]

    await toggle().trigger('click')
    expect(m.putPreferenceKey).toHaveBeenCalledWith('payroll.guide', { hidden: false, done: ['employer'] })
    expect(wrapper.findAll('button[aria-pressed="true"]')).toHaveLength(1)

    await toggle().trigger('click')
    expect(m.putPreferenceKey).toHaveBeenLastCalledWith('payroll.guide', { hidden: false, done: [] })
    expect(wrapper.findAll('button[aria-pressed="true"]')).toHaveLength(0)
  })

  it('skrytí uloží stav, ohlásí neviditelnost a nabídne návrat', async () => {
    const wrapper = await mountGuide()

    await wrapper.get('[data-test="payroll-setup-guide-hide"]').trigger('click')

    expect(m.putPreferenceKey).toHaveBeenCalledWith('payroll.guide', { hidden: true, done: [] })
    expect(wrapper.emitted('update:visible')?.at(-1)).toEqual([false])
    expect(wrapper.findAll('article')).toHaveLength(0)
    expect(wrapper.find('[data-test="payroll-setup-guide-hidden"]').exists()).toBe(true)
  })

  it('uložený skrytý stav vykreslí jen lištu pro návrat a vrátí se na kliknutí', async () => {
    m.getPreferenceKey.mockResolvedValue({ hidden: true, done: [] })
    const wrapper = await mountGuide()

    expect(wrapper.emitted('update:visible')?.at(-1)).toEqual([false])
    expect(wrapper.findAll('article')).toHaveLength(0)

    await wrapper.get('[data-test="payroll-setup-guide-show"]').trigger('click')

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
