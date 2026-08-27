import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import cs from '@/i18n/cs.json'
import { buildPreviewStatus, stopPreview } from '@/api/instancePreview'

const mocks = vi.hoisted(() => ({
  status: vi.fn(),
  resumePendingChanges: vi.fn(),
  tierQuote: vi.fn(),
  changeTier: vi.fn(),
  waitForChange: vi.fn(),
  refresh: vi.fn(),
}))

const auth = vi.hoisted(() => ({
  isSuperadmin: true,
  refresh: vi.fn(),
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))

vi.mock('@/api/license', async (importOriginal) => {
  const original = await importOriginal<typeof import('@/api/license')>()

  return {
    ...original,
    licenseApi: {
      ...original.licenseApi,
      status: mocks.status,
      resumePendingChanges: mocks.resumePendingChanges,
      tierQuote: mocks.tierQuote,
      changeTier: mocks.changeTier,
      waitForChange: mocks.waitForChange,
      refresh: mocks.refresh,
    },
  }
})

const Hosting = (await import('../Hosting.vue')).default

async function mountHosting(path = '/hosting') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/hosting', component: Hosting },
      { path: '/activation/purchase', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs } })
  const wrapper = mount(Hosting, { global: { plugins: [router, i18n] } })
  await flushPromises()

  return { wrapper, router }
}

beforeEach(() => {
  vi.clearAllMocks()
  stopPreview()
  mocks.status.mockResolvedValue(buildPreviewStatus('manual_key', 1_800_000_000))
  mocks.resumePendingChanges.mockResolvedValue([])
  mocks.refresh.mockResolvedValue({ refreshed: true })
  mocks.tierQuote.mockResolvedValue({
    current_tier: 'multi10',
    new_tier: 'unlimited',
    amount: 600,
    recurring_delta: 600,
    currency: 'CZK',
    period_end: 1_801_000_000,
    quote_token: 'real-quote-before-preview',
    expires_at: 1_800_000_300,
    scheduled: false,
    effective_at: null,
  })
})

describe('Hosting — bezpečnost náhledu', () => {
  it('po zapnutí náhledu zahodí tarifní quote a žádné tarifní API už nezavolá', async () => {
    const { wrapper, router } = await mountHosting()

    await wrapper.get('select').setValue('unlimited')
    await wrapper.get('[data-hosting-tier-quote]').trigger('click')
    await flushPromises()
    expect(mocks.tierQuote).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-hosting-tier-apply]').exists()).toBe(true)

    await router.push({ path: '/hosting', query: { nahled: 'overage' } })
    await flushPromises()

    const quoteButton = wrapper.get('[data-hosting-tier-quote]')
    expect(quoteButton.attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-hosting-tier-apply]').exists()).toBe(false)

    await quoteButton.trigger('click')
    await flushPromises()
    expect(mocks.tierQuote).toHaveBeenCalledTimes(1)
    expect(mocks.changeTier).not.toHaveBeenCalled()
  })

  it('chybějící licenční klíč zahrne do souhrnu pozornosti', async () => {
    const { wrapper } = await mountHosting('/hosting?nahled=no_license')

    expect(wrapper.get('[data-hosting-attention] a[href="#klic"]').text()).toContain(cs.hosting.attention_key)
  })

  it('vyčerpaná kapacita se do souhrnu pozornosti nedostane', async () => {
    // Kdo má 1 uživatele z 1 zaplaceného, využívá přesně to, co si koupil.
    // Dokud rovnost se stropem padala do souhrnu, uvítala čerstvě zřízená
    // instalace zákazníka při prvním přihlášení výstrahou.
    mocks.status.mockResolvedValue({
      ...buildPreviewStatus('manual_key', 1_800_000_000),
      users_licensed: 1,
      users_active: 1,
      max_companies: 1,
      companies_active: 1,
    })

    const { wrapper } = await mountHosting()

    expect(wrapper.find('[data-hosting-attention]').exists()).toBe(false)
  })

  it('překročený strop uživatelů do souhrnu pozornosti patří', async () => {
    mocks.status.mockResolvedValue({
      ...buildPreviewStatus('manual_key', 1_800_000_000),
      users_licensed: 1,
      users_active: 2,
    })

    const { wrapper } = await mountHosting()

    expect(wrapper.get('[data-hosting-attention] a[href="#uzivatele"]').text()).toContain(cs.hosting.attention_users)
  })

  it('po aktualizaci rozsahu si stav načte běžnou cestou, ne z odpovědi obnovy', async () => {
    // Odpověď obnovy stav nenese. Kdyby se z ní obrazovka skládala, chybějící
    // blok `instance` by ji shodil do „tahle instalace u nás neběží".
    const { wrapper } = await mountHosting()
    expect(wrapper.find('[data-hosting-managed]').exists()).toBe(true)

    await wrapper.get('[data-hosting-refresh]').trigger('click')
    await flushPromises()

    expect(mocks.refresh).toHaveBeenCalledTimes(1)
    expect(mocks.status).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-hosting-selfhosted]').exists()).toBe(false)
    expect(wrapper.find('[data-hosting-managed]').exists()).toBe(true)
  })
})
