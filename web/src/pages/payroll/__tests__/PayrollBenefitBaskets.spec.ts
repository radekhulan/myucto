import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { BenefitBasketUsage } from '@/api/payrollBenefitBaskets'

const m = vi.hoisted(() => ({
  overview: vi.fn(),
  monthlyOverview: vi.fn(),
}))

vi.mock('@/api/payrollBenefitBaskets', () => ({
  payrollBenefitBasketsApi: { overview: m.overview, monthlyOverview: m.monthlyOverview },
  BENEFIT_EXEMPTION_BASKETS: ['non_cash_health', 'non_cash_leisure', 'old_age_savings'],
  MONTHLY_BENEFIT_EXEMPTION_BASKETS: ['meal_per_shift', 'temporary_accommodation'],
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: (permission: string) => permission === 'payroll',
    canWrite: () => false,
  }),
}))

// `useTablePrefs` táhne @/i18n, které volá skutečné `createI18n` — továrna
// proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => {
  const { ref } = await import('vue')
  return {
    ...(await importOriginal<typeof import('vue-i18n')>()),
    useI18n: () => ({
      t: (key: string, params?: Record<string, unknown>) =>
        params ? `${key}:${JSON.stringify(params)}` : key,
      locale: ref('cs-CZ'),
    }),
  }
})

vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import PayrollBenefitBaskets from '@/pages/payroll/PayrollBenefitBaskets.vue'

function usage(overrides: Partial<BenefitBasketUsage> = {}): BenefitBasketUsage {
  return {
    employee_id: 1,
    employee_name: 'Jana Zkušební',
    basket: 'non_cash_leisure',
    statute: '§ 6 odst. 9 písm. d) bod 2 ZDP',
    limit_basis: 'tax_year',
    limit_minor: 2_448_350,
    used_minor: 1_000_000,
    exempt_minor: 1_000_000,
    taxable_minor: 0,
    remaining_minor: 1_448_350,
    input_count: 1,
    unfrozen_count: 0,
    reversed_count: 0,
    reversed_minor: 0,
    taken_over_minor: 0,
    taken_over_months: 0,
    status: 'ok',
    split_drift: false,
    ...overrides,
  }
}

function page(items: BenefitBasketUsage[], overrides: Record<string, unknown> = {}) {
  return {
    items,
    total: items.length,
    limit: 50,
    offset: 0,
    year: 2026,
    years: [2026],
    ...overrides,
  }
}

function mountPage() {
  return mount(PayrollBenefitBaskets, {
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a><slot /></a>' },
      },
    },
  })
}

function monthlyPage(items: BenefitBasketUsage[], overrides: Record<string, unknown> = {}) {
  return {
    items,
    total: items.length,
    limit: 50,
    offset: 0,
    period: '2026-07',
    periods: ['2026-07'],
    ...overrides,
  }
}

