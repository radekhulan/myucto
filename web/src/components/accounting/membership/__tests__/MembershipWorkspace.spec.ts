import { appIsoDate } from '@/utils/date'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, KeepAlive, ref, reactive } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { formatDate } from '@/composables/useFormat'
import Workspace from '../MembershipWorkspace.vue'
import MembershipBatchFlow from '../MembershipBatchFlow.vue'
import FilterBar from '@/components/ui/FilterBar.vue'
import type { MemberPage, MemberRow } from '@/api/nonprofitMembership'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({ members: vi.fn(), createMember: vi.fn(), titles: vi.fn(), selectionCheck: vi.fn() }))
const navigation = vi.hoisted(() => ({route: {query: {}} as {query: Record<string, string>}, replace: vi.fn()}))
vi.mock('vue-router', async importOriginal => ({...await importOriginal<typeof import('vue-router')>(), useRoute: () => navigation.route, useRouter: () => ({replace: navigation.replace})}))
const accounting = vi.hoisted(() => ({ listPeriods: vi.fn() }))
vi.mock('@/api/nonprofitMembership', () => ({ membershipApi: api }))
vi.mock('@/api/accounting', () => ({ accountingApi: accounting }))
vi.mock('@/api/clients', () => ({ clientsApi: {} }))
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
const Detail = defineComponent({ name: 'MembershipMemberDetail', props: ['supplier', 'canManage', 'memberId', 'label', 'periods', 'refreshVersion'], emits: ['changed', 'close'], setup: () => () => h('div', { 'data-stub': 'detail' }, [h('h2', { tabindex: -1, 'data-focus': 'member-heading' })]) })
const ClientFormModal = defineComponent({ name: 'ClientFormModal', emits: ['created', 'close'], setup: () => () => h('div', { 'data-stub': 'client-modal' }) })
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
      stubs: { ActionBar, SearchableSelect, ClientSearchSelect, ClientFormModal, MembershipMemberDetail: Detail, MembershipReconciliation: Reconciliation, MembershipBatchFlow: true },
    },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  navigation.route = reactive({query: {}})
  navigation.replace.mockImplementation(({query}: {query: Record<string, string>}) => {navigation.route.query = query; return Promise.resolve()})
  api.titles.mockResolvedValue({items: ['SYNTHETIC'], next_after_title_key: null})
  api.selectionCheck.mockImplementation((_supplier: number, ids: number[]) => Promise.resolve({member_ids: ids}))
  Element.prototype.scrollIntoView = vi.fn()
  accounting.listPeriods.mockResolvedValue([period])
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

  it('clears batch selection when a data refresh removes members from the current filter', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-member="1"] [data-action="open-member"]').trigger('click');await flushPromises()
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    api.members.mockResolvedValueOnce(page([]))
    api.selectionCheck.mockResolvedValueOnce({member_ids: []})
    w.findComponent(Detail).vm.$emit('changed');await flushPromises()
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
    expect(w.text()).toContain(cs.nonprofitMembership.listSelectionCleared)
    w.unmount()
  })

  it('shows unproven payments without presenting an unknown open balance as debt', async () => {
    api.members.mockResolvedValue(page([row(1,{contribution:{status:'unproven',claim_count:1,prescribed_cents:'50000',paid_cents:null,open_cents:null}})]))
    const w=mounted();await flushPromises()
    expect(w.get('[data-field="contribution-status"]').text()).toBe(cs.nonprofitMembership.listStatus_unproven)
    expect(w.get('[data-field="contribution-amounts"]').text()).toContain('—')
    expect(w.get('[data-field="contribution-amounts"]').text()).toContain('500,00')
    expect(w.find('[data-field="contribution-unpaired"]').exists()).toBe(false)
    w.unmount()
  })

  it('hides the filters behind the shared toggle like other lists', async () => {
    const w=mounted();await flushPromises()
    expect(w.findComponent(FilterBar).props('collapsible')).toBe(true)
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
  it('passes selected members into a batch and invalidates the existing detail when any batch part completes', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-member="1"] [data-action="open-member"]').trigger('click');await flushPromises()
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    const batch=w.findComponent(MembershipBatchFlow)
    expect(batch.props('selectedMemberIds')).toEqual([1])
    expect(w.findComponent(Detail).props('refreshVersion')).toBe(0)
    batch.vm.$emit('changed');await flushPromises()
    expect(w.findComponent(Detail).props('refreshVersion')).toBe(1)
    expect(w.findComponent(Detail).props('memberId')).toBe(1)
    expect(api.members).toHaveBeenCalledTimes(2)
    w.unmount()
  })
  it('refreshes the register and accounting periods after returning to a cached workspace', async () => {
    const visible = ref(true)
    const host = mount(defineComponent({
      setup: () => () => h(KeepAlive, null, { default: () => visible.value ? h(Workspace, { supplier: 5, canManage: false }) : null }),
    }), { global: {
      plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })],
      stubs: { ActionBar, ClientSearchSelect, MembershipMemberDetail: Detail, MembershipReconciliation: Reconciliation },
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
    expect(current.get('[data-field="member-status"]').classes()).toEqual(expect.arrayContaining(['rounded-full', 'bg-success-50', 'text-success-600']))
    expect(current.classes()).not.toContain('text-warning-700')
    const future = w.get('[data-member="2"] [data-field="member-revision"]')
    expect(future.text()).toBe(cs.nonprofitMembership.futureRevision.replace('{status}', cs.nonprofitMembership.status_ended).replace('{date}', formatDate('2999-01-01')))
    expect(future.classes()).toContain('text-warning-700')
    expect(w.get('[data-member="3"] [data-field="member-revision"]').text()).toBe(cs.nonprofitMembership.noRevision)
    expect(w.get('[data-member="3"]').text()).toContain(cs.nonprofitMembership.clientFallback.replace('{id}', '13'))
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

  it('opens a member with the stable number and contact label', async () => {
    const w = mounted()
    await flushPromises()
    await w.get('[data-member="1"] [data-action="open-member"]').trigger('click')
    const detail = w.findComponent(Detail)
    expect(detail.props()).toMatchObject({ supplier: 5, canManage: true, memberId: 1, label: 'Synthetic member 1' })
    expect(detail.props('periods')).toHaveLength(1)
    w.unmount()
  })

  it('opens the member detail as a master-detail pane above the list and scrolls it into view', async () => {
    const scroll = vi.mocked(Element.prototype.scrollIntoView)
    const w = mounted()
    await flushPromises()
    expect(w.find('[data-section="member-detail-pane"]').exists()).toBe(false)
    await w.get('[data-member="2"] [data-action="open-member"]').trigger('click')
    await flushPromises()
    const layout = w.get('[data-section="members-layout"]')
    expect(layout.classes()).toContain('lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]')
    const children = Array.from(layout.element.children).map(el => el.getAttribute('data-section'))
    expect(children).toEqual(['member-detail-pane', 'members'])
    const pane = w.get('[data-section="member-detail-pane"]')
    expect(pane.find('[data-stub="detail"]').exists()).toBe(true)
    expect(pane.classes()).toContain('lg:sticky')
    expect(scroll).toHaveBeenCalledTimes(1)
    expect(scroll.mock.contexts[0]).toBe(pane.element)
    expect(w.get('[data-member="2"]').attributes('aria-current')).toBe('true')
    expect(w.get('[data-member="1"]').attributes('aria-current')).toBeUndefined()

    await w.get('[data-member="1"] [data-action="open-member"]').trigger('click')
    await flushPromises()
    expect(scroll).toHaveBeenCalledTimes(2)
    expect(w.findComponent(Detail).props('memberId')).toBe(1)
    w.findComponent(Detail).vm.$emit('close')
    await flushPromises()
    expect(w.find('[data-section="member-detail-pane"]').exists()).toBe(false)
    expect(w.get('[data-section="members-layout"]').classes()).not.toContain('lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]')
    w.unmount()
  })

  it('creates a member only on explicit request from a chosen contact and a member number', async () => {
    const w = mounted()
    await flushPromises()
    expect(api.createMember).not.toHaveBeenCalled()
    expect(w.get('[data-action="create-member"]').attributes('disabled')).toBeDefined()
    await w.get('[data-field="member-client"]').setValue('3')
    await w.get('[data-field="member-number"]').setValue(' M-9 ')
    expect(api.createMember).not.toHaveBeenCalled()
    await w.get('[data-action="create-member"]').trigger('click')
    await flushPromises()
    expect(api.createMember).toHaveBeenCalledTimes(1)
    expect(api.createMember).toHaveBeenCalledWith(5, { client_id: 3, member_number: 'M-9' })
    expect(w.findComponent(Detail).props()).toMatchObject({ memberId: 9, label: 'Synthetic client 3' })
    expect(w.get('[data-section="workspace-notice"]').text()).toBe(cs.nonprofitMembership.memberCreated)
    expect(api.members).toHaveBeenCalledTimes(2)
    w.unmount()
  })

  it('creates a new contact in the shared client dialog and selects it for the member', async () => {
    api.createMember.mockResolvedValue({ id: 9, client_id: 44 })
    const w = mounted()
    await flushPromises()
    expect(w.find('[data-stub="client-modal"]').exists()).toBe(false)
    await w.get('[data-action="member-new-client"]').trigger('click')
    w.findComponent(ClientFormModal).vm.$emit('created', { id: 44, company_name: 'Synthetic new contact' })
    await flushPromises()
    expect(w.find('[data-stub="client-modal"]').exists()).toBe(false)
    expect(w.findComponent(ClientSearchSelect).props()).toMatchObject({ modelValue: 44, selectedLabel: 'Synthetic new contact' })
    await w.get('[data-field="member-number"]').setValue('M-44')
    await w.get('[data-action="create-member"]').trigger('click')
    await flushPromises()
    expect(api.createMember).toHaveBeenCalledWith(5, { client_id: 44, member_number: 'M-44' })
    w.unmount()
  })

  it('shows a server refusal of a duplicate identity and does not open a member', async () => {
    api.createMember.mockRejectedValue({ response: { status: 409, data: { error: { code: 'nonprofit_membership_identity_conflict', message: 'Synthetic identity conflict' } } } })
    const w = mounted()
    await flushPromises()
    await w.get('[data-field="member-client"]').setValue('3')
    await w.get('[data-field="member-number"]').setValue('M-1')
    await w.get('[data-action="create-member"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-section="workspace-error"]').text()).toContain(cs.nonprofitMembership.serverErrors.nonprofit_membership_identity_conflict)
    expect(w.find('[data-stub="detail"]').exists()).toBe(false)
    w.unmount()
  })

  it('hides creation without write rights but keeps the register readable', async () => {
    const w = mounted({ canManage: false })
    await flushPromises()
    expect(w.find('[data-section="create-member"]').exists()).toBe(false)
    expect(w.find('[data-action="create-member"]').exists()).toBe(false)
    expect(w.get('[data-section="readonly"]').text()).toBe(cs.nonprofitMembership.readonly)
    expect(w.findAll('[data-section="member-row"]')).toHaveLength(3)
    await w.get('[data-member="1"] [data-action="open-member"]').trigger('click')
    expect(w.findComponent(Detail).props('canManage')).toBe(false)
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

  it('closes an open member when the supplier changes', async () => {
    const w = mounted()
    await flushPromises()
    await w.get('[data-member="1"] [data-action="open-member"]').trigger('click')
    expect(w.find('[data-stub="detail"]').exists()).toBe(true)
    await w.setProps({ supplier: 6 })
    expect(w.find('[data-stub="detail"]').exists()).toBe(false)
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

  it('moves focus into the opened detail and back to the row button after closing it', async () => {
    const w = mount(Workspace, {
      attachTo: document.body,
      props: { supplier: 5, canManage: true },
      global: {
        plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })],
        stubs: { ActionBar, ClientSearchSelect, MembershipMemberDetail: Detail, MembershipReconciliation: Reconciliation },
      },
    })
    await flushPromises()
    await w.get('[data-member="2"] [data-action="open-member"]').trigger('click')
    await flushPromises()
    expect(document.activeElement?.getAttribute('data-focus')).toBe('member-heading')
    w.findComponent(Detail).vm.$emit('close')
    await flushPromises()
    expect(document.activeElement).toBe(w.get('[data-member="2"] [data-action="open-member"]').element)
    w.unmount()
  })

  it('tells the period check that its result is outdated after a write in the member detail', async () => {
    const w = mounted()
    await flushPromises()
    expect(w.findComponent(Reconciliation).props('dataVersion')).toBe(0)
    await w.get('[data-member="1"] [data-action="open-member"]').trigger('click')
    await flushPromises()
    w.findComponent(Detail).vm.$emit('changed')
    await flushPromises()
    expect(w.findComponent(Reconciliation).props('dataVersion')).toBe(1)
    expect(api.members).toHaveBeenCalledTimes(2)
    w.unmount()
  })

  it('returns focus to the list heading when the closed member is no longer in the list', async () => {
    const w = mount(Workspace, {
      attachTo: document.body,
      props: { supplier: 5, canManage: true },
      global: {
        plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })],
        stubs: { ActionBar, ClientSearchSelect, MembershipMemberDetail: Detail, MembershipReconciliation: Reconciliation },
      },
    })
    await flushPromises()
    await w.get('[data-member="2"] [data-action="open-member"]').trigger('click')
    await flushPromises()
    api.members.mockResolvedValue(page([row(1)]))
    w.findComponent(Detail).vm.$emit('changed')
    await flushPromises()
    w.findComponent(Detail).vm.$emit('close')
    await flushPromises()
    expect(document.activeElement?.getAttribute('data-focus')).toBe('members-heading')
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

  it('places the period check below the member list', async () => {
    const w = mounted()
    await flushPromises()
    const sections = Array.from<Element>(w.element.querySelectorAll('[data-section="members"], [data-stub="reconciliation"]')).map(el => el.getAttribute('data-section') ?? 'reconciliation')
    expect(sections).toEqual(['members', 'reconciliation'])
    w.unmount()
  })

  it('waits until the year is typed before loading members or titles', async () => {
    const w=mounted();await flushPromises()
    vi.useFakeTimers()
    try {
      const members=api.members.mock.calls.length, titles=api.titles.mock.calls.length
      for (const value of ['2','20','202','2025']) {
        await w.get('[data-field="member-year"]').setValue(value)
        await vi.advanceTimersByTimeAsync(100)
        expect(w.find('[data-section="workspace-error"]').exists()).toBe(false)
      }
      expect(api.members).toHaveBeenCalledTimes(members)
      expect(api.titles).toHaveBeenCalledTimes(titles)
      await vi.advanceTimersByTimeAsync(300);await flushPromises()
      expect(api.members).toHaveBeenCalledTimes(members+1)
      expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({year:2025}))
      expect(api.titles).toHaveBeenCalledTimes(titles+1)
      expect(api.titles).toHaveBeenLastCalledWith(5,2025,undefined)
    } finally { vi.useRealTimers(); w.unmount() }
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

  it('loads titles for a year typed before a batch lock once the batch unlocks', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    vi.useFakeTimers()
    try {
      await w.get('[data-field="member-year"]').setValue('2025')
      w.findComponent(MembershipBatchFlow).vm.$emit('lock', true);await flushPromises()
      await vi.advanceTimersByTimeAsync(300);await flushPromises()
      expect(api.titles).not.toHaveBeenCalledWith(5,2025,undefined)
      w.findComponent(MembershipBatchFlow).vm.$emit('lock', false);await flushPromises()
      expect(api.titles).toHaveBeenLastCalledWith(5,2025,undefined)
    } finally { vi.useRealTimers(); w.unmount() }
  })

  it('reloads titles after a saved batch or a change in member detail', async () => {
    const w=mounted();await flushPromises()
    await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    api.titles.mockResolvedValue({items: ['SYNTHETIC','SYNTHETIC-NEW'], next_after_title_key: null})
    const before=api.titles.mock.calls.length
    w.findComponent(MembershipBatchFlow).vm.$emit('changed');await flushPromises()
    expect(api.titles).toHaveBeenCalledTimes(before+1)
    expect(w.get('[data-field="member-title"]').findAll('option').map(o => o.text())).toContain('SYNTHETIC-NEW')
    await w.get('[data-member="1"] [data-action="open-member"]').trigger('click');await flushPromises()
    w.findComponent(Detail).vm.$emit('changed');await flushPromises()
    expect(api.titles).toHaveBeenCalledTimes(before+2)
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
    vi.useFakeTimers()
    try {
      await w.get('[data-field="member-year"]').setValue('9999')
      await vi.advanceTimersByTimeAsync(300);await flushPromises()
      expect(w.get('[data-section="workspace-error"]').text()).toBe(cs.nonprofitMembership.listNeedPeriod)
      await w.get('[data-member="1"] [data-action="open-member"]').trigger('click');await flushPromises()
      w.findComponent(Detail).vm.$emit('changed');await flushPromises()
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

  it('does not apply a partially typed year when the status changes during the delay', async () => {
    const w=mounted();await flushPromises()
    vi.useFakeTimers()
    try {
      await w.get('[data-field="member-year"]').setValue('202')
      await w.get('[data-field="member-payment-status"]').setValue('unpaid')
      expect(w.find('[data-section="workspace-error"]').exists()).toBe(false)
      await w.get('[data-field="member-year"]').setValue('2025')
      await vi.advanceTimersByTimeAsync(300);await flushPromises()
      expect(w.find('[data-section="workspace-error"]').exists()).toBe(false)
      expect(api.members).toHaveBeenLastCalledWith(5,0,50,expect.objectContaining({year:2025,payment_status:'unpaid'}))
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
})
