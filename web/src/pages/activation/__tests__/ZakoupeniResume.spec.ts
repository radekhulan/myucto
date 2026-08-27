/**
 * Obnova zrušeného předplatného z aplikace.
 *
 * ⚠️ Regresní síť na slepou uličku: po zrušení automatického prodlužování
 * obrazovka jen konstatovala, že komerční funkce doběhnou, a nenabídla ŽÁDNOU
 * cestu zpět. U hostované instalace přitom mezitím doběhl naplánovaný konec
 * provozu a instalace zhasla.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

const auth = reactive({
  isSuperadmin: true,
  isManagedInstallation: false,
  refresh: vi.fn(async () => undefined),
})

const api = {
  status: vi.fn(),
  completePurchase: vi.fn(),
  startPurchase: vi.fn(),
  resumePendingChanges: vi.fn(async () => []),
  supportLink: vi.fn(),
  cancelRenewal: vi.fn(),
  resumeRenewal: vi.fn(),
}

vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('@/api/license', () => ({ licenseApi: api }))
vi.mock('@/api/instanceStatus', () => ({
  ensureInstanceDunning: vi.fn(async () => undefined),
  instanceStatus: { dunning: ref(null) },
}))
vi.mock('@/api/instanceHealth', () => ({ resolveBillingNarrative: () => null }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    te: () => false,
    tm: () => [],
    rt: (value: string) => value,
  }),
}))

const Zakoupeni = (await import('../Zakoupeni.vue')).default

const VALID_UNTIL = 1_900_000_000

function status(subscription: Record<string, unknown> | null) {
  return {
    state: 'active',
    instance_id: '123e4567-e89b-42d3-a456-426614174000',
    tier: 'single',
    max_companies: 1,
    users_licensed: 1,
    users_active: 1,
    companies_active: 1,
    valid_until: VALID_UNTIL,
    trial_ends_at: null,
    overage_deadline: null,
    perpetual: false,
    commercial_features: true,
    tier_commercial: true,
    license_key_masked: 'MYU-…-AAAA',
    last_check_at: null,
    last_check_ok: true,
    buy_url: 'https://shop.example.test/objednavka',
    subscription,
    company: { name: '', ic: '', dic: '', street: '', city: '', zip: '', email: '' },
  }
}

const cancelled = (over: Record<string, unknown> = {}) => ({
  state: 'cancelled',
  period: 'month',
  auto_renew: false,
  next_charge_at: null,
  cancelled_at: 1_800_000_000,
  valid_until: VALID_UNTIL,
  resumable: true,
  ...over,
})

async function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/activation/purchase', component: { template: '<div />' } },
      { path: '/admin/instance-export', component: { template: '<div />' } },
      { path: '/hosting', component: { template: '<div />' } },
    ],
  })
  await router.push('/activation/purchase')
  await router.isReady()
  const wrapper = mount(Zakoupeni, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  window.history.replaceState({}, '', '/activation/purchase')
  auth.isSuperadmin = true
  auth.isManagedInstallation = false
  api.status.mockReset()
  api.resumeRenewal.mockReset()
  api.resumePendingChanges.mockClear()
})

describe('obnova zrušeného předplatného', () => {
  it('zrušenému předplatnému nabídne cestu zpět', async () => {
    api.status.mockResolvedValue(status(cancelled()))

    const wrapper = await mountPage()

    expect(wrapper.find('[data-renewal-resume]').exists()).toBe(true)
    expect(wrapper.find('[data-renewal-resume-cta]').exists()).toBe(true)
  })

  it('běžícímu předplatnému ji nenabízí', async () => {
    api.status.mockResolvedValue(status({
      state: 'active',
      period: 'month',
      auto_renew: true,
      next_charge_at: VALID_UNTIL,
      cancelled_at: null,
      valid_until: VALID_UNTIL,
    }))

    const wrapper = await mountPage()

    expect(wrapper.find('[data-renewal-resume]').exists()).toBe(false)
  })

  it('u vypnuté instalace tlačítko neslibuje — jen řekne, že to odsud nejde', async () => {
    // Obnova z retenční lhůty je ruční operace. Tlačítko by vedlo na hlášku
    // „ozvěte se podpoře", takže se nabízet nesmí.
    api.status.mockResolvedValue(status(cancelled({ resumable: false })))

    const wrapper = await mountPage()

    expect(wrapper.find('[data-renewal-resume]').exists()).toBe(true)
    expect(wrapper.find('[data-renewal-resume-cta]').exists()).toBe(false)
    expect(wrapper.get('[data-renewal-resume]').text()).toContain('renewal_resume_unavailable')
  })

  it('starší server bez pole `resumable` tlačítko nedostane', async () => {
    const { resumable: _drop, ...withoutFlag } = cancelled()
    api.status.mockResolvedValue(status(withoutFlag))

    const wrapper = await mountPage()

    expect(wrapper.find('[data-renewal-resume-cta]').exists()).toBe(false)
  })

  it('kliknutí pošle zákazníka na platbu', async () => {
    api.status.mockResolvedValue(status(cancelled()))
    api.resumeRenewal.mockResolvedValue({ pay_url: 'https://myucto.cz/gw/abc', valid_until: VALID_UNTIL })
    const assign = vi.fn()
    const original = Object.getOwnPropertyDescriptor(window, 'location')
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, set href(url: string) { assign(url) } },
    })

    try {
      const wrapper = await mountPage()
      await wrapper.get('[data-renewal-resume-cta]').trigger('click')
      await flushPromises()

      expect(api.resumeRenewal).toHaveBeenCalledTimes(1)
      expect(assign).toHaveBeenCalledWith('https://myucto.cz/gw/abc')
    } finally {
      if (original) Object.defineProperty(window, 'location', original)
    }
  })

  it('odmítnutí serverem ukáže jeho hlášku a nikam neodejde', async () => {
    api.status.mockResolvedValue(status(cancelled()))
    api.resumeRenewal.mockRejectedValue({
      response: { data: { error: { code: 'instance_not_restorable', message: 'Provoz už jsme ukončili.' } } },
    })

    const wrapper = await mountPage()
    await wrapper.get('[data-renewal-resume-cta]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Provoz už jsme ukončili.')
  })
})
