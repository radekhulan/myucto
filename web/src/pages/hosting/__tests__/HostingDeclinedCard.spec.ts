/**
 * Odmítnutá karta u doplatku — obrazovka MUSÍ nabídnout, jak to zaplatit.
 *
 * ⚠️ Tohle je regresní síť na skutečný průšvih: uložená karta doplatek
 * neuhradila, licenční server založil platbu a poslal odkaz, ale aplikace ho
 * zahodila a napsala jen „Rozšíření se nezdařilo. Zkuste to prosím znovu."
 * Zákazník se o doplatku dozvěděl jenom z e-mailu a v aplikaci mačkal totéž
 * tlačítko se stejnou kartou pořád dokola.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import cs from '@/i18n/cs.json'
import { buildPreviewStatus, stopPreview } from '@/api/instancePreview'

const PAY_URL = 'https://myucto.cz/platba?t=abc123'

const mocks = vi.hoisted(() => ({
  status: vi.fn(),
  resumePendingChanges: vi.fn(),
  storageQuote: vi.fn(),
  storageUpgrade: vi.fn(),
  upgradeQuote: vi.fn(),
  upgrade: vi.fn(),
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

  return wrapper
}

/** Odmítnutí, jak ho vrací axios — chyba je v `response.data.error`. */
function declined(payUrl: string | null): unknown {
  return {
    response: {
      data: {
        error: {
          code: 'charge_failed',
          message: 'Platbu se nepodařilo strhnout z uložené karty. Doplatek zaplatíte '
            + 'jinou kartou přes odkaz níž — poslali jsme ho i e-mailem.',
          ...(payUrl === null ? {} : { pay_url: payUrl }),
        },
      },
    },
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  stopPreview()
  vi.stubGlobal('confirm', () => true)
  mocks.status.mockResolvedValue(buildPreviewStatus('manual_key', 1_800_000_000))
  mocks.resumePendingChanges.mockResolvedValue([])
  mocks.refresh.mockResolvedValue({ refreshed: true })
  mocks.storageQuote.mockResolvedValue({
    current_quota_gb: 7,
    new_quota_gb: 22,
    amount: 249,
    recurring_delta: 120,
    currency: 'CZK',
    period_end: null,
    quote_token: 'q-storage',
    expires_at: null,
    scheduled: false,
    effective_at: null,
  })
  mocks.upgradeQuote.mockResolvedValue({
    current_users: 3,
    new_users: 5,
    amount: 430,
    currency: 'CZK',
    period_end: null,
    quote_token: 'q-users',
    expires_at: null,
    scheduled: false,
    effective_at: null,
  })
})

async function orderStorage(wrapper: Awaited<ReturnType<typeof mountHosting>>) {
  await wrapper.get('[data-hosting-storage-sizes] button').trigger('click')
  await flushPromises()
  await wrapper.get('[data-hosting-storage-buy]').trigger('click')
  await flushPromises()
}

describe('Hosting — neproběhlá platba doplatku', () => {
  it('u rozšíření místa nabídne zaplacení jinou kartou', async () => {
    mocks.storageUpgrade.mockRejectedValue(declined(PAY_URL))
    const wrapper = await mountHosting()

    await orderStorage(wrapper)

    const error = wrapper.get('[data-hosting-storage-error]')
    expect(error.text()).toContain('nepodařilo strhnout')
    const link = error.get('[data-hosting-pay-again] a')
    expect(link.attributes('href')).toBe(PAY_URL)
    expect(link.text()).toContain(cs.hosting.pay_again)
  })

  it('bez odkazu ze serveru žádné tlačítko nevymýšlí', async () => {
    mocks.storageUpgrade.mockRejectedValue(declined(null))
    const wrapper = await mountHosting()

    await orderStorage(wrapper)

    expect(wrapper.get('[data-hosting-storage-error]').text()).toContain('nepodařilo strhnout')
    expect(wrapper.find('[data-hosting-pay-again]').exists()).toBe(false)
  })

  it('u navýšení uživatelů nabídne zaplacení jinou kartou', async () => {
    mocks.upgrade.mockRejectedValue(declined(PAY_URL))
    const wrapper = await mountHosting()

    await wrapper.get('[data-hosting-users-order] input').setValue('5')
    await wrapper.get('[data-hosting-users-quote]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-hosting-users-buy]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-hosting-pay-again] a').attributes('href')).toBe(PAY_URL)
  })

  it('další pokus o objednávku starý odkaz zahodí', async () => {
    // Odkaz patří ke konkrétnímu odmítnutí. Kdyby přežil další kolo, visel by
    // pod cizí chybou a mířil na dávno vyřízenou objednávku.
    mocks.storageUpgrade.mockRejectedValue(declined(PAY_URL))
    const wrapper = await mountHosting()
    await orderStorage(wrapper)
    expect(wrapper.find('[data-hosting-pay-again]').exists()).toBe(true)

    await wrapper.get('[data-hosting-storage-sizes] button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-hosting-pay-again]').exists()).toBe(false)
  })

  it('náhled odmítnuté karty tu nabídku ukazuje taky', async () => {
    const wrapper = await mountHosting('/hosting?nahled=card_declined')

    expect(wrapper.get('[data-hosting-pay-again] a').attributes('href')).toBeTruthy()
  })
})
