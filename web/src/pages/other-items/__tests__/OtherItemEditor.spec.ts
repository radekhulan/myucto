import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  update: vi.fn(),
  routerPush: vi.fn(),
  routerReplace: vi.fn(),
  listAccounts: vi.fn(),
  listKinds: vi.fn(),
  saveProfiles: vi.fn(),
  dimsSave: vi.fn(),
  dimsLoad: vi.fn(),
  toastError: vi.fn(),
  toastInfo: vi.fn(),
  auth: { isDemo: false, documents: true } as { isDemo: boolean; documents: boolean; move?: boolean },
  params: { id: '42' } as Record<string, string>,
  supplier: { accounting_mode: 'tax_evidence' },
}))

vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: m.listAccounts } }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value) }))

vi.mock('@/api/otherItems', () => ({
  otherItemsApi: { get: m.get, update: m.update, create: vi.fn() },
  otherItemKindsApi: { list: m.listKinds, savePostingProfiles: m.saveProfiles },
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: m.supplier }),
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: (key: string) => key !== 'documents.move' || m.auth.move !== false,
  canRead: (key: string) => key !== 'documents' || m.auth.documents, get isDemo() { return m.auth.isDemo } }) }))
vi.mock('@/api/otherItemSettings', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/otherItemSettings')>()),
  otherItemSettingsApi: { get: vi.fn().mockResolvedValue({ document_folder: 'Smlouvy/{YYYY}', document_folder_default: 'Ostatní pohledávky a závazky/{YYYY}/{MM}', is_default: false }), update: vi.fn() },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: m.toastError, success: vi.fn(), info: m.toastInfo }),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: m.params, query: {} }),
  useRouter: () => ({ push: m.routerPush, replace: m.routerReplace }),
  RouterLink: { template: '<a><slot /></a>' },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

vi.mock('@/composables/useDocumentDimensions', async () => {
  const { ref } = await import('vue')
  return {
    useDocumentDimensions: () => ({
      enabled: ref(false), canEdit: ref(false), header: ref({}), hasAutoFilled: ref(false),
      load: m.dimsLoad, save: m.dimsSave, watchDefaults: vi.fn(),
    }),
  }
})

import OtherItemEditor from '../OtherItemEditor.vue'

