import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollEmployerPolicy, PayrollSetupCheck } from '@/api/payroll'

const m = vi.hoisted(() => ({
  employerPolicies: vi.fn(),
  payrollSetupCheck: vi.fn(),
  createEmployerPolicy: vi.fn(),
  updateEmployerPolicy: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    employerPolicies: m.employerPolicies,
    payrollSetupCheck: m.payrollSetupCheck,
    createEmployerPolicy: m.createEmployerPolicy,
    updateEmployerPolicy: m.updateEmployerPolicy,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

// `te` říká, jestli klíč existuje; stránka podle něj pozná, že má místo syrového
// klíče vypsat hlášku ze serveru. Sada klíčů je tu explicitní, ať se dá otestovat
// i chybějící překlad.
const missingKeys = new Set<string>()

// `useTablePrefs` táhne @/i18n, které volá skutečné `createI18n` — továrna
// proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    te: (key: string) => !missingKeys.has(key),
  }),
}))

// `useTablePrefs` jde přes Pinii a API; v testu stačí prázdné výchozí předvolby.
vi.mock('@/composables/useUserPrefs', async () => {
  // Stavová napodobenina: výběr sloupců se ukládá přes patchPagePrefs a musí
  // se hned projevit v tabulce.
  const { computed, ref } = await import('vue')
  const store = ref<Record<string, unknown>>({})
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => store.value),
    patchPagePrefs: (_page: string, patch: Record<string, unknown>) => {
      store.value = { ...store.value, ...patch }
    },
  }
})

import EmployerPolicies from '@/pages/payroll/EmployerPolicies.vue'

function policy(overrides: Partial<PayrollEmployerPolicy> = {}): PayrollEmployerPolicy {
  return {
    id: 41,
    supplier_id: 7,
    valid_from: '2026-01-01',
    valid_to: null,
    payday_day: 10,
    payday_month_offset: 1,
    payday_business_day_rule: 'previous_business_day',
    balance_rounding_mode: 'exact_minor_units',
    home_office_policy: 'not_used',
    travel_expense_policy: 'not_used',
    leave_entitlement_weeks: 5,
    automatic_posting_enabled: false,
    delivery_channel: 'disabled',
    delivery_verified_on: null,
    source_kind: 'migration',
    source_reference: 'synthetic-source',
    created_by: 3,
    updated_by: 3,
    row_version: 2,
    created_at: '2026-01-01 08:00:00',
    updated_at: '2026-01-01 08:00:00',
    ...overrides,
  }
}

function setup(ready = true): PayrollSetupCheck {
  return {
    ready,
    effective_on: '2026-08-04',
    policy_id: ready ? 41 : null,
    checks: [
      {
        code: 'employer_settings',
        status: 'ok',
        message: 'server Czech text must not be the English UI source',
      },
      {
        code: 'effective_policy',
        status: ready ? 'ok' : 'blocked',
        message: 'server fallback',
      },
    ],
    blockers: ready ? [] : ['effective_policy'],
  }
}

