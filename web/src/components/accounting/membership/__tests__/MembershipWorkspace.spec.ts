import { appIsoDate } from '@/utils/date'
import { config, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { defineComponent, h, KeepAlive, ref, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { formatDate } from '@/composables/useFormat'
import Workspace from '../MembershipWorkspace.vue'
import { markListReturn, readListHandoff, saveListHandoff } from '../membershipPage'
import MembershipBatchFlow from '../MembershipBatchFlow.vue'
import FilterBar from '@/components/ui/FilterBar.vue'
import type { MemberPage, MemberRow } from '@/api/nonprofitMembership'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({ members: vi.fn(), createMember: vi.fn(), titles: vi.fn(), selectionCheck: vi.fn(), exportMembers: vi.fn() }))
const navigation = vi.hoisted(() => ({route: {query: {}} as {query: Record<string, string>; fullPath?: string}, replace: vi.fn()}))
vi.mock('vue-router', async importOriginal => ({...await importOriginal<typeof import('vue-router')>(), useRoute: () => navigation.route, useRouter: () => ({replace: navigation.replace})}))
const accounting = vi.hoisted(() => ({ listPeriods: vi.fn() }))
vi.mock('@/api/nonprofitMembership', () => ({ membershipApi: api }))
vi.mock('@/api/accounting', () => ({ accountingApi: accounting }))
// Preference tabulky (sloupce, hustota) se v testu nenačítají ze serveru.
// Direktiva popisků sloupců se registruje v main.ts; v testu stačí prázdná.
config.global.directives = { ...config.global.directives, 'column-labels': {} }
vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: () => Promise.resolve(), getPagePrefs: () => ({ value: {} }), patchPagePrefs: vi.fn() }))
vi.mock('@/api/clients', () => ({ clientsApi: {} }))
// Detail člena je samostatná stránka; řádek jen naviguje (Ctrl+klik a prostřední tlačítko do nového panelu).
const rowLink = vi.hoisted(() => ({ navigate: vi.fn() }))
vi.mock('@/composables/useRowLink', () => ({ useRowLink: () => rowLink.navigate }))
config.global.stubs = { ...config.global.stubs, RouterLink: RouterLinkStub }
// Potvrzení a varování jdou jako v celé aplikaci do toastu.
const toast = vi.hoisted(() => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn(), info: vi.fn() }))
vi.mock('@/composables/useToast', () => ({ useToast: () => toast }))
vi.mock('@/api/nonprofitDppo', () => ({ nonprofitDppoApi: { profile: vi.fn() } }))

const ActionBar = defineComponent({
  props: ['actions'],
  setup: p => () => h('div', (p.actions as Array<{ key: string; label: string; disabled?: boolean; show?: unknown; run: () => void }>)
    .filter(a => a.show !== false).map(a => h('button', { 'data-action': a.key, disabled: a.disabled, onClick: a.run }, a.label))),
})
const ClientSearchSelect = defineComponent({
  name: 'ClientSearchSelect', props: ['modelValue', 'selectedLabel'], emits: ['update:modelValue', 'selected'],
  setup: (p, { emit, attrs }) => () => h('select', {
    ...attrs, value: p.modelValue ?? '',
    onChange: (e: Event) => {
      const id = Number((e.target as HTMLSelectElement).value) || null
      emit('update:modelValue', id)
      emit('selected', id ? { id, company_name: `Synthetic client ${id}` } : null)
    },
  }, [h('option', { value: '' }), h('option', { value: '3' }, 'Synthetic client 3')]),
})
const SearchableSelect = defineComponent({ props: ['modelValue', 'options', 'disabled'], emits: ['update:modelValue'], setup: (p, {emit, attrs}) => () => h('select', { ...attrs, value: p.modelValue ?? '', disabled: p.disabled,
  onChange: (e: Event) => { const option = (p.options as {value: string | number}[]).find(x => String(x.value) === (e.target as HTMLSelectElement).value); emit('update:modelValue', option?.value ?? null) },
}, [h('option', {value: ''}), ...(p.options as {value: string | number; label: string}[]).map(x => h('option', {value: x.value}, x.label))]) })
const ClientFormModal = defineComponent({ name: 'ClientFormModal', emits: ['created', 'close'], setup: () => () => h('div', { 'data-stub': 'client-modal' }) })
const ImportFlow = defineComponent({ name: 'MembershipImportFlow', props: ['supplier', 'canManage'], emits: ['changed', 'close', 'lock'], setup: () => () => h('div', { 'data-stub': 'import' }) })
// Okno a lišta hromadných akcí se v testu vykreslí na místě (bez teleportu).
const Modal = defineComponent({ name: 'Modal', props: ['title', 'widthClass'], emits: ['close'], setup: (p, { slots }) => () => h('div', { 'data-stub': 'modal', 'data-title': p.title }, [slots.default?.(), slots.footer?.()]) })
const BulkActionBar = defineComponent({ name: 'BulkActionBar', props: ['count'], emits: ['clear'], setup: (p, { slots }) => () => (p.count as number) > 0 ? h('div', { 'data-stub': 'bulk-bar', 'data-count': p.count }, slots.default?.()) : null })
const Reconciliation = defineComponent({ name: 'MembershipReconciliation', props: ['supplier', 'periods', 'dataVersion'], setup: () => () => h('div', { 'data-stub': 'reconciliation' }) })

const row = (id: number, revision: Partial<MemberRow> = {}): MemberRow => ({
  id, supplier_id: 5, client_id: id + 10, member_number: `M-${id}`, created_by: 1, company_name: `Synthetic member ${id}`,
  revision_no: 1, effective_from: '2020-01-01', status: 'active', ...revision,
})
const page = (items: MemberRow[], next: number | null = null): MemberPage => ({ items, next_after_id: next })
const period = { id: 11, supplier_id: 5, fiscal_year: 2026, starts_on: '2000-01-01', ends_on: '2099-12-31', status: 'open', closed_at: null, created_at: '' }

function mounted(props: Record<string, unknown> = {}, locale = 'cs') {
  return mount(Workspace, {
    props: { supplier: 5, canManage: true, ...props },
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { cs, en } })],
      stubs: { ActionBar, SearchableSelect, ClientSearchSelect, ClientFormModal, MembershipReconciliation: Reconciliation, MembershipBatchFlow: true, MembershipImportFlow: ImportFlow, Modal, BulkActionBar, MembershipRates: true, MembershipErasure: true },
    },
  })
}

const DETAIL = (id: number) => `/accounting/nonprofit-membership/members/${id}`
/** Poslední navigace řádku: cíl a zda jde do nového panelu (Ctrl). */
/** Kontrola s účetnictvím je na vlastní záložce; verze dat se čte z ní. */
async function reconciliationVersion(w: ReturnType<typeof mounted>) {
  await w.get('[data-tab="reconciliation"]').trigger('click'); await flushPromises()
  return w.getComponent(Reconciliation).props('dataVersion')
}
const lastNavigation = () => { const [to, event] = rowLink.navigate.mock.lastCall ?? []; return { to, newPanel: !!(event as MouseEvent | undefined)?.ctrlKey } }

beforeEach(() => {
  vi.clearAllMocks()
  sessionStorage.clear()
  navigation.route = reactive({query: {}})
  navigation.replace.mockImplementation(({query}: {query: Record<string, string>}) => {navigation.route.query = query; return Promise.resolve()})
  api.titles.mockResolvedValue({items: ['SYNTHETIC'], next_after_title_key: null})
  api.selectionCheck.mockImplementation((_supplier: number, ids: number[]) => Promise.resolve({member_ids: ids}))
  Element.prototype.scrollIntoView = vi.fn()
  accounting.listPeriods.mockResolvedValue([{ ...period, id: 10, fiscal_year: 2025 }, period, { ...period, id: 12, fiscal_year: 2027 }])
  api.members.mockResolvedValue(page([
    row(1),
    row(2, { revision_no: 2, effective_from: '2999-01-01', status: 'ended' }),
    row(3, { company_name: undefined, revision_no: null, effective_from: null, status: null }),
  ]))
  api.createMember.mockResolvedValue({ id: 9, supplier_id: 5, client_id: 3, member_number: 'M-9', created_by: 1 })
})

