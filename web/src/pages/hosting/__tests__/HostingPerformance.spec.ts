/**
 * Výkonnostní instance na obrazovce Hostingu — stav, doporučení a tři toky
 * (zapnutí s doplatkem, naplánované vypnutí, zrušení naplánovaného vypnutí).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createPinia } from 'pinia'
import { cs, i18nMessages } from '../../../../tests/locales'
import { buildPreviewStatus, stopPreview } from '@/api/instancePreview'
import type { ManagedPerformanceInfo, PerformanceQuote } from '@/api/license'

const mocks = vi.hoisted(() => ({
  status: vi.fn(),
  resumePendingChanges: vi.fn(),
  performanceQuote: vi.fn(),
  changePerformance: vi.fn(),
  refresh: vi.fn(),
}))

const auth = vi.hoisted(() => ({ isSuperadmin: true, refresh: vi.fn() }))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))

vi.mock('@/api/license', async (importOriginal) => {
  const original = await importOriginal<typeof import('@/api/license')>()

  return {
    ...original,
    licenseApi: { ...original.licenseApi, ...mocks },
  }
})

const Hosting = (await import('../Hosting.vue')).default

async function mountHosting() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/hosting', component: Hosting },
      { path: '/activation/purchase', component: { template: '<div />' } },
    ],
  })
  await router.push('/hosting')
  await router.isReady()

  const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs: i18nMessages.cs } })
  const wrapper = mount(Hosting, { global: { plugins: [router, i18n, createPinia()] } })
  await flushPromises()

  return wrapper
}

function performance(overrides: Partial<ManagedPerformanceInfo> = {}): ManagedPerformanceInfo {
  return {
    enabled: false,
    pending_disable: false,
    vendor_state: 'off',
    price_month_czk: 350,
    ram_mb: 1024,
    php_processes: 8,
    base_ram_mb: 512,
    base_php_processes: 4,
    usage: {
      invoices: 1200,
      journal_entries: 4000,
      recommended: false,
      thresholds: { invoices: 100000, journal_entries: 500000 },
    },
    ...overrides,
  }
}

function statusWith(perf: ManagedPerformanceInfo | null) {
  const base = { ...buildPreviewStatus('manual_key', 1_800_000_000), development: true } as unknown as Record<string, unknown>
  const instance = { ...(base.instance as Record<string, unknown>) }
  if (perf) instance.performance = perf
  else delete instance.performance

  return { ...base, instance }
}

function quote(overrides: Partial<PerformanceQuote> = {}): PerformanceQuote {
  return {
    current_enabled: false,
    new_enabled: true,
    pending_disable: false,
    cancel_scheduled: false,
    amount: 175,
    recurring_delta: 350,
    monthly_price: 350,
    currency: 'CZK',
    period_end: '2027-01-15 00:00:00',
    scheduled: false,
    effective_at: null,
    ram_mb: 1024,
    php_processes: 8,
    base_ram_mb: 512,
    base_php_processes: 4,
    quote_token: 'q-perf',
    expires_at: null,
    usage: performance().usage,
    ...overrides,
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  stopPreview()
  vi.stubGlobal('confirm', () => true)
  mocks.resumePendingChanges.mockResolvedValue([])
  mocks.refresh.mockResolvedValue({ refreshed: true })
})

describe('Hosting — výkonnostní instance', () => {
  it('starší licenční server bez bloku sekci nekreslí', async () => {
    mocks.status.mockResolvedValue(statusWith(null))

    const wrapper = await mountHosting()

    expect(wrapper.find('[data-hosting-performance]').exists()).toBe(false)
  })

  it('ukáže stav, parametry a bez velkého objemu dat nic nedoporučuje', async () => {
    mocks.status.mockResolvedValue(statusWith(performance()))

    const wrapper = await mountHosting()

    const section = wrapper.get('[data-hosting-performance]')
    expect(section.attributes('id')).toBe('vykon')
    expect(wrapper.get('[data-hosting-performance-state]').text()).toBe(cs.hosting.performance_state_off)
    expect(section.text()).toContain('1024 MB RAM')
    expect(wrapper.find('[data-hosting-performance-recommended]').exists()).toBe(false)
  })

  it('při velkém objemu dat označí nabídku jako doporučenou a řekne proč', async () => {
    mocks.status.mockResolvedValue(statusWith(performance({
      usage: { invoices: 150000, journal_entries: 10, recommended: true, thresholds: { invoices: 100000, journal_entries: 500000 } },
    })))

    const wrapper = await mountHosting()

    expect(wrapper.get('[data-hosting-performance-recommended]').text()).toBe(cs.hosting.performance_recommended_badge)
    expect(wrapper.get('[data-hosting-performance]').text()).toContain('faktur')
  })

  it('zapnutí ukáže doplatek a po potvrzení zapne', async () => {
    mocks.status.mockResolvedValue(statusWith(performance()))
    mocks.performanceQuote.mockResolvedValue(quote())
    mocks.changePerformance.mockResolvedValue({
      enabled: true, pending_disable: false, amount_charged: 175, scheduled: false,
      effective_at: null, pending: false, order_id: '5', state: statusWith(performance()),
    })
    const wrapper = await mountHosting()

    await wrapper.get('[data-hosting-performance-quote]').trigger('click')
    await flushPromises()

    expect(mocks.performanceQuote).toHaveBeenCalledWith(true)
    const offer = wrapper.get('[data-hosting-performance-offer]')
    expect(offer.text()).toContain('175')
    expect(offer.text()).toContain('350')

    await wrapper.get('[data-hosting-performance-apply]').trigger('click')
    await flushPromises()

    expect(mocks.changePerformance).toHaveBeenCalledWith(true, 'q-perf')
    expect(wrapper.get('[data-hosting-performance-done]').text()).toBe(cs.hosting.performance_enable_done)
  })

  it('zapnutí čekající na poskytovatele je tak i pojmenované', async () => {
    mocks.status.mockResolvedValue(statusWith(performance({ enabled: true, vendor_state: 'enable_requested' })))

    const wrapper = await mountHosting()

    expect(wrapper.get('[data-hosting-performance-state]').text()).toBe(cs.hosting.performance_state_enable_requested)
  })

  it('vypnutí se jen naplánuje ke konci období', async () => {
    mocks.status.mockResolvedValue(statusWith(performance({ enabled: true, vendor_state: 'on' })))
    mocks.performanceQuote.mockResolvedValue(quote({
      current_enabled: true, new_enabled: false, amount: 0, recurring_delta: -350,
      scheduled: true, effective_at: '2027-01-15 00:00:00',
    }))
    mocks.changePerformance.mockResolvedValue({
      enabled: true, pending_disable: true, amount_charged: 0, scheduled: true,
      effective_at: '2027-01-15 00:00:00', pending: false, order_id: null, state: statusWith(performance()),
    })
    const wrapper = await mountHosting()

    expect(wrapper.get('[data-hosting-performance-quote]').text()).toContain(cs.hosting.performance_disable_cta)
    await wrapper.get('[data-hosting-performance-quote]').trigger('click')
    await flushPromises()
    expect(mocks.performanceQuote).toHaveBeenCalledWith(false)

    await wrapper.get('[data-hosting-performance-apply]').trigger('click')
    await flushPromises()

    expect(mocks.changePerformance).toHaveBeenCalledWith(false, 'q-perf')
    expect(wrapper.find('[data-hosting-performance-done]').exists()).toBe(true)
  })

  it('při naplánovaném vypnutí nabídne „Ponechat zapnuté" zdarma', async () => {
    mocks.status.mockResolvedValue(statusWith(performance({ enabled: true, pending_disable: true, vendor_state: 'on' })))
    mocks.performanceQuote.mockResolvedValue(quote({ current_enabled: true, cancel_scheduled: true, amount: 0, recurring_delta: 0 }))
    mocks.changePerformance.mockResolvedValue({
      enabled: true, pending_disable: false, amount_charged: 0, scheduled: false,
      effective_at: null, pending: false, order_id: null, state: statusWith(performance()),
    })
    const wrapper = await mountHosting()

    expect(wrapper.get('[data-hosting-performance-state]').text()).toContain('Vypnutí naplánováno')
    expect(wrapper.get('[data-hosting-performance-quote]').text()).toContain(cs.hosting.performance_keep_cta)
    await wrapper.get('[data-hosting-performance-quote]').trigger('click')
    await flushPromises()

    expect(mocks.performanceQuote).toHaveBeenCalledWith(true)
    expect(wrapper.get('[data-hosting-performance-offer]').text()).toContain(cs.hosting.performance_keep_desc)

    await wrapper.get('[data-hosting-performance-apply]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-hosting-performance-done]').text()).toBe(cs.hosting.performance_keep_done)
  })

  it('odmítnutou kartu doplní odkazem na zaplacení', async () => {
    mocks.status.mockResolvedValue(statusWith(performance()))
    mocks.performanceQuote.mockResolvedValue(quote())
    mocks.changePerformance.mockRejectedValue({
      response: { data: { error: { code: 'charge_failed', message: 'Platbu se nepodařilo strhnout.', pay_url: 'https://example.test/platba' } } },
    })
    const wrapper = await mountHosting()

    await wrapper.get('[data-hosting-performance-quote]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-hosting-performance-apply]').trigger('click')
    await flushPromises()

    const error = wrapper.get('[data-hosting-performance-error]')
    expect(error.text()).toContain('nepodařilo strhnout')
    expect(error.get('[data-hosting-pay-again] a').attributes('href')).toBe('https://example.test/platba')
  })
})
