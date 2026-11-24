/**
 * Změna rozsahu u zrušeného, ale zaplaceného předplatného (typicky ročně
 * fakturou, bez uložené karty).
 *
 * ⚠️ Obrazovka dřív změny nabízela jen běžícímu předplatnému s kartou.
 * Zákazník placený fakturou tak nemohl přidat uživatele ani změnit tarif,
 * i když licenční server změnu umí prodat jednorázovou platbou kartou.
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
  upgradeQuote: vi.fn(),
  upgrade: vi.fn(),
  tierQuote: vi.fn(),
  changeTier: vi.fn(),
  payrollQuote: vi.fn(),
  changePayroll: vi.fn(),
  waitForCheckout: vi.fn(),
}

vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('@/api/license', () => ({ licenseApi: api }))
vi.mock('@/api/instanceStatus', () => ({
  ensureInstanceDunning: vi.fn(async () => undefined),
  instanceStatus: { dunning: ref(null) },
}))
vi.mock('@/api/instanceHealth', () => ({ resolveBillingNarrative: () => null }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' },
    t: (key: string) => key,
    te: () => false,
    tm: () => [],
    rt: (value: string) => value,
  }),
}))

const Zakoupeni = (await import('../Zakoupeni.vue')).default

const FUTURE = Math.floor(Date.now() / 1000) + 200 * 86400
const PAST = Math.floor(Date.now() / 1000) - 86400
const PAY_URL = 'https://myucto.cz/platba-navrat?t=zmena'
const MESSAGE = 'Předplatné nemá uloženou platební kartu, změnu proto zaplatíte jednorázově kartou. Otevřete odkaz k platbě.'

function status(subscription: Record<string, unknown> | null) {
  return {
    state: 'active',
    instance_id: '123e4567-e89b-42d3-a456-426614174000',
    tier: 'single',
    max_companies: 1,
    users_licensed: 1,
    users_active: 1,
    companies_active: 1,
    valid_until: FUTURE,
    trial_ends_at: null,
    overage_deadline: null,
    perpetual: false,
    commercial_features: true,
    tier_commercial: true,
    license_key_masked: 'MYU-…-AAAA',
    last_check_at: null,
    last_check_ok: true,
    buy_url: 'https://shop.example.test/objednavka',
    payroll_enabled: false,
    payroll_max_employees: null,
    payroll_employees_active: 0,
    payroll_users_active: 0,
    payroll_users_licensed: 1,
    subscription,
    company: { name: '', ic: '', dic: '', street: '', city: '', zip: '', email: '' },
  }
}

const invoicePaid = (over: Record<string, unknown> = {}) => ({
  state: 'cancelled',
  period: 'year',
  auto_renew: false,
  next_charge_at: null,
  cancelled_at: null,
  valid_until: FUTURE,
  resumable: false,
  comped: false,
  ...over,
})

function cardPaymentRequired(): unknown {
  return {
    response: {
      data: { error: { code: 'card_payment_required', message: MESSAGE, pay_url: PAY_URL } },
    },
  }
}

async function mountPage() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/activation/purchase', component: { template: '<div />' } },
      { path: '/activation/license', component: { template: '<div />' } },
      { path: '/activation/terms', component: { template: '<div />' } },
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
  vi.stubGlobal('confirm', () => true)
  auth.isSuperadmin = true
  for (const fn of Object.values(api)) fn.mockReset()
  api.resumePendingChanges.mockResolvedValue([])
  api.upgradeQuote.mockResolvedValue({
    current_users: 1,
    new_users: 3,
    amount: 430,
    currency: 'CZK',
    period_end: null,
    quote_token: 'q-users',
    expires_at: null,
    scheduled: false,
    effective_at: null,
  })
  api.tierQuote.mockResolvedValue({
    new_tier: 'multi10',
    amount: 900,
    currency: 'CZK',
    quote_token: 'q-tier',
    scheduled: false,
    effective_at: null,
  })
})

describe('zrušené, ale zaplacené předplatné', () => {
  it('nabídne změnu uživatelů, tarifu i Mezd', async () => {
    api.status.mockResolvedValue(status(invoicePaid()))

    const wrapper = await mountPage()

    expect(wrapper.find('#upgrade').exists()).toBe(true)
    expect(wrapper.find('#tier-change').exists()).toBe(true)
    expect(wrapper.get('#payroll-addon').text()).not.toContain('license.payroll_needs_purchase')
  })

  it('přechod na roční ani obnovu nenabízí', async () => {
    api.status.mockResolvedValue(status(invoicePaid({ period: 'month' })))

    const wrapper = await mountPage()

    expect(wrapper.find('[data-annual-switch]').exists()).toBe(false)
    expect(wrapper.find('[data-renewal-resume-cta]').exists()).toBe(false)
  })

  it('po konci zaplaceného období změny nenabízí', async () => {
    api.status.mockResolvedValue(status(invoicePaid({ valid_until: PAST })))

    const wrapper = await mountPage()

    expect(wrapper.find('#upgrade').exists()).toBe(false)
    expect(wrapper.find('#tier-change').exists()).toBe(false)
  })

  it('přidělenému předplatnému změny nenabízí', async () => {
    api.status.mockResolvedValue(status(invoicePaid({ comped: true })))

    const wrapper = await mountPage()

    expect(wrapper.find('#upgrade').exists()).toBe(false)
  })

  it('navýšení bez karty nabídne zaplatit kartou přes odkaz serveru', async () => {
    api.status.mockResolvedValue(status(invoicePaid()))
    api.upgrade.mockRejectedValue(cardPaymentRequired())

    const wrapper = await mountPage()
    await wrapper.get('#upgrade input').setValue('3')
    await wrapper.get('#upgrade button').trigger('click')
    await flushPromises()
    await wrapper.get('#upgrade .mt-4 button').trigger('click')
    await flushPromises()

    const notice = wrapper.get('#upgrade [data-change-card-payment="required"]')
    expect(notice.text()).toContain(MESSAGE)
    const cta = notice.get('[data-change-card-payment-cta]')
    expect(cta.attributes('href')).toBe(PAY_URL)
    expect(cta.text()).toContain('license.card_payment_cta')
    expect(notice.text()).toContain('license.card_payment_hint')
  })

  it('změna tarifu bez karty nabídne zaplatit kartou', async () => {
    api.status.mockResolvedValue(status(invoicePaid()))
    api.changeTier.mockRejectedValue(cardPaymentRequired())

    const wrapper = await mountPage()
    await wrapper.get('#tier-change select').setValue('multi10')
    await wrapper.get('#tier-change button').trigger('click')
    await flushPromises()
    await wrapper.get('#tier-change .mt-4 button').trigger('click')
    await flushPromises()

    expect(wrapper.get('#tier-change [data-change-card-payment-cta]').attributes('href')).toBe(PAY_URL)
  })

  it('odmítnutá uložená karta nabídne jinou kartu, ne platbu bez karty', async () => {
    api.status.mockResolvedValue(status({ ...invoicePaid(), state: 'active', auto_renew: true }))
    api.upgrade.mockRejectedValue({
      response: { data: { error: { code: 'charge_failed', message: 'Karta neprošla.', pay_url: PAY_URL } } },
    })

    const wrapper = await mountPage()
    await wrapper.get('#upgrade button').trigger('click')
    await flushPromises()
    await wrapper.get('#upgrade .mt-4 button').trigger('click')
    await flushPromises()

    const notice = wrapper.get('#upgrade [data-change-card-payment="declined"]')
    expect(notice.get('[data-change-card-payment-cta]').text()).toContain('license.pay_other_card_cta')
  })

  it('po doplacení mezd přes platební odkaz zobrazí druhé místo bez obnovení stránky', async () => {
    const initial = { ...status(invoicePaid()), payroll_enabled: true, payroll_users_active: 1 }
    api.status.mockResolvedValue(initial)
    api.payrollQuote.mockResolvedValue({ new_enabled: true, amount: 278.1, currency: 'CZK', quote_token: 'q-payroll' })
    api.changePayroll.mockRejectedValue({
      response: { data: { error: { code: 'charge_failed', message: 'Karta neprošla.', pay_url: PAY_URL, order_id: '232' } } },
    })
    let resolve!: (value: unknown) => void
    const promise = new Promise<unknown>((done) => { resolve = done })
    api.waitForCheckout.mockReturnValue(promise)

    const wrapper = await mountPage()
    const payroll = wrapper.get('#payroll-addon')
    await payroll.findAll('input[type="number"]')[1]!.setValue('2')
    await payroll.get('button').trigger('click')
    await flushPromises()
    await payroll.findAll('button').find(button => button.text() === 'license.upgrade_pay_cta')!.trigger('click')
    await flushPromises()

    expect(payroll.get('[data-change-card-payment-cta]').attributes('href')).toBe(PAY_URL)
    expect(api.waitForCheckout).toHaveBeenCalledWith('232')
    resolve({ applied: true, license: { ...initial, payroll_users_licensed: 2 } })
    await flushPromises()
    expect(payroll.find('[data-change-card-payment-cta]').exists()).toBe(false)
    expect(payroll.text()).toContain('1 / 2')
    expect(payroll.text()).toContain('license.payroll_change_success')
    expect(api.changePayroll).toHaveBeenCalledTimes(1)
  })
})
