import { config, flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { createMemoryHistory, createRouter } from 'vue-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Workspace from '../MembershipWorkspace.vue'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({members: vi.fn(), titles: vi.fn(), selectionCheck: vi.fn()}))
vi.mock('@/api/nonprofitMembership', () => ({membershipApi: api}))
vi.mock('@/api/accounting', () => ({accountingApi: {listPeriods: async () => []}}))
vi.mock('@/api/clients', () => ({clientsApi: {}}))
const ActionBar = defineComponent({props: ['actions'], setup: p => () => h('div', (p.actions as {key: string; label: string; show?: boolean; disabled?: boolean; run: () => void}[]).filter(a => a.show !== false).map(a => h('button', {'data-action': a.key, disabled: a.disabled, onClick: a.run}, a.label)))})
const Batch = defineComponent({name: 'MembershipBatchFlow', props: ['selectedMemberIds'], emits: ['lock'], setup: () => () => h('div')})
const empty = {template: '<div />'}
const member = {id: 1, supplier_id: 5, client_id: 11, member_number: 'SYN-1', created_by: 1, company_name: 'Synthetic member', revision_no: 1, effective_from: '2020-01-01', status: 'active'}
async function mounted() {
  const router = createRouter({history: createMemoryHistory(), routes: [{path: '/membership', component: empty}]})
  await router.push('/membership?year=2026'); await router.isReady()
  // Sdílený test setup registruje náhradu odkazu; tento scénář používá skutečný router.
  const link = config.global.components.RouterLink
  delete config.global.components.RouterLink
  const w = mount(Workspace, {props: {supplier: 5, canManage: true}, global: {
    plugins: [router, createI18n({legacy: false, locale: 'cs', messages: {cs, en}})],
    stubs: {ActionBar, MembershipBatchFlow: Batch, MembershipRates: empty, ClientSearchSelect: empty, MembershipMemberDetail: empty, MembershipReconciliation: empty},
  }})
  if (link) config.global.components.RouterLink = link
  await flushPromises()
  await w.get('[data-member="1"] [data-action="batch-check-member"]').setValue(true)
  await w.get('[data-action="batch-open"]').trigger('click'); await flushPromises()
  return {w, router, batch: w.findComponent(Batch)}
}
beforeEach(() => {
  vi.clearAllMocks()
  api.members.mockImplementation(async (_supplier, _after, _limit, filters) => ({items: [{...member, company_name: 'Synthetic ' + filters.year}], next_after_id: null}))
  api.selectionCheck.mockImplementation(async (_supplier, member_ids) => ({member_ids}))
  api.titles.mockResolvedValue({items: [], next_after_title_key: null})
})
afterEach(() => {vi.useRealTimers()})

describe('obnova výběru a skutečná navigace během dávky', () => {
  it.each(['retry', 'debounce'])('preserves selection after a failed refresh and unchanged filter %s', async mode => {
    const {w, batch} = await mounted()
    vi.useFakeTimers()
    if (mode === 'debounce') await w.get('[data-field="member-search"]').setValue('  ')
    api.selectionCheck.mockRejectedValueOnce(new Error('Synthetic selection check failure'))
    await w.get('[data-action="reload-members"]').trigger('click'); await flushPromises()
    expect(w.find('[data-section="workspace-error"]').exists()).toBe(true)
    expect(batch.props('selectedMemberIds')).toEqual([1])
    if (mode === 'retry') await w.get('[data-action="reload-members"]').trigger('click')
    else await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    expect(batch.props('selectedMemberIds')).toEqual([1])
    expect(w.find('[data-section="workspace-error"]').exists()).toBe(false)
    expect(api.selectionCheck).toHaveBeenCalledTimes(2)
    w.unmount()
  })

  it('defers real router transitions while locked and applies only the latest URL after unlock', async () => {
    const {w, router, batch} = await mounted()
    vi.useFakeTimers()
    await w.get('[data-field="member-search"]').setValue('Synthetic unfinished')
    batch.vm.$emit('lock', true); await flushPromises()
    const calls = api.members.mock.calls.length
    await router.push('/membership?year=2027&search=Synthetic%20target'); await flushPromises()
    await vi.advanceTimersByTimeAsync(300); await flushPromises()
    expect(api.members).toHaveBeenCalledTimes(calls)
    expect(w.get('[data-member="1"]').text()).toContain('Synthetic 2026')
    batch.vm.$emit('lock', false); await flushPromises()
    expect(api.members).toHaveBeenCalledTimes(calls + 1)
    expect(api.members).toHaveBeenLastCalledWith(5,0,50,{search:'Synthetic target',year:2027,title_key:'',payment_status:'all'})
    expect((w.get('[data-field="member-search"]').element as HTMLInputElement).value).toBe('Synthetic target')
    expect(w.get('[data-member="1"]').text()).toContain('Synthetic 2027')
    expect(batch.props('selectedMemberIds')).toEqual([])
    expect(router.currentRoute.value.query.year).toBe('2027')

    batch.vm.$emit('lock', true); await flushPromises()
    const backDone = new Promise<void>(resolve => {const stop = router.afterEach(() => {stop(); resolve()})})
    router.back(); await backDone; await flushPromises()
    expect(router.currentRoute.value.query.year).toBe('2026')
    expect(w.get('[data-member="1"]').text()).toContain('Synthetic 2027')
    batch.vm.$emit('lock', false); await flushPromises()
    expect((w.get('[data-field="member-year"]').element as HTMLInputElement).value).toBe('2026')
    expect(w.get('[data-member="1"]').text()).toContain('Synthetic 2026')
    expect(api.members).toHaveBeenCalledTimes(calls + 2)
    w.unmount()
  })
})
