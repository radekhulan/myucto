import { flushPromises, shallowMount } from '@vue/test-utils'
import { computed, defineComponent, h, reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  canWriteOtherItems: true,
  toastSuccess: vi.fn(),
  get: vi.fn(),
  allocations: vi.fn(),
  paymentCandidates: vi.fn(),
  updateCounterpartyAccount: vi.fn(),
  updateText: vi.fn(),
  updateAccrual: vi.fn(),
  removePosted: vi.fn(),
  post: vi.fn(),
  listAccounts: vi.fn(),
  toastError: vi.fn(),
  mode: 'tax_evidence',
  phone: false,
  route: null as any,
  refresh: null as null | (() => Promise<unknown>),
  canRefresh: null as null | (() => boolean),
  realWindowRefresh: false,
  panelReload: vi.fn(),
  noRead: [] as string[],
  listPath: null as string | null,
  navOptions: null as any,
  push: vi.fn(),
  list: vi.fn(),
  remove: vi.fn(),
}))

vi.mock('@/api/otherItems', () => ({
  otherItemsApi: { get: m.get, allocations: m.allocations, paymentCandidates: m.paymentCandidates,
    updateCounterpartyAccount: m.updateCounterpartyAccount, updateText: m.updateText, updateAccrual: m.updateAccrual, removePosted: m.removePosted, post: m.post, list: m.list, remove: m.remove },
}))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: m.listAccounts } }))
vi.mock('@/composables/useWindowRefresh', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/composables/useWindowRefresh')>()
  return {
    useWindowRefresh: (refresh: () => Promise<unknown>, canRefresh: () => boolean, options?: { partial?: boolean }) => {
      m.refresh = refresh
      m.canRefresh = canRefresh
      // Skutečný composable v okně pracovní plochy čte podmínku obnovy hned při založení.
      return m.realWindowRefresh ? actual.useWindowRefresh(refresh, canRefresh, options) : undefined
    },
  }
})
vi.mock('@/composables/useWindowTitle', () => ({ useWindowTitle: vi.fn() }))
vi.mock('@/composables/useListNavigation', async () => {
  const { ref } = await import('vue')
  return { useListNavigation: (options: unknown) => { m.navOptions = options; return { active: ref(m.listPath !== null), hasPrevious: ref(false), hasNext: ref(true), busy: ref(false),
    listPath: ref(m.listPath), previous: vi.fn(), next: vi.fn() } } }
})
vi.mock('@/api/otherItemSettings', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/otherItemSettings')>()),
  otherItemSettingsApi: { get: vi.fn().mockResolvedValue({ document_folder: 'Smlouvy/{YYYY}', document_folder_default: 'Ostatní pohledávky a závazky/{YYYY}/{MM}', is_default: false }), update: vi.fn() },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: (key: string) => key !== 'other_items' || m.canWriteOtherItems, canRead: (key: string) => !m.noRead.includes(key) }) }))
vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => ({ get value() { return m.phone } }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_mode: m.mode } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: m.toastSuccess }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => `${v} Kč`,
  statusBadgeClass: (status: string) => `badge badge-${status}` }))
vi.mock('vue-router', () => ({
  useRoute: () => m.route,
  useRouter: () => ({ push: m.push }),
  RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }),
}))

import OtherItemDetail from '../OtherItemDetail.vue'
import PostingBadge from '@/components/ui/PostingBadge.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DocumentPostingPanel from '@/components/accounting/DocumentPostingPanel.vue'
import { windowContextKey } from '@/workspace/windowContext'
import { installWindowSupplierScope } from '@/workspace/supplierContext'