describe('Membership workspace', () => {
  it('adds the current application year when filtering payment status across a title history', async () => {
    navigation.route.query = {year:'',title_key:'SYNTHETIC',payment_status:'all'}
    const w=mounted(); await flushPromises()
    await w.get('[data-field="member-payment-status"]').setValue('unpaid'); await flushPromises()
    expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({year:Number(appIsoDate().slice(0,4)),payment_status:'unpaid'}))
    expect(w.get('[data-section="status-year-help"]').text()).toBe(cs.nonprofitMembership.listStatusNeedsYear)
    w.unmount()
  })

  it('preserves selection from unloaded pages on refresh and reconciles it with the server', async () => {
    api.members.mockResolvedValueOnce(page([row(1)], 1)).mockResolvedValueOnce(page([row(90)]))
    const w = mounted(); await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-action="more-members"]').trigger('click'); await flushPromises()
    await w.get('[data-member="90"] [data-action="batch-check-member"]').setValue(true)
    api.members.mockResolvedValue(page([row(1)], 1))
    await w.get('[data-action="reload-members"]').trigger('click'); await flushPromises()
    await w.get('[data-action="batch-open"]').trigger('click'); await flushPromises()
    expect(w.findComponent(MembershipBatchFlow).props('selectedMemberIds')).toEqual([1,90])
    expect(api.selectionCheck).toHaveBeenCalledWith(5, [1,90], expect.objectContaining({payment_status: 'all'}))
    w.unmount()
  })
  it('restores URL filters and debounces search by 300 ms', async () => {
    navigation.route.query = {year: '2027', title_key: 'SYNTHETIC', payment_status: 'unproven', search: 'Synthetic'}
    const w = mounted(); await flushPromises()
    expect(api.members).toHaveBeenLastCalledWith(5, 0, 50, {search: 'Synthetic', year: 2027, title_key: 'SYNTHETIC', payment_status: 'unproven'})
    vi.useFakeTimers()
    const calls = api.members.mock.calls.length
    await w.get('[data-field="member-search"]').setValue('Synthetic person')
    await vi.advanceTimersByTimeAsync(299)
    expect(api.members).toHaveBeenCalledTimes(calls)
    await vi.advanceTimersByTimeAsync(1); await flushPromises()
    expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({search:'Synthetic person'}))
    expect(navigation.replace).toHaveBeenCalledWith({query: expect.objectContaining({search:'Synthetic person'})})
    vi.useRealTimers(); w.unmount()
  })
  it('loads every page of title choices and displays a natural person label', async () => {
    api.titles.mockResolvedValueOnce({items:['A'],next_after_title_key:'A'}).mockResolvedValueOnce({items:['B'],next_after_title_key:null})
    api.members.mockResolvedValue(page([row(1,{company_name:'',first_name:'Syntetická',last_name:'Osoba'})]))
    const w=mounted(); await flushPromises()
    expect(w.get('[data-field="member-title"]').text()).toContain('B')
    expect(api.titles).toHaveBeenLastCalledWith(5,expect.any(Number),'A')
    expect(w.get('[data-member="1"]').text()).toContain('Syntetická Osoba')
    w.unmount()
  })

  it('does not retain selectable stale rows or a cursor after a failed filter change', async () => {
    api.members.mockResolvedValueOnce(page([row(1)],1))
    const w=mounted();await flushPromises()
    expect(w.find('[data-action="more-members"]').exists()).toBe(true)
    api.members.mockRejectedValueOnce(new Error('Synthetic filter failure'))
    await w.get('[data-field="member-payment-status"]').setValue('paid'); await flushPromises()
    expect(w.findAll('[data-section="member-row"]')).toHaveLength(0)
    expect(w.find('[data-action="more-members"]').exists()).toBe(false)
    w.unmount()
  })

  it('renders members as a table with a phone card sharing the batch selection and opening on click', async () => {
    const w=mounted();await flushPromises()
    const rowEl=w.get('[data-member="1"]')
    expect(rowEl.element.tagName).toBe('TR')
    // Výběr, číslo a jméno jsou v první (lepivé) buňce, aby identifikace člena při posunu nezmizela.
    const first=rowEl.findAll('td')[0]
    expect(first.find('[data-action="batch-check-member"]').exists()).toBe(true)
    expect(first.get('[data-field="member-number"]').text()).toBe('M-1')
    expect(first.get('[data-field="member-name"]').text()).toBe('Synthetic member 1')
    expect(w.findAll('thead th').map(th => th.text())).toEqual(expect.arrayContaining([cs.nonprofitMembership.listColumns.name, cs.nonprofitMembership.listColumns.prescribed, cs.nonprofitMembership.listColumns.paid, cs.nonprofitMembership.listColumns.open]))
    // Počet vybraných nese lišta hromadných akcí; bez výběru se nevykresluje.
    expect(w.find('[data-stub="bulk-bar"]').exists()).toBe(false)
    await w.get('[data-member-card="1"] [data-action="batch-check-member"]').setValue(true)
    expect((w.get('[data-member="1"] [data-action="batch-check-member"]').element as HTMLInputElement).checked).toBe(true)
    expect(w.get('[data-stub="bulk-bar"]').attributes('data-count')).toBe('1')
    // Zaškrtnutí detail neotevírá; klik na kartu i na buňku řádku otevře samostatnou stránku člena.
    expect(rowLink.navigate).not.toHaveBeenCalled()
    await w.get('[data-member-card="2"]').trigger('click');await flushPromises()
    expect(lastNavigation()).toEqual({ to: DETAIL(2), newPanel: false })
    await w.get('[data-member="1"] td:nth-child(3)').trigger('click');await flushPromises()
    expect(lastNavigation()).toEqual({ to: DETAIL(1), newPanel: false })
    w.unmount()
  })

  it('opens a member as a separate page like a contact, with a real link and a new panel on Ctrl+click', async () => {
    const w=mounted();await flushPromises()
    expect(w.find('[data-section="member-detail-pane"]').exists()).toBe(false)
    expect(w.get('[data-member="1"] [data-field="member-name"]').findComponent(RouterLinkStub).props('to')).toBe(DETAIL(1))
    await w.get('[data-member="1"] [data-field="member-name"]').trigger('click');await flushPromises()
    expect(lastNavigation()).toEqual({ to: DETAIL(1), newPanel: false })
    await w.get('[data-member="2"]').trigger('click', { ctrlKey: true });await flushPromises()
    expect(lastNavigation()).toEqual({ to: DETAIL(2), newPanel: true })
    // Tabulka zůstává celá: sloupce se otevřením nemění.
    expect(w.findAll('thead th').map(th => th.text())).toEqual(expect.arrayContaining([cs.nonprofitMembership.listColumns.membership, cs.nonprofitMembership.listColumns.prescribed]))
    w.unmount()
  })

  it('opens a member in a new panel while a batch or an import is open, so the unsaved work stays', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    await w.get('[data-member="1"] [data-field="member-name"]').trigger('click');await flushPromises()
    expect(lastNavigation()).toEqual({ to: DETAIL(1), newPanel: true })
    w.findComponent(MembershipBatchFlow).vm.$emit('close');await flushPromises()
    await w.get('[data-action="import-open"]').trigger('click');await flushPromises()
    await w.get('[data-member-card="2"]').trigger('click');await flushPromises()
    expect(lastNavigation()).toEqual({ to: DETAIL(2), newPanel: true })
    w.findComponent(ImportFlow).vm.$emit('close');await flushPromises()
    await w.get('[data-member="3"]').trigger('click');await flushPromises()
    expect(lastNavigation()).toEqual({ to: DETAIL(3), newPanel: false })
    w.unmount()
  })

  it('hands the list address, batch selection and opened member over to a detail in the same panel', async () => {
    navigation.route = reactive({query: {year: '2026', search: 'Synthetic'}, fullPath: '/accounting/nonprofit-membership?year=2026&search=Synthetic'}) as typeof navigation.route
    const first=mounted();await flushPromises()
    await first.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await first.get('[data-member="2"] [data-action="batch-check-member"]').setValue(true)
    await first.get('[data-member="3"] [data-field="member-name"]').trigger('click')
    expect(readListHandoff(5, null)).toMatchObject({ path: '/accounting/nonprofit-membership?year=2026&search=Synthetic', ids: [1,2], openedId: 3 })
    first.unmount()
    markListReturn(5, null)
    // Návrat: výběr se obnoví jednou a jako u každé obnovy ověří na serveru (člen 2 mezitím filtru nevyhovuje).
    api.selectionCheck.mockResolvedValueOnce({member_ids: [1]})
    const back=mount(Workspace, { attachTo: document.body, props: { supplier: 5, canManage: true }, global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })], stubs: { ActionBar, ClientSearchSelect, MembershipReconciliation: Reconciliation } } })
    await flushPromises()
    expect(api.selectionCheck).toHaveBeenCalledWith(5, [1,2], expect.objectContaining({search:'Synthetic'}))
    expect((back.get('[data-member="1"] [data-action="batch-check-member"]').element as HTMLInputElement).checked).toBe(true)
    expect((back.get('[data-member="2"] [data-action="batch-check-member"]').element as HTMLInputElement).checked).toBe(false)
    expect(toast.warning).toHaveBeenCalledWith(cs.nonprofitMembership.listSelectionRefreshed)
    // Fokus na řádek člena, ze kterého se detail otevřel.
    expect(document.activeElement).toBe(back.get('[data-member="3"] [data-field="member-name"]').element)
    expect(readListHandoff(5, null)).toBeNull()
    back.unmount()
    const again=mounted();await flushPromises()
    expect((again.get('[data-member="1"] [data-action="batch-check-member"]').element as HTMLInputElement).checked).toBe(false)
    again.unmount()
  })

  it('does not revive a selection cancelled by a filter change, nor hand it to another company', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-member="2"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-field="member-payment-status"]').setValue('unpaid');await flushPromises()
    expect(toast.warning).toHaveBeenCalledWith(cs.nonprofitMembership.listSelectionCleared)
    await w.get('[data-member="1"] [data-field="member-name"]').trigger('click')
    w.unmount()
    markListReturn(5, null)
    navigation.route = reactive({query: {}})
    const back=mounted();await flushPromises()
    expect(api.selectionCheck).not.toHaveBeenCalledWith(5, [1,2], expect.anything())
    expect((back.get('[data-member="1"] [data-action="batch-check-member"]').element as HTMLInputElement).checked).toBe(false)
    back.unmount()

    saveListHandoff({ supplier: 5, pane: null, returning: true, path: '/accounting/nonprofit-membership', filters: {search:'',year:Number(appIsoDate().slice(0,4)),title_key:'',payment_status:'all'}, ids: [1], openedId: 1 })
    const other=mounted({supplier:6});await flushPromises()
    expect((other.get('[data-member="1"] [data-action="batch-check-member"]').element as HTMLInputElement).checked).toBe(false)
    other.unmount()
  })

  it('opens a member in a new panel without a handoff on Ctrl+click, while exporting, and after creating during a batch', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"]').trigger('click', { ctrlKey: true })
    expect(lastNavigation()).toEqual({ to: DETAIL(1), newPanel: true })
    expect(readListHandoff(5, null)).toBeNull()
    // Běžící export by odchodem ze seznamu tiše skončil.
    api.exportMembers.mockReturnValueOnce(new Promise(() => {}))
    await w.get('[data-action="export-xlsx"]').trigger('click');await flushPromises()
    await w.get('[data-member="2"] [data-field="member-name"]').trigger('click')
    expect(lastNavigation()).toEqual({ to: DETAIL(2), newPanel: true })
    w.unmount()
    // Rozběhnutý hromadný předpis zamyká založení člena: jeho okno by se otevřelo nad dávkou.
    const b=mounted();await flushPromises()
    await b.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    b.findComponent(MembershipBatchFlow).vm.$emit('lock', true);await flushPromises()
    expect(b.get('[data-action="create-open"]').attributes('disabled')).toBeDefined()
    b.unmount()
  })
  it('hides the selection column without write access', async () => {
    const w=mounted({canManage:false});await flushPromises()
    expect(w.find('[data-action="batch-check-member"]').exists()).toBe(false)
    expect(w.get('[data-member="1"]').findAll('td')[0].get('[data-field="member-number"]').text()).toBe('M-1')
    expect(w.find('[data-section="batch-selected-count"]').exists()).toBe(false)
    w.unmount()
  })

  it('offers clearing filters from an empty filtered list, but not while a batch is locked', async () => {
    navigation.route.query = {search: 'Nobody'}
    api.members.mockResolvedValue(page([]))
    const w=mounted();await flushPromises()
    const cta=w.get('[data-section="members-empty"] [data-empty-state="filtered"] [data-test="empty-state-cta"]')
    api.members.mockResolvedValue(page([row(1)]))
    await cta.trigger('click');await flushPromises()
    expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({search:''}))
    expect(w.findAll('[data-section="member-row"]')).toHaveLength(1)
    w.unmount()

    navigation.route.query = {search: 'Nobody'}
    api.members.mockResolvedValue(page([]))
    const locked=mounted();await flushPromises()
    await locked.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    locked.findComponent(MembershipBatchFlow).vm.$emit('lock', true);await flushPromises()
    expect(locked.find('[data-test="empty-state-cta"]').exists()).toBe(false)
    locked.unmount()
  })

  it('clears batch selection when a data refresh removes members from the current filter', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    api.members.mockResolvedValueOnce(page([]))
    api.selectionCheck.mockResolvedValueOnce({member_ids: []})
    await w.get('[data-action="reload-members"]').trigger('click');await flushPromises()
    expect(w.findComponent(MembershipBatchFlow).props('selectedMemberIds')).toEqual([])
    w.unmount()
  })

  it('does not discard selected members when applying unchanged filters', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    expect(w.find('[data-action="filter-members"]').exists()).toBe(false)
    await w.get('[data-field="member-year"]').setValue(appIsoDate().slice(0,4));await flushPromises()
    expect((w.get('[data-member="1"] [data-action="batch-check-member"]').element as HTMLInputElement).checked).toBe(true)
    w.unmount()
  })
  it('applies an edited numeric year without assuming the input model stays a string', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-field="member-year"]').setValue('2027')
    await new Promise(resolve => setTimeout(resolve,320));await flushPromises()
    expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({year:2027}))
    w.unmount()
  })
  it('sends search, title and proven-unpaid filters to the server and clears checked members', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-field="member-search"]').setValue('Synthetic')
    await w.get('[data-field="member-year"]').setValue('')
    await w.get('[data-field="member-title"]').setValue('SYNTHETIC')
    await w.get('[data-field="member-payment-status"]').setValue('unpaid')
    await new Promise(resolve => setTimeout(resolve,320));await flushPromises()
    expect(api.members).toHaveBeenLastCalledWith(5,0,50,{search:'Synthetic',year:Number(appIsoDate().slice(0,4)),title_key:'SYNTHETIC',payment_status:'unpaid'})
    expect((w.get('[data-member="1"] [data-action="batch-check-member"]').element as HTMLInputElement).checked).toBe(false)
    expect(toast.warning).toHaveBeenCalledWith(cs.nonprofitMembership.listSelectionCleared)
    w.unmount()
  })

  it('shows unproven payments without presenting an unknown open balance as debt', async () => {
    api.members.mockResolvedValue(page([row(1,{contribution:{status:'unproven',claim_count:1,prescribed_cents:'50000',paid_cents:null,open_cents:null}})]))
    const w=mounted();await flushPromises()
    expect(w.get('[data-field="contribution-status"]').text()).toBe(cs.nonprofitMembership.listStatus_unproven)
    expect(w.get('[data-member="1"] [data-field="contribution-prescribed"]').text()).toContain('500,00')
    expect(w.get('[data-member="1"] [data-field="contribution-paid"]').text()).toBe('—')
    expect(w.get('[data-member="1"] [data-field="contribution-open"]').text()).toBe('—')
    // Mobilní karta ukazuje tytéž částky v jednom řádku.
    expect(w.get('[data-member-card="1"] [data-field="contribution-amounts"]').text()).toContain('500,00')
    expect(w.get('[data-member-card="1"] [data-field="contribution-amounts"]').text()).toContain('—')
    expect(w.find('[data-field="contribution-unpaired"]').exists()).toBe(false)
    w.unmount()
  })

  it('shows the few filters inline in the shared filter bar, search with a placeholder instead of a label', async () => {
    const w=mounted();await flushPromises()
    // Tři filtry se vejdou do řádku (na telefonu je schová lišta sama); sloupce a hustota jsou v liště vpravo.
    expect(w.findComponent(FilterBar).props('collapsible')).toBeFalsy()
    const search = w.get('[data-field="member-search"]')
    expect(search.attributes('placeholder')).toBe(cs.nonprofitMembership.listSearch)
    expect(search.attributes('aria-label')).toBe(cs.nonprofitMembership.listSearch)
    expect(w.get('[data-field="member-year"]').attributes('aria-label')).toBe(cs.nonprofitMembership.listYear)
    expect(w.get('[data-field="member-payment-status"]').attributes('aria-label')).toBe(cs.nonprofitMembership.listPaymentStatus)
    expect(w.findComponent(FilterBar).findComponent({ name: 'ColumnPicker' }).exists()).toBe(true)
    w.unmount()
  })

  it('marks an unpaid charge without a paired payment', async () => {
    api.members.mockResolvedValue(page([
      row(1,{contribution:{status:'unpaid',unpaired_count:1,claim_count:1,prescribed_cents:'50000',paid_cents:'0',open_cents:'50000'}}),
      row(2,{contribution:{status:'unpaid',unpaired_count:0,claim_count:1,prescribed_cents:'50000',paid_cents:'20000',open_cents:'30000'}}),
    ]))
    const w=mounted();await flushPromises()
    expect(w.get('[data-member="1"] [data-field="contribution-unpaired"]').text()).toBe(cs.nonprofitMembership.listUnpaired.replace('{count}','1'))
    expect(w.find('[data-member="2"] [data-field="contribution-unpaired"]').exists()).toBe(false)
    w.unmount()
  })
  it('passes selected members into a batch and reloads the list when any batch part completes', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    const batch=w.findComponent(MembershipBatchFlow)
    expect(batch.props('selectedMemberIds')).toEqual([1])
    // Hromadný předpis je v okně nad seznamem, hromadná lišta se pod ním skryje.
    expect(w.get('[data-stub="modal"]').attributes('data-title')).toBe(cs.nonprofitMembership.batchTitle)
    expect(w.find('[data-stub="bulk-bar"]').exists()).toBe(false)
    batch.vm.$emit('changed');await flushPromises()
    expect(api.members).toHaveBeenCalledTimes(2)
    expect(await reconciliationVersion(w)).toBe(1)
    w.unmount()
  })
  it('refreshes the register and accounting periods after returning to a cached workspace', async () => {
    const visible = ref(true)
    const host = mount(defineComponent({
      setup: () => () => h(KeepAlive, null, { default: () => visible.value ? h(Workspace, { supplier: 5, canManage: false }) : null }),
    }), { global: {
      plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })],
      stubs: { ActionBar, ClientSearchSelect, MembershipReconciliation: Reconciliation },
    } })
    await flushPromises()
    expect(api.members).toHaveBeenCalledTimes(1)
    visible.value = false
    await flushPromises()
    visible.value = true
    await flushPromises()
    expect(api.members).toHaveBeenCalledTimes(2)
    expect(accounting.listPeriods).toHaveBeenCalledTimes(2)
    host.unmount()
  })

  it.each(['cs', 'en'])('renders translated %s texts', async locale => {
    const w = mounted({}, locale)
    await flushPromises()
    expect(w.text()).toContain((locale === 'cs' ? cs : en).nonprofitMembership.title)
    expect(w.text()).not.toContain('nonprofitMembership.')
    w.unmount()
  })

  it('lists members and does not present a future revision as the current status', async () => {
    const w = mounted()
    await flushPromises()
    expect(api.members).toHaveBeenCalledWith(5, 0, 50, expect.any(Object))
    expect(w.findAll('[data-section="member-row"]')).toHaveLength(3)
    const current = w.get('[data-member="1"] [data-field="member-revision"]')
    expect(current.text()).toContain(cs.nonprofitMembership.status_active)
    expect(current.text()).toContain(formatDate('2020-01-01'))
    expect(current.text()).not.toContain('2020-01-01')
    // Běžný stav je tichý štítek s tečkou jako na fakturách.
    expect(current.get('[data-field="member-status"]').classes()).toEqual(expect.arrayContaining(['badge', 'badge-quiet']))
    expect(current.classes()).not.toContain('text-warning-700')
    const future = w.get('[data-member="2"] [data-field="member-revision"]')
    const futureFull = cs.nonprofitMembership.futureRevision.replace('{status}', cs.nonprofitMembership.status_ended).replace('{date}', formatDate('2999-01-01'))
    // Tabulka ukáže krátký text, plné znění je v title a pro čtečku; karta ukazuje plné znění.
    expect(future.text()).toContain(cs.nonprofitMembership.listColumns.future.replace('{status}', cs.nonprofitMembership.status_ended).replace('{date}', formatDate('2999-01-01')))
    expect(future.attributes('title')).toBe(futureFull)
    expect(future.get('.sr-only').text()).toContain(futureFull)
    expect(future.find('[data-field="member-status"]').exists()).toBe(false)
    expect(future.classes()).toContain('text-warning-700')
    expect(w.get('[data-member-card="2"] [data-field="member-revision"]').text()).toBe(futureFull)
    expect(w.get('[data-member-card="1"] [data-field="member-status"]').text()).toBe(cs.nonprofitMembership.status_active)
    expect(w.get('[data-member="3"] [data-field="member-revision"]').attributes('title')).toBe(cs.nonprofitMembership.noRevision)
    expect(w.get('[data-member-card="3"] [data-field="member-revision"]').text()).toBe(cs.nonprofitMembership.noRevision)
    expect(w.get('[data-member="3"]').text()).toContain(cs.nonprofitMembership.clientFallback.replace('{id}', '13'))
    w.unmount()
  })

  it('člena se všemi revizemi zrušenými neoznačí jako člena, jehož členství teprve začne', async () => {
    api.members.mockResolvedValue(page([row(3, { revision_no: null, effective_from: null, status: null, all_revisions_voided: true })]))
    const w = mounted()
    await flushPromises()
    expect(w.get('[data-member="3"] [data-field="member-revision"]').attributes('title')).toBe(cs.nonprofitMembership.allRevisionsVoided)
    expect(w.get('[data-member="3"] [data-field="member-revision"]').text()).toContain(cs.nonprofitMembership.listColumns.voided)
    const text = w.get('[data-member-card="3"] [data-field="member-revision"]').text()
    expect(text).toBe(cs.nonprofitMembership.allRevisionsVoided)
    expect(text).not.toBe(cs.nonprofitMembership.noRevision)
    w.unmount()
  })

  it('pages members by cursor and appends them', async () => {
    api.members.mockResolvedValueOnce(page([row(1)], 1))
    api.members.mockResolvedValueOnce(page([row(2)], null))
    const w = mounted()
    await flushPromises()
    await w.get('[data-action="more-members"]').trigger('click')
    await flushPromises()
    expect(api.members.mock.calls[1]?.slice(0,3)).toEqual([5, 1, 50])
    expect(w.findAll('[data-section="member-row"]')).toHaveLength(2)
    expect(w.find('[data-action="more-members"]').exists()).toBe(false)
    w.unmount()
  })

  it('shows the stable number and contact label as the link to the member page', async () => {
    const w = mounted()
    await flushPromises()
    const link = w.get('[data-member="1"] [data-field="member-name"]')
    expect(link.text()).toBe('Synthetic member 1')
    expect(w.get('[data-member="1"] [data-field="member-number"]').text()).toBe('M-1')
    await link.trigger('click');await flushPromises()
    expect(lastNavigation()).toEqual({ to: DETAIL(1), newPanel: false })
    w.unmount()
  })
  it('creates a member only on explicit request from a chosen contact and a member number', async () => {
    const w = mounted()
    await flushPromises()
    // Založení je v okně jako u ostatních seznamů; nad seznamem není formulář.
    expect(w.find('[data-section="create-member"]').exists()).toBe(false)
    await w.get('[data-action="create-open"]').trigger('click')
    expect(w.get('[data-stub="modal"]').attributes('data-title')).toBe(cs.nonprofitMembership.createTitle)
    expect(api.createMember).not.toHaveBeenCalled()
    expect(w.get('[data-action="create-member"]').attributes('disabled')).toBeDefined()
    await w.get('[data-field="member-client"]').setValue('3')
    await w.get('[data-section="create-member"] [data-field="member-number"]').setValue(' M-9 ')
    expect(api.createMember).not.toHaveBeenCalled()
    await w.get('[data-action="create-member"]').trigger('click')
    await flushPromises()
    expect(api.createMember).toHaveBeenCalledTimes(1)
    expect(api.createMember).toHaveBeenCalledWith(5, { client_id: 3, member_number: 'M-9' })
    // Jako u kontaktu: po založení rovnou stránka nového člena (zapíše se mu první revize).
    expect(lastNavigation()).toEqual({ to: DETAIL(9), newPanel: false })
    expect(toast.success).toHaveBeenCalledWith(cs.nonprofitMembership.memberCreated)
    expect(w.find('[data-section="create-member"]').exists()).toBe(false)
    w.unmount()
  })

  it('creates a new contact in the shared client dialog and selects it for the member', async () => {
    api.createMember.mockResolvedValue({ id: 9, client_id: 44 })
    const w = mounted()
    await flushPromises()
    await w.get('[data-action="create-open"]').trigger('click')
    expect(w.find('[data-stub="client-modal"]').exists()).toBe(false)
    await w.get('[data-action="member-new-client"]').trigger('click')
    w.findComponent(ClientFormModal).vm.$emit('created', { id: 44, company_name: 'Synthetic new contact' })
    await flushPromises()
    expect(w.find('[data-stub="client-modal"]').exists()).toBe(false)
    expect(w.findComponent(ClientSearchSelect).props()).toMatchObject({ modelValue: 44, selectedLabel: 'Synthetic new contact' })
    await w.get('[data-section="create-member"] [data-field="member-number"]').setValue('M-44')
    await w.get('[data-action="create-member"]').trigger('click')
    await flushPromises()
    expect(api.createMember).toHaveBeenCalledWith(5, { client_id: 44, member_number: 'M-44' })
    w.unmount()
  })

  it('shows a server refusal of a duplicate identity and does not open a member', async () => {
    api.createMember.mockRejectedValue({ response: { status: 409, data: { error: { code: 'nonprofit_membership_identity_conflict', message: 'Synthetic identity conflict' } } } })
    const w = mounted()
    await flushPromises()
    await w.get('[data-action="create-open"]').trigger('click')
    await w.get('[data-field="member-client"]').setValue('3')
    await w.get('[data-section="create-member"] [data-field="member-number"]').setValue('M-1')
    await w.get('[data-action="create-member"]').trigger('click')
    await flushPromises()
    // Chyba zůstává v okně u formuláře, rozepsané údaje se neztratí.
    expect(w.get('[data-section="create-error"]').text()).toContain(cs.nonprofitMembership.serverErrors.nonprofit_membership_identity_conflict)
    expect((w.get('[data-section="create-member"] [data-field="member-number"]').element as HTMLInputElement).value).toBe('M-1')
    expect(rowLink.navigate).not.toHaveBeenCalled()
    w.unmount()
  })

  it('hides creation without write rights but keeps the register readable', async () => {
    const w = mounted({ canManage: false })
    await flushPromises()
    expect(w.find('[data-section="create-member"]').exists()).toBe(false)
    expect(w.find('[data-action="create-member"]').exists()).toBe(false)
    expect(w.get('[data-section="readonly"]').text()).toBe(cs.nonprofitMembership.readonly)
    expect(w.findAll('[data-section="member-row"]')).toHaveLength(3)
    await w.get('[data-member="1"] [data-field="member-name"]').trigger('click')
    expect(lastNavigation()).toEqual({ to: DETAIL(1), newPanel: false })
    w.unmount()
  })

  it('discards a list that answers after the supplier changed and reloads for the new one', async () => {
    let resolve!: (value: MemberPage) => void
    api.members.mockImplementationOnce(() => new Promise<MemberPage>(r => { resolve = r }))
    const w = mounted()
    await w.setProps({ supplier: 6 })
    await flushPromises()
    resolve(page([row(99)]))
    await flushPromises()
    expect(w.text()).not.toContain('M-99')
    expect(api.members).toHaveBeenLastCalledWith(6, 0, 50, expect.objectContaining({search:'',title_key:'',payment_status:'all'}))
    expect(w.findAll('[data-section="member-row"]')).toHaveLength(3)
    w.unmount()
  })

  it('shows a load error', async () => {
    api.members.mockRejectedValue({ response: { status: 403, data: { error: { code: 'forbidden', message: 'Synthetic forbidden' } } } })
    const w = mounted()
    await flushPromises()
    expect(w.get('[data-section="workspace-error"]').text()).toContain(cs.nonprofitMembership.error)
    expect(w.get('[data-section="workspace-error"]').text()).not.toContain('Synthetic forbidden')
    expect(w.get('[data-section="workspace-error"]').classes()).toEqual(expect.arrayContaining(['bg-danger-50', 'text-danger-700']))
    expect(w.find('[data-section="members-empty"]').exists()).toBe(false)
    w.unmount()
  })

  it('shows a loading state before the first page of members arrives', async () => {
    api.members.mockReturnValue(new Promise(() => {}))
    const w = mounted()
    await flushPromises()
    expect(w.find('[data-section="members-loading"]').exists()).toBe(true)
    expect(w.find('[data-section="members-empty"]').exists()).toBe(false)
    w.unmount()
  })

  it('shows the first steps in order when no member exists yet', async () => {
    api.members.mockResolvedValue(page([]))
    const w = mounted()
    await flushPromises()
    const steps = w.findAll('[data-section="members-steps"] li').map(li => li.text())
    expect(steps).toEqual(cs.nonprofitMembership.firstSteps)
    w.unmount()
  })

  it('splits the register, rates, accounting check and erasure into tabs kept in the address', async () => {
    const w = mounted()
    await flushPromises()
    expect(w.findAll('[role="tab"]').map(tab => tab.attributes('data-tab'))).toEqual(['members', 'rates', 'reconciliation'])
    expect(w.get('[data-tab="members"]').attributes('aria-selected')).toBe('true')
    expect((w.get('#membership-panel-reconciliation').element as HTMLElement).style.display).toBe('none')
    await w.get('[data-tab="reconciliation"]').trigger('click'); await flushPromises()
    expect((w.get('#membership-panel-reconciliation').element as HTMLElement).style.display).toBe('')
    expect((w.get('[data-section="members"]').element as HTMLElement).style.display).toBe('none')
    expect(navigation.route.query).toMatchObject({ tab: 'reconciliation' })
    // Přepnutí záložky seznam znovu nenačítá.
    expect(api.members).toHaveBeenCalledTimes(1)
    await w.get('[data-tab="reconciliation"]').trigger('keydown', { key: 'ArrowRight' }); await flushPromises()
    expect(w.get('[data-tab="members"]').attributes('aria-selected')).toBe('true')
    expect(navigation.route.query.tab).toBeUndefined()
    w.unmount()

    // Záložka z adresy; výmaz jen s právem ke čtení.
    navigation.route = reactive({ query: { tab: 'rates' } })
    const rates = mounted({ canReadErasure: true }); await flushPromises()
    expect(rates.get('[data-tab="rates"]').attributes('aria-selected')).toBe('true')
    expect(rates.find('[data-section="rates-management"]').exists()).toBe(true)
    expect(rates.findAll('[role="tab"]').map(tab => tab.attributes('data-tab'))).toContain('erasure')
    rates.unmount()
  })

  it('chooses the year from a select like other lists and applies it at once', async () => {
    const w=mounted();await flushPromises()
    const year = w.get('[data-field="member-year"]')
    expect(year.element.tagName).toBe('SELECT')
    const options = year.findAll('option').map(o => o.attributes('value'))
    expect(options[0]).toBe('')
    expect(options).toEqual(expect.arrayContaining(['2025', '2026', '2027', appIsoDate().slice(0, 4)]))
    expect(year.findAll('option')[0].text()).toBe(cs.nonprofitMembership.listYearAll)
    const members=api.members.mock.calls.length
    await year.setValue('2025');await flushPromises()
    expect(api.members).toHaveBeenCalledTimes(members+1)
    expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({year:2025}))
    expect(api.titles).toHaveBeenLastCalledWith(5,2025,undefined)
    w.unmount()
  })

  it('keeps the current rows visible while a refresh is loading', async () => {
    const w=mounted();await flushPromises()
    let resolve: (value: MemberPage) => void = () => {}
    api.members.mockReturnValueOnce(new Promise<MemberPage>(r => {resolve = r}))
    await w.get('[data-action="reload-members"]').trigger('click');await flushPromises()
    expect(w.findAll('[data-section="member-row"]')).toHaveLength(3)
    expect(w.find('[data-section="members-loading"]').exists()).toBe(true)
    expect(w.get('[data-section="members"]').attributes('aria-busy')).toBe('true')
    resolve(page([row(1)]));await flushPromises()
    expect(w.get('[data-section="members"]').attributes('aria-busy')).toBe('false')
    expect(w.findAll('[data-section="member-row"]')).toHaveLength(1)
    w.unmount()
  })

  it('shows one styled retry for a title loading failure', async () => {
    api.titles.mockRejectedValue(new Error('Synthetic titles failure'))
    api.members.mockRejectedValueOnce(new Error('Synthetic members failure'))
    const w=mounted();await flushPromises()
    expect(w.find('[data-action="filter-members"]').exists()).toBe(false)
    const retry=w.get('[data-section="titles-error"]').findAll('button')
    expect(retry).toHaveLength(1)
    expect(retry[0].classes()).toContain('whitespace-nowrap')
    expect(retry[0].get('svg').attributes('stroke-width')).toBe('2')
    api.titles.mockResolvedValue({items: ['SYNTHETIC'], next_after_title_key: null})
    await retry[0].trigger('click');await flushPromises()
    expect(w.find('[data-section="titles-error"]').exists()).toBe(false)
    w.unmount()
  })

  it('loads titles for the applied year even when search typing cancels the year timer', async () => {
    const w=mounted();await flushPromises()
    vi.useFakeTimers()
    try {
      await w.get('[data-field="member-year"]').setValue('2025')
      await vi.advanceTimersByTimeAsync(100)
      await w.get('[data-field="member-search"]').setValue('Synthetic')
      await vi.advanceTimersByTimeAsync(300);await flushPromises()
      expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({year:2025,search:'Synthetic'}))
      expect(api.titles).toHaveBeenLastCalledWith(5,2025,undefined)
    } finally { vi.useRealTimers(); w.unmount() }
  })

  it('reloads titles after a saved batch', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    api.titles.mockResolvedValue({items: ['SYNTHETIC','SYNTHETIC-NEW'], next_after_title_key: null})
    const before=api.titles.mock.calls.length
    w.findComponent(MembershipBatchFlow).vm.$emit('changed');await flushPromises()
    expect(api.titles).toHaveBeenCalledTimes(before+1)
    expect(w.get('[data-field="member-title"]').findAll('option').map(o => o.text())).toContain('SYNTHETIC-NEW')
    w.unmount()
  })

  it('keeps the year validation error when reloading with an invalid year', async () => {
    const w=mounted();await flushPromises()
    vi.useFakeTimers()
    try {
      await w.get('[data-field="member-year"]').setValue('202')
      await vi.advanceTimersByTimeAsync(300);await flushPromises()
      expect(w.get('[data-section="workspace-error"]').text()).toBe(cs.nonprofitMembership.listNeedPeriod)
      const calls=api.members.mock.calls.length
      await w.get('[data-action="reload-members"]').trigger('click');await flushPromises()
      expect(api.members).toHaveBeenCalledTimes(calls)
      expect(w.get('[data-section="workspace-error"]').text()).toBe(cs.nonprofitMembership.listNeedPeriod)
    } finally { vi.useRealTimers(); w.unmount() }
  })

  it('reloads titles for the applied year, not for an unapplied year in the form', async () => {
    const w=mounted();await flushPromises()
    // Dávka otevřená před chybou roku (s chybou seznamu se hromadný předpis otevřít nedá).
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    vi.useFakeTimers()
    try {
      await w.get('[data-field="member-year"]').setValue('9999')
      await vi.advanceTimersByTimeAsync(300);await flushPromises()
      expect(w.get('[data-section="workspace-error"]').text()).toBe(cs.nonprofitMembership.listNeedPeriod)
      w.findComponent(MembershipBatchFlow).vm.$emit('changed');await flushPromises()
      expect(api.titles).toHaveBeenLastCalledWith(5,Number(appIsoDate().slice(0,4)),undefined)
      expect(w.find('[data-section="titles-error"]').exists()).toBe(false)
    } finally { vi.useRealTimers(); w.unmount() }
  })

  it('reloads with the applied filters while a batch is locked', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    w.findComponent(MembershipBatchFlow).vm.$emit('lock', true);await flushPromises()
    await w.get('[data-field="member-year"]').setValue('202')
    const calls=api.members.mock.calls.length
    await w.get('[data-action="reload-members"]').trigger('click');await flushPromises()
    expect(api.members).toHaveBeenCalledTimes(calls+1)
    expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({year:Number(appIsoDate().slice(0,4))}))
    expect(w.find('[data-section="workspace-error"]').exists()).toBe(false)
    w.unmount()
  })

  it('applies a filter chip removed during a batch once the batch unlocks', async () => {
    navigation.route.query = {year: '2026', payment_status: 'unpaid'}
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    w.findComponent(MembershipBatchFlow).vm.$emit('lock', true);await flushPromises()
    const calls=api.members.mock.calls.length
    w.findComponent(FilterBar).vm.$emit('clear', 'status');await flushPromises()
    expect(api.members).toHaveBeenCalledTimes(calls)
    w.findComponent(MembershipBatchFlow).vm.$emit('lock', false);await flushPromises()
    expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({year:2026,payment_status:'all'}))
    w.unmount()
  })

  it('shows the year chip only when removing it changes the filter', async () => {
    const current=appIsoDate().slice(0,4)
    navigation.route.query = {}
    let w=mounted();await flushPromises()
    let bar=w.findComponent(FilterBar)
    expect(bar.props('chips')).toEqual([])
    expect(bar.props('activeCount')).toBe(0)
    w.unmount()

    navigation.route.query = {year: '2020'}
    w=mounted();await flushPromises()
    bar=w.findComponent(FilterBar)
    expect(bar.props('chips')).toEqual([expect.objectContaining({key:'year',value:'2020'})])
    bar.vm.$emit('clear','year');await flushPromises()
    expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({year:Number(current)}))
    expect(w.findComponent(FilterBar).props('chips')).toEqual([])
    w.unmount()

    navigation.route.query = {year: current, title_key: 'SYNTHETIC'}
    w=mounted();await flushPromises()
    bar=w.findComponent(FilterBar)
    expect(bar.props('chips')).toEqual([expect.objectContaining({key:'year'}),expect.objectContaining({key:'title'})])
    bar.vm.$emit('clear','year');await flushPromises()
    expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({year:null,title_key:'SYNTHETIC'}))
    w.unmount()

    // Stav úhrady vyžaduje rok: aktuální rok vedle titulu nejde odebrat, jiný rok se vrátí na aktuální.
    navigation.route.query = {year: current, title_key: 'SYNTHETIC', payment_status: 'paid'}
    w=mounted();await flushPromises()
    expect(w.findComponent(FilterBar).props('chips')).not.toContainEqual(expect.objectContaining({key:'year'}))
    w.unmount()
    navigation.route.query = {year: '2020', title_key: 'SYNTHETIC', payment_status: 'paid'}
    w=mounted();await flushPromises()
    w.findComponent(FilterBar).vm.$emit('clear','year');await flushPromises()
    expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({year:Number(current),title_key:'SYNTHETIC',payment_status:'paid'}))
    expect(w.findComponent(FilterBar).props('chips')).not.toContainEqual(expect.objectContaining({key:'year'}))
    w.unmount()
  })

  it('applies clearing all filters during a batch once the batch unlocks', async () => {
    navigation.route.query = {year: '', title_key: 'SYNTHETIC', search: 'Synthetic'}
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    w.findComponent(MembershipBatchFlow).vm.$emit('lock', true);await flushPromises()
    const calls=api.members.mock.calls.length
    w.findComponent(FilterBar).vm.$emit('clear-all');await flushPromises()
    expect(api.members).toHaveBeenCalledTimes(calls)
    w.findComponent(MembershipBatchFlow).vm.$emit('lock', false);await flushPromises()
    expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({search:'',year:Number(appIsoDate().slice(0,4)),title_key:'',payment_status:'all'}))
    w.unmount()
  })

  it('requests titles once when reloading a changed year', async () => {
    const w=mounted();await flushPromises()
    vi.useFakeTimers()
    try {
      await w.get('[data-field="member-year"]').setValue('2025')
      const before=api.titles.mock.calls.length
      await w.get('[data-action="reload-members"]').trigger('click');await flushPromises()
      await vi.advanceTimersByTimeAsync(300);await flushPromises()
      expect(api.titles).toHaveBeenCalledTimes(before+1)
      expect(api.titles).toHaveBeenLastCalledWith(5,2025,undefined)
    } finally { vi.useRealTimers(); w.unmount() }
  })

  it('waits with a title change for a running search delay and applies it together', async () => {
    const w=mounted();await flushPromises()
    vi.useFakeTimers()
    try {
      const calls=api.members.mock.calls.length
      await w.get('[data-field="member-search"]').setValue('Syn')
      await w.get('[data-field="member-title"]').setValue('SYNTHETIC');await flushPromises()
      expect(api.members).toHaveBeenCalledTimes(calls)
      await vi.advanceTimersByTimeAsync(300);await flushPromises()
      expect(api.members).toHaveBeenCalledTimes(calls+1)
      expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({search:'Syn',title_key:'SYNTHETIC'}))
    } finally { vi.useRealTimers(); w.unmount() }
  })

  it('applies title and status at once when no typing delay is running', async () => {
    const w=mounted();await flushPromises()
    vi.useFakeTimers()
    try {
      await w.get('[data-field="member-title"]').setValue('SYNTHETIC');await flushPromises()
      expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({title_key:'SYNTHETIC'}))
      const calls=api.members.mock.calls.length
      await w.get('[data-field="member-payment-status"]').setValue('paid');await flushPromises()
      expect(api.members).toHaveBeenCalledTimes(calls+1)
      expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({title_key:'SYNTHETIC',payment_status:'paid'}))
    } finally { vi.useRealTimers(); w.unmount() }
  })

  it('exportuje podle načtených filtrů, ne rozepsaného hledání, a import nabízí jen se zápisem', async () => {
    api.exportMembers.mockResolvedValue({ blob: new Blob(['x']), filename: 'clenove-2026-06-01.csv' })
    const createUrl = vi.fn(() => 'blob:x'), revoke = vi.fn()
    Object.assign(URL, { createObjectURL: createUrl, revokeObjectURL: revoke })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const w = mounted(); await flushPromises()
    await w.get('[data-field="member-search"]').setValue('Neodeslane')
    await w.get('[data-action="export-csv"]').trigger('click'); await flushPromises()
    expect(api.exportMembers).toHaveBeenCalledWith(5, 'csv', { search: '', year: Number(appIsoDate().slice(0, 4)), title_key: '', payment_status: 'all' })
    expect(click).toHaveBeenCalledTimes(1)
    expect(revoke).toHaveBeenCalledWith('blob:x')
    api.exportMembers.mockRejectedValueOnce({ response: { data: { error: { code: 'nonprofit_membership_list_year_required' } } } })
    await w.get('[data-action="export-xlsx"]').trigger('click'); await flushPromises()
    expect(w.get('[data-section="export-error"]').text()).toBe(cs.nonprofitMembership.serverErrors.nonprofit_membership_list_year_required)
    // Chyba exportu nezablokuje hromadný předpis (ten hlídá jen chyba načtení seznamu).
    expect(w.find('[data-section="workspace-error"]').exists()).toBe(false)
    expect(w.get('[data-action="batch-open"]').attributes('disabled')).toBeUndefined()
    // Nové načtení seznamu (jiný výběr) starou hlášku exportu zruší.
    await w.get('[data-action="reload-members"]').trigger('click'); await flushPromises()
    expect(w.find('[data-section="export-error"]').exists()).toBe(false)
    click.mockRestore(); w.unmount()

    const reader = mounted({ canManage: false }); await flushPromises()
    expect(reader.find('[data-action="import-open"]').exists()).toBe(false)
    expect(reader.find('[data-action="export-xlsx"]').exists()).toBe(true)
    reader.unmount()
  })

  it('po importu obnoví seznam a panel zavře změna firmy', async () => {
    const w = mounted(); await flushPromises()
    await w.get('[data-action="import-open"]').trigger('click')
    const panel = w.findComponent(ImportFlow)
    expect(panel.exists()).toBe(true)
    const calls = api.members.mock.calls.length
    panel.vm.$emit('changed'); await flushPromises()
    expect(api.members).toHaveBeenCalledTimes(calls + 1)
    expect(await reconciliationVersion(w)).toBeGreaterThan(0)
    api.exportMembers.mockRejectedValueOnce({ response: { data: { error: { code: 'nonprofit_membership_export_too_large' } } } })
    await w.get('[data-action="export-xlsx"]').trigger('click'); await flushPromises()
    expect(w.find('[data-section="export-error"]').exists()).toBe(true)
    await w.setProps({ supplier: 6 }); await flushPromises()
    expect(w.find('[data-section="export-error"]').exists()).toBe(false)
    expect(w.findComponent(ImportFlow).exists()).toBe(false)
    w.unmount()
  })

  it('úspěšný export nesmaže chybu načtení seznamu a hromadný předpis zůstane zašedlý', async () => {
    api.members.mockRejectedValueOnce({ response: { data: { error: { code: 'nonprofit_membership_list_filter_invalid' } } } })
    api.exportMembers.mockResolvedValue({ blob: new Blob(['x']), filename: 'clenove.csv' })
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const w = mounted(); await flushPromises()
    expect(w.find('[data-section="workspace-error"]').exists()).toBe(true)
    await w.get('[data-action="export-csv"]').trigger('click'); await flushPromises()
    expect(w.find('[data-section="workspace-error"]').exists()).toBe(true)
    expect(w.get('[data-action="batch-open"]').attributes('disabled')).toBeDefined()
    click.mockRestore(); w.unmount()
  })

  it('„Načíst další“ hlášku exportu nezruší, výběr se tím nemění', async () => {
    api.members.mockResolvedValueOnce(page([row(1)], 1)).mockResolvedValueOnce(page([row(2)], null))
    api.exportMembers.mockRejectedValueOnce({ response: { data: { error: { code: 'nonprofit_membership_export_too_large' } } } })
    const w = mounted(); await flushPromises()
    await w.get('[data-action="export-xlsx"]').trigger('click'); await flushPromises()
    await w.get('[data-action="more-members"]').trigger('click'); await flushPromises()
    expect(w.findAll('[data-section="member-row"]')).toHaveLength(2)
    expect(w.get('[data-section="export-error"]').text()).toBe(cs.nonprofitMembership.serverErrors.nonprofit_membership_export_too_large)
    w.unmount()
  })

  it('chyba exportu, která dorazí až po novém načtení seznamu, se neukáže u nového výběru', async () => {
    let reject: (e: unknown) => void = () => {}
    api.exportMembers.mockReturnValueOnce(new Promise((_resolve, fail) => { reject = fail }))
    const w = mounted(); await flushPromises()
    await w.get('[data-action="export-xlsx"]').trigger('click')
    await w.get('[data-action="reload-members"]').trigger('click'); await flushPromises()
    reject({ response: { data: { error: { code: 'nonprofit_membership_export_too_large' } } } }); await flushPromises()
    expect(w.find('[data-section="export-error"]').exists()).toBe(false)
    w.unmount()
  })

  it('během exportu je „Načíst další“ zašedlé, aby odpověď exportu nepřišla o hlášku', async () => {
    let resolve: (v: unknown) => void = () => {}
    api.members.mockResolvedValueOnce(page([row(1)], 1))
    api.exportMembers.mockReturnValueOnce(new Promise(done => { resolve = done }))
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const w = mounted(); await flushPromises()
    await w.get('[data-action="export-xlsx"]').trigger('click')
    expect(w.get('[data-action="more-members"]').attributes('disabled')).toBeDefined()
    resolve({ blob: new Blob(['x']), filename: 'clenove.xlsx' }); await flushPromises()
    expect(w.get('[data-action="more-members"]').attributes('disabled')).toBeUndefined()
    click.mockRestore(); w.unmount()
  })

  it('export staré firmy nezablokuje akce nové firmy', async () => {
    let resolve: (v: unknown) => void = () => {}
    api.exportMembers.mockReturnValueOnce(new Promise(done => { resolve = done }))
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const w = mounted(); await flushPromises()
    await w.get('[data-action="export-csv"]').trigger('click')
    expect(w.get('[data-action="export-xlsx"]').attributes('disabled')).toBeDefined()
    await w.setProps({ supplier: 6 }); await flushPromises()
    expect(w.get('[data-action="export-xlsx"]').attributes('disabled')).toBeUndefined()
    // Soubor staré firmy se po přepnutí nestáhne.
    resolve({ blob: new Blob(['a']), filename: 'a.csv' }); await flushPromises()
    expect(click).not.toHaveBeenCalled()
    click.mockRestore(); w.unmount()
  })

  it('starší export nestáhne soubor ani neodemkne akce, když po A → B → A běží novější export téže firmy', async () => {
    const pending: Array<(v: unknown) => void> = []
    api.exportMembers.mockImplementation(() => new Promise(done => { pending.push(done) }))
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const w = mounted()
    try {
      await flushPromises()
      await w.get('[data-action="export-csv"]').trigger('click')
      await w.setProps({ supplier: 6 }); await flushPromises()
      await w.setProps({ supplier: 5 }); await flushPromises()
      // Starý export firmy 5 doběhne, až je firma 5 znovu vybraná: kontrola firmy by ho pustila.
      pending[0]!({ blob: new Blob(['a']), filename: 'a.csv' }); await flushPromises()
      expect(click).not.toHaveBeenCalled()
      await w.get('[data-action="export-csv"]').trigger('click')
      expect(w.get('[data-action="export-xlsx"]').attributes('disabled')).toBeDefined()
      pending[1]!({ blob: new Blob(['b']), filename: 'b.csv' }); await flushPromises()
      expect(click).toHaveBeenCalledTimes(1)
      expect(w.get('[data-action="export-xlsx"]').attributes('disabled')).toBeUndefined()
    } finally {
      click.mockRestore(); api.exportMembers.mockReset(); w.unmount()
    }
  })

  it('starší export nezruší zámek novějšího exportu téže firmy po A → B → A', async () => {
    const pending: Array<(v: unknown) => void> = []
    api.exportMembers.mockImplementation(() => new Promise(done => { pending.push(done) }))
    const w = mounted()
    try {
      await flushPromises()
      await w.get('[data-action="export-csv"]').trigger('click')
      await w.setProps({ supplier: 6 }); await flushPromises()
      await w.setProps({ supplier: 5 }); await flushPromises()
      await w.get('[data-action="export-csv"]').trigger('click')
      pending[0]!({ blob: new Blob(['a']), filename: 'a.csv' }); await flushPromises()
      expect(w.get('[data-action="export-xlsx"]').attributes('disabled')).toBeDefined()
    } finally {
      api.exportMembers.mockReset(); w.unmount()
    }
  })


  it('keeps every window open while its write is running and closes it otherwise', async () => {
    const confirm = vi.spyOn(window, 'confirm')
    const modalOf = (w: ReturnType<typeof mounted>, title: string) => w.findAllComponents(Modal).find(m => m.props('title') === title)
    // Založení: rozběhnuté uložení ani otevřené okno nového kontaktu okno nezavře.
    let finish: (v: unknown) => void = () => {}
    api.createMember.mockReturnValueOnce(new Promise(resolve => { finish = resolve }))
    const w = mounted(); await flushPromises()
    await w.get('[data-action="create-open"]').trigger('click')
    await w.get('[data-action="member-new-client"]').trigger('click')
    modalOf(w, cs.nonprofitMembership.createTitle)!.vm.$emit('close'); await flushPromises()
    expect(w.find('[data-section="create-member"]').exists()).toBe(true)
    w.findComponent(ClientFormModal).vm.$emit('close'); await flushPromises()
    await w.get('[data-field="member-client"]').setValue('3')
    await w.get('[data-section="create-member"] [data-field="member-number"]').setValue('M-9')
    await w.get('[data-action="create-member"]').trigger('click')
    modalOf(w, cs.nonprofitMembership.createTitle)!.vm.$emit('close'); await flushPromises()
    expect(w.find('[data-section="create-member"]').exists()).toBe(true)
    finish({ id: 9, supplier_id: 5, client_id: 3, member_number: 'M-9', created_by: 1 }); await flushPromises()
    expect(w.find('[data-section="create-member"]').exists()).toBe(false)
    // Úspěšné založení se při zavření na nic neptá (formulář je uložený).
    expect(confirm).not.toHaveBeenCalled()
    // Rozepsaný formulář: zavření se zeptá jako správa kategorií datové schránky; Zrušit okno nechá.
    await w.get('[data-action="create-open"]').trigger('click')
    await w.get('[data-section="create-member"] [data-field="member-number"]').setValue('M-10')
    confirm.mockReturnValueOnce(false)
    modalOf(w, cs.nonprofitMembership.createTitle)!.vm.$emit('close'); await flushPromises()
    expect(confirm).toHaveBeenCalledWith(cs.nonprofitMembership.closeUnsavedConfirm)
    expect((w.get('[data-section="create-member"] [data-field="member-number"]').element as HTMLInputElement).value).toBe('M-10')
    confirm.mockReturnValueOnce(true)
    await w.get('[data-action="create-cancel"]').trigger('click'); await flushPromises()
    expect(w.find('[data-section="create-member"]').exists()).toBe(false)
    // Nevyplněné okno se zavře bez dotazu.
    confirm.mockClear()
    await w.get('[data-action="create-open"]').trigger('click')
    modalOf(w, cs.nonprofitMembership.createTitle)!.vm.$emit('close'); await flushPromises()
    expect(w.find('[data-section="create-member"]').exists()).toBe(false)
    expect(confirm).not.toHaveBeenCalled()

    // Hromadný předpis a import: zamčený tok okno drží, odemčený ho pustí.
    await w.get('[data-action="batch-open"]').trigger('click'); await flushPromises()
    w.findComponent(MembershipBatchFlow).vm.$emit('lock', true); await flushPromises()
    modalOf(w, cs.nonprofitMembership.batchTitle)!.vm.$emit('close'); await flushPromises()
    expect(w.findComponent(MembershipBatchFlow).exists()).toBe(true)
    w.findComponent(MembershipBatchFlow).vm.$emit('lock', false); await flushPromises()
    modalOf(w, cs.nonprofitMembership.batchTitle)!.vm.$emit('close'); await flushPromises()
    expect(w.findComponent(MembershipBatchFlow).exists()).toBe(false)
    await w.get('[data-action="import-open"]').trigger('click'); await flushPromises()
    w.findComponent(ImportFlow).vm.$emit('lock', true); await flushPromises()
    modalOf(w, cs.nonprofitMembership.import.title)!.vm.$emit('close'); await flushPromises()
    expect(w.findComponent(ImportFlow).exists()).toBe(true)
    w.findComponent(ImportFlow).vm.$emit('lock', false); await flushPromises()
    modalOf(w, cs.nonprofitMembership.import.title)!.vm.$emit('close'); await flushPromises()
    expect(w.findComponent(ImportFlow).exists()).toBe(false)
    w.unmount()
  })

  it('restores a handed-over selection only on the way back to the same list in the same panel', async () => {
    const filters = { search: '', year: Number(appIsoDate().slice(0, 4)), title_key: '', payment_status: 'all' as const }
    // Jiný panel (sdílené sessionStorage) výběr nepřevezme.
    saveListHandoff({ supplier: 5, pane: 'other-pane', returning: true, path: '/accounting/nonprofit-membership', filters, ids: [1], openedId: 1 })
    const otherPane = mounted(); await flushPromises()
    expect((otherPane.get('[data-member="1"] [data-action="batch-check-member"]').element as HTMLInputElement).checked).toBe(false)
    otherPane.unmount()
    // Seznam otevřený jinou cestou (menu bez filtrů v adrese) starý výběr tiše neobnoví.
    saveListHandoff({ supplier: 5, pane: null, returning: true, path: '/accounting/nonprofit-membership?year=2026', filters, ids: [1], openedId: 1 })
    navigation.route = reactive({ query: {}, fullPath: '/accounting/nonprofit-membership' }) as typeof navigation.route
    const viaMenu = mounted(); await flushPromises()
    expect((viaMenu.get('[data-member="1"] [data-action="batch-check-member"]').element as HTMLInputElement).checked).toBe(false)
    // Pravé tlačítko nic neotevírá ani nepředává.
    sessionStorage.clear()
    await viaMenu.get('[data-member="2"]').trigger('auxclick', { button: 2 })
    expect(rowLink.navigate).not.toHaveBeenCalled()
    expect(readListHandoff(5, null)).toBeNull()
    viaMenu.unmount()
  })

  it('after a member was deleted on its page, returns focus to the list heading without a selection warning', async () => {
    const filters = { search: '', year: Number(appIsoDate().slice(0, 4)), title_key: '', payment_status: 'all' as const }
    // Detail smazaného člena ho z předání vyřadil (forgetMemberInHandoff): zůstal jen člen 1, otevřený není žádný.
    saveListHandoff({ supplier: 5, pane: null, returning: true, path: '/accounting/nonprofit-membership', filters, ids: [1], openedId: null })
    const w = mount(Workspace, { attachTo: document.body, props: { supplier: 5, canManage: true }, global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })], stubs: { ActionBar, SearchableSelect, ClientSearchSelect, ClientFormModal, MembershipReconciliation: Reconciliation, MembershipBatchFlow: true, MembershipImportFlow: ImportFlow, Modal, BulkActionBar, MembershipRates: true } } })
    await flushPromises()
    expect(toast.warning).not.toHaveBeenCalled()
    expect(document.activeElement).toBe(w.get('[data-focus="members-heading"]').element)
    w.unmount()
  })

  it('explains the 500 member selection limit on a greyed checkbox', async () => {
    const filters = { search: '', year: Number(appIsoDate().slice(0, 4)), title_key: '', payment_status: 'all' as const }
    const ids = Array.from({ length: 500 }, (_, index) => 1000 + index)
    saveListHandoff({ supplier: 5, pane: null, returning: true, path: '/accounting/nonprofit-membership', filters, ids, openedId: null })
    const w = mounted(); await flushPromises()
    const box = w.get('[data-member="1"] [data-action="batch-check-member"]')
    expect(box.attributes('disabled')).toBeDefined()
    expect(box.attributes('title')).toBe(cs.nonprofitMembership.batchSelectionLimit)
    expect(box.attributes('aria-label')).toContain(cs.nonprofitMembership.batchSelectionLimit)
    w.unmount()
  })

  it('ties tabs to their panels and drops a tab the page does not offer from the address', async () => {
    navigation.route = reactive({ query: { tab: 'erasure' } })
    const w = mounted(); await flushPromises()
    expect(w.get('[data-tab="members"]').attributes('aria-selected')).toBe('true')
    expect(navigation.route.query.tab).toBeUndefined()
    const members = w.get('[data-tab="members"]')
    expect(w.get(`#${members.attributes('aria-controls')}`).attributes('aria-labelledby')).toBe(members.attributes('id'))
    // Kontrola s účetnictvím přežije přepnutí záložky (zvolené období a výsledek).
    await w.get('[data-tab="reconciliation"]').trigger('click'); await flushPromises()
    const reconciliation = w.findComponent(Reconciliation)
    await w.get('[data-tab="members"]').trigger('click'); await flushPromises()
    expect(reconciliation.exists()).toBe(true)
    w.unmount()
  })

  it('does not restore a handed-over selection when the list is opened again from the menu', async () => {
    const filters = { search: '', year: Number(appIsoDate().slice(0, 4)), title_key: '', payment_status: 'all' as const }
    // Detail se opustil jinam než odkazem Zpět: předání není označené jako návrat, i když adresa sedí.
    saveListHandoff({ supplier: 5, pane: null, returning: false, path: '/accounting/nonprofit-membership', filters, ids: [1], openedId: 1 })
    const w = mounted(); await flushPromises()
    expect((w.get('[data-member="1"] [data-action="batch-check-member"]').element as HTMLInputElement).checked).toBe(false)
    expect(api.selectionCheck).not.toHaveBeenCalled()
    w.unmount()
  })

  it('does not let a running import of the previous company keep the next import window locked', async () => {
    const w = mounted(); await flushPromises()
    await w.get('[data-action="import-open"]').trigger('click'); await flushPromises()
    w.findComponent(ImportFlow).vm.$emit('lock', true); await flushPromises()
    await w.setProps({ supplier: 6 }); await flushPromises()
    await w.get('[data-action="import-open"]').trigger('click'); await flushPromises()
    w.findAllComponents(Modal).find(m => m.props('title') === cs.nonprofitMembership.import.title)!.vm.$emit('close'); await flushPromises()
    expect(w.findComponent(ImportFlow).exists()).toBe(false)
    w.unmount()
  })

  it('asks before closing a batch or an import with unsaved input, not after a saved part', async () => {
    const confirm = vi.spyOn(window, 'confirm')
    const w = mounted(); await flushPromises()
    await w.get('[data-action="batch-open"]').trigger('click'); await flushPromises()
    w.findComponent(MembershipBatchFlow).trigger('input')
    confirm.mockReturnValueOnce(false)
    w.findAllComponents(Modal).find(m => m.props('title') === cs.nonprofitMembership.batchTitle)!.vm.$emit('close'); await flushPromises()
    expect(w.findComponent(MembershipBatchFlow).exists()).toBe(true)
    w.findComponent(MembershipBatchFlow).vm.$emit('changed'); await flushPromises()
    confirm.mockClear()
    w.findAllComponents(Modal).find(m => m.props('title') === cs.nonprofitMembership.batchTitle)!.vm.$emit('close'); await flushPromises()
    expect(confirm).not.toHaveBeenCalled()
    expect(w.findComponent(MembershipBatchFlow).exists()).toBe(false)

    await w.get('[data-action="import-open"]').trigger('click'); await flushPromises()
    w.findComponent(ImportFlow).trigger('change')
    confirm.mockReturnValueOnce(true)
    w.findComponent(ImportFlow).vm.$emit('close'); await flushPromises()
    expect(confirm).toHaveBeenCalledWith(cs.nonprofitMembership.closeUnsavedConfirm)
    expect(w.findComponent(ImportFlow).exists()).toBe(false)
    w.unmount()
  })
})