describe('OtherItemEditor partner', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.supplier.accounting_mode = 'tax_evidence'
    m.auth = { isDemo: false, documents: true }
    m.params = { id: '42' }
    m.get.mockResolvedValue({
      id: 42,
      status: 'draft',
      side: 'payable',
      kind: 'rent',
      title: 'Syntetický nájem',
      partner_id: 17,
      partner_name: 'Syntetický dodavatel',
      issued_on: '2099-01-01',
      accounting_on: '2099-01-01',
      due_on: '2099-01-20',
      currency: 'CZK',
      exchange_rate: null,
      amount: 1200,
      variable_symbol: null,
      account_code: null,
      counter_account_code: null,
      note: null,
    })
    m.update.mockResolvedValue({ id: 42 })
    m.routerPush.mockResolvedValue(undefined)
    m.listKinds.mockResolvedValue([])
  })

  it('při uložení konceptu zachová vazbu na načteného partnera', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    await wrapper.get('input[maxlength="255"]').setValue('Upravený nájem')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({
      partner_id: 17,
      partner_name: 'Syntetický dodavatel',
      title: 'Upravený nájem',
    }))
  })

  it('načte a po uložení dokladu uloží dimenze hlavičky', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    expect(m.dimsLoad).toHaveBeenCalledWith(42)
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.dimsSave).toHaveBeenCalledWith(42)
    expect(m.update.mock.invocationCallOrder[0]).toBeLessThan(m.dimsSave.mock.invocationCallOrder[0])
  })

  it('při výslovném vymazání protistrany odstraní i vazbu', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    await wrapper.get('input[maxlength="190"]').setValue('')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({
      partner_id: null,
      partner_name: null,
    }))
  })

  it('při ruční změně názvu protistrany odstraní vazbu na původního klienta', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    await wrapper.get('input[maxlength="190"]').setValue('Jiná protistrana')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({
      partner_id: null,
      partner_name: 'Jiná protistrana',
    }))
  })

  it('výběr protistrany z adresáře uloží její identifikátor i název', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const picker = wrapper.getComponent({ name: 'ClientSearchSelect' })
    picker.vm.$emit('update:modelValue', 23)
    picker.vm.$emit('selected', { id: 23, company_name: 'Syntetický klient' })
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({
      partner_id: 23,
      partner_name: 'Syntetický klient',
    }))
  })

  it('při změně směru přepne význam druhu a vrátí jej na obecnou položku', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const kind = wrapper.findAll('select')[1]!
    expect(kind.find('option[value="loan"]').text()).toBe('other_items.kind_by_side.payable.loan')
    // Vyplněný druh by změna strany zahodila: bez souhlasu zůstane strana i druh.
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false)
    await wrapper.findAll('select')[0]!.setValue('receivable')
    expect(confirm).toHaveBeenCalledWith('other_items.side_change_confirm')
    expect((wrapper.findAll('select')[0]!.element as HTMLSelectElement).value).toBe('payable')
    expect((kind.element as HTMLSelectElement).value).toBe('rent')

    confirm.mockReturnValueOnce(true)
    await wrapper.findAll('select')[0]!.setValue('receivable')
    expect(kind.find('option[value="loan"]').text()).toBe('other_items.kind_by_side.receivable.loan')
    expect((kind.element as HTMLSelectElement).value).toBe('other')
    confirm.mockRestore()
  })

  it('obecnou položku bez účtů přepne na druhou stranu bez ptaní', async () => {
    m.get.mockResolvedValue({ ...(await m.get()), kind: 'other' })
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const confirm = vi.spyOn(window, 'confirm')
    await wrapper.findAll('select')[0]!.setValue('receivable')
    expect(confirm).not.toHaveBeenCalled()
    expect((wrapper.findAll('select')[0]!.element as HTMLSelectElement).value).toBe('receivable')
    confirm.mockRestore()
  })

  it('chybějící údaje ukáže u polí a trvale nad formulářem, opravou chyba pole zmizí', async () => {
    const wrapper = shallowMount(OtherItemEditor, { global: { stubs: { FieldNote: { props: ['error'], template: '<span class="field-note">{{ error }}</span>' } } } })
    await flushPromises()
    await wrapper.get('[data-test="editor-title"]').setValue('  ')
    await wrapper.get('[data-test="editor-amount"]').setValue('0')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="form-error"]').attributes('role')).toBe('alert')
    expect(wrapper.get('[data-test="form-error"]').text()).toBe('other_items.errors.form_invalid')
    const notes = () => wrapper.findAll('.field-note').map(note => note.text()).filter(Boolean)
    expect(notes()).toEqual(['other_items.errors.required', 'other_items.errors.amount_positive'])
    await wrapper.get('[data-test="editor-title"]').setValue('Opraveno')
    expect(notes()).toEqual(['other_items.errors.amount_positive'])
  })

  it('odmítnutí serverem zůstane jako hláška nad formulářem a Ctrl+S uloží', async () => {
    m.update.mockRejectedValueOnce({ response: { data: { error: { message: 'Období je uzavřené.' } } } })
    const wrapper = shallowMount(OtherItemEditor, { attachTo: document.body })
    await flushPromises()
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 's', ctrlKey: true }))
    await flushPromises()
    expect(m.update).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-test="form-error"]').text()).toBe('Období je uzavřené.')
    wrapper.unmount()
  })

  it('nabídne vlastní druhy své strany a uloží je jako kind_id', async () => {
    m.listKinds.mockResolvedValue([
      { id: 5, side: 'payable', builtin: false, builtin_code: null, name: 'Syntetické nájemné', own_series: true, is_active: true },
      { id: 6, side: 'receivable', builtin: false, builtin_code: null, name: 'Syntetický nárok', own_series: false, is_active: true },
      { id: 7, side: 'payable', builtin: false, builtin_code: null, name: 'Vyřazený druh', own_series: false, is_active: false },
    ])
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const kind = wrapper.findAll('select')[1]!
    expect(kind.findAll('optgroup option').map(option => option.text())).toEqual(['Syntetické nájemné'])
    await kind.setValue('custom:5')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({ kind: 'other', kind_id: 5 }))
  })

  it('koncept si ponechá deaktivovaný vlastní druh a vestavěný druh posílá bez kind_id', async () => {
    m.listKinds.mockResolvedValue([
      { id: 7, side: 'payable', builtin: false, builtin_code: null, name: 'Vyřazený druh', own_series: false, is_active: false },
    ])
    m.get.mockResolvedValue({ ...(await m.get()), kind: 'other', kind_id: '7' })
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const kind = wrapper.findAll('select')[1]!
    expect((kind.element as HTMLSelectElement).value).toBe('custom:7')
    await kind.setValue('loan')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({ kind: 'loan', kind_id: null }))
  })

  it('skrytý vestavěný druh nenabízí, koncept si ho ale ponechá', async () => {
    m.listKinds.mockResolvedValue([
      { id: 3, side: 'payable', builtin: true, builtin_code: 'loan', name: null, own_series: false, is_active: false },
      { id: 4, side: 'payable', builtin: true, builtin_code: 'rent', name: null, own_series: true, is_active: false },
      { id: 8, side: 'receivable', builtin: true, builtin_code: 'fee', name: null, own_series: false, is_active: false },
    ])
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const kind = wrapper.findAll('select')[1]!
    const values = kind.findAll('option').map(option => option.attributes('value'))
    expect((kind.element as HTMLSelectElement).value).toBe('rent')
    expect(values).toContain('rent')
    expect(values).not.toContain('loan')
    // Skrytí platí jen pro svou stranu.
    expect(values).toContain('fee')
  })

  it('v podvojném účetnictví nabízí našeptávač zvlášť pro rozvahový účet a protiúčet', async () => {
    m.supplier.accounting_mode = 'double_entry'
    m.listAccounts.mockResolvedValue([
      { id: 1, account_code: '311.100', name: 'Pohledávka', account_type: 'asset', is_active: true },
      { id: 2, account_code: '325.100', name: 'Závazek', account_type: 'liability', is_active: true },
      { id: 3, account_code: '648', name: 'Výnos', account_type: 'revenue', is_active: true },
    ])
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const pickers = wrapper.findAllComponents({ name: 'ChartAccountSelect' })
    expect(pickers).toHaveLength(2)
    expect(pickers[0]!.props('accounts').map((account: { account_code: string }) => account.account_code)).toEqual(['325.100'])
    expect(pickers[1]!.props('accounts')).toHaveLength(3)
  })

  it('načte účet protistrany jako číslo/kód banky a odešle ho bez samostatného kódu', async () => {
    m.get.mockResolvedValue({ ...(await m.get()), counterparty_account: '19-1000000005', counterparty_bank_code: '0100' })
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const account = wrapper.get('[data-test="counterparty-account"]')
    expect((account.element as HTMLInputElement).value).toBe('19-1000000005/0100')
    await account.setValue('CZ18 0100 0000 0010 0000 0005')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({
      counterparty_account: 'CZ18 0100 0000 0010 0000 0005',
      counterparty_bank_code: null,
    }))
  })

  it('účet protistrany v neplatném tvaru neodešle', async () => {
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    await wrapper.get('[data-test="counterparty-account"]').setValue('1000000005')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).not.toHaveBeenCalled()
    expect(wrapper.findAllComponents({ name: 'FieldNote' }).map(note => note.props('error')).filter(Boolean))
      .toEqual(['other_items.counterparty_account_invalid'])
  })

  it('prázdný účet protistrany pošle jako null', async () => {
    m.get.mockResolvedValue({ ...(await m.get()), counterparty_account: '1000000005', counterparty_bank_code: '0100' })
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    await wrapper.get('[data-test="counterparty-account"]').setValue('  ')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({ counterparty_account: null, counterparty_bank_code: null }))
  })

  it('při úpravě zachová rozdělenou kontaci a odešle oba protiřádky', async () => {
    m.supplier.accounting_mode = 'double_entry'
    m.listAccounts.mockResolvedValue([
      { id: 1, account_code: '325', name: 'Závazky', account_type: 'liability', is_active: true },
      { id: 2, account_code: '518', name: 'Náklady', account_type: 'expense', is_active: true },
      { id: 3, account_code: '378', name: 'Jiné pohledávky', account_type: 'asset', is_active: true },
    ])
    m.get.mockResolvedValue({
      id: 42, status: 'draft', side: 'payable', kind: 'rent', title: 'Syntetický nájem',
      partner_id: null, partner_name: null, issued_on: '2099-01-01', accounting_on: '2099-01-01',
      due_on: '2099-01-20', currency: 'CZK', amount: 1200, variable_symbol: null,
      account_code: '325', counter_account_code: null, note: null,
      posting_lines: [{ account_code: '518', amount: 700 }, { account_code: '378', amount: 500 }],
    })
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    const lines = wrapper.getComponent({ name: 'OtherItemPostingLines' })
    expect(lines.props('modelValue')).toEqual([
      { account_code: '518', amount: 700 }, { account_code: '378', amount: 500 },
    ])
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalledWith(42, expect.objectContaining({
      counter_account_code: null,
      posting_lines: [{ account_code: '518', amount: 700 }, { account_code: '378', amount: 500 }],
    }))
  })

  it('nabídne návrh účtů druhu bez profilu a uloží ho jako výchozí jen po kliknutí', async () => {
    m.supplier.accounting_mode = 'double_entry'
    m.listAccounts.mockResolvedValue([])
    const suggested = { id: null, side: 'payable', builtin: true, builtin_code: 'rent', name: null, is_active: true,
      posting_profiles: {}, posting_suggestion: { debit_account_code: '381', credit_account_code: '325', available: true } }
    m.listKinds.mockResolvedValue([suggested])
    m.saveProfiles.mockResolvedValue({})
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    expect(wrapper.get('[data-test="origin-suggestion"]').text()).toContain('other_items.posting.suggested_for_kind')

    await wrapper.get('[data-test="origin-suggestion-use"]').trigger('click')
    expect(wrapper.find('[data-test="origin-suggestion"]').exists()).toBe(false)
    const pickers = wrapper.findAllComponents({ name: 'ChartAccountSelect' })
    expect(pickers[0]!.props('modelValue')).toBe('325')
    expect(pickers[1]!.props('modelValue')).toBe('381')
    expect(m.saveProfiles).not.toHaveBeenCalled()
  })

  it('návrh uloží jako profil vzniku druhu a znovu načte druhy', async () => {
    m.supplier.accounting_mode = 'double_entry'
    m.listAccounts.mockResolvedValue([])
    m.listKinds.mockResolvedValue([{ id: null, side: 'payable', builtin: true, builtin_code: 'rent', name: null, is_active: true,
      posting_profiles: {}, posting_suggestion: { debit_account_code: '381', credit_account_code: '325', available: true } }])
    m.saveProfiles.mockResolvedValue({})
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    await wrapper.get('[data-test="origin-suggestion-save"]').trigger('click')
    await flushPromises()
    expect(m.saveProfiles).toHaveBeenCalledWith(expect.objectContaining({ builtin_code: 'rent' }), {
      origin: { debit_account_code: '381', credit_account_code: '325', description_template: null, dimensions: [], is_active: true },
    })
    expect(m.listKinds).toHaveBeenCalledTimes(2)
  })

  it('nenabízí návrh, když není použitelný nebo je v daňové evidenci', async () => {
    m.supplier.accounting_mode = 'double_entry'
    m.listAccounts.mockResolvedValue([])
    m.listKinds.mockResolvedValue([{ id: null, side: 'payable', builtin: true, builtin_code: 'rent', name: null, is_active: true,
      posting_profiles: {}, posting_suggestion: { debit_account_code: '381', credit_account_code: '325', available: false } }])
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    expect(wrapper.find('[data-test="origin-suggestion"]').exists()).toBe(false)
  })
})