const RouterLinkStub = { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' }
const ModalStub = { template: '<div><slot /><slot name="footer" /></div>' }
const stubs = { RouterLink: RouterLinkStub, Modal: ModalStub }

// Režim telefonu nesmí přetéct do dalších bloků, ani když telefonní test spadne.
beforeEach(() => { m.phone = false })

/** Pole data podle data-test (shallowMount: stub DateInput nese atributy). */
const dateInput = (wrapper: any, test: string): any => wrapper.findAllComponents({ name: 'DateInput' }).find((c: any) => c.attributes('data-test') === test)

describe('OtherItemDetail', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.mode = 'tax_evidence'
    m.phone = false
    m.noRead = []
    m.route = reactive({ params: { id: '9' } })
    m.listAccounts.mockResolvedValue([])
    m.get.mockResolvedValue({
      id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'confirmed',
      variable_symbol: '2099777', counterparty_account: '19-1000000005', counterparty_bank_code: '0100',
    })
    m.allocations.mockResolvedValue([])
    m.paymentCandidates.mockResolvedValue([
      { source: 'bank', id: 41, payment_on: '2099-03-09', amount: 1000, currency: 'CZK', description: 'Syntetická platba',
        match_score: 75, match_reasons: ['counterparty_account', 'installment_amount'],
        installment: { id: 5, position: 1, due_on: '2099-03-10', remaining_amount: 1000 } },
      { source: 'bank', id: 42, payment_on: '2099-03-30', amount: 999, currency: 'CZK', description: 'Jiná platba',
        match_score: 0, match_reasons: [], installment: null },
    ])
  })

  it('v okně pracovní plochy s firmou okna se založí celý i s lištou akcí', async () => {
    // Skutečná obnova okna čte podmínku „nic se neupravuje“ hned při založení; stav úprav
    // deklarovaný až pod ní dřív skončil ReferenceError a detail v okně byl bez lišty akcí.
    m.realWindowRefresh = true
    installWindowSupplierScope({ windowId: 'w-detail', supplierId: 3 })
    const root = document.createElement('div')
    const context = { paneId: computed(() => null), active: computed(() => true), visible: computed(() => true), root, portal: root }
    try {
      const wrapper = shallowMount(OtherItemDetail, { global: { stubs, provide: { [windowContextKey as symbol]: context } } })
      await flushPromises()
      const actions = wrapper.findComponent({ name: 'ActionBar' }).props('actions') as Array<{ key: string }>
      expect(actions.map(action => action.key)).toContain('edit')
      expect(m.canRefresh!()).toBe(true)
      wrapper.unmount()
    } finally {
      m.realWindowRefresh = false
    }
  })

  it('ukáže účet protistrany a u kandidátů úhrady důvody shody', async () => {
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    expect(wrapper.get('[data-test="counterparty-account"]').text()).toBe('19-1000000005 / 0100')

    await wrapper.get('input[type="search"]').trigger('focus')
    await flushPromises()
    const groups = wrapper.findAll('[data-test="match-reasons"]')
    expect(groups).toHaveLength(1)
    expect(groups[0]!.findAll('span').map(span => span.text())).toEqual([
      'other_items.match_reason.counterparty_account:{"number":1}',
      'other_items.match_reason.installment_amount:{"number":1}',
    ])
  })

  it('upraví účet protistrany potvrzeného dokladu a označí automatické přiřazení', async () => {
    m.allocations.mockResolvedValue([{ id: 3, installment_id: null, bank_transaction_id: 77, cash_document_id: null,
      amount: 1000, payment_on: '2099-03-10', auto_match_reason: 'other_item_vs_account', reversed_on: null }])
    m.updateCounterpartyAccount.mockResolvedValue({
      id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 1000, remaining_amount: 2000, status: 'confirmed',
      variable_symbol: '2099777', counterparty_account: '1000000005', counterparty_bank_code: '0100',
    })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    expect(wrapper.get('[data-test="allocation-auto"]').attributes('title')).toBe('other_items.auto_match_reason.other_item_vs_account')

    await wrapper.get('[data-test="counterparty-edit"]').trigger('click')
    const input = wrapper.get('[data-test="counterparty-form"] input')
    expect((input.element as HTMLInputElement).value).toBe('19-1000000005 / 0100')
    await input.setValue('1000000005/0100')
    await wrapper.get('[data-test="counterparty-form"]').trigger('submit')
    await flushPromises()

    expect(m.updateCounterpartyAccount).toHaveBeenCalledWith(9, '1000000005/0100')
    expect(wrapper.find('[data-test="counterparty-form"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="counterparty-account"]').text()).toBe('1000000005 / 0100')
  })

  it('koncept účet protistrany samostatně neupravuje', async () => {
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Koncept', partner_name: null,
      issued_on: '2099-01-01', due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000,
      status: 'draft', variable_symbol: null, counterparty_account: null, counterparty_bank_code: null })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    expect(wrapper.find('[data-test="counterparty-edit"]').exists()).toBe(false)
  })

  it('zápočet nabídne v liště a u úhrad rozliší storno vlastního zápočtu a odebrání přiřazeného zápisu', async () => {
    m.mode = 'double_entry'
    m.get.mockResolvedValue({
      id: 9, side: 'receivable', kind: 'insurance', title: 'Syntetické pojistné plnění', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 1500, remaining_amount: 1500, status: 'posted',
      document_no: 'OP2099001', journal_entry_id: 77,
    })
    m.allocations.mockResolvedValue([
      { id: 1, bank_transaction_id: null, cash_document_id: null, journal_allocation_id: 11, internal_document_id: 5,
        offset_document_no: 'ID2099001', journal_entry_id: 80, amount: 1000, total_amount: 1000, payment_on: '2099-02-10', reversed_on: null },
      { id: 2, bank_transaction_id: null, cash_document_id: null, journal_allocation_id: 12, internal_document_id: null,
        offset_document_no: 'ID2099002', journal_entry_id: 81, amount: 500, total_amount: 500, payment_on: '2099-02-12', reversed_on: null },
    ])
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()

    const offset = (wrapper.findComponent(ActionBar).props('actions') as ActionItem[]).find(a => a.key === 'offset')!
    expect(offset.show).toBe(true)
    expect(offset.label).toBe('other_items.offset.action')
    const rows = wrapper.findAll('[data-test="allocation"]')
    expect(rows[0].get('[data-test="allocation-source"]').text()).toContain('other_items.offset.payment ID2099001')
    expect(rows[0].get('[data-test="allocation-source"]').attributes('data-to')).toContain('accounting-internal-document-detail')
    expect(rows[0].find('[data-test="offset-cancel"]').exists()).toBe(true)
    expect(rows[0].find('[data-test="allocation-unlink"]').exists()).toBe(false)
    expect(rows[1].find('[data-test="offset-detach"]').exists()).toBe(true)
    expect(rows[1].find('[data-test="offset-cancel"]').exists()).toBe(false)
  })

  it('zápočet se řídí zbývající jistinou a stornovaný řádek už nejde odebrat', async () => {
    m.mode = 'double_entry'
    m.get.mockResolvedValue({
      id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 3000, remaining_amount: 0, status: 'posted',
      remaining_payment: 70, has_split_installments: true, document_no: 'OZ2099001', journal_entry_id: 77,
    })
    m.allocations.mockResolvedValue([
      { id: 2, bank_transaction_id: null, cash_document_id: null, journal_allocation_id: 12, internal_document_id: null,
        offset_document_no: 'ID2099002', journal_entry_id: null, amount: 500, total_amount: 500, payment_on: '2099-02-12', reversed_on: '2099-03-01' },
    ])
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    const offset = (wrapper.findComponent(ActionBar).props('actions') as ActionItem[]).find(a => a.key === 'offset')!
    expect(offset.show).toBe(false)
    expect(wrapper.find('[data-test="offset-detach"]').exists()).toBe(false)
  })

  it('zápočet v daňové evidenci nenabízí', async () => {
    m.allocations.mockResolvedValue([])
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    const offset = (wrapper.findComponent(ActionBar).props('actions') as ActionItem[]).find(a => a.key === 'offset')!
    expect(offset.show).toBe(false)
  })

  it('u úvěru s rozdělenými splátkami ukáže zbývající jistinu, celou platbu a rozdělení úhrady', async () => {
    m.get.mockResolvedValue({
      id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 1000, remaining_amount: 2000, status: 'confirmed',
      remaining_payment: 2070, has_split_installments: true,
    })
    m.allocations.mockResolvedValue([
      { id: 1, installment_id: 5, bank_transaction_id: 41, cash_document_id: null, amount: 1000, interest_amount: 100,
        fee_amount: 20, total_amount: 1120, payment_on: '2099-02-10', reversed_on: null },
    ])
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    expect(wrapper.text()).toContain('other_items.remaining_principal')
    expect(wrapper.get('[data-test="remaining-payment"]').text()).toContain('2070 Kč')
    expect(wrapper.get('[data-test="allocation-split"]').text())
      .toBe('other_items.allocation_split:{"principal":"1000 Kč","interest":"100 Kč","fee":"20 Kč"}')
    expect(wrapper.text()).toContain('1120 Kč')
  })

  it('období rozlišení výnosu ukáže u pohledávky v podvojném účetnictví a u zaúčtované ho upraví', async () => {
    m.mode = 'double_entry'
    const posted = { id: 9, side: 'receivable', kind: 'claim', title: 'Syntetický nájem', partner_name: null, issued_on: '2099-07-01',
      due_on: '2099-07-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'posted',
      journal_entry_id: 77, accrual_from: null, accrual_to: null }
    m.get.mockResolvedValue(posted)
    m.updateAccrual.mockResolvedValue({ ...posted, accrual_from: '2099-07-01', accrual_to: '2100-06-30' })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    expect(wrapper.get('[data-test="accrual-period"]').text()).toBe('other_items.accrual.none')
    // Text „Bez rozlišení“ je věta, ne údaj: bez neproporcionálního písma dat.
    expect(wrapper.get('[data-test="accrual-period"]').classes()).not.toContain('font-mono')
    await wrapper.get('[data-test="accrual-edit"]').trigger('click')
    // Pole Od/Do se otevřou rovnou, nápověda je vidět u nich (ne jen v title).
    expect(wrapper.get('[data-test="accrual-hint"]').text()).toBe('other_items.accrual.hint_receivable')
    dateInput(wrapper, 'accrual-from').vm.$emit('update:modelValue', '2099-07-01')
    dateInput(wrapper, 'accrual-to').vm.$emit('update:modelValue', '2100-06-30')
    await wrapper.get('[data-test="accrual-form"]').trigger('submit')
    await flushPromises()
    expect(m.updateAccrual).toHaveBeenCalledWith(9, '2099-07-01', '2100-06-30')
    expect(wrapper.get('[data-test="accrual-period"]').text()).toBe('other_items.accrual.period:{"from":"2099-07-01","to":"2100-06-30"}')
    expect(wrapper.get('[data-test="accrual-period"]').classes()).toContain('font-mono')
    expect(m.toastSuccess).toHaveBeenCalledWith('accrual_period.saved')
  })

  it('zrušení rozlišení v detailu pošle prázdné období a zachová posouzení smazání se zápisem', async () => {
    m.mode = 'double_entry'
    const posted = { id: 9, side: 'receivable', kind: 'claim', title: 'Syntetický nájem', partner_name: null, issued_on: '2099-07-01',
      due_on: '2099-07-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'posted',
      journal_entry_id: 77, accrual_from: '2099-07-01', accrual_to: '2100-06-30',
      posted_deletion: { allowed: true, needs_ack: false, message: null } }
    m.get.mockResolvedValue(posted)
    const { posted_deletion: _omitted, ...withoutDeletion } = posted
    m.updateAccrual.mockResolvedValue({ ...withoutDeletion, accrual_from: null, accrual_to: null })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    await wrapper.get('[data-test="accrual-edit"]').trigger('click')
    const from = dateInput(wrapper, 'accrual-from')
    expect(from.props('modelValue')).toBe('2099-07-01')
    from.vm.$emit('update:modelValue', '')
    dateInput(wrapper, 'accrual-to').vm.$emit('update:modelValue', '')
    await wrapper.get('[data-test="accrual-form"]').trigger('submit')
    await flushPromises()
    expect(m.updateAccrual).toHaveBeenCalledWith(9, null, null)
    expect(wrapper.get('[data-test="accrual-period"]').text()).toBe('other_items.accrual.none')
    expect((wrapper.vm as any).item.posted_deletion).toEqual({ allowed: true, needs_ack: false, message: null })
  })

  it.each([
    'period_closed', 'accrual_deferral_posted', 'accrual_period_incomplete', 'accrual_period_invalid',
    'accrual_not_posted', 'is_draft', 'accrual_double_entry_only',
  ])('odmítnutou změnu období %s ohlásí přeloženou hláškou u polí', async (code) => {
    m.mode = 'double_entry'
    m.get.mockResolvedValue({ id: 9, side: 'receivable', kind: 'claim', title: 'Syntetický nájem', partner_name: null,
      issued_on: '2099-07-01', due_on: '2099-07-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000,
      status: 'posted', journal_entry_id: 77, accrual_from: null, accrual_to: null })
    m.updateAccrual.mockRejectedValue({ response: { data: { error: { code: `other_items.error.${code}`, message: 'Serverová hláška' } } } })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    await wrapper.get('[data-test="accrual-edit"]').trigger('click')
    await wrapper.get('[data-test="accrual-form"]').trigger('submit')
    await flushPromises()
    // Hláška zůstane u polí (toast by zmizel), úprava zůstane otevřená.
    expect(wrapper.getComponent({ name: 'FieldNote' }).props('error')).toBe(`other_items.accrual.error.${code}`)
    expect(wrapper.find('[data-test="accrual-form"]').exists()).toBe(true)
    expect(m.toastError).not.toHaveBeenCalled()
    // Oprava data hlášku schová.
    dateInput(wrapper, 'accrual-from').vm.$emit('update:modelValue', '2099-07-01')
    await flushPromises()
    expect(wrapper.getComponent({ name: 'FieldNote' }).props('error')).toBe('')
  })

  it('v daňové evidenci uložené období neupraví, ale jde ho zrušit', async () => {
    // Potvrzená položka v daňové evidenci nemá vlastní zápis; období zbylo z podvojného účetnictví.
    const confirmed = { id: 9, side: 'receivable', kind: 'claim', title: 'Syntetický nájem', partner_name: null,
      issued_on: '2099-07-01', due_on: '2099-07-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000,
      status: 'confirmed', journal_entry_id: null, accrual_from: '2099-07-01', accrual_to: '2100-06-30' }
    m.get.mockResolvedValue(confirmed)
    m.updateAccrual.mockResolvedValue({ ...confirmed, accrual_from: null, accrual_to: null })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    expect(wrapper.get('[data-test="accrual-period"]').text()).toBe('other_items.accrual.period:{"from":"2099-07-01","to":"2100-06-30"}')
    expect(wrapper.find('[data-test="accrual-edit"]').exists()).toBe(false)
    // Zrušení je nevratné: bez souhlasu se nic neodešle.
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false)
    await wrapper.get('[data-test="accrual-clear"]').trigger('click')
    expect(confirm).toHaveBeenCalledWith('other_items.accrual.clear_confirm')
    expect(m.updateAccrual).not.toHaveBeenCalled()
    confirm.mockReturnValueOnce(true)
    await wrapper.get('[data-test="accrual-clear"]').trigger('click')
    await flushPromises()
    confirm.mockRestore()
    expect(m.updateAccrual).toHaveBeenCalledWith(9, null, null)
    expect(wrapper.find('[data-test="accrual"]').exists()).toBe(false)
  })

  it('zrušení odmítnuté serverem ohlásí u pole', async () => {
    m.get.mockResolvedValue({ id: 9, side: 'receivable', kind: 'claim', title: 'Syntetický nájem', partner_name: null,
      issued_on: '2099-07-01', due_on: '2099-07-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000,
      status: 'confirmed', journal_entry_id: null, accrual_from: '2099-07-01', accrual_to: '2100-06-30' })
    m.updateAccrual.mockRejectedValue({ response: { data: { error: { code: 'other_items.error.period_closed', message: 'x' } } } })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(true)
    await wrapper.get('[data-test="accrual-clear"]').trigger('click')
    await flushPromises()
    confirm.mockRestore()
    expect(wrapper.getComponent({ name: 'FieldNote' }).props('error')).toBe('other_items.accrual.error.period_closed')
  })

  it.each([
    ['položka ze zůstatku v podvojném účetnictví', 'double_entry', 'posted'],
    ['stornovaná položka', 'double_entry', 'reversed'],
  ])('%s bez vlastního zápisu období neupraví, jen zruší', async (_label, mode, status) => {
    m.mode = mode
    m.get.mockResolvedValue({ id: 9, side: 'receivable', kind: 'claim', title: 'Syntetický nájem', partner_name: null,
      issued_on: '2099-07-01', due_on: '2099-07-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000,
      status, journal_entry_id: null, accrual_from: '2099-07-01', accrual_to: '2100-06-30' })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    expect(wrapper.find('[data-test="accrual-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="accrual-clear"]').exists()).toBe(true)
  })

  it('zrušení úprav po odmítnutí serverem schová i jeho hlášku', async () => {
    m.mode = 'double_entry'
    m.get.mockResolvedValue({ id: 9, side: 'receivable', kind: 'claim', title: 'Syntetický nájem', partner_name: null,
      issued_on: '2099-07-01', due_on: '2099-07-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000,
      status: 'posted', journal_entry_id: 77, accrual_from: null, accrual_to: null })
    m.updateAccrual.mockRejectedValue({ response: { data: { error: { code: 'other_items.error.accrual_deferral_posted', message: 'x' } } } })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    await wrapper.get('[data-test="accrual-edit"]').trigger('click')
    await wrapper.get('[data-test="accrual-form"]').trigger('submit')
    await flushPromises()
    const cancel = wrapper.get('[data-test="accrual-form"]').findAll('button').find(b => b.text().includes('common.cancel'))!
    await cancel.trigger('click')
    expect(wrapper.getComponent({ name: 'FieldNote' }).props('error')).toBe('')
  })

  it('uložení období během načítání nenechá tichou obnovu trvale vypnutou', async () => {
    m.get.mockResolvedValue({ id: 9, side: 'receivable', kind: 'claim', title: 'Syntetický nájem', partner_name: null,
      issued_on: '2099-07-01', due_on: '2099-07-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000,
      status: 'confirmed', journal_entry_id: null, accrual_from: '2099-07-01', accrual_to: '2100-06-30' })
    m.updateAccrual.mockResolvedValue({ id: 9, side: 'receivable', status: 'confirmed', journal_entry_id: null, accrual_from: null, accrual_to: null })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    let resolveGet!: (value: unknown) => void
    m.get.mockReturnValueOnce(new Promise(resolve => { resolveGet = resolve }))
    void (wrapper.vm as any).load()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(true)
    await wrapper.get('[data-test="accrual-clear"]').trigger('click')
    await flushPromises()
    confirm.mockRestore()
    resolveGet({ id: 9, side: 'receivable', status: 'confirmed', journal_entry_id: null, accrual_from: '2099-07-01', accrual_to: '2100-06-30' })
    await flushPromises()
    expect(m.canRefresh!()).toBe(true)
  })

  it('neúplné či obrácené období v detailu na server neposílá', async () => {
    m.mode = 'double_entry'
    m.get.mockResolvedValue({ id: 9, side: 'receivable', kind: 'claim', title: 'Syntetický nájem', partner_name: null, issued_on: '2099-07-01',
      due_on: '2099-07-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'posted',
      journal_entry_id: 77, accrual_from: null, accrual_to: null })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    await wrapper.get('[data-test="accrual-edit"]').trigger('click')
    dateInput(wrapper, 'accrual-from').vm.$emit('update:modelValue', '2099-07-01')
    await flushPromises()
    await wrapper.get('[data-test="accrual-form"]').trigger('submit')
    await flushPromises()
    expect(wrapper.getComponent({ name: 'FieldNote' }).props('error')).toBe('other_items.accrual.error.accrual_period_incomplete')
    dateInput(wrapper, 'accrual-to').vm.$emit('update:modelValue', '2099-06-30')
    await flushPromises()
    await wrapper.get('[data-test="accrual-form"]').trigger('submit')
    await flushPromises()
    expect(wrapper.getComponent({ name: 'FieldNote' }).props('error')).toBe('other_items.accrual.error.accrual_period_invalid')
    expect(m.updateAccrual).not.toHaveBeenCalled()
  })

  it('odpověď uložení období po přepnutí na jinou položku nepřepíše její detail', async () => {
    m.mode = 'double_entry'
    m.get.mockResolvedValue({ id: 9, side: 'receivable', kind: 'claim', title: 'Syntetický nájem', partner_name: null, issued_on: '2099-07-01',
      due_on: '2099-07-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'posted',
      journal_entry_id: 77, accrual_from: null, accrual_to: null })
    let resolveSave!: (value: unknown) => void
    m.updateAccrual.mockReturnValue(new Promise(resolve => { resolveSave = resolve }))
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    await wrapper.get('[data-test="accrual-edit"]').trigger('click')
    dateInput(wrapper, 'accrual-from').vm.$emit('update:modelValue', '2099-07-01')
    dateInput(wrapper, 'accrual-to').vm.$emit('update:modelValue', '2100-06-30')
    await wrapper.get('[data-test="accrual-form"]').trigger('submit')
    m.get.mockResolvedValue({ id: 10, side: 'receivable', kind: 'claim', title: 'Jiná položka', partner_name: null, issued_on: '2099-08-01',
      due_on: '2099-08-15', currency: 'CZK', amount: 500, paid_amount: 0, remaining_amount: 500, status: 'posted',
      journal_entry_id: 78, accrual_from: null, accrual_to: null })
    m.route.params.id = '10'
    await flushPromises()
    resolveSave({ id: 9, side: 'receivable', status: 'posted', journal_entry_id: 77, accrual_from: '2099-07-01', accrual_to: '2100-06-30' })
    await flushPromises()
    expect((wrapper.vm as any).item.id).toBe(10)
    expect(wrapper.get('[data-test="accrual-period"]').text()).toBe('other_items.accrual.none')
  })

  it('smazání konceptu odmítnuté kvůli období ohlásí přeloženou hláškou', async () => {
    m.get.mockResolvedValue({ id: 9, side: 'receivable', kind: 'claim', title: 'Koncept', partner_name: null,
      issued_on: '2099-01-01', due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'draft' })
    m.remove.mockRejectedValue({ response: { data: { error: { code: 'other_items.error.accrual_deferral_posted', message: 'Serverová hláška' } } } })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(true)
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    ;(wrapper.findComponent(ActionBar).props('actions') as ActionItem[]).find(a => a.key === 'delete')!.run!()
    await flushPromises()
    confirm.mockRestore()
    expect(m.toastError).toHaveBeenCalledWith('other_items.accrual.error.accrual_deferral_posted')
  })

  it('u zaúčtovaného závazku v podvojném účetnictví nabídne období rozlišení nákladu', async () => {
    m.mode = 'double_entry'
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'rent', title: 'Syntetické nájemné', partner_name: null,
      issued_on: '2099-07-01', due_on: '2099-07-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000,
      status: 'posted', journal_entry_id: 77, accrual_from: null, accrual_to: null })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    expect(wrapper.get('[data-test="accrual"]').text()).toContain('other_items.accrual.label_payable')
    await wrapper.get('[data-test="accrual-edit"]').trigger('click')
    expect(wrapper.get('[data-test="accrual-hint"]').text()).toBe('other_items.accrual.hint_payable')
  })

  it('období rozlišení neukáže v daňové evidenci bez uloženého období ani úpravu u konceptu', async () => {
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    expect(wrapper.find('[data-test="accrual"]').exists()).toBe(false)

    m.mode = 'double_entry'
    m.get.mockResolvedValue({ id: 9, side: 'receivable', kind: 'claim', title: 'Syntetický nájem', partner_name: null,
      issued_on: '2099-07-01', due_on: '2099-07-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000,
      status: 'draft', journal_entry_id: null })
    const draft = shallowMount(OtherItemDetail)
    await flushPromises()
    expect(draft.find('[data-test="accrual"]').exists()).toBe(true)
    expect(draft.find('[data-test="accrual-edit"]').exists()).toBe(false)
  })

  it('doplní poznámku potvrzeného dokladu bez úpravy konceptu', async () => {
    m.updateText.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null,
      issued_on: '2099-01-01', due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000,
      status: 'confirmed', note: 'Doplněno' })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    await wrapper.get('[data-test="note-edit"]').trigger('click')
    await wrapper.get('[data-test="note-form"] textarea').setValue('  Doplněno ')
    await wrapper.get('[data-test="note-form"]').trigger('submit')
    await flushPromises()
    expect(m.updateText).toHaveBeenCalledWith(9, 'Doplněno')
  })

  it('smazání zaúčtovaného dokladu nabídne jen s povolením serveru, jinak s důvodem', async () => {
    m.mode = 'double_entry'
    const posted = { id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'posted',
      journal_entry_id: 55, document_no: 'OZ-2099-0001' }
    m.get.mockResolvedValue({ ...posted, posted_deletion: { allowed: false, needs_ack: false, code: 'period_not_open', message: 'Uzavřené období.' } })
    let wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    let action = (wrapper.findComponent(ActionBar).props('actions') as ActionItem[]).find(a => a.key === 'delete_posted')!
    expect(action.show).toBe(true)
    expect(action.disabled).toBe(true)
    expect(action.disabledReason).toBe('other_items.delete_posted_blocked:{"reason":"Uzavřené období."}')
    expect((wrapper.findComponent(ActionBar).props('actions') as ActionItem[]).find(a => a.key === 'reverse')!.show).toBe(true)

    m.get.mockResolvedValue({ ...posted, posted_deletion: { allowed: true, needs_ack: false, code: null, message: null } })
    m.removePosted.mockResolvedValue({ deleted: true, journal_entry_id: 55, status: 'deleted' })
    wrapper = shallowMount(OtherItemDetail, { global: { stubs: { Modal: { template: '<div><slot /><slot name="footer" /></div>' } } } })
    await flushPromises()
    action = (wrapper.findComponent(ActionBar).props('actions') as ActionItem[]).find(a => a.key === 'delete_posted')!
    expect(action.disabled).toBe(false)
    action.run!()
    await flushPromises()
    await wrapper.get('[data-test="delete-posted-confirm"]').trigger('click')
    await flushPromises()
    expect(m.removePosted).toHaveBeenCalledWith(9, false)
  })

  it('nadpis nese číslo dokladu, stav sdíleným odznakem a stav úhrady po splatnosti', async () => {
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null, issued_on: '2000-01-01',
      due_on: '2000-02-01', currency: 'CZK', amount: 3000, paid_amount: 1000, remaining_amount: 2000, status: 'confirmed', document_no: 'OZ-2099-0007' })
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.get('[data-test="detail-heading"] .font-mono').text()).toBe('OZ-2099-0007')
    expect(wrapper.get('[data-test="detail-status"]').classes()).toContain('badge-partially_paid')
    expect(wrapper.find('[data-test="detail-overdue"]').exists()).toBe(true)
    // Mock překladu vrací klíč, stránka pak ukáže kód stavu. Po splatnosti nese jen štítek v záhlaví.
    expect(wrapper.get('[data-test="payment-state"]').text()).toBe('partial')
    expect(wrapper.get('[data-test="payment-state"]').classes()).toContain('badge-partially_paid')
  })

  it('přeplacená položka nese štítek Přeplaceno jako faktura', async () => {
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-02-01', currency: 'CZK', amount: 3000, paid_amount: 3100, remaining_amount: -100, status: 'confirmed', document_no: 'OZ-2099-0008' })
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.get('[data-test="detail-status"]').classes()).toContain('badge-overpaid')
    expect(wrapper.get('[data-test="payment-state"]').text()).toBe('overpaid')
    expect(wrapper.get('[data-test="payment-state"]').classes()).toContain('badge-overpaid')
  })

  it('záhlaví a karty jako přijatá faktura: číslo s odznaky, název s protistranou, bez kotvy na plány', async () => {
    m.mode = 'double_entry'
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'rent', title: 'Syntetický nájem', partner_id: 5, partner_name: 'Syntetická protistrana',
      issued_on: '2099-01-01', due_on: '2099-02-01', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'posted',
      document_no: 'OZ-2099-0008', journal_entry_id: 41, posted_at: '2099-01-01 10:00:00' })
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.get('[data-test="detail-heading"]').text()).not.toContain('Syntetický nájem')
    expect(wrapper.get('[data-test="detail-title"]').text()).toBe('Syntetický nájem')
    expect(wrapper.get('[data-test="detail-kind"]').text()).toContain('other_items.side.payable')
    expect(wrapper.get('[data-test="detail-partner"]').text()).toContain('Syntetická protistrana')
    expect(wrapper.findComponent({ name: 'ClientQuickLinks' }).props()).toMatchObject({ clientId: 5, vendor: true })
    expect(wrapper.findAll('[data-test="detail-cards"] > div')).toHaveLength(3)
    expect(wrapper.find('a[href="#other-item-plans"]').exists()).toBe(false)
    expect(wrapper.find('a[href^="#"]').exists()).toBe(false)
    // Stav „Zaúčtováno“ nese text, štítek jen odkaz; u uhrazené ho pojmenuje.
    expect(wrapper.findComponent(PostingBadge).props('withLabel')).toBe(false)
  })

  it('koncept bez protistrany: číslo nahradí #id, bez odkazů na klienta, telefon schová karty za Podrobnosti', async () => {
    m.mode = 'double_entry'
    m.phone = true
    m.get.mockResolvedValue({ id: 12, side: 'receivable', kind: 'fee', title: 'Syntetický poplatek', partner_id: null, partner_name: null,
      issued_on: '2099-01-01', due_on: '2000-02-01', currency: 'CZK', amount: 500, paid_amount: 0, remaining_amount: 500, status: 'draft', document_no: null })
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.get('[data-test="detail-heading"] .font-mono').text()).toBe('#12')
    expect(wrapper.findComponent({ name: 'ClientQuickLinks' }).exists()).toBe(false)
    expect(wrapper.get('[data-test="detail-partner"]').text()).toContain('other_items.no_partner')
    expect(wrapper.get('[data-test="phone-summary"]').text()).toContain('500')
    const hidden = () => [wrapper.get('[data-test="detail-cards"]'), wrapper.findComponent({ name: 'DocumentPostingPanel' }),
      wrapper.findComponent({ name: 'DocumentDimensionsPanel' })].map(part => (part.attributes('style') ?? '').includes('display: none'))
    expect(hidden()).toEqual([true, true, true])
    await wrapper.get('[data-test="phone-details-toggle"]').trigger('click')
    expect(hidden()).toEqual([false, false, false])
  })

  it('vygenerovaná položka ukáže zdroj opakování a akce opakování a splátek řídí komponenta plánů', async () => {
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'rent', title: 'Syntetický nájem', partner_name: null, issued_on: '2099-02-01',
      due_on: '2099-02-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'confirmed', document_no: 'OZ-2099-0011',
      occurrence: { schedule_id: 4, occurrence_index: 2, source_item_id: 7, source_document_no: 'OZ-2099-0001', source_title: 'Zdroj',
        schedule_created_at: '2099-01-01 10:00:00', created_by_name: 'Syntetický účetní' } })
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    const source = wrapper.get('[data-test="occurrence-source"]')
    expect(source.text()).toContain('other_items.occurrence.label')
    expect(wrapper.get('[data-test="occurrence-source-link"]').text()).toBe('OZ-2099-0001')
    expect(wrapper.get('[data-test="occurrence-source-link"]').attributes('data-to')).toContain('"id":7')
    const actionKeys = () => (wrapper.findComponent({ name: 'ActionBar' }).props('actions') as Array<{ key: string; show?: boolean }>)
      .filter(action => action.show !== false).map(action => action.key)
    expect(actionKeys()).not.toContain('schedule')
    expect(actionKeys()).not.toContain('installments')
    wrapper.findComponent({ name: 'OtherItemPlans' }).vm.$emit('state', { canSchedule: false, canSplit: true })
    await flushPromises()
    expect(actionKeys()).toContain('installments')
    expect(actionKeys()).not.toContain('schedule')
  })

  it('akce v liště otevřou dialog opakování a kalendář splátek v komponentě plánů', async () => {
    const openSchedule = vi.fn()
    const openInstallments = vi.fn()
    const PlansStub = defineComponent({ name: 'OtherItemPlans', emits: ['state'], setup(_props, { expose }) {
      expose({ openSchedule, openInstallments })
      return () => h('div')
    } })
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs: { ...stubs, OtherItemPlans: PlansStub } } })
    await flushPromises()
    wrapper.findComponent({ name: 'OtherItemPlans' }).vm.$emit('state', { canSchedule: true, canSplit: true, editing: false })
    await flushPromises()
    const actions = wrapper.findComponent({ name: 'ActionBar' }).props('actions') as Array<{ key: string; run?: () => void }>
    actions.find(action => action.key === 'schedule')!.run!()
    expect(openSchedule).toHaveBeenCalledTimes(1)
    expect(openInstallments).not.toHaveBeenCalled()
    actions.find(action => action.key === 'installments')!.run!()
    expect(openInstallments).toHaveBeenCalledTimes(1)
  })

  it('po zrušení opakování položka dál ukáže zdroj a nenabídne nové opakování', async () => {
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'rent', title: 'Syntetický nájem', partner_name: null, issued_on: '2099-02-01',
      due_on: '2099-02-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'draft', document_no: null,
      occurrence: { schedule_id: null, occurrence_index: 1, source_item_id: 7, source_document_no: 'OZ-2099-0001', source_title: 'Zdroj',
        schedule_created_at: null, created_by_name: null } })
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.get('[data-test="occurrence-source-link"]').text()).toBe('OZ-2099-0001')
    expect(wrapper.get('[data-test="occurrence-cancelled"]').text()).toContain('other_items.occurrence.cancelled')
    expect(wrapper.get('[data-test="occurrence-source"]').text()).not.toContain('other_items.occurrence.created_by')
    // Smazaný zdroj: štítek zůstane, odkaz zmizí.
    m.get.mockResolvedValue({ ...(await m.get.mock.results[0].value), occurrence: { schedule_id: null, occurrence_index: 1, source_item_id: null,
      source_document_no: null, source_title: null, schedule_created_at: null, created_by_name: null } })
    const orphan = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(orphan.get('[data-test="occurrence-source"]').text()).toContain('other_items.occurrence.label')
    expect(orphan.find('[data-test="occurrence-source-link"]').exists()).toBe(false)
  })

  it('opakování a splátky jsou vždy pohromadě v „…“ pod skupinou Plány', async () => {
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    wrapper.findComponent({ name: 'OtherItemPlans' }).vm.$emit('state', { canSchedule: true, canSplit: true, editing: false })
    await flushPromises()
    const plans = (wrapper.findComponent({ name: 'ActionBar' }).props('actions') as Array<{ key: string; tier?: string; group?: string }>)
      .filter(action => action.key === 'schedule' || action.key === 'installments')
    expect(plans.map(action => [action.key, action.tier, action.group])).toEqual([
      ['schedule', 'overflow', 'other_items.plans.group'],
      ['installments', 'overflow', 'other_items.plans.group'],
    ])
  })

  it('Upravit je u nekonceptu vidět, zamčené s důvodem a bez odkazu na editor', async () => {
    const actionOf = async (status: string) => {
      m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null, issued_on: '2099-01-01',
        due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status })
      const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
      await flushPromises()
      return (wrapper.findComponent({ name: 'ActionBar' }).props('actions') as Array<{ key: string; show?: unknown; disabled?: boolean; disabledReason?: string; to?: unknown; tier?: string }>)
        .find(action => action.key === 'edit')!
    }
    const draft = await actionOf('draft')
    expect(draft.disabled).toBeFalsy()
    expect(draft.tier).toBe('secondary')
    expect(draft.to).toEqual({ name: 'other-item-edit', params: { id: 9 } })
    for (const status of ['posted', 'confirmed', 'reversed', 'cancelled']) {
      const locked = await actionOf(status)
      expect(locked.show).not.toBe(false)
      expect(locked.disabled).toBe(true)
      // Zamčené Upravit je v „…“ jako zamčené Stornovat, ne trvalý text pod lištou.
      expect(locked.tier).toBe('overflow')
      expect(locked.disabledReason).toBe(`other_items.edit_locked.${status}`)
      expect(locked.to).toBeUndefined()
    }
    // Bez práva zápisu se zamčené Upravit vůbec nenabízí.
    m.canWriteOtherItems = false
    expect((await actionOf('posted')).show).toBe(false)
    m.canWriteOtherItems = true
  })

  it('položka mimo rozvrh řádek se zdrojem nemá', async () => {
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.find('[data-test="occurrence-source"]').exists()).toBe(false)
  })

  it('štítek Zaúčtováno (odkaz do deníku) nemá role bez práva číst účetnictví, stejně jako výpis', async () => {
    m.mode = 'double_entry'
    m.noRead = ['accounting']
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'rent', title: 'Syntetický nájem', partner_name: null, issued_on: '2099-01-01', due_on: '2099-02-01',
      currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'posted', document_no: 'OZ-2099-0012', journal_entry_id: 41 })
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.findComponent(PostingBadge).exists()).toBe(false)
  })

  it('štítek Zaúčtováno pojmenuje u uhrazené položky a u storna chybí', async () => {
    m.mode = 'double_entry'
    const base = { id: 9, side: 'payable', kind: 'rent', title: 'Syntetický nájem', partner_name: null, issued_on: '2099-01-01', due_on: '2099-02-01',
      currency: 'CZK', amount: 3000, document_no: 'OZ-2099-0009', journal_entry_id: 41, posted_at: '2099-01-01 10:00:00' }
    m.get.mockResolvedValue({ ...base, paid_amount: 3000, remaining_amount: 0, status: 'posted' })
    const paid = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(paid.findComponent(PostingBadge).props('withLabel')).toBe(true)
    m.get.mockResolvedValue({ ...base, paid_amount: 0, remaining_amount: 3000, status: 'reversed' })
    const reversed = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(reversed.findComponent(PostingBadge).exists()).toBe(false)
  })

  it('rozliší neexistující položku od výpadku načtení a při chybě obnovy nechá data', async () => {
    m.get.mockRejectedValueOnce({ response: { status: 404 } })
    let wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.findComponent(EmptyState).props('title')).toBe('other_items.not_found')

    m.get.mockRejectedValueOnce({ response: { status: 500 } })
    wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.findComponent(EmptyState).props('variant')).toBe('failed')

    // Po uložení poznámky se detail znovu načte; výpadek toho načtení nesmí detail vyprázdnit.
    wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    m.updateText.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null,
      issued_on: '2099-01-01', due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000,
      status: 'confirmed', note: 'Doplněno' })
    m.get.mockRejectedValueOnce({ response: { status: 500 } })
    await wrapper.get('[data-test="note-edit"]').trigger('click')
    await wrapper.get('[data-test="note-form"] textarea').setValue('Doplněno')
    await wrapper.get('[data-test="note-form"]').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Syntetický úvěr')
    expect(wrapper.get('[role="alert"]').text()).toContain('other_items.load_failed_stale')
  })

  it('po přepnutí na jinou položku zahodí opožděnou odpověď té předchozí', async () => {
    let resolveOld!: (value: unknown) => void
    m.get.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
    m.get.mockResolvedValueOnce({ id: 10, side: 'payable', kind: 'loan', title: 'Nová položka', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-03-10', currency: 'CZK', amount: 10, paid_amount: 0, remaining_amount: 10, status: 'draft' })
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    m.route.params.id = '10'
    await flushPromises()
    resolveOld({ id: 9, side: 'payable', kind: 'loan', title: 'Stará položka', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-03-10', currency: 'CZK', amount: 10, paid_amount: 0, remaining_amount: 10, status: 'draft' })
    await flushPromises()
    expect(wrapper.text()).toContain('Nová položka')
    expect(wrapper.text()).not.toContain('Stará položka')
  })

  it('zaúčtovanou položku ukáže v sekci Zaúčtování a přeúčtování nabídne jen tam', async () => {
    m.mode = 'double_entry'
    const posted = { id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'posted',
      journal_entry_id: 55, document_no: 'OZ-2099-0001', account_code: '325', counter_account_code: '518' }
    m.get.mockResolvedValue(posted)
    let wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    const panel = wrapper.findComponent(DocumentPostingPanel)
    expect(panel.props('source')).toBe('other-items')
    expect(panel.props('externalRepost')).toEqual({ disabled: false, reason: undefined })
    expect((wrapper.findComponent(ActionBar).props('actions') as ActionItem[]).some(a => a.key === 'repost')).toBe(false)
    panel.vm.$emit('repost')
    await flushPromises()
    expect(m.listAccounts).toHaveBeenCalled()
    expect(wrapper.text()).toContain('other_items.repost_current')

    m.get.mockResolvedValue({ ...posted, paid_amount: 100, remaining_amount: 2900 })
    wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.findComponent(DocumentPostingPanel).props('externalRepost')).toEqual({ disabled: true, reason: 'other_items.repost_paid' })
  })

  it('zaúčtování konceptu potvrdí dialog s náhledem kontace', async () => {
    m.mode = 'double_entry'
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Koncept', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'draft',
      posting_rules: { origin: { event: 'origin', source: 'kind', posting_lines: [{ account_code: '518', amount: 3000 }],
        lines: [{ account_code: '518', side: 'debit', amount: 3000 }, { account_code: '325', side: 'credit', amount: 3000 }] } } })
    m.post.mockResolvedValue({})
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    ;(wrapper.findComponent(ActionBar).props('actions') as ActionItem[]).find(a => a.key === 'post')!.run!()
    await flushPromises()
    const preview = wrapper.get('[data-test="post-preview"]').text()
    expect(preview).toContain('518')
    expect(preview).toContain('325')
    expect(m.post).not.toHaveBeenCalled()
    await wrapper.get('[data-test="post-confirm-submit"]').trigger('click')
    await flushPromises()
    expect(m.post).toHaveBeenCalledWith(9)
  })

  it('bez protiúčtu je Zaúčtovat v dialogu zamčené s důvodem', async () => {
    m.mode = 'double_entry'
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Koncept', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'draft',
      posting_rules: { origin: { event: 'origin', source: 'default', posting_lines: [], lines: [{ account_code: '325', side: 'credit', amount: 3000 }] } } })
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    ;(wrapper.findComponent(ActionBar).props('actions') as ActionItem[]).find(a => a.key === 'post')!.run!()
    await flushPromises()
    const submit = wrapper.get('[data-test="post-confirm-submit"]')
    expect(submit.attributes('disabled')).toBeDefined()
    expect(submit.attributes('title')).toBe('other_items.posting.missing_counter')
    expect(submit.attributes('aria-describedby')).toBe('other-item-post-missing-counter')
    expect(wrapper.get('[data-test="post-missing-counter"]').text()).toBe('other_items.posting.missing_counter')
    await submit.trigger('click')
    expect(m.post).not.toHaveBeenCalled()
  })

  it('úhrady vedou na výpis, pokladní doklad a zápis v deníku a odpojení je tlačítko', async () => {
    m.allocations.mockResolvedValue([
      { id: 1, bank_transaction_id: 41, cash_document_id: null, amount: 100, payment_on: '2099-02-10', reversed_on: null,
        bank_statement_id: 7, cash_doc_number: null, journal_entry_id: 88 },
      { id: 2, bank_transaction_id: null, cash_document_id: 5, amount: 50, payment_on: '2099-02-11', reversed_on: null,
        bank_statement_id: null, cash_doc_number: 'PD-2099-0005', journal_entry_id: null },
    ])
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    const sources = wrapper.findAll('[data-test="allocation-source"]')
    expect(JSON.parse(sources[0]!.attributes('data-to')!)).toEqual({ name: 'bank-detail', params: { id: 7 } })
    expect(sources[1]!.text()).toContain('PD-2099-0005')
    expect(JSON.parse(sources[1]!.attributes('data-to')!)).toEqual({ name: 'accounting-cash', query: { document_id: '5' } })
    const journal = wrapper.findAll('[data-test="allocation-journal"]')
    expect(journal).toHaveLength(1)
    expect(JSON.parse(journal[0]!.attributes('data-to')!)).toEqual({ name: 'accounting-journal', query: { entry_id: '88' } })
    expect(wrapper.findAll('[data-test="allocation-unlink"]')[0]!.element.tagName).toBe('BUTTON')
  })

  it('prázdné hledání úhrady řekne, že nic nenašlo', async () => {
    m.paymentCandidates.mockResolvedValue([])
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    await wrapper.get('input[type="search"]').setValue('nic')
    await new Promise(resolve => setTimeout(resolve, 300))
    await flushPromises()
    expect(wrapper.get('[data-test="no-payment-candidates"]').text()).toBe('other_items.no_payment_candidates_search')
  })
})

