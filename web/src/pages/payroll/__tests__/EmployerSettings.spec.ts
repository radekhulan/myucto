import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type {
  PayrollAccountOption,
  PayrollEmployerAccounts,
  PayrollEmployerSettings,
} from '@/api/payroll'

const m = vi.hoisted(() => ({
  employerSettings: vi.fn(),
  saveEmployerSettings: vi.fn(),
  accountOptions: vi.fn(),
  institutionAccounts: vi.fn(),
  createInstitutionAccount: vi.fn(),
  updateInstitutionAccount: vi.fn(),
  employerPolicies: vi.fn(),
  payrollSetupCheck: vi.fn(),
  createEmployerPolicy: vi.fn(),
  updateEmployerPolicy: vi.fn(),
  regzelProfile: vi.fn(),
  saveRegzelProfile: vi.fn(),
  jmhzEmployerAnnualEvidence: vi.fn(),
  saveJmhzEmployerAnnualEvidence: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  routeQuery: {} as Record<string, string>,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.routeQuery, hash: '' }),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    employerSettings: m.employerSettings,
    saveEmployerSettings: m.saveEmployerSettings,
    accountOptions: m.accountOptions,
    institutionAccounts: m.institutionAccounts,
    createInstitutionAccount: m.createInstitutionAccount,
    updateInstitutionAccount: m.updateInstitutionAccount,
    employerPolicies: m.employerPolicies,
    payrollSetupCheck: m.payrollSetupCheck,
    createEmployerPolicy: m.createEmployerPolicy,
    updateEmployerPolicy: m.updateEmployerPolicy,
    regzelProfile: m.regzelProfile,
    saveRegzelProfile: m.saveRegzelProfile,
    jmhzEmployerAnnualEvidence: m.jmhzEmployerAnnualEvidence,
    saveJmhzEmployerAnnualEvidence: m.saveJmhzEmployerAnnualEvidence,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

// `useTablePrefs` táhne @/i18n, které volá skutečné `createI18n` — továrna
// proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
    locale: { value: 'cs-CZ' },
  }),
}))

// `useTablePrefs` jde přes Pinii a API; v testu stačí prázdné výchozí předvolby.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import EmployerSettings from '@/pages/payroll/EmployerSettings.vue'

const defaultAccounts: PayrollEmployerAccounts = {
  employment_gross_debit: '521',
  employment_gross_credit: '331',
  partner_gross_debit: '522',
  partner_gross_credit: '366',
  statutory_gross_debit: '523',
  statutory_gross_credit: '366',
  employer_insurance_debit: '524',
  social_insurance_credit: '336',
  health_insurance_credit: '336',
  income_tax_credit: '342',
  other_deductions_credit: '379',
  partner_settlement_credit: '365',
}

function chartAccount(
  account_code: string,
  account_type: PayrollAccountOption['account_type'],
  is_active = true,
  name = `Účet ${account_code}`,
): PayrollAccountOption {
  return {
    id: Number(account_code.replace(/\D/g, '')) || 1,
    account_code,
    name,
    account_type,
    is_synthetic: account_code.length === 3,
    parent_id: null,
    is_active,
  }
}

function chartAccounts(): PayrollAccountOption[] {
  return [
    ...['521', '522', '523', '524'].map(code => chartAccount(code, 'expense')),
    ...['331', '336', '342', '365', '366', '379'].map(code => chartAccount(code, 'liability')),
    chartAccount('521001', 'expense', true, 'Analytická mzda'),
    chartAccount('521999', 'expense', false, 'Neaktivní mzda'),
  ]
}

function settings(accounts: PayrollEmployerAccounts = defaultAccounts): PayrollEmployerSettings {
  return {
    supplier_id: 1,
    row_version: 3,
    employer_registration_number: '12345678',
    social_security_office_code: '110',
    default_health_insurer_code: '111',
    payroll_contact_name: 'Testovací účetní',
    payroll_contact_email: 'payroll@example.test',
    payroll_contact_phone: '+420 000 000 000',
    default_office_code: 'MAIN',
    accounts,
    offices: [{
      id: 1,
      code: 'MAIN',
      name: 'Hlavní účtárna',
      social_security_variable_symbol: '0012345678',
      is_active: true,
      row_version: 1,
    }],
    created_at: '',
    updated_at: '',
  }
}

