import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Batch from '../MembershipBatchFlow.vue'
import Issue from '../MembershipIssueFlow.vue'
import type { MembershipBatchDetail, MembershipIssueRequest } from '@/api/nonprofitMembership'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({batches: vi.fn(), batch: vi.fn(), createBatch: vi.fn(), prepareBatch: vi.fn(), confirmBatch: vi.fn(), cancelBatch: vi.fn(), renewBatch: vi.fn(), classificationOptions: vi.fn()}))
vi.mock('@/api/nonprofitMembership', () => ({membershipApi: api}))
const ActionBar = defineComponent({props: ['actions'], setup: p => () => h('div', (p.actions as Array<{key:string;label:string;show?:unknown;disabled?:boolean;run:()=>void}>).filter(x => x.show !== false).map(x => h('button', {'data-action': x.key, disabled: x.disabled, onClick: x.run}, x.label)))})
const request: MembershipIssueRequest = {member_id:0,rate_id:3,title_key:'synthetic',period_from:'2026-01-01',period_to:'2026-12-31',accounting_date:'2026-01-01',due_date:'2026-01-31',amount:'500.00',receivable_account:'315',revenue_account:'684',title_reference:'Synthetic resolution',evidence:'Synthetic evidence',reason:'Synthetic batch',nonreciprocal:true,classification:{activity_value_id:11,activity_kind_value_id:22,tax_regime_value_id:33}}
const {member_id: _id, ...template} = request
const state = (phase: MembershipBatchDetail['batch']['phase']): MembershipBatchDetail => ({
  batch:{id:7,supplier_id:5,phase,template,approval_token:phase === 'preparing' ? null : 'a'.repeat(64),member_count:2,selection_mode:'selected'},
  summary:{total:2,pending:phase === 'preparing' ? 2 : 0,ready:phase === 'ready' ? 2 : phase === 'posting' ? 1 : 0,created:phase === 'finished' ? 2 : phase === 'posting' ? 1 : 0,existing:0,rejected:0,planned_cents:'100000',created_cents:phase === 'finished' ? '100000' : '0',deferred_cents:'0',deferral_date:null},
  items:[9,10].map(id=>({member_id:id,member_number:`SYN-${id}`,company_name:'Synthetic member',status:phase === 'finished' ? 'created' : 'ready',amount_cents:50000,operation_id:null,error_code:null,error_message:null})),
  next_after_member_id:null,accounts:[{account:'315',side:'debit',amount_cents:'100000'},{account:'684',side:'credit',amount_cents:'100000'}],
})
const dimensions = {types:[{id:1,supplier_id:5,system_key:'activity'},{id:2,supplier_id:5,system_key:'activity_kind'},{id:3,supplier_id:5,system_key:'tax_regime'}],values:[{id:11,supplier_id:5,type_id:1,name:'Synthetic main',semantic_key:'main'},{id:22,supplier_id:5,type_id:2,name:'Synthetic membership',code:'SYNTHETIC'},{id:33,supplier_id:5,type_id:3,name:'Synthetic tax',semantic_key:'subject'}]}
function mounted(props = {}) {return mount(Batch,{props:{supplier:5,canManage:true,periods:[],selectedMemberIds:[9,10],...props},global:{plugins:[createI18n({legacy:false,locale:'cs',messages:{cs,en}})],stubs:{ActionBar,MembershipIssueFlow:true}}})}
beforeEach(() => {
  vi.clearAllMocks()
  api.renewBatch.mockResolvedValue(state('preparing'))
  api.cancelBatch.mockResolvedValue({...state('cancelled'), batch: {...state('cancelled').batch, approval_token: null}})
  api.batches.mockResolvedValue({items:[],next_before_id:null})
  api.classificationOptions.mockResolvedValue(dimensions)
  api.createBatch.mockResolvedValue(state('preparing'))
  api.prepareBatch.mockResolvedValue(state('ready'))
  api.confirmBatch.mockResolvedValue(state('finished'))
  api.batch.mockResolvedValue(state('ready'))
})
describe('hromadný předpis', () => {
  it('continues seeding pages before preparing and never posts during seeding', async () => {
    api.createBatch.mockResolvedValue(state('seeding'))
    api.prepareBatch.mockResolvedValueOnce(state('seeding')).mockResolvedValueOnce(state('preparing')).mockResolvedValueOnce(state('ready'))
    const w=mounted(); await flushPromises()
    w.findComponent(Issue).vm.$emit('batchRequest',request); await flushPromises()
    expect(api.prepareBatch).toHaveBeenCalledTimes(3)
    expect(api.confirmBatch).not.toHaveBeenCalled()
    expect(w.get('[data-action="confirm"]').attributes('disabled')).toBeDefined()
    w.unmount()
  })

  it('expires a stored approval and requires a renewed preview and fresh acknowledgement', async () => {
    api.prepareBatch.mockResolvedValueOnce({...state('ready'),batch:{...state('ready').batch,approval_expires_at:'2000-01-01 00:00:00'}}).mockResolvedValueOnce(state('ready'))
    const w=mounted(); await flushPromises()
    w.findComponent(Issue).vm.$emit('batchRequest',request); await flushPromises()
    expect(w.find('[data-action="confirm"]').exists()).toBe(false)
    await w.get('[data-action="batch-renew"]').trigger('click'); await flushPromises()
    expect(api.renewBatch).toHaveBeenCalledWith(5,7)
    expect(w.get('[data-action="confirm"]').attributes('disabled')).toBeDefined()
    expect(api.confirmBatch).not.toHaveBeenCalled()
    await w.get('[data-field="batch-ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click'); await flushPromises()
    expect(api.confirmBatch).toHaveBeenCalledTimes(1)
    w.unmount()
  })

  it('cancels further processing only after explicit confirmation and requires a new preview after expiry', async () => {
    const w=mounted(); await flushPromises()
    w.findComponent(Issue).vm.$emit('batchRequest',request); await flushPromises()
    await w.get('[data-action="batch-cancel"]').trigger('click')
    expect(api.cancelBatch).not.toHaveBeenCalled()
    await w.get('[data-action="batch-cancel-confirm"]').trigger('click'); await flushPromises()
    expect(api.cancelBatch).toHaveBeenCalledWith(5,7)
    expect(w.find('[data-action="confirm"]').exists()).toBe(false)
    expect(w.text()).toContain(cs.nonprofitMembership.batchPhase_cancelled)
    w.unmount()
    api.prepareBatch.mockResolvedValue({...state('expired'),batch:{...state('expired').batch,approval_token:null}})
    const expired=mounted(); await flushPromises()
    expired.findComponent(Issue).vm.$emit('batchRequest',request); await flushPromises()
    expect(expired.text()).toContain(cs.nonprofitMembership.batchNeedNewPreview)
    expect(expired.find('[data-action="confirm"]').exists()).toBe(false)
    expired.unmount()
  })

  it('copies a selected saved batch from history across pages without issuing anything', async () => {
    const source={...state('finished'),batch:{...state('finished').batch,member_count:3}}
    api.batches.mockResolvedValue({items:[{id:7,phase:'finished',title_key:'synthetic',period_from:'2026-01-01',period_to:'2026-12-31',created_at:'2026-01-01 12:00:00',member_count:3}],next_before_id:null})
    api.batch.mockResolvedValueOnce(source).mockResolvedValueOnce({...source,next_after_member_id:10})
      .mockResolvedValueOnce({...source,items:[{...source.items[0]!,member_id:11}],next_after_member_id:null})
    const w=mounted({selectedMemberIds:[]});await flushPromises()
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    await w.get('[data-action="batch-new"]').trigger('click');await flushPromises()
    expect(api.batch).toHaveBeenNthCalledWith(3,5,7,10)
    expect(w.findComponent(Issue).props('initialRequest')).toEqual(template)
    expect(api.createBatch).not.toHaveBeenCalled()
    w.findComponent(Issue).vm.$emit('batchRequest',request);await flushPromises()
    expect(api.createBatch).toHaveBeenCalledWith(5,expect.objectContaining({member_ids:[9,10,11]}))
    expect(api.confirmBatch).not.toHaveBeenCalled()
    w.unmount()
  })
  it('keeps the saved batch visible when copying its selected members fails', async () => {
    const w=mounted();await flushPromises()
    w.findComponent(Issue).vm.$emit('batchRequest',request);await flushPromises()
    api.batch.mockRejectedValueOnce(new Error('Synthetic copy failure'))
    await w.get('[data-action="batch-new"]').trigger('click');await flushPromises()
    expect(w.find('[data-section="batch-summary"]').exists()).toBe(true)
    expect(w.get('[data-section="batch-error"]').text()).toContain(cs.nonprofitMembership.error)
    expect(api.createBatch).toHaveBeenCalledTimes(1)
    w.unmount()
  })
  it('restores the saved template and selected members for correction after the list selection clears', async () => {
    const w=mounted();await flushPromises()
    w.findComponent(Issue).vm.$emit('batchRequest',request);await flushPromises()
    await w.setProps({selectedMemberIds:[]});await flushPromises()
    await w.get('[data-action="batch-new"]').trigger('click');await flushPromises()
    expect(w.findComponent(Issue).props('initialRequest')).toEqual(template)
    w.findComponent(Issue).vm.$emit('batchRequest',{...request,due_date:'2026-02-01'});await flushPromises()
    expect(api.createBatch.mock.calls[1]?.[1]).toMatchObject({member_ids:[9,10],template:{due_date:'2026-02-01'}})
    w.unmount()
  })
  it('shows the server-calculated aggregate deferral before batch approval', async () => {
    api.prepareBatch.mockResolvedValue({...state('ready'),summary:{...state('ready').summary,deferred_cents:'50000',deferral_date:'2026-12-31'}})
    const w=mounted();await flushPromises()
    w.findComponent(Issue).vm.$emit('batchRequest',request);await flushPromises()
    expect(w.get('[data-section="batch-deferral"]').text()).toContain('384')
    expect(api.confirmBatch).not.toHaveBeenCalled()
    w.unmount()
  })
  it('starts a genuinely new batch with the same values only after the explicit new-batch action', async () => {
    const w=mounted();await flushPromises()
    w.findComponent(Issue).vm.$emit('batchRequest',request);await flushPromises()
    const first=api.createBatch.mock.calls[0]?.[1].idempotency_key
    await w.get('[data-action="batch-new"]').trigger('click');await flushPromises()
    w.findComponent(Issue).vm.$emit('batchRequest',request);await flushPromises()
    expect(api.createBatch.mock.calls[1]?.[1].idempotency_key).not.toBe(first)
    w.unmount()
  })
  it('prepares a complete preview and posts only after explicit acknowledgement', async () => {
    const w=mounted();await flushPromises()
    w.findComponent(Issue).vm.$emit('batchRequest',request);await flushPromises()
    expect(api.createBatch).toHaveBeenCalledWith(5,expect.objectContaining({template,selection_mode:'selected',member_ids:[9,10]}))
    expect(api.prepareBatch).toHaveBeenCalledWith(5,7)
    expect(api.confirmBatch).not.toHaveBeenCalled()
    expect(w.get('[data-action="confirm"]').attributes('disabled')).toBeDefined()
    await w.get('[data-field="batch-ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click');await flushPromises()
    expect(api.confirmBatch).toHaveBeenCalledWith(5,7,'a'.repeat(64))
    expect(w.emitted('changed')).toHaveLength(1)
    expect(w.get('[data-section="batch-summary"]').text()).toContain('2')
    w.unmount()
  })
  it('requires selected members or an explicit active selection', async () => {
    const w=mounted({selectedMemberIds:[]});await flushPromises()
    w.findComponent(Issue).vm.$emit('batchRequest',request);await flushPromises()
    expect(api.createBatch).not.toHaveBeenCalled()
    await w.get('[data-field="batch-active"]').setValue()
    w.findComponent(Issue).vm.$emit('batchRequest',request);await flushPromises()
    expect(api.createBatch).toHaveBeenCalledWith(5,expect.objectContaining({selection_mode:'active',member_ids:[]}))
    w.unmount()
  })
  it('reuses the create key after a lost response and never auto-confirms after retry', async () => {
    api.createBatch.mockRejectedValueOnce(new Error('Synthetic network failure'))
    const w=mounted();await flushPromises()
    w.findComponent(Issue).vm.$emit('batchRequest',request);await flushPromises()
    const first=api.createBatch.mock.calls[0]?.[1]
    w.findComponent(Issue).vm.$emit('batchRequest',request);await flushPromises()
    expect(api.createBatch.mock.calls[1]?.[1].idempotency_key).toBe(first.idempotency_key)
    expect(api.confirmBatch).not.toHaveBeenCalled()
    w.unmount()
  })
  it('can reopen and resume an interrupted saved batch', async () => {
    api.batches.mockResolvedValue({items:[{id:7,phase:'posting',title_key:'synthetic',period_from:'2026-01-01',period_to:'2026-12-31',created_at:'2026-01-01 12:00:00',member_count:2}],next_before_id:null})
    api.batch.mockResolvedValue(state('posting'))
    const w=mounted();await flushPromises()
    await w.get('[data-action="batch-open"]').trigger('click');await flushPromises()
    expect(api.confirmBatch).not.toHaveBeenCalled()
    await w.get('[data-field="batch-ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click');await flushPromises()
    expect(api.confirmBatch).toHaveBeenCalledTimes(1)
    w.unmount()
  })
  it('keeps a lost confirmation recoverable without silently sending the next page', async () => {
    api.confirmBatch.mockRejectedValueOnce(new Error('Synthetic lost response'))
    api.batch.mockResolvedValue(state('posting'))
    const w=mounted();await flushPromises()
    w.findComponent(Issue).vm.$emit('batchRequest',request);await flushPromises()
    await w.get('[data-field="batch-ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click');await flushPromises()
    expect(api.confirmBatch).toHaveBeenCalledTimes(1)
    expect(w.find('[data-section="batch-error"]').exists()).toBe(true)
    await w.get('[data-action="confirm"]').trigger('click');await flushPromises()
    expect(api.confirmBatch).toHaveBeenCalledTimes(2)
    w.unmount()
  })
  it('ignores a late create response after switching suppliers', async () => {
    let complete!:(value:MembershipBatchDetail)=>void
    api.createBatch.mockReturnValue(new Promise(resolve=>{complete=resolve}))
    const w=mounted();await flushPromises()
    w.findComponent(Issue).vm.$emit('batchRequest',request);await flushPromises()
    await w.setProps({supplier:6});await flushPromises()
    complete(state('preparing'));await flushPromises()
    expect(api.prepareBatch).not.toHaveBeenCalled()
    expect(w.find('[data-section="batch-summary"]').exists()).toBe(false)
    w.unmount()
  })
})