describe('OtherItemEditor chyby a pojistky', () => {
  const FieldNoteStub = { props: ['error'], template: '<span class="field-note">{{ error }}</span>' }
  const PostingLinesStub = { name: 'OtherItemPostingLines', props: ['modelValue'], template: '<div class="posting-lines" />' }
  const draft = {
    id: 42, status: 'draft', side: 'payable', kind: 'other', title: 'Syntetický závazek', partner_id: null, partner_name: '',
    issued_on: '2099-01-01', accounting_on: '2099-01-01', due_on: '2099-01-20', currency: 'CZK', exchange_rate: null,
    amount: 1200, variable_symbol: null, counterparty_account: null, counterparty_bank_code: null,
    account_code: null, counter_account_code: null, posting_lines: [], note: null,
  }

  beforeEach(() => {
    vi.clearAllMocks()
    m.supplier.accounting_mode = 'tax_evidence'
    m.auth = { isDemo: false, documents: true }
    m.params = { id: '42' }
    m.get.mockResolvedValue({ ...draft })
    m.update.mockResolvedValue({ ...draft })
    m.listKinds.mockResolvedValue([])
    m.listAccounts.mockResolvedValue([])
    m.dimsLoad.mockResolvedValue(undefined)
    m.dimsSave.mockResolvedValue(undefined)
    m.routerPush.mockResolvedValue(undefined)
  })

  const notes = (wrapper: ReturnType<typeof shallowMount>) => wrapper.findAll('.field-note').map(note => note.text()).filter(Boolean)

  it('souhrn nad formulářem zmizí, když je opravené poslední chybné pole', async () => {
    const wrapper = shallowMount(OtherItemEditor, { global: { stubs: { FieldNote: FieldNoteStub } } })
    await flushPromises()
    await wrapper.get('[data-test="editor-title"]').setValue('')
    await wrapper.get('[data-test="editor-amount"]').setValue('0')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[data-test="form-error"]').exists()).toBe(true)
    await wrapper.get('[data-test="editor-title"]').setValue('Opraveno')
    expect(wrapper.find('[data-test="form-error"]').exists()).toBe(true)
    await wrapper.get('[data-test="editor-amount"]').setValue('100')
    expect(wrapper.find('[data-test="form-error"]').exists()).toBe(false)
  })

  it('chybějící datum zvýrazní i samotné pole data', async () => {
    const wrapper = shallowMount(OtherItemEditor, { global: { stubs: { FieldNote: FieldNoteStub } } })
    await flushPromises()
    wrapper.findAllComponents({ name: 'DateInput' }).find(input => input.attributes('data-test') === 'editor-due-on')!.vm.$emit('update:modelValue', '')
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const due = wrapper.get('[data-test="editor-due-on"]')
    expect(due.attributes('aria-invalid')).toBe('true')
    expect(due.classes()).toContain('border-danger-500')
    expect(wrapper.get('[data-test="editor-issued-on"]').attributes('aria-invalid')).toBe('false')
  })

  it('nápovědu k dokumentům ukáže jen v novém konceptu s právem k dokumentům', async () => {
    let wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    expect(wrapper.find('[data-test="form-hint"]').exists()).toBe(false)

    m.params = {}
    wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    expect(wrapper.find('[data-test="form-hint"]').exists()).toBe(true)

    m.auth.documents = false
    wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    expect(wrapper.find('[data-test="form-hint"]').exists()).toBe(false)
  })

  it('variabilní symbol s písmenem odmítne u pole a neodešle', async () => {
    const wrapper = shallowMount(OtherItemEditor, { global: { stubs: { FieldNote: FieldNoteStub } } })
    await flushPromises()
    await wrapper.get('[data-test="editor-vs"]').setValue('12A')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.update).not.toHaveBeenCalled()
    expect(notes(wrapper)).toEqual(['other_items.errors.variable_symbol_digits'])
  })

  it('nesedící rozpad ukáže u rozpadu a oprava částky chybu zruší', async () => {
    m.supplier.accounting_mode = 'double_entry'
    m.get.mockResolvedValue({ ...draft, posting_lines: [{ account_code: '518', amount: 700 }, { account_code: '548', amount: 400 }] })
    const wrapper = shallowMount(OtherItemEditor, { global: { stubs: { FieldNote: FieldNoteStub, OtherItemPostingLines: PostingLinesStub } } })
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.update).not.toHaveBeenCalled()
    expect(notes(wrapper)).toEqual(['other_items.posting_lines_invalid'])
    await wrapper.get('[data-test="editor-amount"]').setValue('1100')
    expect(notes(wrapper)).toEqual([])
  })

  it.each([
    ['vyplněný účet položky', { account_code: '378' }],
    ['vyplněný protiúčet', { counter_account_code: '648' }],
    ['rozpad na protiúčty', { posting_lines: [{ account_code: '518', amount: 700 }, { account_code: '548', amount: 500 }] }],
  ])('před změnou strany se zeptá, když má obecná položka %s', async (_label, changes) => {
    m.supplier.accounting_mode = 'double_entry'
    m.get.mockResolvedValue({ ...draft, ...changes })
    const wrapper = shallowMount(OtherItemEditor, { global: { stubs: { OtherItemPostingLines: PostingLinesStub } } })
    await flushPromises()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false)
    await wrapper.get('[data-test="editor-side"]').setValue('receivable')
    expect(confirm).toHaveBeenCalledWith('other_items.side_change_confirm')
    expect((wrapper.get('[data-test="editor-side"]').element as HTMLSelectElement).value).toBe('payable')
    confirm.mockRestore()
  })

  it('v demu položku neuloží', async () => {
    m.auth.isDemo = true
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.update).not.toHaveBeenCalled()
    expect(m.toastInfo).toHaveBeenCalledWith('demo.read_only')
  })
})