describe('OtherItemDetail obnova a přepínání', () => {
  const PanelStub = { name: 'DocumentPostingPanel', props: ['source', 'docId', 'externalRepost', 'docLabel'],
    setup(_: unknown, { expose }: { expose: (value: Record<string, unknown>) => void }) { expose({ reload: m.panelReload }) },
    template: '<div class="posting-panel"><slot /></div>' }
  const base = { id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null, issued_on: '2099-01-01',
    due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'confirmed' }

  beforeEach(() => {
    vi.clearAllMocks()
    m.mode = 'tax_evidence'
    m.route = reactive({ params: { id: '9' } })
    m.noRead = []
    m.listAccounts.mockResolvedValue([])
    m.get.mockResolvedValue({ ...base })
    m.allocations.mockResolvedValue([])
    m.paymentCandidates.mockResolvedValue([])
  })

  it('po zaúčtování a po uložení dimenzí obnoví sekci Zaúčtování', async () => {
    m.mode = 'double_entry'
    m.get.mockResolvedValue({ ...base, status: 'draft', posting_rules: { origin: { lines: [], posting_lines: [{ account_code: '518', amount: 3000 }] } } })
    m.post.mockResolvedValue({})
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs: { ...stubs, DocumentPostingPanel: PanelStub } } })
    await flushPromises()
    expect(m.panelReload).not.toHaveBeenCalled()
    ;(wrapper.findComponent(ActionBar).props('actions') as ActionItem[]).find(a => a.key === 'post')!.run!()
    await flushPromises()
    m.get.mockResolvedValue({ ...base, status: 'posted', journal_entry_id: 55 })
    await wrapper.get('[data-test="post-confirm-submit"]').trigger('click')
    await flushPromises()
    expect(m.panelReload).toHaveBeenCalledTimes(1)

    wrapper.findComponent({ name: 'DocumentDimensionsPanel' }).vm.$emit('saved', {})
    expect(m.panelReload).toHaveBeenCalledTimes(2)
  })

  it('tichá obnova přepíše data, ale ne během rozepsané poznámky, hledání úhrady nebo dialogu', async () => {
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(m.canRefresh!()).toBe(true)
    m.get.mockResolvedValue({ ...base, title: 'Obnovený název' })
    await m.refresh!()
    await flushPromises()
    expect(wrapper.text()).toContain('Obnovený název')

    await wrapper.get('[data-test="note-edit"]').trigger('click')
    expect(m.canRefresh!()).toBe(false)
    await wrapper.get('[data-test="note-form"] button[type="button"]').trigger('click')
    expect(m.canRefresh!()).toBe(true)
    await wrapper.get('input[type="search"]').setValue('platba')
    expect(m.canRefresh!()).toBe(false)
  })

  it('rozepsaný kalendář nebo dialog opakování tichou obnovu odloží', async () => {
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(m.canRefresh!()).toBe(true)
    wrapper.findComponent({ name: 'OtherItemPlans' }).vm.$emit('state', { canSchedule: false, canSplit: false, editing: true })
    await flushPromises()
    expect(m.canRefresh!()).toBe(false)
  })

  it('rozepsané období rozlišení tichou obnovu odloží a přepnutí na jinou položku ho zavře', async () => {
    m.mode = 'double_entry'
    const posted = { id: 9, side: 'receivable', kind: 'claim', title: 'Syntetický nájem', partner_name: null, issued_on: '2099-07-01',
      due_on: '2099-07-15', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'posted',
      journal_entry_id: 77, accrual_from: null, accrual_to: null }
    m.get.mockResolvedValue(posted)
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(m.canRefresh!()).toBe(true)
    await wrapper.get('[data-test="accrual-edit"]').trigger('click')
    expect(m.canRefresh!()).toBe(false)
    m.route.params.id = '10'
    await flushPromises()
    expect(wrapper.find('[data-test="accrual-form"]').exists()).toBe(false)
    expect(m.canRefresh!()).toBe(true)
  })

  it('obnova po smazání položky v jiném okně ukáže, že položka neexistuje', async () => {
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    m.get.mockRejectedValueOnce({ response: { status: 404 } })
    await m.refresh!()
    await flushPromises()
    expect(wrapper.findComponent(EmptyState).props('title')).toBe('other_items.not_found')
  })

  it('přepnutí na jinou položku zavře rozepsanou poznámku a zapomene „nenalezeno“', async () => {
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    await wrapper.get('[data-test="note-edit"]').trigger('click')
    m.get.mockRejectedValueOnce({ response: { status: 404 } })
    m.route.params.id = '10'
    await flushPromises()
    expect(wrapper.findComponent(EmptyState).props('title')).toBe('other_items.not_found')

    m.get.mockRejectedValueOnce({ response: { status: 500 } })
    m.route.params.id = '11'
    await flushPromises()
    expect(wrapper.findComponent(EmptyState).props('variant')).toBe('failed')

    m.route.params.id = '12'
    await flushPromises()
    expect(wrapper.find('[data-test="note-form"]').exists()).toBe(false)
  })

  it('u rozdělených splátek nehlásí uhrazeno, dokud zbývá úrok nebo poplatek', async () => {
    m.get.mockResolvedValue({ ...base, paid_amount: 3000, remaining_amount: 0, remaining_payment: 70, has_split_installments: true })
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.get('[data-test="payment-state"]').text()).toBe('partial')
    expect(wrapper.get('[data-test="detail-status"]').classes()).toContain('badge-partially_paid')
  })
})

