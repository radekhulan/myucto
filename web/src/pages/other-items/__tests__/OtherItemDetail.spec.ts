import { flushPromises, shallowMount } from '@vue/test-utils'
import { reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  allocations: vi.fn(),
  paymentCandidates: vi.fn(),
  updateCounterpartyAccount: vi.fn(),
  updateText: vi.fn(),
  removePosted: vi.fn(),
  post: vi.fn(),
  listAccounts: vi.fn(),
  mode: 'tax_evidence',
  route: null as any,
  refresh: null as null | (() => Promise<unknown>),
  canRefresh: null as null | (() => boolean),
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
    updateCounterpartyAccount: m.updateCounterpartyAccount, updateText: m.updateText, removePosted: m.removePosted, post: m.post, list: m.list, remove: m.remove },
}))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: m.listAccounts } }))
vi.mock('@/composables/useWindowRefresh', () => ({
  useWindowRefresh: (refresh: () => Promise<unknown>, canRefresh: () => boolean) => { m.refresh = refresh; m.canRefresh = canRefresh },
}))
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
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: (key: string) => !m.noRead.includes(key) }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_mode: m.mode } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
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
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DocumentPostingPanel from '@/components/accounting/DocumentPostingPanel.vue'

const RouterLinkStub = { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' }
const ModalStub = { template: '<div><slot /><slot name="footer" /></div>' }
const stubs = { RouterLink: RouterLinkStub, Modal: ModalStub }

describe('OtherItemDetail', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.mode = 'tax_evidence'
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
    m.get.mockResolvedValue({ ...base, status: 'draft', posting_rules: { origin: { lines: [], posting_lines: [] } } })
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
