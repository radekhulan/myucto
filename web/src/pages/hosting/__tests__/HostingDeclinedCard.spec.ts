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
import { createPinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { cs, i18nMessages } from '../../../../tests/locales'
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
  waitForCheckout: vi.fn(),
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

  const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs: i18nMessages.cs } })
  const wrapper = mount(Hosting, { global: { plugins: [router, i18n, createPinia()] } })
  await flushPromises()

  return wrapper
}

/** Odmítnutí, jak ho vrací axios — chyba je v `response.data.error`. */
function declined(payUrl: string | null, orderId?: string): unknown {
  return {
    response: {
      data: {
        error: {
          code: 'charge_failed',
          message: 'Platbu se nepodařilo strhnout z uložené karty. Doplatek zaplatíte '
            + 'jinou kartou přes odkaz níž — poslali jsme ho i e-mailem.',
          ...(payUrl === null ? {} : { pay_url: payUrl }),
          ...(orderId === undefined ? {} : { order_id: orderId }),
        },
      },
    },
  }
}

/**
 * Náhled stavů je od 6.6.0 dostupný jen na serveru v development režimu
 * (commit b51fd5d88 — na produkční instalaci nemá superadmin co předstírat).
 * Testy náhledu proto musí dodat `development: true`, jinak by měřily jen to,
 * že se náhled správně nezapnul.
 */
function previewStatus(scenario: Parameters<typeof buildPreviewStatus>[0], now = 1_800_000_000) {
  return { ...buildPreviewStatus(scenario, now), development: true }
}

