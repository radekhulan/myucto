import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  listPeriods: vi.fn(),
  updatePeriodStart: vi.fn(),
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: { listPeriods: m.listPeriods, createPeriod: vi.fn(), updatePeriodStart: m.updatePeriodStart },
}))

vi.mock('@/api/closing', () => ({
  closingApi: { setPeriodStatus: vi.fn() },
  closingSettingsApi: { get: vi.fn().mockResolvedValue({}), update: vi.fn() },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true, can: () => true }),
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { fiscal_year_start_month: 1 } }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: vi.fn(), warning: vi.fn(), success: vi.fn() }),
}))

vi.mock('@/composables/useFormat', () => ({ formatDate: (d: string) => d }))

vi.mock('@/composables/useHotkey', () => ({ useHotkey: vi.fn() }))

vi.mock('vue-router', () => ({
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: {} }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key, te: (key: string) => key.startsWith('accounting.periods.start_errors.') }),
}))

import Periods from '@/pages/accounting/Periods.vue'

function period(id: number, status: string) {
  return {
    id,
    fiscal_year: 2020 + id,
    starts_on: `${2020 + id}-01-01`,
    ends_on: `${2020 + id}-12-31`,
    status,
    row_version: 1,
  }
}

describe('Periods: schválení závěrky je konečné', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('u schváleného období nenabízí zrušení schválení, jen zámek s vysvětlením', async () => {
    m.listPeriods.mockResolvedValue([period(1, 'approved')])
    const wrapper = await mountPage()
    expect(wrapper.text()).not.toContain('accounting.closing.approve.revoke_button')
    expect(wrapper.text()).not.toContain('accounting.closing.approve.button')
    expect(wrapper.text()).not.toContain('accounting.closing.reopen.button')
    expect(wrapper.find('[title="accounting.closing.approve.locked_hint"]').exists()).toBe(true)
  })

  it('u uzavřeného období nabízí schválení a znovuotevření', async () => {
    m.listPeriods.mockResolvedValue([period(2, 'closed')])
    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('accounting.closing.approve.button')
    expect(wrapper.text()).toContain('accounting.closing.reopen.button')
    expect(wrapper.text()).not.toContain('accounting.closing.approve.revoke_button')
  })
})

describe('Periods: začátek prvního období (#227)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('změnu začátku nabízí jen u prvního otevřeného období', async () => {
    m.listPeriods.mockResolvedValue([period(7, 'open'), period(6, 'open')])
    const wrapper = await mountPage()
    expect(wrapper.findAll('[data-test="period-change-start"]')).toHaveLength(1)

    await wrapper.get('[data-test="period-change-start"]').trigger('click')
    expect(wrapper.get('[data-test="period-start-dialog"]').text()).toContain('2026-12-31')
  })

  it('u uzavřeného prvního období změnu nenabízí', async () => {
    m.listPeriods.mockResolvedValue([period(6, 'closed'), period(7, 'open')])
    const wrapper = await mountPage()
    expect(wrapper.find('[data-test="period-change-start"]').exists()).toBe(false)
  })

  it('pošle nový začátek s row_version a chybu serveru ukáže v dialogu', async () => {
    m.listPeriods.mockResolvedValue([period(6, 'open')])
    m.updatePeriodStart.mockRejectedValueOnce({
      response: { data: { error: { code: 'entries_before_start', entries_count: 2, first_entry_date: '2026-03-01' } } },
    })
    const wrapper = await mountPage()
    await wrapper.get('[data-test="period-change-start"]').trigger('click')
    await wrapper.get('[data-test="period-start-save"]').trigger('click')
    await flushPromises()

    expect(m.updatePeriodStart).toHaveBeenCalledWith(6, '2026-01-01', 1)
    expect(wrapper.get('[data-test="period-start-dialog"]').text()).toContain('accounting.periods.start_errors.entries_before_start')

    m.updatePeriodStart.mockResolvedValueOnce({})
    await wrapper.get('[data-test="period-start-save"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="period-start-dialog"]').exists()).toBe(false)
  })
})

async function mountPage() {
  const wrapper = mount(Periods, { props: { embedded: true } })
  await flushPromises()
  return wrapper
}