async function mountPage(value = settings()) {
  m.employerSettings.mockResolvedValue(value)
  m.accountOptions.mockResolvedValue(chartAccounts())
  m.institutionAccounts.mockResolvedValue([])
  m.employerPolicies.mockResolvedValue({ items: [], total: 0 })
  m.payrollSetupCheck.mockResolvedValue({
    ready: false,
    effective_on: '2026-08-04',
    policy_id: null,
    checks: [],
    blockers: ['effective_policy'],
  })
  m.regzelProfile.mockResolvedValue({
    profile: null,
    suggested_tax_office_workplace_code: null,
  })
  m.jmhzEmployerAnnualEvidence.mockResolvedValue({
    evidence: null,
    offices: [],
    collective_agreement_types: [],
    ownership_forms: [],
  })
  m.saveEmployerSettings.mockResolvedValue(value)
  const wrapper = mount(EmployerSettings, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

async function openAccounting(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  await wrapper.findAll('[role="tab"]')[2]!.trigger('click')
}

describe('EmployerSettings — účtová osnova', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.routeQuery = {}
    document.body.innerHTML = ''
  })

  it('otevře záložku podání podle query vedlejšího routeru', async () => {
    m.routeQuery = { tab: 'submissions' }

    const wrapper = await mountPage()

    expect(wrapper.findAll('[role="tab"]')[5]!.attributes('aria-selected')).toBe('true')
    expect(wrapper.text()).toContain('payroll.regzel.profile.title')
    wrapper.unmount()
  })

  it('načte i neaktivní účty pro validaci a nabízí jen aktivní účet správného typu', async () => {
    const wrapper = await mountPage()
    await openAccounting(wrapper)

    expect(m.accountOptions).toHaveBeenCalledOnce()
    const picker = wrapper.findAll('[data-account-key="employment_gross_debit"]')[0]
    const input = picker.find('input[role="combobox"]')
    await input.trigger('focus')

    const optionTexts = Array.from(document.querySelectorAll<HTMLElement>('[role="option"]'))
      .map(option => option.textContent ?? '')
    expect(optionTexts.some(text => text.startsWith('521Účet 521'))).toBe(true)
    expect(optionTexts).toContain('521001Analytická mzda')
    expect(optionTexts).not.toContain('331')
    expect(optionTexts).not.toContain('521999Neaktivní mzda')

    wrapper.unmount()
  })

  it('typově chybný účet označí a neodešle', async () => {
    const invalid = settings({ ...defaultAccounts, employment_gross_debit: '331' })
    const wrapper = await mountPage(invalid)
    await openAccounting(wrapper)

    const picker = wrapper.findAll('[data-account-key="employment_gross_debit"]')[0]
    expect(picker.find('input').attributes('aria-invalid')).toBe('true')
    expect(picker.text()).toContain('payroll.employer.validation.account_wrong_type')

    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')
    await save!.trigger('click')
    expect(m.saveEmployerSettings).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('payroll.employer.validation.accounts')

    wrapper.unmount()
  })

  it('umožní účet vyhledat klávesnicí a pošle jeho kód', async () => {
    const wrapper = await mountPage()
    await openAccounting(wrapper)
    const picker = wrapper.findAll('[data-account-key="employment_gross_debit"]')[0]
    const input = picker.find('input[role="combobox"]')

    await input.trigger('focus')
    await input.setValue('Analytická')
    await input.trigger('keydown', { key: 'ArrowDown' })
    await input.trigger('keydown', { key: 'Enter' })
    expect((input.element as HTMLInputElement).value).toBe('521001')

    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.saveEmployerSettings).toHaveBeenCalledTimes(1)
    expect(m.saveEmployerSettings.mock.calls[0][0].accounts.employment_gross_debit).toBe('521001')

    wrapper.unmount()
  })

  it('při více našeptávačích propojí každý combobox s vlastním listboxem', async () => {
    const wrapper = await mountPage()
    await openAccounting(wrapper)
    const inputs = wrapper.findAll('input[role="combobox"]')

    await inputs[0].trigger('focus')
    await inputs[1].trigger('focus')

    expect(inputs[0].attributes('aria-controls')).toBeTruthy()
    expect(inputs[1].attributes('aria-controls')).toBeTruthy()
    expect(inputs[0].attributes('aria-controls')).not.toBe(inputs[1].attributes('aria-controls'))

    wrapper.unmount()
  })

  it('zachová validní ČSSZ VS u mzdové účtárny v payloadu', async () => {
    const wrapper = await mountPage()
    const inputs = wrapper.findAll('[data-office-social-vs]')
    expect(inputs).toHaveLength(2)
    expect((inputs[0].element as HTMLInputElement).value).toBe('0012345678')

    await inputs[0].setValue('0000000042')
    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.saveEmployerSettings).toHaveBeenCalledTimes(1)
    expect(m.saveEmployerSettings.mock.calls[0][0].offices[0]).toMatchObject({
      code: 'MAIN',
      social_security_variable_symbol: '0000000042',
    })
    expect(m.saveEmployerSettings.mock.calls[0][0]).not.toHaveProperty('health_insurance_payer_number')

    wrapper.unmount()
  })

  it('neodešle nečíselný ČSSZ VS účtárny', async () => {
    const wrapper = await mountPage()
    const input = wrapper.findAll('[data-office-social-vs]')[0]
    await input.setValue('VS-42')

    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')
    await save!.trigger('click')

    expect(m.saveEmployerSettings).not.toHaveBeenCalled()
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('payroll.employer.validation.social_security_variable_symbol')

    wrapper.unmount()
  })

  it('nabízí zdravotní pojišťovny z číselníku místo volného textu', async () => {
    const wrapper = await mountPage()
    const picker = wrapper.get('[data-test="default-health-insurer"]')
    const input = picker.get('input[role="combobox"]')
    expect((input.element as HTMLInputElement).value).toContain('111')

    await input.trigger('focus')
    const optionTexts = Array.from(document.querySelectorAll<HTMLElement>('[role="option"]'))
      .map(option => option.textContent ?? '')
    expect(optionTexts).toHaveLength(7)
    expect(optionTexts.some(text => text.includes('213'))).toBe(true)
    expect(optionTexts.some(text => text.includes('999'))).toBe(false)

    wrapper.unmount()
  })

  it('pošle kód pojišťovny vybraný ze seznamu', async () => {
    const wrapper = await mountPage()
    const input = wrapper.get('[data-test="default-health-insurer"]').get('input[role="combobox"]')

    await input.trigger('focus')
    await input.setValue('205')
    await input.trigger('keydown', { key: 'Enter' })

    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.saveEmployerSettings).toHaveBeenCalledTimes(1)
    expect(m.saveEmployerSettings.mock.calls[0][0].default_health_insurer_code).toBe('205')

    wrapper.unmount()
  })

  it('neuloží pojišťovnu mimo číselník', async () => {
    const wrapper = await mountPage({ ...settings(), default_health_insurer_code: '999' })

    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')
    await save!.trigger('click')

    expect(m.saveEmployerSettings).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('payroll.employer.validation.default_health_insurer_code')

    wrapper.unmount()
  })

  it('prázdná pojišťovna je platná a odejde jako null', async () => {
    const wrapper = await mountPage({ ...settings(), default_health_insurer_code: null })

    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.saveEmployerSettings).toHaveBeenCalledTimes(1)
    expect(m.saveEmployerSettings.mock.calls[0][0].default_health_insurer_code).toBeNull()

    wrapper.unmount()
  })

  it('pustí trojmístný kód OSSZ a nabízí jen tři znaky', async () => {
    const wrapper = await mountPage()
    const field = wrapper.get('[data-test="social-security-office-code"]')
    expect(field.attributes('maxlength')).toBe('3')
    expect(field.attributes('inputmode')).toBe('numeric')

    await field.setValue('115')
    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.saveEmployerSettings).toHaveBeenCalledTimes(1)
    expect(m.saveEmployerSettings.mock.calls[0][0].social_security_office_code).toBe('115')

    wrapper.unmount()
  })

  it('neuloží kód OSSZ jiného tvaru než tří číslic a řekne, jak má vypadat', async () => {
    const wrapper = await mountPage()

    for (const invalid of ['1', '1105', 'PSSZ', '11a']) {
      m.saveEmployerSettings.mockClear()
      await wrapper.get('[data-test="social-security-office-code"]').setValue(invalid)
      const save = wrapper.findAll('button').find(button => button.text() === 'common.save')
      await save!.trigger('click')
      await flushPromises()

      expect(m.saveEmployerSettings).not.toHaveBeenCalled()
      expect(wrapper.text()).toContain('payroll.employer.validation.social_security_office_code')
    }

    wrapper.unmount()
  })

  it('prázdný kód OSSZ zůstává platný a odejde jako null', async () => {
    const wrapper = await mountPage({ ...settings(), social_security_office_code: null })

    const save = wrapper.findAll('button').find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.saveEmployerSettings).toHaveBeenCalledTimes(1)
    expect(m.saveEmployerSettings.mock.calls[0][0].social_security_office_code).toBeNull()

    wrapper.unmount()
  })

  it('používá standardní záložky a neukazuje globální uložení u vlastních formulářů', async () => {
    const wrapper = await mountPage()
    const tabs = wrapper.findAll('[role="tab"]')

    // Šestá záložka je Dimenze (MZ-03-W05, střediska/zakázky/činnosti).
    expect(tabs).toHaveLength(6)
    expect(tabs[0].attributes('aria-selected')).toBe('true')
    expect(wrapper.text()).toContain('payroll.employer.registration_title')
    expect(wrapper.text()).not.toContain('payroll.employer.health_accounts.title')

    await tabs[1].trigger('click')
    await flushPromises()
    expect(tabs[1].attributes('aria-selected')).toBe('true')
    expect(wrapper.text()).toContain('payroll.employer.health_accounts.title')
    expect(wrapper.findAll('button').some(button => button.text() === 'common.save')).toBe(false)

    await tabs[2].trigger('click')
    expect(wrapper.text()).toContain('payroll.employer.accounting_title')
    expect(wrapper.findAll('button').some(button => button.text() === 'common.save')).toBe(true)

    await tabs[3].trigger('click')
    await flushPromises()
    expect(m.employerPolicies).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain('payroll.employer.policies.title')
    expect(wrapper.findAll('button').filter(button => button.text() === 'common.save')).toHaveLength(1)

    // Pořadí záložek: employer, institutions, accounting, policies, dimensions, submissions.
    await tabs[4].trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('payroll.employer.dimensions.title')

    await tabs[5].trigger('click')
    await flushPromises()
    expect(m.regzelProfile).toHaveBeenCalledOnce()
    expect(wrapper.text()).toContain('payroll.regzel.profile.title')

    wrapper.unmount()
  })

  it('odvodí kód nové účtárny z názvu, dokud do něj uživatel nesáhne', async () => {
    const wrapper = await mountPage()
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.add_office')!
      .trigger('click')

    // Index 1 = nově přidaný řádek (index 0 je načtená účtárna MAIN).
    const names = wrapper.findAll('[data-office-name]')
    const codes = wrapper.findAll('[data-office-code]')
    await names[1].setValue('Účtárna Brno')

    expect((codes[1].element as HTMLInputElement).value).toBe('UCTARNA_BRNO')

    // Ruční zásah do kódu auto-generování vypne.
    await codes[1].setValue('BRNO2')
    await names[1].setValue('Účtárna Ostrava')
    expect((codes[1].element as HTMLInputElement).value).toBe('BRNO2')

    wrapper.unmount()
  })

  it('kód nové účtárny odliší suffixem, když se trefí do existujícího', async () => {
    const wrapper = await mountPage()
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.add_office')!
      .trigger('click')

    // Účtárna MAIN už existuje — kolizi musí vyřešit UI, ne až server.
    await wrapper.findAll('[data-office-name]')[1].setValue('Main')
    expect((wrapper.findAll('[data-office-code]')[1].element as HTMLInputElement).value)
      .toBe('MAIN_2')

    wrapper.unmount()
  })

  it('kód existující účtárny se přepisem názvu nemění', async () => {
    const wrapper = await mountPage()
    const names = wrapper.findAll('[data-office-name]')
    const codes = wrapper.findAll('[data-office-code]')

    await names[0].setValue('Přejmenovaná účtárna')

    expect((codes[0].element as HTMLInputElement).value).toBe('MAIN')

    wrapper.unmount()
  })
})
