import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  components: vi.fn(),
  recurringComponents: vi.fn(),
  inputs: vi.fn(),
  people: vi.fn(),
  person: vi.fn(),
  absenceContext: vi.fn(),
  accountOptions: vi.fn(),
  componentJmhzTargets: vi.fn(),
  componentJmhzMappings: vi.fn(),
  saveComponentJmhzMapping: vi.fn(),
  removeComponentJmhzMapping: vi.fn(),
  deleteComponent: vi.fn(),
  deleteRecurringComponent: vi.fn(),
  createComponent: vi.fn(),
  createRecurringComponent: vi.fn(),
  createInput: vi.fn(),
  previewInput: vi.fn(),
  previewInputImport: vi.fn(),
  applyInputImport: vi.fn(),
  riskySavings: vi.fn(),
  institutionAccounts: vi.fn(),
  canWrite: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  slugify: vi.fn(),
}))

// Stránka čte předvýběr z adresy (odkaz z karty zaměstnance), takže potřebuje
// router. Originál se rozprostře, ať zůstanou i ostatní exporty (RouterLink).
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.routerReplace }),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    components: m.components,
    recurringComponents: m.recurringComponents,
    inputs: m.inputs,
    people: m.people,
    person: m.person,
    accountOptions: m.accountOptions,
    componentJmhzTargets: m.componentJmhzTargets,
    componentJmhzMappings: m.componentJmhzMappings,
    saveComponentJmhzMapping: m.saveComponentJmhzMapping,
    removeComponentJmhzMapping: m.removeComponentJmhzMapping,
    deleteComponent: m.deleteComponent,
    previewInputImport: m.previewInputImport,
    applyInputImport: m.applyInputImport,
    createComponent: m.createComponent,
    updateComponent: vi.fn(),
    createRecurringComponent: m.createRecurringComponent,
    updateRecurringComponent: vi.fn(),
    deleteRecurringComponent: m.deleteRecurringComponent,
    materializeRecurringComponents: vi.fn(),
    previewInput: m.previewInput,
    createInput: m.createInput,
    updateInput: vi.fn(),
    approveInput: vi.fn(),
    riskySavings: m.riskySavings,
    institutionAccounts: m.institutionAccounts,
  },
}))

