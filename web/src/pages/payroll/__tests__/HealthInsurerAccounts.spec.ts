import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollInstitutionAccount } from '@/api/payroll'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

const m = vi.hoisted(() => ({
  institutionAccounts: vi.fn(),
  createInstitutionAccount: vi.fn(),
  updateInstitutionAccount: vi.fn(),
  deleteInstitutionAccount: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  routeHash: '',
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ get hash() { return m.routeHash }, query: {} }),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    institutionAccounts: m.institutionAccounts,
    createInstitutionAccount: m.createInstitutionAccount,
    updateInstitutionAccount: m.updateInstitutionAccount,
    deleteInstitutionAccount: m.deleteInstitutionAccount,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

// `useTablePrefs` táhne @/i18n, které volá skutečné `createI18n` — továrna
// proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string) => key,
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

import HealthInsurerAccounts from '@/pages/payroll/HealthInsurerAccounts.vue'

function account(overrides: Partial<PayrollInstitutionAccount> = {}): PayrollInstitutionAccount {
  return {
    id: 7,
    supplier_id: 1,
    institution_id: 11,
    institution_type: 'health_insurer',
    institution_code: 'SYNTH-111',
    institution_name: 'Syntetická zdravotní pojišťovna',
    bank_account: '1000000005/0100',
    bank_account_masked: '••••0005/0100',
    currency_code: 'CZK',
    variable_symbol: '0012345678',
    specific_symbol: null,
    constant_symbol: '0558',
    valid_from: '2026-01-01',
    valid_to: null,
    source_kind: 'official_document',
    source_reference: 'SYNTHETIC-DOCUMENT-001',
    verified_on: '2026-01-01',
    verified_by: 1,
    row_version: 3,
    created_at: '2026-01-01 00:00:00',
    updated_at: '2026-01-01 00:00:00',
    can_delete: true,
    delete_blocker: null,
    ...overrides,
  }
}

async function mountComponent(items: PayrollInstitutionAccount[] = [account()]) {
  m.institutionAccounts.mockResolvedValue(items)
  const wrapper = mount(HealthInsurerAccounts, {
    props: { canWrite: true },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

async function mountReadOnly(items: PayrollInstitutionAccount[] = [account()]) {
  m.institutionAccounts.mockResolvedValue(items)
  const wrapper = mount(HealthInsurerAccounts, {
    props: { canWrite: false },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

describe('HealthInsurerAccounts', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.routeHash = ''
    document.body.innerHTML = ''
  })

  it('ukazuje číslo účtu i variabilní symbol rovnou v přehledu, bez rozklikávání', async () => {
    const wrapper = await mountComponent()

    const numbers = wrapper.findAll('[data-testid="account-number"]')
    expect(numbers).not.toHaveLength(0)
    expect(numbers[0].text()).toBe('1000000005/0100')
    expect(wrapper.get('[data-testid="account-vs"]').text()).toBe('0012345678')

    wrapper.unmount()
  })

  it('u nedočtelného šifrovaného účtu spadne zpět na maskovanou podobu', async () => {
    const wrapper = await mountComponent([account({ bank_account: null })])

    expect(wrapper.get('[data-testid="account-number"]').text()).toBe('••••0005/0100')

    wrapper.unmount()
  })

  it('uloží účet i s prázdnou referencí zdroje', async () => {
    m.updateInstitutionAccount.mockResolvedValue(account({ source_reference: '', row_version: 4 }))
    const wrapper = await mountComponent()

    const edit = wrapper.findAll('button').find(button => button.text() === 'common.edit')
    await edit!.trigger('click')
    await wrapper.get('[data-testid="health-edit-source-reference"]').setValue('')
    const save = wrapper.get('[data-testid="health-account-edit"]').findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.updateInstitutionAccount).toHaveBeenCalledTimes(1)
    expect(m.updateInstitutionAccount.mock.calls[0][1]).toMatchObject({ source_reference: '' })

    wrapper.unmount()
  })

  it('označí povinná pole hvězdičkou a nepovinná nechá bez značky', async () => {
    const wrapper = await mountComponent([])

    const add = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.health_accounts.add')
    await add!.trigger('click')

    const marked = wrapper.findAll('[data-required-mark]')
      .map(node => node.element.parentElement?.textContent ?? '')
    expect(marked.some(label => label.includes('health_accounts.bank_account'))).toBe(true)
    expect(marked.some(label => label.includes('health_accounts.source_reference'))).toBe(false)
    expect(marked.some(label => label.includes('health_accounts.specific_symbol'))).toBe(false)

    wrapper.unmount()
  })

  it('založí nový historický účet zvoleného typu a odešle nemaskovaný účet pouze create endpointu', async () => {
    const created = account({
      id: 8,
      institution_type: 'social_security',
      institution_code: 'SYNTH-201',
      bank_account: '1000000005/0300',
      bank_account_masked: '••••0005/0300',
    })
    m.createInstitutionAccount.mockResolvedValue(created)
    const wrapper = await mountComponent([])

    const add = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.health_accounts.add')
    await add!.trigger('click')
    // Typ instituce + pojišťovna z číselníku + zdroj údaje. Po přepnutí na ČSSZ
    // číselník mizí (mimo zdravotní pojišťovny žádný nemáme) a zbydou dva.
    const selects = wrapper.findAllComponents(SearchableSelect)
    expect(selects).toHaveLength(3)
    await wrapper
      .get('[aria-label="payroll.employer.health_accounts.institution_type"]')
      .trigger('focus')
    const socialSecurity = wrapper.findAll('[role="option"]')
      .find(option => option.text() === 'payroll.employer.health_accounts.types.social_security')
    await socialSecurity!.trigger('click')
    await wrapper.get('[data-testid="health-create-code"]').setValue('synth-201')
    await wrapper.get('[data-testid="health-create-name"]').setValue('Syntetická správa sociálního zabezpečení')
    await wrapper.get('[data-testid="health-create-account"]').setValue('1000000005/0300')
    await wrapper.get('[data-testid="health-create-vs"]').setValue('0000000042')
    await wrapper.get('[data-testid="health-create-source-reference"]').setValue('SYNTHETIC-NOTICE-002')

    const create = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.health_accounts.create')
    await create!.trigger('click')
    await flushPromises()

    expect(m.createInstitutionAccount).toHaveBeenCalledTimes(1)
    expect(m.createInstitutionAccount.mock.calls[0][0]).toMatchObject({
      institution_type: 'social_security',
      institution_code: 'SYNTH-201',
      institution_name: 'Syntetická správa sociálního zabezpečení',
      bank_account: '1000000005/0300',
      currency_code: 'CZK',
      variable_symbol: '0000000042',
      source_reference: 'SYNTHETIC-NOTICE-002',
    })
    expect(m.createInstitutionAccount.mock.calls[0][0]).not.toHaveProperty('bank_account_masked')
    expect(wrapper.text()).toContain('1000000005/0300')

    wrapper.unmount()
  })

  it('výběr zdravotní pojišťovny z číselníku doplní kód i název naráz', async () => {
    m.createInstitutionAccount.mockResolvedValue(account({
      id: 9,
      institution_code: '205',
      institution_name: 'Česká průmyslová zdravotní pojišťovna (ČPZP)',
    }))
    const wrapper = await mountComponent([])

    const add = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.health_accounts.add')
    await add!.trigger('click')

    // Volný text pro kód a název u zdravotní pojišťovny vůbec není — jen výběr.
    expect(wrapper.find('[data-testid="health-create-code"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="health-create-name"]').exists()).toBe(false)

    await wrapper
      .get('[aria-label="payroll.employer.health_accounts.insurer"]')
      .trigger('focus')
    const cpzp = wrapper.findAll('[role="option"]')
      .find(option => option.text().startsWith('205'))
    await cpzp!.trigger('click')

    await wrapper.get('[data-testid="health-create-account"]').setValue('1000000005/0100')
    await wrapper.get('[data-testid="health-create-source-reference"]').setValue('SYNTHETIC-NOTICE-205')
    const create = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.health_accounts.create')
    await create!.trigger('click')
    await flushPromises()

    expect(m.createInstitutionAccount).toHaveBeenCalledTimes(1)
    expect(m.createInstitutionAccount.mock.calls[0][0]).toMatchObject({
      institution_type: 'health_insurer',
      institution_code: '205',
      institution_name: 'Česká průmyslová zdravotní pojišťovna (ČPZP)',
    })

    wrapper.unmount()
  })

  it('umožní zadat kód pojišťovny mimo číselník ručně', async () => {
    m.createInstitutionAccount.mockResolvedValue(account({ id: 10, institution_code: '299' }))
    const wrapper = await mountComponent([])

    const add = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.health_accounts.add')
    await add!.trigger('click')
    await wrapper.get('[data-testid="health-create-manual-code"]').trigger('click')

    await wrapper.get('[data-testid="health-create-code"]').setValue('299')
    await wrapper.get('[data-testid="health-create-name"]').setValue('Nová zdravotní pojišťovna')
    await wrapper.get('[data-testid="health-create-account"]').setValue('1000000005/0100')
    await wrapper.get('[data-testid="health-create-source-reference"]').setValue('SYNTHETIC-NOTICE-299')
    const create = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.health_accounts.create')
    await create!.trigger('click')
    await flushPromises()

    expect(m.createInstitutionAccount.mock.calls[0][0]).toMatchObject({
      institution_type: 'health_insurer',
      institution_code: '299',
      institution_name: 'Nová zdravotní pojišťovna',
    })

    wrapper.unmount()
  })

  it('v ruční větvi odvodí kód instituce z názvu, dokud do něj uživatel nesáhne', async () => {
    const wrapper = await mountComponent([])
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.employer.health_accounts.add')!
      .trigger('click')
    await wrapper.get('[data-testid="health-create-manual-code"]').trigger('click')

    const name = wrapper.get('[data-testid="health-create-name"]')
    const code = wrapper.get('[data-testid="health-create-code"]')
    await name.setValue('Nová zdravotní pojišťovna')

    expect((code.element as HTMLInputElement).value).toBe('NOVA_ZDRAVOTNI_POJISTOVNA')

    await code.setValue('299')
    await name.setValue('Jiný název pojišťovny')
    expect((code.element as HTMLInputElement).value).toBe('299')

    wrapper.unmount()
  })

  it('existující účet s kódem mimo číselník zůstane čitelný i editovatelný', async () => {
    m.updateInstitutionAccount.mockResolvedValue(account({
      institution_name: 'Přejmenovaná pojišťovna',
      row_version: 4,
    }))
    // `SYNTH-111` v číselníku není — takový záznam nesmí zůstat zamčený.
    const wrapper = await mountComponent()
    expect(wrapper.text()).toContain('SYNTH-111')

    const edit = wrapper.findAll('button').find(button => button.text() === 'common.edit')
    await edit!.trigger('click')
    const name = wrapper.get('[data-testid="health-edit-name"]')
    expect((name.element as HTMLInputElement).value).toBe('Syntetická zdravotní pojišťovna')

    await name.setValue('Přejmenovaná pojišťovna')
    const save = wrapper.get('[data-testid="health-account-edit"]').findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.updateInstitutionAccount).toHaveBeenCalledTimes(1)
    expect(m.updateInstitutionAccount.mock.calls[0][1]).toMatchObject({
      institution_name: 'Přejmenovaná pojišťovna',
    })
    expect(m.updateInstitutionAccount.mock.calls[0][1]).not.toHaveProperty('institution_code')

    wrapper.unmount()
  })

  it('při editaci posílá jen povolená pole a zachová optimistickou verzi', async () => {
    m.updateInstitutionAccount.mockResolvedValue(account({
      variable_symbol: '0000000042',
      row_version: 4,
    }))
    const wrapper = await mountComponent()

    const edit = wrapper.findAll('button').find(button => button.text() === 'common.edit')
    await edit!.trigger('click')
    expect(wrapper.findAllComponents(SearchableSelect)).toHaveLength(1)
    await wrapper.get('[data-testid="health-edit-vs"]').setValue('0000000042')
    const save = wrapper.get('[data-testid="health-account-edit"]').findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(m.updateInstitutionAccount).toHaveBeenCalledTimes(1)
    expect(m.updateInstitutionAccount.mock.calls[0][0]).toBe(7)
    expect(m.updateInstitutionAccount.mock.calls[0][1]).toMatchObject({
      row_version: 3,
      variable_symbol: '0000000042',
      constant_symbol: '0558',
    })
    expect(m.updateInstitutionAccount.mock.calls[0][1]).not.toHaveProperty('bank_account')
    expect(m.updateInstitutionAccount.mock.calls[0][1]).not.toHaveProperty('bank_account_masked')
    expect(m.updateInstitutionAccount.mock.calls[0][1]).not.toHaveProperty('institution_code')
    expect(m.updateInstitutionAccount.mock.calls[0][1]).not.toHaveProperty('valid_from')

    wrapper.unmount()
  })

  it('zobrazuje účty všech podporovaných institucí včetně jejich typu', async () => {
    const wrapper = await mountReadOnly([
      account(),
      account({
        id: 8,
        institution_id: 12,
        institution_type: 'social_security',
        institution_code: 'SYNTH-CSSZ',
        institution_name: 'Syntetická správa sociálního zabezpečení',
      }),
      account({
        id: 9,
        institution_id: 13,
        institution_type: 'tax_office',
        institution_code: 'SYNTH-FU',
        institution_name: 'Syntetický finanční úřad',
      }),
    ])

    expect(wrapper.text()).toContain('payroll.employer.health_accounts.types.health_insurer')
    expect(wrapper.text()).toContain('payroll.employer.health_accounts.types.social_security')
    expect(wrapper.text()).toContain('payroll.employer.health_accounts.types.tax_office')
    expect(wrapper.text()).toContain('Syntetický finanční úřad')

    wrapper.unmount()
  })

  it('po asynchronním načtení respektuje přímý odkaz na účty pojišťoven', async () => {
    const scrollIntoView = vi.fn()
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
      configurable: true,
      value: scrollIntoView,
    })
    m.routeHash = '#health-insurer-accounts'

    const wrapper = await mountComponent()

    expect(scrollIntoView).toHaveBeenCalledWith({ block: 'start' })
    wrapper.unmount()
  })

  it('v režimu pouze pro čtení skryje přidání i editaci', async () => {
    const wrapper = await mountReadOnly([
      account(),
      account({
        id: 8,
        institution_id: 12,
        institution_code: 'SYNTH-222',
        institution_name: 'Druhá syntetická pojišťovna',
      }),
    ])

    expect(wrapper.attributes('id')).toBe('health-insurer-accounts')
    expect(wrapper.text()).toContain('Syntetická zdravotní pojišťovna')
    expect(wrapper.text()).toContain('Druhá syntetická pojišťovna')
    expect(wrapper.findAll('button').some(button =>
      button.text() === 'payroll.employer.health_accounts.add'
      || button.text() === 'common.edit')).toBe(false)

    wrapper.unmount()
  })

  it('neodešle nečíselný VS a konflikt verze vyžádá načtení aktuálních dat', async () => {
    const wrapper = await mountComponent()
    const edit = wrapper.findAll('button').find(button => button.text() === 'common.edit')
    await edit!.trigger('click')
    await wrapper.get('[data-testid="health-edit-vs"]').setValue('VS-42')
    let save = wrapper.get('[data-testid="health-account-edit"]').findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    expect(m.updateInstitutionAccount).not.toHaveBeenCalled()

    await wrapper.get('[data-testid="health-edit-vs"]').setValue('42')
    m.updateInstitutionAccount.mockRejectedValue({
      isAxiosError: true,
      response: { status: 409, data: { error: { code: 'row_version_conflict' } } },
    })
    save = wrapper.get('[data-testid="health-account-edit"]').findAll('button')
      .find(button => button.text() === 'common.save')
    await save!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.employer.health_accounts.conflict')
    expect(m.updateInstitutionAccount).toHaveBeenCalledTimes(1)

    wrapper.unmount()
  })
  /**
   * Duplicitní účet pod špatným kódem instituce se při přípravě plateb nikdy
   * nenajde, takže je to mrtvý řádek — jenže stojí vedle správného se stejným
   * číslem a účetní z dvojice nepozná, který platí. Ukončení platnosti ho
   * z přehledu neodstraní, ten ukazuje i historii.
   */
  it('smaže účet, ze kterého se nikdy neplatilo, a pošle jeho verzi', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    m.deleteInstitutionAccount.mockResolvedValue({})
    const wrapper = await mountComponent([account({ id: 7, row_version: 3 })])

    await wrapper.get('[data-test="delete-institution-account"]').trigger('click')
    await flushPromises()

    expect(confirm).toHaveBeenCalled()
    expect(m.deleteInstitutionAccount).toHaveBeenCalledWith(7, 3)
    expect(wrapper.text()).not.toContain('Syntetická zdravotní pojišťovna')
    expect(m.toastSuccess).toHaveBeenCalledWith('payroll.employer.health_accounts.deleted')

    confirm.mockRestore()
    wrapper.unmount()
  })

  it('u účtu, ze kterého se platilo, neptá na potvrzení a řekne důvod ze serveru', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = await mountComponent([account({
      can_delete: false,
      delete_blocker: {
        code: 'payroll_institution_account_in_liability',
        message: 'Na tento účet už míří mzdový platební závazek.',
        employment_id: null,
        employment_code: null,
      },
    })])

    await wrapper.get('[data-test="delete-institution-account"]').trigger('click')
    await flushPromises()

    expect(m.deleteInstitutionAccount).not.toHaveBeenCalled()
    expect(confirm).not.toHaveBeenCalled()
    expect(m.toastError).toHaveBeenCalledWith('Na tento účet už míří mzdový platební závazek.')
    expect(wrapper.text()).toContain('Syntetická zdravotní pojišťovna')

    confirm.mockRestore()
    wrapper.unmount()
  })
})