beforeEach(() => {
  vi.clearAllMocks()
  stopPreview()
  vi.stubGlobal('confirm', () => true)
  mocks.status.mockResolvedValue(previewStatus('manual_key'))
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
  it('obnoví vyčerpanou kvótu, když hosting mezitím potvrdí 7 GB', async () => {
    const initial = previewStatus('storage_100')
    const instance = initial.instance!
    const pending = {
      ...initial,
      instance: { ...instance, storage: { ...instance.storage, quota_bytes: 2 * 1024 ** 3, quota_gb_ordered: 7, change_pending: true } },
    }
    const confirmed = {
      ...pending,
      instance: { ...instance, storage: { ...pending.instance.storage, quota_bytes: 7 * 1024 ** 3, change_pending: false, blocks_writes: false, percent: 32 } },
    }
    mocks.status.mockResolvedValueOnce(pending).mockResolvedValueOnce(confirmed)
    let resolve!: (value: unknown) => void
    const promise = new Promise<unknown>((done) => { resolve = done })
    mocks.refresh.mockReturnValueOnce(promise)

    const wrapper = await mountHosting()
    expect(wrapper.find('[data-hosting-storage-pending]').exists()).toBe(true)
    expect(mocks.refresh).toHaveBeenCalledTimes(1)
    resolve({ refreshed: true })
    await flushPromises()

    expect(wrapper.find('[data-hosting-storage-pending]').exists()).toBe(false)
    expect(auth.refresh).toHaveBeenCalledTimes(1)
  })

  it('u rozšíření místa nabídne zaplacení jinou kartou', async () => {
    mocks.storageUpgrade.mockRejectedValue(declined(PAY_URL, '42'))
    mocks.waitForCheckout.mockResolvedValue({ applied: false })
    const wrapper = await mountHosting()

    await orderStorage(wrapper)

    const error = wrapper.get('[data-hosting-storage-error]')
    expect(error.text()).toContain('nepodařilo strhnout')
    const link = error.get('[data-hosting-pay-again] a')
    expect(link.attributes('href')).toBe(PAY_URL)
    expect(link.text()).toContain(cs.hosting.pay_again)
    expect(mocks.waitForCheckout).toHaveBeenCalledWith('42')
  })

  it('po doplacení čeká na potvrzenou kvótu i při výpadku načtení stavu', async () => {
    const initial = previewStatus('manual_key')
    const instance = initial.instance!
    const confirmed = {
      ...initial,
      instance: { ...instance, storage: { ...instance.storage, quota_gb_ordered: 22, quota_bytes: 22 * 1024 ** 3, change_pending: false } },
    }
    mocks.status.mockResolvedValueOnce(initial).mockRejectedValueOnce(new Error('offline')).mockResolvedValue(confirmed)
    mocks.storageUpgrade.mockRejectedValue(declined(PAY_URL, '42'))
    mocks.waitForCheckout.mockResolvedValue({ applied: true })
    let resolve!: (value: unknown) => void
    const promise = new Promise<unknown>((done) => { resolve = done })
    mocks.refresh.mockReturnValueOnce(promise)

    const wrapper = await mountHosting()
    await orderStorage(wrapper)

    expect(wrapper.text()).not.toContain('Rozšířeno')
    expect(mocks.refresh).toHaveBeenCalledTimes(1)
    resolve({ refreshed: true })
    await flushPromises()
    expect(mocks.status).toHaveBeenCalledWith(expect.any(AbortSignal))
    expect(wrapper.get('[data-hosting-storage-done]').text()).toBe(cs.hosting.storage_done.replace('{gb}', '22'))
    expect(wrapper.find('[data-hosting-storage-pending]').exists()).toBe(false)
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

  it('předplatné placené fakturou nabídne zaplatit kartou, ne „jinou kartou"', async () => {
    // Bez uložené karty není co odmítnout — změna se zaplatí jednorázově kartou.
    mocks.upgrade.mockRejectedValue({
      response: {
        data: {
          error: {
            code: 'card_payment_required',
            message: 'Předplatné nemá uloženou platební kartu, změnu proto zaplatíte jednorázově kartou. '
              + 'Otevřete odkaz k platbě.',
            pay_url: PAY_URL,
          },
        },
      },
    })
    const wrapper = await mountHosting()

    await wrapper.get('[data-hosting-users-order] input').setValue('5')
    await wrapper.get('[data-hosting-users-quote]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-hosting-users-buy]').trigger('click')
    await flushPromises()

    const notice = wrapper.get('[data-hosting-pay-again]')
    expect(notice.get('a').attributes('href')).toBe(PAY_URL)
    expect(notice.get('a').text()).toContain(cs.license.card_payment_cta)
    expect(notice.text()).not.toContain(cs.hosting.pay_again)
    expect(notice.text()).toContain(cs.license.card_payment_hint)
  })

  it('náhled odmítnuté karty tu nabídku ukazuje taky', async () => {
    const wrapper = await mountHosting('/hosting?nahled=card_declined')

    expect(wrapper.get('[data-hosting-pay-again] a').attributes('href')).toBeTruthy()
  })
})

/**
 * ⚠️ Odmítnutá kontrola licence se NESMÍ tvářit jako v pořádku.
 *
 * Po vrácení peněz server licenci odmítne, ale token doběhne dál — obrazovka
 * do té doby hlásila „Stav licence: Aktivní" a datum poslední kontroly, jako
 * by všechno sedělo. Zákazník se o konci placených funkcí dozvěděl až tím,
 * že mu zmizely.
 */
describe('Hosting — neúspěšná kontrola licence', () => {
  // `billing` chodí uvnitř bloku `instance`, ne vedle něj — obrazovka ho čte
  // přes instanceStatus, ne ze stavu licence.
  function withBilling(lastCheckOk: boolean) {
    const base = previewStatus('manual_key') as unknown as Record<string, unknown>
    const instance = { ...(base.instance as Record<string, unknown>) }
    const billing = { ...(instance.billing as Record<string, unknown> ?? {}) }

    return {
      ...base,
      instance: {
        ...instance,
        billing: { ...billing, last_check_ok: lastCheckOk, last_check_at: '2026-08-27T20:53:48+02:00' },
      },
    }
  }

  it('neúspěšnou kontrolu pojmenuje', async () => {
    mocks.status.mockResolvedValue(withBilling(false))

    const wrapper = await mountHosting()

    expect(wrapper.find('[data-hosting-check-failed]').exists()).toBe(true)
  })

  it('u úspěšné kontroly nic nestraší', async () => {
    mocks.status.mockResolvedValue(withBilling(true))

    const wrapper = await mountHosting()

    expect(wrapper.find('[data-hosting-check-failed]').exists()).toBe(false)
  })
})
