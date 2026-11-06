import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  invoices: {} as Record<number, Record<string, unknown>>,
  getDocument: vi.fn(),
  prefill: vi.fn(),
  saveDocument: vi.fn(),
  setExpenseKinds: vi.fn(),
  expenseSuggestions: vi.fn(),
  listAccounts: vi.fn(),
  vatClassifications: vi.fn(),
  dismiss: vi.fn(),
  toastWarning: vi.fn(),
  requiresApproval: false,
  canTransition: true,
  types: () => [{ id: 1, name: 'Středisko', is_active: true, show_on_documents: true, requires_approval: m.requiresApproval }],
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ RouterLink: { props: ['to'], template: '<a><slot /></a>' } }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: m.toastWarning }),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isClientRole: false, canWrite: () => m.canTransition }) }))
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: true },
    canEdit: { value: true },
    documentTypes: { get value() { return m.types() } },
    types: { get value() { return m.types() } },
    values: { value: [] },
    typeById: { value: new Map([[1, { id: 1, name: 'Středisko' }]]) },
    valueLabel: (id: number) => `hodnota-${id}`,
    load: () => Promise.resolve(),
  }),
}))
vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: {
    get: (id: number) => Promise.resolve(m.invoices[id]),
    setExpenseKinds: (...args: unknown[]) => m.setExpenseKinds(...args),
    expenseSuggestions: (...args: unknown[]) => m.expenseSuggestions(...args),
    dismissExtractionWarning: (...args: unknown[]) => m.dismiss(...args),
  },
}))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: () => m.listAccounts() } }))
vi.mock('@/api/vatClassifications', () => ({ vatClassificationsApi: { list: (...args: unknown[]) => m.vatClassifications(...args) } }))
vi.mock('@/api/dimensions', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/dimensions')>()),
  dimensionsApi: {
    getDocument: (...args: unknown[]) => m.getDocument(...args),
    prefill: (...args: unknown[]) => m.prefill(...args),
    saveDocument: (...args: unknown[]) => m.saveDocument(...args),
  },
}))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => ({ value: {} }),
  patchPagePrefs: vi.fn(),
}))
vi.mock('@/components/ui/Modal.vue',() => ({ default: { template: '<div><slot /><slot name="footer" /></div>' } }))
vi.mock('@/components/purchase/ExtractionWarningText.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/dimensions/DimensionChips.vue', () => ({ default: { props: ['dimensions', 'splits'], template: '<span class="split-chips" />' } }))
vi.mock('@/components/dimensions/ItemDimensionsToggle.vue', () => ({ default: { template: '<button class="item-toggle" />' } }))
vi.mock('@/components/dimensions/DimensionFields.vue', () => ({
  default: { name: 'DimensionFields', props: ['modelValue', 'disabled', 'compact', 'types'], emits: ['update:modelValue'], template: '<div class="fields" />' },
}))

import ExtractionReviewModal from '@/components/purchase/ExtractionReviewModal.vue'

function invoice(id: number, overrides: Record<string, unknown> = {}) {
  return {
    id,
    status: 'draft',
    vendor_id: 30,
    project_id: null,
    vendor_company_name: 'Syntetický dodavatel',
    vendor_invoice_number: `FV-${id}`,
    varsymbol: null,
    issue_date: '2096-06-15',
    total_with_vat: 1210,
    currency: 'CZK',
    extraction_warning: null,
    extraction_review: null,
    review: { reasons: [], details: {} },
    items: [{ id: 100 + id, order_index: 0, description: 'Položka', quantity: 1, unit_price_without_vat: 1000, total_without_vat: 1000, expense_kind: null }],
    locked: null,
    ...overrides,
  }
}

const missingCenter = {
  reasons: ['missing_required_dimension'],
  details: { missing_required_dimension: { missing_dimensions: [{ type_id: 1, type_name: 'Středisko', account_codes: ['518'] }] } },
}

async function mountModal(ids: number[], onlyFlagged = true) {
  const wrapper = mount(ExtractionReviewModal, { props: { invoiceIds: ids, onlyFlagged } })
  await flushPromises()
  return wrapper
}

function saveButton(wrapper: Awaited<ReturnType<typeof mountModal>>) {
  return wrapper.findAll('button').find(b => b.text().includes('purchase_invoice.extraction_review.save_'))!
}