describe('OtherItemDetail kolo 2', () => {
  const base = { id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null, issued_on: '2099-01-01',
    due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'confirmed' }

  beforeEach(() => {
    vi.clearAllMocks()
    m.mode = 'tax_evidence'
    m.noRead = []
    m.route = reactive({ params: { id: '9' } })
    m.listAccounts.mockResolvedValue([])
    m.get.mockResolvedValue({ ...base })
    m.allocations.mockResolvedValue([])
    m.paymentCandidates.mockResolvedValue([])
  })

  it('role bez čtení účetnictví nedostane sekci Zaúčtování, vznik a přeúčtování najde v části Účtování', async () => {
    m.mode = 'double_entry'
    m.noRead = ['accounting']
    m.get.mockResolvedValue({ ...base, status: 'posted', journal_entry_id: 55, posting_rules: { payment_bank: { event: 'payment_bank' } } })
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.findComponent(DocumentPostingPanel).exists()).toBe(false)
    const rules = wrapper.findComponent({ name: 'OtherItemPostingRules' })
    expect(rules.props('showPostedOrigin')).toBe(true)
    expect(rules.props('canRepost')).toBe(true)
    rules.vm.$emit('repost')
    await flushPromises()
    expect(wrapper.text()).toContain('other_items.repost_current')

    m.noRead = []
    const withJournal = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(withJournal.findComponent(DocumentPostingPanel).exists()).toBe(true)
    expect(withJournal.findComponent({ name: 'OtherItemPostingRules' }).props('canRepost')).toBe(false)
  })

  it('účet protistrany se během opakovaného načtení neuloží a tichá obnova pak zase běží', async () => {
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    m.updateText.mockResolvedValueOnce({ ...base, note: 'Nová' })
    m.get.mockRejectedValueOnce(new Error('500'))
    await wrapper.get('[data-test="note-edit"]').trigger('click')
    await wrapper.get('[data-test="note-form"]').trigger('submit')
    await flushPromises()
    let finish!: (value: unknown) => void
    m.get.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    await wrapper.get('[role="alert"] button').trigger('click')
    await wrapper.get('[data-test="counterparty-edit"]').trigger('click')
    expect(wrapper.get('[data-test="counterparty-save"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="counterparty-form"]').trigger('submit')
    expect(m.updateCounterpartyAccount).not.toHaveBeenCalled()
    finish({ ...base, title: 'Načteno znovu' })
    await flushPromises()
    expect(wrapper.text()).toContain('Načteno znovu')
    await wrapper.get('[data-test="counterparty-form"] button[type="button"]').trigger('click')
    expect(m.canRefresh!()).toBe(true)
  })

  it('uložený účet protistrany předchozí položky nepřepíše nově otevřenou', async () => {
    let finish!: (value: unknown) => void
    m.updateCounterpartyAccount.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    await wrapper.get('[data-test="counterparty-edit"]').trigger('click')
    await wrapper.get('[data-test="counterparty-form"]').trigger('submit')
    m.get.mockResolvedValue({ ...base, id: 10, title: 'Druhá položka' })
    m.route.params.id = '10'
    await flushPromises()
    finish({ ...base, title: 'Syntetický úvěr' })
    await flushPromises()
    expect(wrapper.text()).toContain('Druhá položka')
    expect(wrapper.text()).not.toContain('Syntetický úvěr')
  })

  it('kandidáti úhrady předchozí položky se po přepnutí nezobrazí', async () => {
    vi.useFakeTimers()
    let finish!: (value: unknown) => void
    m.paymentCandidates.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    await wrapper.get('input[type="search"]').setValue('x')
    vi.advanceTimersByTime(300)
    m.route.params.id = '10'
    await flushPromises()
    finish([{ source: 'bank', id: 41, payment_on: '2099-03-09', amount: 1000, currency: 'CZK', description: 'Platba A', match_reasons: [] }])
    await flushPromises()
    expect(wrapper.text()).not.toContain('Platba A')
    vi.useRealTimers()
  })
})

