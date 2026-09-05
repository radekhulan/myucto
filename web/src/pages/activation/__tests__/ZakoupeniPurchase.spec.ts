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

function status(over: Record<string, unknown> = {}) {
  return {
    state: 'trial',
    instance_id: '123e4567-e89b-42d3-a456-426614174000',
    tier: null,
    max_companies: 1,
    users_licensed: 0,
    users_active: 1,
    companies_active: 1,
    valid_until: null,
    trial_ends_at: 1_900_000_000,
    overage_deadline: null,
    perpetual: false,
    commercial_features: true,
    tier_commercial: true,
    license_key_masked: null,
    last_check_at: null,
    last_check_ok: true,
    buy_url: 'https://shop.example.test/objednavka',
    subscription: null,
    company: { name: '', ic: '', dic: '', street: '', city: '', zip: '', email: '' },
    ...over,
  }
}

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
  auth.refresh.mockClear()
  api.status.mockReset().mockResolvedValue(status())
  api.completePurchase.mockReset()
  api.startPurchase.mockReset()
  api.resumePendingChanges.mockClear()
})

describe('automatický purchase handoff', () => {
  it('odstraní fragment a po serverovém claimu neukáže žádný licenční klíč', async () => {
    const purchase = 'a'.repeat(32)
    const state = 'B'.repeat(43)
    window.history.replaceState({}, '', `/activation/purchase#purchase=${purchase}&state=${state}`)
    api.completePurchase.mockImplementation(() => {
      expect(window.location.hash).toBe('')
      return Promise.resolve(status({
        state: 'active',
        tier: 'single',
        users_licensed: 1,
        license_key_masked: 'MYU-…-AAAA',
      }))
    })

    const wrapper = await mountPage()

    expect(api.completePurchase).toHaveBeenCalledWith(purchase, state)
    expect(wrapper.find('[data-license-purchase-success]').exists()).toBe(true)
    expect(wrapper.html()).not.toContain(purchase)
  })

  it('u živého předplatného nenabízí druhý nákup', async () => {
    api.status.mockResolvedValue(status({
      state: 'active',
      tier: 'single',
      users_licensed: 1,
      license_key_masked: 'MYU-…-AAAA',
      subscription: {
        state: 'active', period: 'month', auto_renew: true,
        next_charge_at: 1_900_000_000, cancelled_at: null, valid_until: 1_900_000_000,
      },
    }))

    const wrapper = await mountPage()

    expect(wrapper.find('[data-license-purchase-start]').exists()).toBe(false)
    expect(wrapper.text()).toContain('license.purchase_existing_subscription_hint')
  })

  it('u ruční licence bez předplatného nabídne nový nákup místo nefunkčních doplatků', async () => {
    api.status.mockResolvedValue(status({
      state: 'active',
      tier: 'single',
      users_licensed: 1,
      license_key_masked: 'MYU-…-AAAA',
      subscription: null,
    }))

    const wrapper = await mountPage()

    expect(wrapper.find('[data-license-purchase-start]').exists()).toBe(true)
    expect(wrapper.find('#payroll-addon').exists()).toBe(false)
    expect(wrapper.find('#tier-change').exists()).toBe(false)
    expect(wrapper.find('#upgrade').exists()).toBe(false)
  })

  it('self-hosted předplatné neukazuje hostingové varování ani nákup prostoru', async () => {
    api.status.mockResolvedValue(status({
      state: 'active',
      tier: 'single',
      users_licensed: 1,
      license_key_masked: 'MYU-…-AAAA',
      subscription: {
        state: 'active', period: 'month', auto_renew: true,
        next_charge_at: 1_900_000_000, cancelled_at: null, valid_until: 1_900_000_000,
      },
    }))

    const wrapper = await mountPage()

    expect(wrapper.find('[data-managed-renewal]').exists()).toBe(false)
    expect(wrapper.find('[data-managed-cancellation-warning]').exists()).toBe(false)
    expect(wrapper.find('a[href="/admin/instance-export"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/hosting#misto"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('license.managed_storage_title')
    expect(wrapper.text()).not.toContain('hosting.storage_order_title')
    expect(wrapper.find('#tier-change').exists()).toBe(true)
    expect(wrapper.find('#upgrade').exists()).toBe(true)
  })

  it('u spravované instance důrazně ukáže export před zrušením hostingu', async () => {
    auth.isManagedInstallation = true
    api.status.mockResolvedValue(status({
      state: 'active',
      tier: 'single',
      users_licensed: 1,
      license_key_masked: 'MYU-…-AAAA',
      subscription: {
        state: 'active', period: 'month', auto_renew: true,
        next_charge_at: 1_900_000_000, cancelled_at: null, valid_until: 1_900_000_000,
      },
      instance: {
        managed: true,
        plan: 'invoicing',
        managed_since: '2026-08-01',
        subscription_url: null,
        storage: null,
        billing: {},
        links: {},
      },
    }))

    const wrapper = await mountPage()

    expect(wrapper.find('[data-managed-cancellation-warning]').exists()).toBe(true)
    expect(wrapper.find('a[href="/admin/instance-export"]').exists()).toBe(true)
  })
})