describe('PayrollBenefitBaskets', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.overview.mockResolvedValue(page([usage()]))
    m.monthlyOverview.mockResolvedValue(monthlyPage([
      usage({
        basket: 'meal_per_shift',
        statute: '§ 6 odst. 9 písm. b) ZDP',
        limit_basis: 'per_shift',
        limit_minor: null,
        used_minor: 240_000,
        exempt_minor: 240_000,
        remaining_minor: null,
        status: 'limit_unavailable',
      }),
    ]))
  })

  it('vykreslí jeden řádek na osobu a koš i s vyčerpáním', async () => {
    const wrapper = mountPage()
    await flushPromises()

    const row = wrapper.find('[data-test="basket-row-1-non_cash_leisure"]')
    expect(row.exists()).toBe(true)
    expect(row.text()).toContain('Jana Zkušební')
  })

  /** Bez limitu se nesmí tvrdit „zbývá" — pomlčka, ne nula. */
  it('u chybějícího limitu neukazuje zbytek jako nulu', async () => {
    m.overview.mockResolvedValue(page([
      usage({ limit_minor: null, remaining_minor: null, status: 'limit_unavailable' }),
    ]))
    const wrapper = mountPage()
    await flushPromises()

    const row = wrapper.find('[data-test="basket-row-1-non_cash_leisure"]')
    expect(row.text()).toContain('payroll.benefit_baskets.status.limit_unavailable')
    expect(row.text()).toContain('—')
  })

  /** Chybějící podklad se říká větou, nedopočítává se. */
  it('u neúplného podkladu ukáže poznámku o chybějícím rozpadu', async () => {
    m.overview.mockResolvedValue(page([
      usage({ unfrozen_count: 2, status: 'incomplete' }),
    ]))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.benefit_baskets.unfrozen_note')
    expect(wrapper.text()).toContain('"count":2')
  })

  it('překročený koš zvýrazní zdaněnou nadlimitní část', async () => {
    m.overview.mockResolvedValue(page([
      usage({
        used_minor: 2_548_350,
        exempt_minor: 2_448_350,
        taxable_minor: 100_000,
        remaining_minor: 0,
        status: 'exceeded',
      }),
    ]))
    const wrapper = mountPage()
    await flushPromises()

    const badge = wrapper.find('[data-test="basket-status-1-non_cash_leisure"]')
    expect(badge.text()).toBe('payroll.benefit_baskets.status.exceeded')
    expect(badge.classes().join(' ')).toContain('danger')
  })

  /** Filtr se uplatňuje na serveru; klient si stránku nepřefiltrovává sám. */
  it('posílá filtr na koš a rok na server', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(m.overview).toHaveBeenCalledWith(
      expect.objectContaining({ year: new Date().getFullYear(), basket: '', offset: 0 }),
    )

    await wrapper.find('[id$="-basket-kind"]').setValue('non_cash_health')
    await flushPromises()

    expect(m.overview).toHaveBeenLastCalledWith(
      expect.objectContaining({ basket: 'non_cash_health', offset: 0 }),
    )
  })

  /**
   * Selhání se nekreslí jako prázdná tabulka: „nikdo nic nevyčerpal" je právě
   * ta věta, kvůli které obrazovka vznikla, a nesmí zaznít omylem.
   */
  it('rozlišuje selhání načtení od prázdného přehledu', async () => {
    m.overview.mockRejectedValue(new Error('boom'))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.benefit_baskets.load_failed')
    expect(wrapper.text()).not.toContain('payroll.benefit_baskets.no_match')
  })

  it('prázdný rok ukáže filtrovaný prázdný stav, ne chybu', async () => {
    m.overview.mockResolvedValue(page([], { years: [] }))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.benefit_baskets.no_match')
    expect(wrapper.text()).not.toContain('payroll.benefit_baskets.load_failed')
  })

  // ── Měsíční záložka ───────────────────────────────────────────────────────

  /** Rozhodné období si koš nevybírá; měsíční koše mají vlastní endpoint. */
  it('po přepnutí na měsíční záložku se ptá na období, ne na rok', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="basket-tab-monthly"]').trigger('click')
    await flushPromises()

    expect(m.monthlyOverview).toHaveBeenCalledWith(
      expect.objectContaining({ period: expect.stringMatching(/^\d{4}-\d{2}$/), offset: 0 }),
    )
    expect(m.overview).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[id$="-basket-period"]').exists()).toBe(true)
    expect(wrapper.find('[id$="-basket-year"]').exists()).toBe(false)
  })

  /**
   * PAST PÍSM. B): měsíční součet se proti limitu za směnu poměřit nedá.
   * Bez věty by prázdný sloupec limitu vypadal jako „v pořádku" — a to je
   * přesně to tvrzení, které obrazovka vyslovit nesmí.
   */
  it('u stravného řekne, že limit je za směnu, a netvrdí zbytek', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="basket-tab-monthly"]').trigger('click')
    await flushPromises()

    const note = wrapper.find('[data-test="basket-limit-basis-1-meal_per_shift"]')
    expect(note.exists()).toBe(true)
    expect(note.text()).toBe('payroll.benefit_baskets.limit_basis.per_shift')

    const badge = wrapper.find('[data-test="basket-status-1-meal_per_shift"]')
    expect(badge.text()).toBe('payroll.benefit_baskets.status.limit_unavailable')
    expect(badge.text()).not.toContain('status.ok')
    expect(wrapper.find('[data-test="basket-monthly-explainer"]').exists()).toBe(true)
  })

  /**
   * Koše obou režimů se nepřekrývají, takže ponechaný filtr by druhý režim
   * odmítl (422) — stránka by ukázala chybu, kterou uživatel nezpůsobil.
   */
  it('přepnutí záložky zahodí filtr na koš a načte jen jednou', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[id$="-basket-kind"]').setValue('non_cash_health')
    await flushPromises()
    expect(m.overview).toHaveBeenLastCalledWith(
      expect.objectContaining({ basket: 'non_cash_health' }),
    )

    await wrapper.find('[data-test="basket-tab-monthly"]').trigger('click')
    await flushPromises()

    expect(m.monthlyOverview).toHaveBeenCalledTimes(1)
    expect(m.monthlyOverview).toHaveBeenCalledWith(expect.objectContaining({ basket: '' }))
  })

  /** Roční koše se v měsíční nabídce nesmí objevit — server by je odmítl. */
  it('měsíční záložka nabízí jen měsíční koše', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="basket-tab-monthly"]').trigger('click')
    await flushPromises()

    const values = wrapper.find('[id$="-basket-kind"]').findAll('option').map(o => o.element.value)
    expect(values).toEqual(['', 'meal_per_shift', 'temporary_accommodation'])
  })
})
