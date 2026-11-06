import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Panel from '../SettlementDeadlinesPanel.vue'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const mocks = vi.hoisted(() => ({ deadlines: vi.fn(), recordObjection: vi.fn(), resolveObjection: vi.fn() }))
vi.mock('@/api/propertySettlements', () => ({ propertySettlementsApi: mocks }))
const ActionBar = defineComponent({ props: ['actions'], setup: p => () => h('div', (p.actions as Array<{ key: string; label: string; disabled?: boolean; run: () => void }>).map(a => h('button', { 'data-action': a.key, disabled: a.disabled, onClick: a.run }, a.label))) })
const DateInput = defineComponent({ props: ['modelValue'], emits: ['update:modelValue'], setup: (p, { emit, attrs }) => () => h('input', { ...attrs, value: p.modelValue, onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).value) }) })
const read = {
 today: '2027-08-01', period_to: '2026-12-31', delivery_deadline: '2027-04-30', blocking: false,
 deliveries: [{ segment_ordinal: 1, recipient_id: 7, status: 'delivered', delivery_deadline: '2027-04-30', delivered_on: '2027-05-10', due_date: '2027-10-31', due_limit: '2027-09-10', objection_deadline: '2027-06-09', warnings: ['delivery_late', 'due_beyond_limit'] }],
 objections: [{ id: 3, recipient_id: 7, received_on: '2027-06-20', content: 'Synthetic objection', resolved_on: null, resolution: null, recorded_at: '2027-06-20 10:00:00', submission_deadline: '2027-06-09', resolution_deadline: '2027-07-20', warnings: ['objection_late', 'objection_resolution_overdue'] }],
 warnings: [], parties: [{ id: 7, name: 'Synthetic recipient' }],
}
function mounted(manage = true, locale = 'cs') {
 return mount(Panel, { props: { supplier: 5, documentId: 11, manage }, global: { plugins: [createI18n({ legacy: false, locale, messages: { cs, en } })], stubs: { ActionBar, DateInput } } })
}
beforeEach(() => {
 vi.clearAllMocks()
 mocks.deadlines.mockResolvedValue(structuredClone(read))
 mocks.recordObjection.mockResolvedValue({})
 mocks.resolveObjection.mockResolvedValue({})
})
describe('settlement statutory deadlines', () => {
 it.each(['cs', 'en'])('shows readable deadlines and warnings in %s', async locale => {
  const w = mounted(true, locale); await flushPromises()
  const messages = locale === 'cs' ? cs : en
  expect(w.text()).toContain(messages.propertySettlements.deadlines.warning.delivery_late)
  expect(w.text()).toContain(messages.propertySettlements.deadlines.warning.objection_resolution_overdue)
  expect(w.text()).toContain('Synthetic recipient')
  expect(w.text()).not.toContain('2027-05-10')
  expect(w.text()).not.toContain('propertySettlements.')
  w.unmount()
 })
 it('records and resolves an objection only with the manage right', async () => {
  let w = mounted(); await flushPromises()
  await w.get('[data-field="objection-content"]').setValue('Synthetic new objection')
  await w.get('[data-field="objection-received"]').setValue('2027-06-01')
  await w.get('[data-action="record-objection"]').trigger('click'); await flushPromises()
  expect(mocks.recordObjection).toHaveBeenCalledWith(5, 11, { recipient_id: 7, received_on: '2027-06-01', content: 'Synthetic new objection' })
  await w.get('[data-action="begin-resolve-3"]').trigger('click')
  await w.get('[data-field="resolved-on"]').setValue('2027-07-25'); await w.get('[data-field="resolution"]').setValue('Synthetic rejection')
  await w.get('[data-action="resolve-objection"]').trigger('click'); await flushPromises()
  expect(mocks.resolveObjection).toHaveBeenCalledWith(5, 3, { resolved_on: '2027-07-25', resolution: 'Synthetic rejection' })
  w.unmount()
  w = mounted(false); await flushPromises()
  expect(w.find('[data-action="record-objection"]').exists()).toBe(false)
  expect(w.find('[data-action="begin-resolve-3"]').exists()).toBe(false)
  w.unmount()
 })
})