describe('OtherItemEditor selhané načtení úpravy', () => {
  it('bez načteného konceptu nenabídne formulář, jen opakování', async () => {
    vi.clearAllMocks()
    m.supplier.accounting_mode = 'tax_evidence'
    m.auth = { isDemo: false, documents: true }
    m.params = { id: '42' }
    m.listKinds.mockResolvedValue([])
    m.dimsLoad.mockResolvedValue(undefined)
    m.get.mockRejectedValueOnce({ response: { status: 500, data: { error: { message: 'Výpadek' } } } })
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.findComponent({ name: 'EmptyState' }).props('variant')).toBe('failed')
    expect(m.update).not.toHaveBeenCalled()

    m.get.mockResolvedValueOnce({ id: 42, status: 'draft', side: 'payable', kind: 'other', title: 'Načteno', issued_on: '2099-01-01',
      due_on: '2099-01-20', amount: 10, posting_lines: [] })
    wrapper.findComponent({ name: 'EmptyState' }).vm.$emit('action')
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(true)
  })
})

describe('OtherItemEditor nápověda k dokumentům', () => {
  it('bez práva připojovat dokumenty nápovědu neukáže', async () => {
    vi.clearAllMocks()
    m.supplier.accounting_mode = 'tax_evidence'
    m.auth = { isDemo: false, documents: true, move: false }
    m.params = {}
    m.listKinds.mockResolvedValue([])
    const wrapper = shallowMount(OtherItemEditor)
    await flushPromises()
    expect(wrapper.find('[data-test="form-hint"]').exists()).toBe(false)
  })
})