describe('OtherItemDetail kolo 3', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.mode = 'tax_evidence'
    m.noRead = []
    m.route = reactive({ params: { id: '9' } })
    m.listAccounts.mockResolvedValue([])
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null,
      issued_on: '2099-01-01', due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'confirmed' })
    m.allocations.mockResolvedValue([])
    m.paymentCandidates.mockResolvedValue([])
  })

  it('přechod na jinou položku nespustí hledání úhrady, o které nikdo nežádal', async () => {
    vi.useFakeTimers()
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    await wrapper.get('input[type="search"]').setValue('x')
    vi.advanceTimersByTime(300)
    await flushPromises()
    expect(m.paymentCandidates).toHaveBeenCalledTimes(1)
    m.route.params.id = '10'
    await flushPromises()
    vi.advanceTimersByTime(300)
    await flushPromises()
    expect(m.paymentCandidates).toHaveBeenCalledTimes(1)
    vi.useRealTimers()
  })
})

describe('OtherItemDetail kolo 4', () => {
  it('vymazání pole hledání uživatelem vrátí výchozí nabídku plateb', async () => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    m.mode = 'tax_evidence'
    m.noRead = []
    m.route = reactive({ params: { id: '9' } })
    m.listAccounts.mockResolvedValue([])
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null,
      issued_on: '2099-01-01', due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'confirmed' })
    m.allocations.mockResolvedValue([])
    m.paymentCandidates.mockResolvedValue([{ source: 'bank', id: 41, payment_on: '2099-03-09', amount: 1000, currency: 'CZK',
      description: 'Výchozí platba', match_reasons: [] }])
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs }, attachTo: document.body })
    await flushPromises()
    const input = wrapper.get('input[type="search"]')
    ;(input.element as HTMLInputElement).focus()
    await input.setValue('xyz')
    vi.advanceTimersByTime(300)
    await flushPromises()
    await input.setValue('')
    vi.advanceTimersByTime(300)
    await flushPromises()
    expect(m.paymentCandidates).toHaveBeenLastCalledWith(9, '')
    expect(wrapper.text()).toContain('Výchozí platba')
    wrapper.unmount()
    vi.useRealTimers()
  })
})

