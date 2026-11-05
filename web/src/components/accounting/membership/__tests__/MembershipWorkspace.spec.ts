import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, KeepAlive, ref } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { formatDate } from '@/composables/useFormat'
import Workspace from '../MembershipWorkspace.vue'
import type { MemberPage, MemberRow } from '@/api/nonprofitMembership'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({ members: vi.fn(), createMember: vi.fn() }))
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
const Detail = defineComponent({ name: 'MembershipMemberDetail', props: ['supplier', 'canManage', 'memberId', 'label', 'periods'], emits: ['changed', 'close'], setup: () => () => h('div', { 'data-stub': 'detail' }, [h('h2', { tabindex: -1, 'data-focus': 'member-heading' })]) })
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
      stubs: { ActionBar, ClientSearchSelect, MembershipMemberDetail: Detail, MembershipReconciliation: Reconciliation },
    },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
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
    expect(api.members).toHaveBeenCalledWith(5, 0, 50)
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
    expect(api.members.mock.calls[1]).toEqual([5, 1, 50])
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

  it('shows a server refusal of a duplicate identity and does not open a member', async () => {
    api.createMember.mockRejectedValue({ response: { status: 409, data: { error: { code: 'nonprofit_membership_identity_conflict', message: 'Synthetic identity conflict' } } } })
    const w = mounted()
    await flushPromises()
    await w.get('[data-field="member-client"]').setValue('3')
    await w.get('[data-field="member-number"]').setValue('M-1')
    await w.get('[data-action="create-member"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-section="workspace-error"]').text()).toContain('Synthetic identity conflict')
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
    expect(api.members).toHaveBeenLastCalledWith(6, 0, 50)
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
    expect(w.get('[data-section="workspace-error"]').text()).toContain('Synthetic forbidden')
    expect(w.get('[data-section="workspace-error"]').classes()).toEqual(expect.arrayContaining(['bg-danger-50', 'text-danger-700']))
    expect(w.find('[data-section="members-empty"]').exists()).toBe(true)
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
})