describe('ExtractionReviewModal — dimenze', () => {
  beforeEach(() => {
    m.invoices = {}
    m.getDocument.mockReset().mockResolvedValue({ header: {}, items: {} })
    m.prefill.mockReset().mockResolvedValue({ header: { 1: 7 }, sources: { 1: 'history' } })
    m.saveDocument.mockReset().mockResolvedValue({ header: { 1: 7 }, items: {}, restamp: { lines: 0, needs_repost: false } })
    m.setExpenseKinds.mockReset()
    m.expenseSuggestions.mockReset().mockResolvedValue({ items: {} })
    m.listAccounts.mockReset().mockResolvedValue([])
    m.vatClassifications.mockReset().mockResolvedValue([
      { id: 1, code: '23', label: 'Pořízení zboží z EU', supplier_id: null },
      { id: 2, code: '24e', label: 'Přijetí služby z EU', supplier_id: null },
    ])
    m.dismiss.mockReset().mockImplementation((id: number) => Promise.resolve({ ...m.invoices[id], extraction_warning: null }))
    m.toastWarning.mockReset()
    m.requiresApproval = false
    m.canTransition = true
  })

  it('potvrzení úhrady nabídne jen s právem ke změně stavu', async () => {
    m.canTransition = false
    m.invoices = { 1: invoice(1, { extraction_warning: 'Doklad je uhrazený', extraction_review: { paid_per_document: true } }) }
    const wrapper = await mountModal([1], false)
    expect(wrapper.findAll('button').some(b => b.text().includes('purchase_invoice.extraction_review.confirm_paid'))).toBe(false)
    wrapper.unmount()
    m.canTransition = true
    const allowed = await mountModal([1], false)
    expect(allowed.findAll('button').some(b => b.text().includes('purchase_invoice.extraction_review.confirm_paid'))).toBe(true)
    allowed.unmount()
  })

  it('nabídne a uloží účet šablony i při stejném druhu nákladu až po potvrzení', async () => {
    m.invoices = { 2: invoice(2, {
      extraction_warning: 'Rozdíl součtů',
      review: { reasons: ['extraction_warning'], details: {} },
      items: [{ id: 102, order_index: 0, description: 'Hosting', quantity: 1, unit_price_without_vat: 4000, expense_kind: 'service', expense_account_code: null }],
    }) }
    m.expenseSuggestions.mockResolvedValue({ items: { 102: {
      expense_kind: 'service', expense_account_code: '518.200', confidence: 1,
      reason: 'Pravidlo hosting', source: 'rule', auto: false, current_expense_kind: 'service',
    } } })
    m.setExpenseKinds.mockResolvedValue(m.invoices[2])
    const wrapper = await mountModal([2])

    expect(m.expenseSuggestions).toHaveBeenCalledWith(2)
    expect(m.setExpenseKinds).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('518.200')
    const apply = wrapper.findAll('button').find(b => b.text() === 'purchase_invoice.extraction_review.apply')!
    await apply.trigger('click')
    expect(m.setExpenseKinds).not.toHaveBeenCalled()
    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(m.setExpenseKinds).toHaveBeenCalledWith(2, [{ id: 102, expense_kind: 'service', expense_account_code: '518.200' }])
  })

  it('u přenesené povinnosti uloží změnu členění DPH jen u změněné položky (issue #150)', async () => {
    m.invoices = { 2: invoice(2, {
      reverse_charge: true,
      items: [
        { id: 102, order_index: 0, description: 'Kampaň', quantity: 1, unit_price_without_vat: 1000, expense_kind: 'service', vat_classification_code: '23' },
        { id: 103, order_index: 1, description: 'Krabice', quantity: 1, unit_price_without_vat: 100, expense_kind: 'material', vat_classification_code: '23' },
      ],
    }) }
    m.setExpenseKinds.mockResolvedValue(m.invoices[2])
    const wrapper = await mountModal([2], false)

    const selects = wrapper.findAll('[data-test="review-item-vat-classification"]')
    expect(selects).toHaveLength(2)
    await selects[0].setValue('24e')
    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(m.setExpenseKinds).toHaveBeenCalledWith(2, [{ id: 102, expense_kind: 'service', vat_classification_code: '24e' }])
  })

  it('změna druhu nákladu u samovyměření přepne kód služby na zboží a zpět', async () => {
    m.invoices = { 2: invoice(2, {
      reverse_charge: true,
      items: [{ id: 102, order_index: 0, description: 'Klávesnice', quantity: 1, unit_price_without_vat: 1000, expense_kind: 'service', vat_classification_code: '24e' }],
    }) }
    m.setExpenseKinds.mockResolvedValue(m.invoices[2])
    const wrapper = await mountModal([2], false)

    const kind = wrapper.get('[data-test="review-item-expense-kind"]')
    const vat = wrapper.get('[data-test="review-item-vat-classification"]')
    await kind.setValue('small_asset')
    expect((vat.element as HTMLSelectElement).value).toBe('23')
    await kind.setValue('small_intangible')
    expect((vat.element as HTMLSelectElement).value).toBe('24e')
    await kind.setValue('material')
    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(m.setExpenseKinds).toHaveBeenCalledWith(2, [{ id: 102, expense_kind: 'material', vat_classification_code: '23' }])
  })

  it('bez přenesené povinnosti členění DPH nenabízí', async () => {
    m.invoices = { 2: invoice(2) }
    const wrapper = await mountModal([2], false)
    expect(wrapper.find('[data-test="review-item-vat-classification"]').exists()).toBe(false)
  })

  it('schvalování dimenze kontrolu nevynucuje: koncept bez hlášení a bez střediska se nepřidá', async () => {
    m.requiresApproval = true
    m.invoices = { 1: invoice(1) }
    const wrapper = await mountModal([1])

    expect(wrapper.text()).not.toContain('FV-1')
    expect(wrapper.find('[data-test="review-approval-missing"]').exists()).toBe(false)
  })

  it('ruční kontrola otevře doklad bez hlášení a uloží změnu analytiky', async () => {
    m.getDocument.mockResolvedValue({ header: { 1: 7 }, items: {} })
    m.listAccounts.mockResolvedValue([
      { id: 9, account_code: '518.100', name: 'Služby', is_active: true, parent_id: null },
      { id: 10, account_code: '518.200', name: 'Hosting', is_active: true, parent_id: null },
    ])
    m.invoices = { 2: invoice(2, {
      items: [{ id: 102, order_index: 0, description: 'Hosting', quantity: 1, unit_price_without_vat: 4000, expense_kind: 'service', expense_account_code: '518.100' }],
    }) }
    m.setExpenseKinds.mockResolvedValue(m.invoices[2])
    const wrapper = await mountModal([2], false)

    expect(wrapper.text()).toContain('FV-2')
    expect(wrapper.emitted('close')).toBeUndefined()
    expect(m.expenseSuggestions).toHaveBeenCalledWith(2)
    await wrapper.get('input[aria-label="purchase_invoice.items.expense_account"]').setValue('518.200')
    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(m.setExpenseKinds).toHaveBeenCalledWith(2, [{ id: 102, expense_kind: 'service', expense_account_code: '518.200' }])
    expect(m.dismiss).not.toHaveBeenCalled()
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('ruční vymazání analytiky uloží explicitní null', async () => {
    m.listAccounts.mockResolvedValue([
      { id: 9, account_code: '518.200', name: 'Hosting', is_active: true, parent_id: null },
      { id: 14, account_code: '602', name: 'Tržby', is_active: true, parent_id: null },
      { id: 15, account_code: '378', name: 'Pohledávky', is_active: true, parent_id: null },
      { id: 16, account_code: '042', name: 'Pořízení majetku', is_active: true, parent_id: null },
      { id: 10, account_code: '518.300', name: 'Inactive', is_active: false, parent_id: null },
      { id: 11, account_code: '321.100', name: 'Payable', is_active: true, parent_id: null },
      { id: 12, account_code: '343.100', name: 'VAT', is_active: true, parent_id: null },
      { id: 13, account_code: '221.100', name: 'Bank', is_active: true, parent_id: null },
    ])
    m.invoices = { 2: invoice(2, {
      review: missingCenter,
      items: [{ id: 102, order_index: 0, description: 'Hosting', quantity: 1, unit_price_without_vat: 4000, expense_kind: 'service', expense_account_code: '518.200' }],
    }) }
    m.setExpenseKinds.mockResolvedValue(m.invoices[2])
    const wrapper = await mountModal([2])
    expect(wrapper.findAll('datalist option').map(option => option.attributes('value'))).toEqual(['518.200'])
    await wrapper.get('input[aria-label="purchase_invoice.items.expense_account"]').setValue('')
    await saveButton(wrapper).trigger('click')
    await flushPromises()
    expect(m.setExpenseKinds).toHaveBeenCalledWith(2, [{ id: 102, expense_kind: 'service', expense_account_code: null }])
  })

  it('otevře doklad bez hlášení vytěžení, kterému chybí povinná dimenze, a přeskočí doklad v pořádku', async () => {
    m.invoices = { 1: invoice(1), 2: invoice(2, { review: missingCenter }) }
    const wrapper = await mountModal([1, 2])

    expect(wrapper.text()).toContain('purchase_invoice.extraction_review.counter')
    expect(m.getDocument).toHaveBeenCalledTimes(1)
    expect(m.getDocument).toHaveBeenCalledWith('purchase-invoices', 2)
    expect(wrapper.find('[data-test="review-dimension-missing"]').exists()).toBe(false)
  })

  it('předvyplní hlavičku z historie dodavatele a návrh vyznačí', async () => {
    m.invoices = { 2: invoice(2, { review: missingCenter }) }
    const wrapper = await mountModal([2])

    expect(m.prefill).toHaveBeenCalledWith({ client_id: 30, project_id: null, history: 1, exclude_purchase_invoice_id: 2 })
    expect(wrapper.findComponent({ name: 'DimensionFields' }).props('modelValue')).toEqual({ 1: 7 })
    const suggestion = wrapper.get('[data-test="review-dimension-suggestion"]')
    expect(suggestion.text()).toContain('purchase_invoice.extraction_review.dimensions.suggestion')
    expect(suggestion.text()).toContain('hodnota-7')
    expect(suggestion.text()).toContain('purchase_invoice.extraction_review.dimensions.source.history')
  })

  it('ruční změna návrh zruší a chybějící povinnou dimenzi ukáže', async () => {
    m.invoices = { 2: invoice(2, { review: missingCenter }) }
    const wrapper = await mountModal([2])

    wrapper.findComponent({ name: 'DimensionFields' }).vm.$emit('update:modelValue', { 1: null })
    await flushPromises()
    expect(wrapper.find('[data-test="review-dimension-suggestion"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="review-dimension-missing"]').text()).toContain('purchase_invoice.extraction_review.dimensions.required')
  })

  it('„Uložit" zapíše dimenze dokladu explicitně (hlavička, položky beze změny neposílá)', async () => {
    m.invoices = { 2: invoice(2, { review: missingCenter }) }
    const wrapper = await mountModal([2])

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(m.saveDocument).toHaveBeenCalledWith('purchase-invoices', 2, { header: { 1: 7 } })
    expect(m.setExpenseKinds).not.toHaveBeenCalled()
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('typ s rozpadem v hlavičce nepředvyplní, nenabídne a uložení rozpad nepošle k přepsání', async () => {
    m.getDocument.mockResolvedValue({ header: {}, items: {}, splits: { 0: { 1: [{ value_id: 3, share: 0.5 }, { value_id: 4, share: 0.5 }] } } })
    m.invoices = { 2: invoice(2, { review: missingCenter }) }
    const wrapper = await mountModal([2])

    const fields = wrapper.findComponent({ name: 'DimensionFields' })
    expect(fields.props('modelValue')).toEqual({})
    expect(fields.props('types')).toEqual([])
    expect(wrapper.find('[data-test="review-dimension-suggestion"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-dimension-missing"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="review-dimension-splits"]').exists()).toBe(true)

    await saveButton(wrapper).trigger('click')
    await flushPromises()
    expect(m.saveDocument).not.toHaveBeenCalled()
  })

  it('po uložení předá rodiči doklad s přepočteným review', async () => {
    m.invoices = { 2: invoice(2, { review: missingCenter }) }
    const wrapper = await mountModal([2])
    m.invoices[2] = invoice(2, { review: { reasons: [], details: {} } })

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    const updated = wrapper.emitted('updated')?.[0]?.[0] as { review: { reasons: string[] } }
    expect(updated.review.reasons).toEqual([])
  })

  it('výchozí hodnota z dokladu se nepřepíše a beze změny se nic neukládá', async () => {
    m.getDocument.mockResolvedValue({ header: { 1: 5 }, items: {} })
    m.invoices = { 2: invoice(2, { extraction_warning: 'Rozdíl součtů', review: { reasons: ['extraction_warning'], details: {} } }) }
    const wrapper = await mountModal([2])

    expect(wrapper.findComponent({ name: 'DimensionFields' }).props('modelValue')).toEqual({ 1: 5 })
    expect(wrapper.find('[data-test="review-dimension-suggestion"]').exists()).toBe(false)

    await saveButton(wrapper).trigger('click')
    await flushPromises()
    expect(m.saveDocument).not.toHaveBeenCalled()
  })

  it('„Uložit a dokončit" vyřídí body hlášení zobrazené v okně, návrhy druhu nákladu nechá', async () => {
    const warning = 'Podle dokladu je faktura už uhrazená.\n\nRozdíl součtů položek\n\nAI navrhuje druh nákladu:\n• řádek 1: služba'
    m.invoices = { 2: invoice(2, { extraction_warning: warning, review: { reasons: ['extraction_warning'], details: {} } }) }
    const wrapper = await mountModal([2])

    await saveButton(wrapper).trigger('click')
    await flushPromises()

    expect(m.dismiss.mock.calls).toEqual([
      [2, 'Podle dokladu je faktura už uhrazená.'],
      [2, 'Rozdíl součtů položek'],
    ])
    const updated = wrapper.emitted('updated')?.[0]?.[0] as { extraction_warning: string | null }
    expect(updated.extraction_warning).toBeNull()
  })

  it('jen ke čtení (storno) hlášení při uložení nemaže', async () => {
    m.invoices = { 2: invoice(2, { status: 'cancelled', extraction_warning: 'Rozdíl součtů', review: { reasons: ['extraction_warning'], details: {} } }) }
    const wrapper = await mountModal([2])

    await saveButton(wrapper).trigger('click')
    await flushPromises()
    expect(m.dismiss).not.toHaveBeenCalled()
  })
})