vi.mock('@/api/payrollAbsences', () => ({
  payrollAbsenceApi: {
    context: m.absenceContext,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

vi.mock('@/api/slug', () => ({
  slugify: m.slugify,
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string) => key,
    locale: ref('cs-CZ'),
  }),
}))

// Preference tabulek jdou přes Pinii a API; v testu stačí prázdné výchozí.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import PayrollComponents from '@/pages/payroll/PayrollComponents.vue'

describe('PayrollComponents', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    // Zúžení z adresy si nese jen ten test, který ho zadá — jinak by přeteklo
    // do dalších a ty by měřily něco jiného, než co mají v názvu.
    m.routeQuery = {}
    m.canWrite.mockReturnValue(true)
    // PayrollRiskySavingsPanel se montuje uvnitř této obrazovky a načítá se sám.
    // Bez těchto dvou mocků skončí jeho `load()` v catch větvi a vyhodí toast,
    // který pak měří testy o něčem úplně jiném.
    m.riskySavings.mockResolvedValue({
      items: [],
      minimum_shift_eighths: 24,
      rate_basis_points: 400,
    })
    m.institutionAccounts.mockResolvedValue([])
    m.components.mockResolvedValue([{
      id: 5,
      supplier_id: 1,
      code: 'SYN_BONUS',
      name: 'Syntetická odměna',
      component_kind: 'bonus',
      value_kind: 'monetary',
      frequency_kind: 'one_off',
      tax_treatment: 'included',
      social_participation_treatment: 'included',
      social_treatment: 'included',
      health_participation_treatment: 'included',
      health_treatment: 'included',
      average_earning_treatment: 'included',
      enforcement_treatment: 'included',
      jmhz_treatment: 'included',
      statistics_treatment: 'included',
      accounting_debit_code: null,
      accounting_credit_code: null,
      annual_limit_minor: null,
      exemption_basket: null,
      valid_from: '2026-01-01',
      valid_to: null,
      is_active: true,
      row_version: 1,
      created_at: '2026-01-01 00:00:00',
      updated_at: '2026-01-01 00:00:00',
    }])
    m.recurringComponents.mockResolvedValue({
      recurring_components: [],
      total: 0,
      limit: 25,
      offset: 0,
    })
    // Seznam ručních vstupů se stránkuje na serveru — klient dostává stránku
    // plus celkový počet.
    m.inputs.mockResolvedValue({ total: 1, items: [{
      id: 9,
      supplier_id: 1,
      employee_id: 8,
      employee_name: 'Syntetická osoba',
      employment_id: 12,
      employment_code: 'SYN-HPP',
      relation_type: 'employment',
      component_id: 5,
      component_code: 'SYN_BONUS',
      component_name: 'Syntetická odměna',
      component_kind: 'bonus',
      value_kind: 'monetary',
      period_start: '2026-06-01',
      source_period_start: null,
      amount_minor: 25000,
      quantity_milliunits: null,
      source_kind: 'manual',
      external_id: 'synthetic-1',
      import_id: null,
      status: 'draft',
      component_snapshot_json: null,
      row_version: 1,
      created_by: 1,
      approved_by: null,
      approved_at: null,
      created_at: '2026-06-01 00:00:00',
      updated_at: '2026-06-01 00:00:00',
    }] })
    m.absenceContext.mockResolvedValue([{
      id: 12,
      employee_id: 8,
      code: 'SYN-HPP',
      relation_type: 'employment',
      status: 'active',
      full_name: 'Syntetická osoba',
    }])
    m.accountOptions.mockResolvedValue([
      {
        id: 1,
        account_code: '521',
        name: 'Mzdové náklady',
        account_type: 'expense',
        is_synthetic: false,
        parent_id: null,
        is_active: true,
      },
      {
        id: 2,
        account_code: '331',
        name: 'Zaměstnanci',
        account_type: 'liability',
        is_synthetic: false,
        parent_id: null,
        is_active: true,
      },
    ])
    m.componentJmhzTargets.mockResolvedValue({
      package_key: 'synthetic-package',
      manifest_sha256: 'a'.repeat(64),
      topology_hash: 'b'.repeat(64),
      targets: [{
        attribute_id: '10330',
        name: 'Pravidelné prémie a odměny',
        xsd_mapping: 'mzda.mzdaRozpad.odmenyPravidelne',
        data_type: 'číslo',
        monthly_marker: 'x',
        parent_attribute_id: '10328',
        ancestor_attribute_ids: ['10328'],
        aggregation_role: 'detail',
        aggregation_scope: 'employment',
      }],
    })
    m.componentJmhzMappings.mockResolvedValue([{
      component_id: 5,
      jmhz_treatment: 'included',
      status: 'missing',
      mapping: null,
    }])
    m.saveComponentJmhzMapping.mockResolvedValue({
      component_id: 5,
      jmhz_treatment: 'included',
      status: 'configured',
      mapping: {
        id: 1,
        component_definition_id: 5,
        package_key: 'synthetic-package',
        spec_manifest_sha256: 'a'.repeat(64),
        target_attribute_id: '10330',
        target_attribute_name: 'Pravidelné prémie a odměny',
        target_xsd_mapping: 'mzda.mzdaRozpad.odmenyPravidelne',
        is_active: true,
        disabled_at: null,
        row_version: 1,
        parent_attribute_id: '10328',
        ancestor_attribute_ids: ['10328'],
        aggregation_role: 'detail',
        aggregation_scope: 'employment',
        topology_hash: 'b'.repeat(64),
        is_current_package: true,
      },
    })
    m.removeComponentJmhzMapping.mockResolvedValue(undefined)
    m.deleteComponent.mockResolvedValue({ jmhz_mapping: 0 })
    m.deleteRecurringComponent.mockResolvedValue({})
    m.previewInputImport.mockResolvedValue({
      format: 'csv',
      source_name: 'synthetic.csv',
      period: '2026-06',
      content_hash: 'synthetic-hash',
      row_count: 1,
      accepted_count: 1,
      rejected_count: 0,
      duplicate_count: 0,
      rows: [],
      errors: [],
      duplicates: [],
    })
    m.applyInputImport.mockResolvedValue({
      id: 20,
      status: 'accepted',
      accepted_count: 1,
      rejected_count: 0,
      duplicate_count: 0,
      replayed: false,
      rows: [],
    })
    m.slugify.mockResolvedValue('ceska-odmena')
    m.createRecurringComponent.mockResolvedValue({})
    m.createComponent.mockResolvedValue({})
    m.createInput.mockResolvedValue({})
    m.previewInput.mockResolvedValue({
      support_status: 'supported',
      blocker: null,
      annual_limit_exceeded: false,
      annual_limit_minor: null,
      annual_used_minor: null,
      annual_after_minor: null,
      exemption_basket: null,
    })
  })

  /**
   * Zúžení z karty zaměstnance musí jít na server u OBOU seznamů — opakovaných
   * složek i mzdových vstupů. Vstupy dřív zužoval prohlížeč nad načtenou
   * stránkou, takže vztah z jiné strany se tiše neprojevil.
   */
  it('sends the narrowing to the server for both recurring components and inputs', async () => {
    m.routeQuery = { employment: '12' }
    const wrapper = mount(PayrollComponents)
    await flushPromises()

    expect(m.recurringComponents).toHaveBeenLastCalledWith(12, { limit: 25, offset: 0 })
    expect(m.inputs).toHaveBeenLastCalledWith(
      expect.any(String),
      { limit: 25, offset: 0 },
      12,
    )
    wrapper.unmount()
  })

  /**
   * Listování stránkami zúžení neztrácí — jinak se po prvním kliknutí na pager
   * seznam tiše rozšířil zpátky na celou firmu.
   */
  it('keeps the narrowing while paging', async () => {
    m.routeQuery = { employment: '12' }
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    const vm = wrapper.vm as unknown as {
      goToRecurringPage: (page: number) => void
      goToInputsPage: (page: number) => void
    }

    vm.goToRecurringPage(2)
    vm.goToInputsPage(2)
    await flushPromises()

    expect(m.recurringComponents).toHaveBeenLastCalledWith(12, { limit: 25, offset: 25 })
    expect(m.inputs).toHaveBeenLastCalledWith(
      expect.any(String),
      { limit: 25, offset: 25 },
      12,
    )
    wrapper.unmount()
  })

  /** Prázdné zúžení se pojmenuje větou, ne tichou prázdnou tabulkou. */
  it('names an empty narrowing', async () => {
    m.routeQuery = { employment: '12' }
    m.inputs.mockResolvedValue({ total: 0, items: [] })
    const wrapper = mount(PayrollComponents)
    await flushPromises()

    const notice = wrapper.find('[data-test="payroll-focus-notice"]')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('payroll.agendas.focus.missing')
    wrapper.unmount()
  })

  it('renders matching desktop tables and mobile cards from one API contract', async () => {
    const wrapper = mount(PayrollComponents)
    await flushPromises()

    expect(wrapper.get('[data-layout="desktop"]').text()).toContain('Syntetická osoba')
    expect(wrapper.get('[data-layout="mobile"]').text()).toContain('Syntetická osoba')
    expect(wrapper.get('[data-layout="mobile"]').text()).toContain('SYN_BONUS')
    wrapper.unmount()
  })

  // Nabídka vztahů se dřív skládala ze seznamu osob a detailu KAŽDÉ z nich —
  // u padesáti zaměstnanců padesát jedna požadavků při každém otevření stránky.
  // Počet požadavků na nabídku nesmí růst s počtem lidí, ať jich přijde kolik chce.
  it('builds the relation offer from one bulk call, not one request per employee', async () => {
    m.absenceContext.mockResolvedValue(Array.from({ length: 50 }, (_unused, index) => ({
      id: 100 + index,
      employee_id: 200 + index,
      code: `SYN-${index}`,
      relation_type: 'employment',
      status: 'active',
      full_name: `Syntetická osoba ${index}`,
    })))

    const wrapper = mount(PayrollComponents)
    await flushPromises()

    expect(m.absenceContext).toHaveBeenCalledTimes(1)
    expect(m.people).not.toHaveBeenCalled()
    expect(m.person).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('keeps apply disabled until the exact file has a successful dry-run', async () => {
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    const importTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.import')
    await importTab!.trigger('click')

    const fileInput = wrapper.get('[data-testid="payroll-import-file"]')
    const file = new File([
      'employment_id;employment_code;component_code;amount_minor;external_id\n'
      + '12;SYN-HPP;SYN_BONUS;25000;synthetic-1',
    ], 'synthetic.csv', { type: 'text/csv' })
    Object.defineProperty(fileInput.element, 'files', { value: [file], configurable: true })
    await fileInput.trigger('change')
    await vi.waitFor(() => {
      const previewButton = wrapper.findAll('button')
        .find(button => button.text() === 'payroll.components.import.preview')
      expect(previewButton!.attributes('disabled')).toBeUndefined()
    })

    const apply = wrapper.get('[data-testid="payroll-import-apply"]')
    expect(apply.attributes('disabled')).toBeDefined()
    await apply.trigger('click')
    expect(m.applyInputImport).not.toHaveBeenCalled()

    const preview = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.import.preview')
    await preview!.trigger('click')
    await flushPromises()
    expect(m.previewInputImport).toHaveBeenCalledTimes(1)
    const enabledApply = wrapper.get('[data-testid="payroll-import-apply"]')
    expect(enabledApply.attributes('disabled')).toBeUndefined()

    await enabledApply.trigger('click')
    await flushPromises()
    expect(m.applyInputImport).toHaveBeenCalledTimes(1)
    expect(m.applyInputImport.mock.calls[0][0]).toMatchObject({
      format: 'csv',
      source_name: 'synthetic.csv',
    })
    expect(m.applyInputImport.mock.calls[0][0].content_base64).not.toBe('')
    wrapper.unmount()
  })

  it('does not expose import controls without payroll input write permission', async () => {
    m.canWrite.mockImplementation((permission: string) => permission !== 'payroll.inputs.write')
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    const importTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.import')
    await importTab!.trigger('click')

    expect(wrapper.find('[data-testid="payroll-import-file"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="payroll-import-apply"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('accepts a supported file dropped into the import zone', async () => {
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    const importTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.import')
    await importTab!.trigger('click')

    const file = new File([
      'employment_id;component_code;amount_minor\n12;SYN_BONUS;25000',
    ], 'synthetic.xlsx', {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    })
    await wrapper.get('[data-testid="payroll-import-dropzone"]').trigger('drop', {
      dataTransfer: { files: [file] },
    })

    await vi.waitFor(() => {
      expect(wrapper.get('[data-testid="payroll-import-selected"]').attributes('title')).toBe('synthetic.xlsx')
      const previewButton = wrapper.findAll('button')
        .find(button => button.text() === 'payroll.components.import.preview')
      expect(previewButton!.attributes('disabled')).toBeUndefined()
    })

    const unsupported = new File(['unsupported'], 'synthetic.txt', { type: 'text/plain' })
    await wrapper.get('[data-testid="payroll-import-dropzone"]').trigger('drop', {
      dataTransfer: { files: [unsupported] },
    })
    expect(wrapper.find('[data-testid="payroll-import-selected"]').exists()).toBe(false)
    const previewButton = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.import.preview')
    expect(previewButton!.attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('creates the code from the name until the user edits it manually', async () => {
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    const catalogTab = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.catalog')
    await catalogTab!.trigger('click')
    const addButton = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.catalog.add')
    await addButton!.trigger('click')

    // Číselník mezd vyžaduje verzálkový kód (`^[A-Z0-9][A-Z0-9._-]{0,63}$`),
    // proto se odvozuje klientsky a synchronně — bez serverového /api/slug.
    await wrapper.get('[data-testid="payroll-component-name"]').setValue('Česká odměna')
    await flushPromises()

    expect(m.slugify).not.toHaveBeenCalled()
    const codeInput = wrapper.get('[data-testid="payroll-component-code"]')
    expect((codeInput.element as HTMLInputElement).value).toBe('CESKA_ODMENA')

    await codeInput.setValue('VLASTNI_KOD')
    await wrapper.get('[data-testid="payroll-component-name"]').setValue('Jiný název')
    await flushPromises()

    expect((codeInput.element as HTMLInputElement).value).toBe('VLASTNI_KOD')
    wrapper.unmount()
  })

  it('při kolizi s existující složkou přidá k odvozenému kódu suffix', async () => {
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.catalog')!
      .trigger('click')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.catalog.add')!
      .trigger('click')

    // V katalogu už existuje složka s kódem SYN_BONUS — tenhle název na něj
    // slugifikuje, takže se musí odlišit suffixem, ne spadnout na serveru.
    await wrapper.get('[data-testid="payroll-component-name"]').setValue('Syn bonus')
    await flushPromises()

    const codeInput = wrapper.get('[data-testid="payroll-component-code"]')
    expect((codeInput.element as HTMLInputElement).value).toBe('SYN_BONUS_2')
    wrapper.unmount()
  })

  it('uses searchable selectors including account suggestions in the catalogue editor', async () => {
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    expect(m.accountOptions).toHaveBeenCalledTimes(1)

    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.catalog')!
      .trigger('click')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.catalog.add')!
      .trigger('click')

    const editor = wrapper.get('[data-testid="payroll-component-editor"]')
    expect(editor.find('select').exists()).toBe(false)
    expect(editor.findAll('[role="combobox"]').length).toBe(15)

    const debit = editor.get('[data-testid="payroll-component-debit"]')
    await debit.get('input').trigger('focus')
    expect(wrapper.text()).toContain('Mzdové náklady')
    const credit = editor.get('[data-testid="payroll-component-credit"]')
    await credit.get('input').trigger('focus')
    expect(wrapper.text()).toContain('Zaměstnanci')
    wrapper.unmount()
  })

  it('configures an explicit JMHZ target for an included component', async () => {
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.catalog')!
      .trigger('click')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.jmhz.configure')!
      .trigger('click')

    const editor = wrapper.get('[data-testid="payroll-jmhz-mapping-editor"]')
    await editor.get('[role="combobox"]').trigger('focus')
    await wrapper.findAll('[role="option"]')
      .find(option => option.text().includes('10330'))!
      .trigger('click')
    await editor.findAll('button').find(button => button.text() === 'common.save')!.trigger('click')
    await flushPromises()

    expect(m.saveComponentJmhzMapping).toHaveBeenCalledWith(5, '10330', null)
    expect(m.toastSuccess).toHaveBeenCalledWith('payroll.components.jmhz.saved')
    wrapper.unmount()
  })

  it('smaže dosud nepoužitou mzdovou složku po potvrzení', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.catalog')!
      .trigger('click')

    await wrapper.findAll('[data-testid="payroll-component-delete"]')[0].trigger('click')
    await flushPromises()

    expect(m.deleteComponent).toHaveBeenCalledWith(5, 1)
    expect(m.toastSuccess).toHaveBeenCalledWith('payroll.components.catalog.deleted')
    expect(wrapper.text()).not.toContain('Syntetická odměna')
    wrapper.unmount()
  })

  it('smaže předpis jen přes bezpečný backendový guard', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    m.recurringComponents
      .mockResolvedValueOnce({
        recurring_components: [{
          id: 31,
          employee_id: 8,
          employment_id: 12,
          employee_name: 'Syntetická osoba',
          employment_code: 'SYN-HPP',
          component_id: 5,
          component_name: 'Syntetická odměna',
          component_code: 'SYN_BONUS',
          calculation_kind: 'fixed_amount',
          amount_minor: 25000,
          rate_basis_points: null,
          valid_from: '2026-01-01',
          valid_to: null,
          allocation_rule: 'full_month',
          maximum_amount_minor: null,
          note: null,
          is_active: true,
          row_version: 2,
        }],
        total: 1,
        limit: 25,
        offset: 0,
      })
      .mockResolvedValueOnce({
        recurring_components: [],
        total: 0,
        limit: 25,
        offset: 0,
      })
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.recurring')!
      .trigger('click')

    await wrapper.findAll('[data-testid="payroll-recurring-delete"]')[0].trigger('click')
    await flushPromises()

    expect(m.deleteRecurringComponent).toHaveBeenCalledWith(31, 2)
    expect(m.toastSuccess).toHaveBeenCalledWith('payroll.components.recurring.deleted')
    wrapper.unmount()
  })

  it('keeps the payroll catalogue usable when JMHZ configuration cannot load', async () => {
    m.componentJmhzTargets.mockRejectedValue(new Error('synthetic JMHZ failure'))
    const wrapper = mount(PayrollComponents)
    await flushPromises()

    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.catalog')!
      .trigger('click')

    expect(wrapper.text()).toContain('Syntetická odměna')
    expect(m.toastError).toHaveBeenCalledWith('synthetic JMHZ failure')
    wrapper.unmount()
  })

  it('does not keep the payroll page loading while JMHZ configuration is pending', async () => {
    m.componentJmhzTargets.mockReturnValue(new Promise(() => undefined))
    const wrapper = mount(PayrollComponents)
    await flushPromises()

    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.catalog')!
      .trigger('click')

    expect(wrapper.text()).toContain('Syntetická odměna')
    expect(wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.jmhz.configure')!
      .attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('converts percentages and ordinary quantities to API integer units', async () => {
    const regularComponent = {
      id: 6,
      supplier_id: 1,
      code: 'PRAVIDELNA',
      name: 'Pravidelná složka',
      component_kind: 'bonus',
      value_kind: 'monetary',
      frequency_kind: 'regular',
      tax_treatment: 'included',
      social_participation_treatment: 'included',
      social_treatment: 'included',
      health_participation_treatment: 'included',
      health_treatment: 'included',
      average_earning_treatment: 'included',
      enforcement_treatment: 'included',
      jmhz_treatment: 'included',
      statistics_treatment: 'included',
      accounting_debit_code: null,
      accounting_credit_code: null,
      annual_limit_minor: null,
      valid_from: '2026-01-01',
      valid_to: null,
      is_active: true,
      row_version: 1,
      created_at: '2026-01-01 00:00:00',
      updated_at: '2026-01-01 00:00:00',
    }
    m.components.mockResolvedValue([
      regularComponent,
      {
        ...regularComponent,
        id: 7,
        code: 'JEDNORAZOVA',
        name: 'Jednorázová složka',
        frequency_kind: 'one_off',
      },
    ])
    const wrapper = mount(PayrollComponents)
    await flushPromises()

    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.recurring')!
      .trigger('click')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.recurring.add')!
      .trigger('click')
    const calculation = wrapper.get('[data-testid="payroll-recurring-calculation"]')
    await calculation.get('input').trigger('focus')
    await wrapper.findAll('[role="option"]')
      .find(option => option.text() === 'payroll.components.calculation.employment_gross_basis_points')!
      .trigger('click')
    await wrapper.get('[data-testid="payroll-recurring-rate"]').setValue('12.5')
    await wrapper.findAll('button').find(button => button.text() === 'common.save')!.trigger('click')
    await flushPromises()
    expect(m.createRecurringComponent).toHaveBeenCalledWith(expect.objectContaining({
      rate_basis_points: 1250,
    }))

    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.inputs')!
      .trigger('click')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.inputs.add')!
      .trigger('click')
    await wrapper.get('[data-testid="payroll-input-amount"]').setValue('250')
    await wrapper.get('[data-testid="payroll-input-quantity"]').setValue('1.75')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.inputs.preview')!
      .trigger('click')
    await flushPromises()
    expect(m.previewInput).toHaveBeenCalledWith(expect.objectContaining({
      quantity_milliunits: 1750,
    }))
    wrapper.unmount()
  })

  it('filters monthly input employments by the selected employee', async () => {
    m.absenceContext.mockResolvedValue([
      {
        id: 12,
        employee_id: 8,
        code: 'SYN-HPP',
        relation_type: 'employment',
        status: 'active',
        full_name: 'Syntetická osoba',
      },
      {
        id: 13,
        employee_id: 9,
        code: 'SYN-DPP',
        relation_type: 'work_performance_agreement',
        status: 'active',
        full_name: 'Druhá osoba',
      },
    ])

    const wrapper = mount(PayrollComponents)
    await flushPromises()
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.inputs.add')!
      .trigger('click')

    await wrapper.get('[data-test="payroll-input-person"] input').trigger('focus')
    await wrapper.findAll('[role="option"]')
      .find(option => option.text().includes('Druhá osoba'))!
      .trigger('click')
    await wrapper.get('[data-test="payroll-input-employment"] input').trigger('focus')

    const relationships = wrapper.findAll('[role="option"]').map(option => option.text())
    expect(relationships.some(label => label.includes('SYN-DPP'))).toBe(true)
    expect(relationships.some(label => label.includes('SYN-HPP'))).toBe(false)
    wrapper.unmount()
  })

  // Roční koš osvobození je bez náhledu past: účetní zjistí překročení až
  // tehdy, když z prosincového benefitu vyskočí daň a pojistné. Náhled proto
  // musí ukázat vyčerpání koše i rozpad na osvobozenou a zdanitelnou část.
  it('shows how much of the statutory benefit basket is used and what is taxable', async () => {
    m.previewInput.mockResolvedValue({
      support_status: 'supported',
      blocker: null,
      annual_limit_exceeded: false,
      annual_limit_minor: null,
      annual_used_minor: null,
      annual_after_minor: null,
      exemption_basket: {
        basket: 'non_cash_leisure',
        statute: '§ 6 odst. 9 písm. d) bod 2 ZDP',
        limit_minor: 2448350,
        used_before_minor: 1900000,
        used_after_minor: 2900000,
        remaining_minor: 0,
        exempt_minor: 548350,
        taxable_minor: 451650,
        limit_exceeded: true,
      },
    })

    const wrapper = mount(PayrollComponents)
    await flushPromises()
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.inputs')!
      .trigger('click')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.inputs.add')!
      .trigger('click')
    await wrapper.get('[data-testid="payroll-input-amount"]').setValue('10000')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.inputs.preview')!
      .trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="payroll-input-basket"]').text())
      .toContain('payroll.components.inputs.basket_usage')
    expect(wrapper.get('[data-testid="payroll-input-basket-over"]').text())
      .toContain('payroll.components.inputs.basket_over_limit')
    wrapper.unmount()
  })

  it('shows the meal entitlement counts and mixed evidence basis', async () => {
    m.previewInput.mockResolvedValue({
      support_status: 'supported',
      blocker: null,
      annual_limit_exceeded: false,
      annual_limit_minor: null,
      annual_used_minor: 0,
      annual_after_minor: 30000,
      exemption_basket: {
        basket: 'meal_per_shift',
        statute: '§ 6 odst. 9 písm. b) ZDP',
        shift_entitlements: 3,
        limit_minor: 38850,
        used_before_minor: 0,
        used_after_minor: 30000,
        remaining_minor: 8850,
        exempt_minor: 30000,
        taxable_minor: 0,
        limit_exceeded: false,
        allocation: {
          mode: 'uniform_per_entitlement',
          entitlement_count: 3,
          amount_per_entitlement_minor: 10000,
          limit_per_entitlement_minor: 12950,
          exempt_per_entitlement_minor: 10000,
          taxable_per_entitlement_minor: 0,
        },
        entitlement: {
          period_start: '2026-06-01',
          basis: 'mixed',
          qualifying_count: 2,
          second_contribution_count: 1,
          count: 3,
          complete: true,
          missing: [],
        },
      },
    })

    const wrapper = mount(PayrollComponents)
    await flushPromises()
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.inputs')!
      .trigger('click')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.inputs.add')!
      .trigger('click')
    await wrapper.get('[data-testid="payroll-input-amount"]').setValue('300')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.inputs.preview')!
      .trigger('click')
    await flushPromises()

    const entitlement = wrapper.get('[data-testid="payroll-input-meal-entitlement"]')
    expect(entitlement.text()).toContain('payroll.components.inputs.meal_entitlement_summary')
    expect(entitlement.attributes('data-basis')).toBe('mixed')
    expect(entitlement.text()).toContain('payroll.components.inputs.meal_evidence_complete')
    expect(wrapper.get('[data-testid="payroll-input-meal-allocation"]').text())
      .toContain('payroll.components.inputs.meal_allocation_uniform')
    wrapper.unmount()
  })

  it('warns with a translated reason when meal evidence is incomplete', async () => {
    m.previewInput.mockResolvedValue({
      support_status: 'manual_review',
      blocker: 'Chybí úplný podklad: calendar_day_break_allocation_missing.',
      annual_limit_exceeded: false,
      annual_limit_minor: null,
      annual_used_minor: 0,
      annual_after_minor: 10000,
      exemption_basket: null,
      meal_entitlement: {
        period_start: '2026-06-01',
        basis: 'calendar_day',
        qualifying_count: 0,
        second_contribution_count: 0,
        count: 0,
        complete: false,
        missing: [
          'attendance_month_open',
          'calendar_day_break_allocation_missing',
        ],
      },
    })

    const wrapper = mount(PayrollComponents)
    await flushPromises()
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.inputs')!
      .trigger('click')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.inputs.add')!
      .trigger('click')
    await wrapper.get('[data-testid="payroll-input-amount"]').setValue('100')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.inputs.preview')!
      .trigger('click')
    await flushPromises()

    const entitlement = wrapper.get('[data-testid="payroll-input-meal-entitlement"]')
    expect(entitlement.text()).toContain('payroll.components.inputs.meal_evidence_incomplete')
    expect(entitlement.text()).toContain(
      'payroll.components.inputs.meal_missing.attendance_month_open',
    )
    expect(entitlement.text()).toContain(
      'payroll.components.inputs.meal_missing.calendar_day_break_allocation_missing',
    )
    expect(wrapper.get('[data-testid="payroll-input-preview"]').classes())
      .toContain('border-warning-500/40')
    wrapper.unmount()
  })

  it('shows the exact API validation error inside the active editor', async () => {
    m.createComponent.mockRejectedValue({
      response: {
        data: {
          error: {
            message: 'Složku nelze uložit.',
            fields: {
              accounting_debit_code: ['Účet 521 není aktivní.'],
            },
          },
        },
      },
    })
    const wrapper = mount(PayrollComponents)
    await flushPromises()
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.tabs.catalog')!
      .trigger('click')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.components.catalog.add')!
      .trigger('click')
    await wrapper.get('[data-testid="payroll-component-name"]').setValue('Syntetická složka')
    await wrapper.get('[data-testid="payroll-component-code"]').setValue('SYN_SLOZKA')
    await wrapper.findAll('button').find(button => button.text() === 'common.save')!.trigger('click')
    await flushPromises()

    expect(m.createComponent).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[role="alert"]').text())
      .toBe('Složku nelze uložit.: Účet 521 není aktivní.')
    expect(m.toastError).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