describe('OtherItemDetail návrat na seznam', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.mode = 'tax_evidence'
    m.noRead = []
    m.route = reactive({ params: { id: '9' } })
    m.listAccounts.mockResolvedValue([])
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null,
      issued_on: '2099-01-01', due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'draft' })
    m.allocations.mockResolvedValue([])
  })

  it('„Zpět“ vede na seznam se zapamatovanými filtry a nabídne šipky výběru', async () => {
    m.listPath = '/other-items?side=payable&status=all'
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.get('[data-test="back-to-list"]').attributes('data-to')).toBe('"/other-items?side=payable&status=all"')
    expect(wrapper.findComponent({ name: 'ListNavArrows' }).exists()).toBe(true)
  })

  it('bez zapamatovaného výběru vede na výchozí seznam', async () => {
    m.listPath = null
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(JSON.parse(wrapper.get('[data-test="back-to-list"]').attributes('data-to')!)).toEqual({ name: 'other-items' })
    expect(wrapper.findComponent({ name: 'ListNavArrows' }).exists()).toBe(false)
  })
})

describe('OtherItemDetail listování ve výběru', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.mode = 'tax_evidence'
    m.noRead = []
    m.listPath = '/other-items?side=payable&status=all'
    m.route = reactive({ params: { id: '9' } })
    m.listAccounts.mockResolvedValue([])
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null,
      issued_on: '2099-01-01', due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'confirmed' })
    m.allocations.mockResolvedValue([])
  })

  it('při rozepsané poznámce klávesy nepřepnou položku', async () => {
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(m.navOptions.hotkeysEnabled()).toBe(true)
    await wrapper.get('[data-test="note-edit"]').trigger('click')
    expect(m.navOptions.hotkeysEnabled()).toBe(false)
  })

  it('po smazání konceptu vrátí seznam se zapamatovanými filtry', async () => {
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Koncept', partner_name: null,
      issued_on: '2099-01-01', due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'draft' })
    m.remove.mockResolvedValue({})
    vi.spyOn(window, 'confirm').mockReturnValueOnce(true)
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    ;(wrapper.findComponent(ActionBar).props('actions') as ActionItem[]).find(a => a.key === 'delete')!.run!()
    await flushPromises()
    expect(m.push).toHaveBeenCalledWith('/other-items?side=payable&status=all')
  })

  it('další stránku výběru načte se stejnými parametry jako seznam', async () => {
    shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    m.list.mockResolvedValue({ items: [{ source_id: 51 }, { source_id: 52 }], total: 120, per_page: 50 })
    const page = await m.navOptions.fetchPage({ status: 'all', sort_by: 'amount', sort_dir: 'asc' }, 2)
    expect(m.list).toHaveBeenCalledWith({ status: 'all', sort_by: 'amount', sort_dir: 'asc', page: 2 })
    expect(page).toEqual({ ids: [51, 52], pages: 3 })
  })

  it('u nenalezené položky vede zpět na seznam se zapamatovanými filtry', async () => {
    m.get.mockRejectedValueOnce({ response: { status: 404 } })
    const wrapper = shallowMount(OtherItemDetail, { global: { stubs } })
    await flushPromises()
    expect(wrapper.findComponent(EmptyState).props('to')).toBe('/other-items?side=payable&status=all')
  })
})