async function mountComponent(canWrite = true, policies = [policy()], total = policies.length) {
  // Historie se stránkuje na serveru — klient dostává stránku plus celkový počet.
  m.employerPolicies.mockResolvedValue({ items: policies, total })
  m.payrollSetupCheck.mockResolvedValue(setup(policies.length > 0))
  m.createEmployerPolicy.mockImplementation(async payload => policy({
    id: 42,
    row_version: 1,
    source_kind: payload.source_kind,
  }))
  m.updateEmployerPolicy.mockImplementation(async (_id, payload) => policy({
    ...payload,
    row_version: payload.row_version + 1,
  }))
  const wrapper = mount(EmployerPolicies, {
    props: { canWrite },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

describe('EmployerPolicies', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    document.body.innerHTML = ''
  })

  /**
   * Politika se verzuje při každé změně výplatního termínu i automatizace,
   * takže historie roste po celou dobu provozu firmy. Klient si proto říká
   * o jednu ohraničenou stránku a další si dotáhne ze serveru — ořezávat
   * seznam až v prohlížeči by znamenalo stáhnout celou historii pokaždé.
   */
  it('stránkuje historii na serveru, ne v prohlížeči', async () => {
    const wrapper = await mountComponent(
      true,
      [policy({ id: 1, valid_from: '2030-01-01' })],
      60,
    )

    expect(m.employerPolicies).toHaveBeenCalledWith(undefined, { limit: 25, offset: 0 })
    // Celkový počet je vidět, i když se na stránku vejde jen zlomek.
    expect(wrapper.text()).toContain('common.pagination_range')

    m.employerPolicies.mockResolvedValue({
      items: [policy({ id: 2, valid_from: '2029-01-01' })],
      total: 60,
    })
    const next = wrapper.findAll('button')
      .find(button => button.text().includes('common.next'))
    expect(next).toBeDefined()
    await next!.trigger('click')
    await flushPromises()

    expect(m.employerPolicies).toHaveBeenLastCalledWith(undefined, { limit: 25, offset: 25 })
    expect(wrapper.find('table').text()).toContain('2029-01-01')
    expect(wrapper.find('table').text()).not.toContain('2030-01-01')

    wrapper.unmount()
  })

  /** Skrytý sloupec zmizí z hlavičky i z buněk, mobilní karta ho drží dál. */
  it('skryje sloupec v desktopové tabulce a mobilní kartu nechá být', async () => {
    const wrapper = await mountComponent()

    expect(wrapper.find('table').text()).toContain('payroll.employer.policies.payday')

    const picker = wrapper.findAll('button')
      .find(button => button.text() === 'common.columns')
    expect(picker).toBeDefined()
    await picker!.trigger('click')
    const toggle = wrapper.findAll('label')
      .find(label => label.text() === 'payroll.employer.policies.payday')
    expect(toggle).toBeDefined()
    await toggle!.find('input').trigger('change')
    await flushPromises()

    expect(wrapper.find('table').text()).not.toContain('payroll.employer.policies.payday')
    const mobile = wrapper.findAll('div')
      .find(node => node.classes().includes('md:hidden') && node.text() !== '')
    expect(mobile).toBeDefined()
    expect(mobile!.text()).toContain('payroll.employer.policies.payday_value')

    wrapper.unmount()
  })

  it('zobrazuje kontrolu připravenosti, desktopovou historii i mobilní karty', async () => {
    const wrapper = await mountComponent()

    expect(m.employerPolicies).toHaveBeenCalledOnce()
    expect(m.payrollSetupCheck).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain('payroll.employer.policies.setup_ready')
    expect(wrapper.text()).toContain('payroll.employer.policies.checks.employer_settings.ok')
    expect(wrapper.find('table').exists()).toBe(true)
    expect(wrapper.find('[class*="md:hidden"]').exists()).toBe(true)

    wrapper.unmount()
  })

  /**
   * Nepovinná kontrola (`pending`) měla vlastní stav i vlastní text, ale ani
   * jeden nebyl přeložený — stránka pak u certifikátu JMHZ vypsala rovnou klíč
   * `payroll.employer.policies.checks.jmhz_certificate.pending`. Chybějící
   * překlad musí spadnout na hlášku ze serveru, ne na klíč.
   */
  it('u nepřeložené kontroly vypíše hlášku ze serveru, ne klíč', async () => {
    missingKeys.add('payroll.employer.policies.checks.jmhz_certificate.pending')
    m.employerPolicies.mockResolvedValue({ items: [policy()], total: 1 })
    m.payrollSetupCheck.mockResolvedValue({
      ...setup(),
      checks: [{
        code: 'jmhz_certificate',
        status: 'pending' as const,
        message: 'Zvolte podpisový certifikát pro produkční prostředí.',
      }],
    })
    const wrapper = mount(EmployerPolicies, { props: { canWrite: true }, attachTo: document.body })
    await flushPromises()

    expect(wrapper.text()).toContain('Zvolte podpisový certifikát pro produkční prostředí.')
    expect(wrapper.text()).not.toContain('checks.jmhz_certificate.pending')
    // Stav má vlastní popisek i nenápadný tón — nepovinná kontrola není překážka.
    expect(wrapper.text()).toContain('payroll.employer.policies.status.pending')
    expect(wrapper.html()).toContain('bg-neutral-100')

    missingKeys.delete('payroll.employer.policies.checks.jmhz_certificate.pending')
    wrapper.unmount()
  })

  it('novou politiku odešle jako ruční zdroj s bezpečnými výchozími hodnotami', async () => {
    const wrapper = await mountComponent(true, [])

    const save = wrapper.findAll('button')
      .find(button => button.text() === 'common.save')
    expect(save).toBeDefined()
    await save!.trigger('click')
    await flushPromises()

    expect(m.createEmployerPolicy).toHaveBeenCalledOnce()
    expect(m.createEmployerPolicy.mock.calls[0][0]).toMatchObject({
      row_version: 0,
      source_kind: 'manual',
      automatic_posting_enabled: false,
      delivery_channel: 'disabled',
      delivery_verified_on: null,
    })
    /*
     * Přepínače bez konzumenta (D-01/D-02) se z formuláře nesmí vrátit ani
     * jako mrtvá pole: kdyby je klient dál posílal, obrazovka by o nich zase
     * začala tvrdit, že něco dělají.
     */
    expect(m.createEmployerPolicy.mock.calls[0][0])
      .not.toHaveProperty('four_eyes_required')
    expect(m.createEmployerPolicy.mock.calls[0][0])
      .not.toHaveProperty('automatic_calculation_enabled')
    expect(m.createEmployerPolicy.mock.calls[0][0])
      .not.toHaveProperty('automatic_payments_enabled')

    wrapper.unmount()
  })

  it('při úpravě zachová původ a optimistickou verzi', async () => {
    const current = policy({ source_kind: 'migration', row_version: 8 })
    const wrapper = await mountComponent(true, [current])
    const edit = wrapper.findAll('button')
      .find(button => button.text() === 'common.edit')
    await edit!.trigger('click')
    const save = wrapper.findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.updateEmployerPolicy).toHaveBeenCalledOnce()
    expect(m.updateEmployerPolicy.mock.calls[0][0]).toBe(41)
    expect(m.updateEmployerPolicy.mock.calls[0][1]).toMatchObject({
      source_kind: 'migration',
      row_version: 8,
    })

    wrapper.unmount()
  })

  it('umožní zrušit konec platnosti a odešle otevřený interval jako null', async () => {
    const wrapper = await mountComponent(true, [policy({ valid_to: '2026-12-31' })])
    const edit = wrapper.findAll('button')
      .find(button => button.text() === 'common.edit')
    await edit!.trigger('click')
    const dateInputs = wrapper.findAll('input[type="date"]')
    await dateInputs[2]!.setValue('')
    const save = wrapper.findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.updateEmployerPolicy).toHaveBeenCalledOnce()
    expect(m.updateEmployerPolicy.mock.calls[0][1].valid_to).toBeNull()

    wrapper.unmount()
  })

  it('po úspěšném uložení neoznačí selhání obnovy checklistu za selhání mutace', async () => {
    const wrapper = await mountComponent(true, [])
    m.payrollSetupCheck.mockRejectedValueOnce(new Error('setup unavailable'))

    const save = wrapper.findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.createEmployerPolicy).toHaveBeenCalledOnce()
    expect(m.toastSuccess).toHaveBeenCalledWith('payroll.employer.policies.saved')
    expect(wrapper.text()).toContain('payroll.employer.policies.setup_failed')
    expect(wrapper.text()).not.toContain('payroll.employer.policies.save_failed')

    wrapper.unmount()
  })

  it('ponechá přesný důvod konfliktu ve formuláři a nabídne reload', async () => {
    const error = Object.assign(new Error('conflict'), {
      isAxiosError: true,
      response: {
        status: 409,
        data: {
          error: {
            code: 'row_version_conflict',
            message: 'Syntetická novější verze existuje.',
            current_row_version: 9,
          },
        },
      },
    })
    const wrapper = await mountComponent()
    m.updateEmployerPolicy.mockRejectedValueOnce(error)
    const edit = wrapper.findAll('button')
      .find(button => button.text() === 'common.edit')
    await edit!.trigger('click')
    const save = wrapper.findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Syntetická novější verze existuje.')
    expect(wrapper.text()).toContain('payroll.employer.policies.reload_current')

    m.employerPolicies.mockResolvedValueOnce({
      items: [policy({ source_kind: 'migration', row_version: 9 })],
      total: 1,
    })
    const reload = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.policies.reload_current')
    await reload!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).not.toContain('Syntetická novější verze existuje.')

    const retrySave = wrapper.findAll('button')
      .find(button => button.text() === 'common.save')
    await retrySave!.trigger('click')
    await flushPromises()
    expect(m.updateEmployerPolicy).toHaveBeenCalledTimes(2)
    expect(m.updateEmployerPolicy.mock.calls[1][1]).toMatchObject({
      source_kind: 'migration',
      row_version: 9,
    })

    wrapper.unmount()
  })

  it('read-only role nevidí žádnou mutační akci', async () => {
    const wrapper = await mountComponent(false)

    expect(wrapper.text()).not.toContain('payroll.employer.policies.add')
    expect(wrapper.findAll('button').some(button => button.text() === 'common.edit')).toBe(false)
    expect(wrapper.findAll('button').some(button => button.text() === 'common.save')).toBe(false)

    wrapper.unmount()
  })
})
