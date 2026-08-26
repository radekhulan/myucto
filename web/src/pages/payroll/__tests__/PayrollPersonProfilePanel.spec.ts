import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type {
  PayrollPayoutRule,
  PayrollPayoutRulesResponse,
  PayrollPersonProfile,
} from '@/api/payroll'

const mocks = vi.hoisted(() => ({
  personProfile: vi.fn(),
  savePersonProfile: vi.fn(),
  verifyPersonAccount: vi.fn(),
  personPayoutRules: vi.fn(),
  createPersonPayoutRule: vi.fn(),
  updatePersonPayoutRule: vi.fn(),
  deactivatePersonPayoutRule: vi.fn(),
  applyPersonPayoutRuleDefaults: vi.fn(),
  countries: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    personProfile: mocks.personProfile,
    savePersonProfile: mocks.savePersonProfile,
    verifyPersonAccount: mocks.verifyPersonAccount,
    personPayoutRules: mocks.personPayoutRules,
    createPersonPayoutRule: mocks.createPersonPayoutRule,
    updatePersonPayoutRule: mocks.updatePersonPayoutRule,
    deactivatePersonPayoutRule: mocks.deactivatePersonPayoutRule,
    applyPersonPayoutRuleDefaults: mocks.applyPersonPayoutRuleDefaults,
  },
}))

vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    countries: mocks.countries,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({
    success: mocks.success,
    error: mocks.error,
  }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

import PayrollPersonProfilePanel from '@/pages/payroll/PayrollPersonProfilePanel.vue'

function profile(): PayrollPersonProfile {
  return {
    employee_id: 17,
    full_name: 'Testovací Zaměstnanec',
    profile_status: 'setup',
    payout_method: 'bank',
  partner_settlement_account_code: null,
    cash_allocation_basis_points: 0,
    payout_effective_on: '2026-08-01',
    secure_delivery_channel: 'portal',
    row_version: 3,
    identity_history: [{
      id: 1,
      full_name: 'Testovací Zaměstnanec',
      first_name: 'Testovací',
      last_name: 'Zaměstnanec',
      title_prefix: null,
      title_suffix: null,
      birth_surname_masked: 'T•••••••',
      birth_date: null,
      birth_place: null,
      birth_country_code: null,
      citizenship_country_code: null,
      sex: null,
      effective_from: '2026-01-01',
      effective_to: null,
      row_version: 1,
    }],
    addresses: [{
      id: 2,
      address_type: 'residence',
      address_masked: 'P••••, CZ',
      effective_from: '2026-01-01',
      effective_to: null,
      row_version: 1,
    }],
    contacts: [{
      id: 3,
      contact_type: 'email',
      value_masked: 't•••@e••••••.cz',
      is_primary: true,
      is_active: true,
      row_version: 1,
    }],
    identifiers: [{
      id: 4,
      identifier_type: 'birth_number',
      value_masked: '••••••/••••',
      row_version: 1,
    }],
    accounts: [{
      id: 5,
      label: 'Výplata',
      bank_account_masked: '••••••0005/0100',
      allocation_basis_points: 10000,
      effective_from: '2026-01-01',
      effective_to: null,
      is_active: true,
      row_version: 4,
      verification_source: 'bank_document',
      verified_on: '2026-07-31',
      verified_by: 9,
    }],
    created_at: '2026-01-01 10:00:00',
    updated_at: '2026-08-01 10:00:00',
  }
}

function payoutRule(overrides: Partial<PayrollPayoutRule> = {}): PayrollPayoutRule {
  return {
    id: 11,
    supplier_id: 1,
    employee_id: 17,
    allocation_reference: 'payout-bank-a1b2c3d4e5f6',
    destination_kind: 'bank',
    destination_reference: 'account:5',
    allocation_kind: 'remainder',
    amount_minor: null,
    basis_points: null,
    priority_no: 100,
    is_active: true,
    destination_verified: true,
    row_version: 2,
    created_at: '2026-08-01 10:00:00',
    updated_at: '2026-08-01 10:00:00',
    ...overrides,
  }
}

function payoutRulesResponse(rules: PayrollPayoutRule[] = [payoutRule()]): PayrollPayoutRulesResponse {
  const hasActive = rules.some(rule => rule.is_active)

  return {
    rules,
    warnings: rules
      .filter(rule => rule.is_active && rule.destination_verified === false)
      .map(rule => ({
        code: 'unverified_destination' as const,
        rule_id: rule.id,
        account_id: 5,
        message: 'Výplatní účet zatím není ověřený.',
      })),
    proposal: {
      payout_method: 'bank',
      available: true,
      applicable: !hasActive,
      has_active_rules: hasActive,
      blocked_reason: hasActive
        ? 'Zaměstnanec už má vlastní výplatní pravidla — výchozí sada je nepřepisuje.'
        : null,
      rules: [{
        destination_kind: 'bank',
        destination_reference: 'account:5',
        allocation_kind: 'remainder',
        amount_minor: null,
        basis_points: null,
        priority_no: 100,
      }],
    },
  }
}

async function mountedPanel() {
  const wrapper = mount(PayrollPersonProfilePanel, {
    props: {
      personId: 17,
      canWrite: true,
    },
  })
  await flushPromises()
  return wrapper
}

async function openPayout(wrapper: Awaited<ReturnType<typeof mountedPanel>>) {
  const button = wrapper.findAll('button').find(item =>
    item.text().includes('payroll.people.profile.tabs.payout'),
  )
  expect(button).toBeDefined()
  await button!.trigger('click')
}

describe('PayrollPersonProfilePanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.personProfile.mockResolvedValue(profile())
    mocks.savePersonProfile.mockResolvedValue(profile())
    mocks.countries.mockResolvedValue([{
      id: 1,
      iso2: 'CZ',
      iso3: 'CZE',
      name_cs: 'Česko',
      name_en: 'Czechia',
      is_eu: true,
    }])
    mocks.verifyPersonAccount.mockResolvedValue({
      ...profile().accounts[0],
      verification_source: 'user_verified',
      verified_on: '2026-08-04',
      row_version: 5,
    })
    mocks.personPayoutRules.mockResolvedValue(payoutRulesResponse())
    mocks.createPersonPayoutRule.mockResolvedValue(payoutRule())
    mocks.updatePersonPayoutRule.mockResolvedValue({ ...payoutRule(), row_version: 3 })
    mocks.deactivatePersonPayoutRule.mockResolvedValue({
      ...payoutRule(),
      is_active: false,
      row_version: 3,
    })
    mocks.applyPersonPayoutRuleDefaults.mockResolvedValue(payoutRulesResponse())
  })

  it('zobrazuje pouze maskované citlivé hodnoty', async () => {
    const wrapper = await mountedPanel()

    expect(wrapper.text()).toContain('T•••••••')
    expect(wrapper.text()).toContain('P••••, CZ')
    expect(wrapper.text()).not.toContain('1000000005/0100')

    await openPayout(wrapper)
    expect(wrapper.text()).toContain('••••••0005/0100')
    expect(wrapper.get<HTMLInputElement>('[data-test="bank-account-plaintext"]').element.value).toBe('')
  })

  it('nezobrazuje uživateli technickou row_version', async () => {
    const wrapper = await mountedPanel()

    expect(wrapper.text()).not.toContain('payroll.people.profile.version')
  })

  it('používá pro všechny adresy společný číselník států', async () => {
    const wrapper = await mountedPanel()

    expect(wrapper.find('[data-test="profile-country-code"] input').exists()).toBe(true)
  })

  it('zpřístupní registrační identitu v rozbalitelné části a odešle ji s historií', async () => {
    const loaded = profile()
    Object.assign(loaded.identity_history[0], {
      title_prefix: 'Ing.',
      title_suffix: 'Ph.D.',
      birth_date: '1990-02-03',
      birth_place: 'Brno',
      birth_country_code: 'CZ',
      citizenship_country_code: 'SK',
      sex: 'female',
    })
    mocks.personProfile.mockResolvedValue(loaded)

    const wrapper = await mountedPanel()

    expect(wrapper.find('[data-test="registration-identity-details"]').exists()).toBe(true)
    expect(wrapper.get<HTMLInputElement>('[data-test="identity-birth-date"]').element.value)
      .toBe('1990-02-03')
    expect(wrapper.find('[data-test="identity-birth-country"] input').exists()).toBe(true)
    expect(wrapper.find('[data-test="identity-citizenship-country"] input').exists()).toBe(true)

    await wrapper.get('[data-test="save-profile"]').trigger('click')
    await flushPromises()

    expect(mocks.savePersonProfile).toHaveBeenCalledWith(17, expect.objectContaining({
      identity_history: [expect.objectContaining({
        title_prefix: 'Ing.',
        title_suffix: 'Ph.D.',
        birth_date: '1990-02-03',
        birth_place: 'Brno',
        birth_country_code: 'CZ',
        citizenship_country_code: 'SK',
        sex: 'female',
      })],
    }))
  })

  it('používá pro přidávací akce plné primární tlačítko', async () => {
    const wrapper = await mountedPanel()
    const addIdentity = wrapper.findAll('button').find(button =>
      button.text().includes('payroll.people.profile.add_identity'),
    )

    expect(addIdentity).toBeDefined()
    expect(addIdentity!.classes()).toContain('bg-primary-600')
    expect(addIdentity!.classes()).toContain('text-white')
  })

  it('pošle nový plaintext jen v PUT payloadu a po uložení input vyčistí', async () => {
    const wrapper = await mountedPanel()
    await openPayout(wrapper)
    const input = wrapper.get<HTMLInputElement>('[data-test="bank-account-plaintext"]')
    await input.setValue('1000000005/0100')

    await wrapper.get('[data-test="save-profile"]').trigger('click')
    await flushPromises()

    expect(mocks.savePersonProfile).toHaveBeenCalledWith(
      17,
      expect.objectContaining({
        row_version: 3,
        identity_history: [expect.objectContaining({
          id: 1,
          full_name: 'Testovací Zaměstnanec',
          first_name: 'Testovací',
          last_name: 'Zaměstnanec',
        })],
        accounts: [expect.objectContaining({
          id: 5,
          bank_account: '1000000005/0100',
        })],
      }),
    )
    expect(wrapper.get<HTMLInputElement>('[data-test="bank-account-plaintext"]').element.value).toBe('')
  })

  it('ověření účtu používá jeho expected row_version', async () => {
    const wrapper = await mountedPanel()
    await openPayout(wrapper)

    await wrapper.get('[data-test="verify-account"]').trigger('click')
    await flushPromises()

    expect(mocks.verifyPersonAccount).toHaveBeenCalledWith(17, 5, {
      verification_source: 'bank_document',
      verified_on: '2026-07-31',
      row_version: 4,
    })
    expect(wrapper.text()).toContain('payroll.people.profile.verified_summary')
  })

  it('neumožní ověřit účet s dosud neuloženou změnou', async () => {
    const wrapper = await mountedPanel()
    await openPayout(wrapper)
    await wrapper
      .get<HTMLInputElement>('[data-test="bank-account-plaintext"]')
      .setValue('1000000005/0100')

    const verify = wrapper.get<HTMLButtonElement>(
      '[data-test="verify-account"]',
    )
    expect(verify.element.disabled).toBe(true)
    await verify.trigger('click')

    expect(mocks.verifyPersonAccount).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain(
      'payroll.people.profile.save_before_verify',
    )
  })

  it('varuje, že bez výplatního pravidla nejde vyplatit mzdu', async () => {
    mocks.personPayoutRules.mockResolvedValue(payoutRulesResponse([]))
    const wrapper = await mountedPanel()
    await openPayout(wrapper)

    expect(wrapper.find('[data-test="payout-rules-missing"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('payroll.people.profile.payout_rules.missing_title')
  })

  it('s aktivním pravidlem varování neukazuje a nabídku výchozího pravidla schová', async () => {
    const wrapper = await mountedPanel()
    await openPayout(wrapper)

    expect(wrapper.find('[data-test="payout-rules-missing"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="apply-payout-defaults"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="payout-defaults-blocked"]').text()).toContain(
      'výchozí sada je nepřepisuje',
    )
  })

  it('nabídne výchozí pravidlo jen když je použitelné', async () => {
    mocks.personPayoutRules.mockResolvedValue(payoutRulesResponse([]))
    const wrapper = await mountedPanel()
    await openPayout(wrapper)

    await wrapper.get('[data-test="apply-payout-defaults"]').trigger('click')
    await flushPromises()

    expect(mocks.applyPersonPayoutRuleDefaults).toHaveBeenCalledWith(17)
  })

  it('nedává pravidlům vlastní Uložit — ukládá se jedním tlačítkem panelu', async () => {
    const wrapper = await mountedPanel()
    await openPayout(wrapper)

    const ruleButtons = wrapper.findAll('[data-test="payout-rules"] button')
    expect(ruleButtons.length).toBeGreaterThan(0)
    expect(ruleButtons.some(button => button.text().includes('common.save'))).toBe(false)
    expect(wrapper.findAll('[data-test="save-profile"]')).toHaveLength(1)
  })

  it('deaktivaci pravidla odešle až společné Uložit', async () => {
    const wrapper = await mountedPanel()
    await openPayout(wrapper)

    await wrapper.get('[data-test="deactivate-payout-rule"]').trigger('click')
    expect(mocks.deactivatePersonPayoutRule).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="payout-rule-pending-deactivation"]').exists()).toBe(true)

    await wrapper.get('[data-test="save-profile"]').trigger('click')
    await flushPromises()

    expect(mocks.deactivatePersonPayoutRule).toHaveBeenCalledWith(17, 11, 2)
    expect(mocks.updatePersonPayoutRule).not.toHaveBeenCalled()
  })

  it('u pravidla na neověřený účet vysvětlí důsledek a nabídne ověření', async () => {
    mocks.personPayoutRules.mockResolvedValue(
      payoutRulesResponse([payoutRule({ destination_verified: false })]),
    )
    const wrapper = await mountedPanel()
    await openPayout(wrapper)

    const warning = wrapper.get('[data-test="payout-rule-unverified"]')
    expect(warning.text()).toContain('payroll.people.profile.payout_rules.unverified_account')
    expect(wrapper.find('[data-test="payout-rule-verify-account"]').exists()).toBe(true)
  })

  it('ověřený i nebankovní cíl varování neukazuje', async () => {
    for (const rule of [
      payoutRule({ destination_verified: true }),
      payoutRule({
        destination_kind: 'cash',
        destination_reference: null,
        destination_verified: null,
      }),
      payoutRule({
        destination_kind: 'partner_settlement',
        destination_reference: '365.100',
        destination_verified: null,
      }),
      // Vypnuté pravidlo do výplaty nevstupuje, takže ho neověřený účet nepálí.
      payoutRule({ destination_verified: false, is_active: false }),
    ]) {
      mocks.personPayoutRules.mockResolvedValue(payoutRulesResponse([rule]))
      const wrapper = await mountedPanel()
      await openPayout(wrapper)

      expect(wrapper.find('[data-test="payout-rule-unverified"]').exists()).toBe(false)
    }
  })

  it('proklik z varování zaostří na ověření příslušného účtu', async () => {
    mocks.personPayoutRules.mockResolvedValue(
      payoutRulesResponse([payoutRule({ destination_verified: false })]),
    )
    const wrapper = await mountedPanel()
    await openPayout(wrapper)

    const card = document.createElement('div')
    card.id = 'payout-account-5'
    const verifyButton = document.createElement('button')
    verifyButton.dataset.test = 'verify-account'
    card.appendChild(verifyButton)
    document.body.appendChild(card)
    card.scrollIntoView = vi.fn()

    await wrapper.get('[data-test="payout-rule-verify-account"]').trigger('click')

    expect(card.scrollIntoView).toHaveBeenCalled()
    expect(document.activeElement).toBe(verifyButton)
    card.remove()
  })

  it('nové pravidlo pošle až po uložení karty a v haléřích', async () => {
    const wrapper = await mountedPanel()
    await openPayout(wrapper)

    await wrapper.get('[data-test="add-payout-rule"]').trigger('click')
    const rows = wrapper.findAll('[data-test="payout-rule"]')
    expect(rows).toHaveLength(2)

    await wrapper.get('[data-test="save-profile"]').trigger('click')
    await flushPromises()

    expect(mocks.createPersonPayoutRule).toHaveBeenCalledWith(17, expect.objectContaining({
      destination_kind: 'bank',
      destination_reference: 'account:5',
      allocation_kind: 'remainder',
      amount_minor: null,
      basis_points: null,
      priority_no: 110,
      is_active: true,
    }))
  })

  it.each([
    [409, 'Profil mezitím změnil jiný uživatel.'],
    [422, 'Datum ověření není platné.'],
  ])('zobrazí konkrétní chybu API pro stav %i', async (status, message) => {
    mocks.verifyPersonAccount.mockRejectedValueOnce({
      response: {
        status,
        data: { error: { code: 'validation_failed', message } },
      },
    })
    const wrapper = await mountedPanel()
    await openPayout(wrapper)

    await wrapper.get('[data-test="verify-account"]').trigger('click')
    await flushPromises()

    expect(mocks.error).toHaveBeenCalledWith(message)
  })
})
