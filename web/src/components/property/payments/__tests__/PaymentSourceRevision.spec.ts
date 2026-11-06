import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import PaymentSourceRevisionEditor from '../PaymentSourceRevisionEditor.vue'
import type { PaymentSourceDetails } from '@/api/propertyPayments'
const calls = vi.hoisted(() => ({ revisionPreview: vi.fn(), revisionConfirm: vi.fn() }))
vi.mock('@/api/propertyPayments', () => ({ propertyPaymentsApi: calls }))
const source: PaymentSourceDetails = { source_id: 9, source_version: 3, revision_id: null, revision_date: null, kind: 'cash', id: 12, date: '2026-02-01', direction: 'in', amount_cents: '10000', consumed_cents: '6000', available_cents: '4000', cells: [{ key: 'old', amount_cents: '10000', available_cents: '4000', dimensions: {}, dimension_splits: {} }] }
const actions = { props: ['actions'], template: '<div><button v-for="a in actions" :key="a.key" :data-action="a.key" :disabled="a.disabled" @click="a.run">{{ a.label }}</button></div>' }
const date = { props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' }
function mounted() {
  return mount(PaymentSourceRevisionEditor, {
    props: { supplier: 1, source, dimensions: [], canManage: true, canPost: true },
    global: {
      plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs: {} }, missingWarn: false, fallbackWarn: false })],
      stubs: { ActionBar: actions, DateInput: date, PropertyInstantInput: { ...date, template: '<input data-instant :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' } },
    },
  })
}
beforeEach(() => { vi.clearAllMocks() })
async function fill(wrapper: ReturnType<typeof mounted>, amount: string) {
  await wrapper.get('[data-action="revision-open"]').trigger('click')
  await wrapper.get('[data-field="revision-date"]').setValue('2026-03-01')
  await wrapper.get('[data-instant]').setValue('2026-03-01T10:00:00+01:00')
  await wrapper.get('[data-field="revision-reason"]').setValue('Synthetic analytic correction')
  await wrapper.get('[data-field="revision-evidence"]').setValue('Synthetic documented allocation')
  await wrapper.get('[data-field="revision-from"]').setValue('old')
  await wrapper.get('[data-field="revision-amount"]').setValue(amount)
}
describe('free clearing correction', () => {
  it('does not preview more than the free forty crowns', async () => {
    const wrapper = mounted(); await fill(wrapper, '40.01')
    await wrapper.get('[data-action="revision-preview"]').trigger('click'); await flushPromises()
    expect(calls.revisionPreview).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toBe('propertyPayments.revisionInvalid')
  })
  it('posts the exact displayed preview with current source CAS and an opaque retry token', async () => {
    calls.revisionPreview.mockResolvedValue({ status: 'ready', preview_hash: 'synthetic-hash', snapshot: { plan: { cells: [] } } })
    calls.revisionConfirm.mockResolvedValue({ id: 8 })
    const wrapper = mounted(); await fill(wrapper, '40.00')
    await wrapper.get('[data-action="revision-preview"]').trigger('click'); await flushPromises()
    const request = calls.revisionPreview.mock.calls[0]![1]
    expect(request).toMatchObject({ source_id: 9, expected_source_version: 3, expected_revision_id: null, moves: [{ from_key: 'old', amount_cents: '4000' }] })
    await wrapper.get('[data-action="revision-confirm"]').trigger('click'); await flushPromises()
    expect(calls.revisionConfirm).toHaveBeenCalledWith(1, request, 'synthetic-hash', expect.stringMatching(/^[0-9a-f-]{36}$/))
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })
  it('discards an in-flight preview after the source company changes', async () => {
    let resolve!: (value: unknown) => void
    calls.revisionPreview.mockReturnValue(new Promise(r => { resolve = r }))
    const wrapper = mounted(); await fill(wrapper, '40.00')
    await wrapper.get('[data-action="revision-preview"]').trigger('click')
    await wrapper.setProps({ supplier: 2 })
    resolve({ status: 'ready', preview_hash: 'old-company', snapshot: { plan: { cells: [] } } }); await flushPromises()
    expect(wrapper.find('[data-action="revision-confirm"]').exists()).toBe(false)
    expect(calls.revisionConfirm).not.toHaveBeenCalled()
  })
})
