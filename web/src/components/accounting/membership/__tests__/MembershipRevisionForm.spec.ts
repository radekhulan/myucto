import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { formatDate } from '@/composables/useFormat'
import Form from '../MembershipRevisionForm.vue'
import type { MemberRevision } from '@/api/nonprofitMembership'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({ appendRevision: vi.fn() }))
vi.mock('@/api/nonprofitMembership', () => ({ membershipApi: api }))

const ActionBar = defineComponent({
  props: ['actions'],
  setup: p => () => h('div', (p.actions as Array<{ key: string; label: string; disabled?: boolean; show?: unknown; run: () => void }>)
    .filter(a => a.show !== false).map(a => h('button', { 'data-action': a.key, disabled: a.disabled, onClick: a.run }, a.label))),
})
const DateInput = defineComponent({
  props: ['modelValue'], emits: ['update:modelValue'],
  setup: (p, { emit, attrs }) => () => h('input', { ...attrs, value: p.modelValue, onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).value) }),
})
const member = { id: 9, supplier_id: 5, client_id: 3, member_number: 'M-1', created_by: 1 }
const revision = (no: number, from: string, status: MemberRevision['status'] = 'active'): MemberRevision => ({
  id: no, supplier_id: 5, member_id: 9, revision_no: no, previous_id: no > 1 ? no - 1 : null, effective_from: from, status,
  legal_basis: { organisation_form: 'association', relationship: 'nonreciprocal_membership', reference: 'r', evidence: 'e' },
  idempotency_key: `k${no}`, request_hash: 'a', parent_hash: 'b', record_hash: 'c', created_by: 1,
})

function mounted(props: Record<string, unknown> = {}, locale = 'cs') {
  return mount(Form, {
    props: { supplier: 5, canManage: true, member, history: [revision(1, '2020-01-01')], ...props },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { cs, en } })], stubs: { ActionBar, DateInput } },
  })
}
async function fill(w: ReturnType<typeof mounted>, from = '2026-01-01') {
  await w.get('[data-field="rev-status"]').setValue('suspended')
  await w.get('[data-field="rev-from"]').setValue(from)
  await w.get('[data-field="rev-reference"]').setValue('Board resolution 4/2026')
  await w.get('[data-field="rev-evidence"]').setValue('Synthetic minutes')
}
async function review(w: ReturnType<typeof mounted>) {
  await w.get('[data-action="rev-review"]').trigger('click')
  expect(w.find('[data-section="rev-preview"]').exists()).toBe(true)
}
const body = {
  status: 'suspended', effective_from: '2026-01-01',
  legal_basis: { organisation_form: 'association', relationship: 'nonreciprocal_membership', reference: 'Board resolution 4/2026', evidence: 'Synthetic minutes' },
  expected_revision: 1, idempotency_key: expect.stringMatching(/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,79}$/),
}

beforeEach(() => {
  vi.clearAllMocks()
  api.appendRevision.mockResolvedValue({ id: 2 })
})

describe('Membership revision form', () => {
  it('does not arm a revision while the visible effective date is invalid', async () => {
    const w = mounted()
    await fill(w)
    w.get<HTMLInputElement>('[data-field="rev-from"]').element.setCustomValidity('Invalid visible date')
    await w.get('[data-action="rev-review"]').trigger('click')
    expect(w.find('[data-section="rev-preview"]').exists()).toBe(false)
    expect(api.appendRevision).not.toHaveBeenCalled()
    w.unmount()
  })

  it.each(['cs', 'en'])('renders translated %s texts', async locale => {
    const w = mounted({}, locale)
    expect(w.text()).toContain((locale === 'cs' ? cs : en).nonprofitMembership.revisionTitle)
    expect(w.text()).not.toContain('nonprofitMembership.')
    w.unmount()
  })

  it('offers only activation as the first revision', () => {
    const w = mounted({ history: [] })
    expect(w.findAll('[data-field="rev-status"] option').map(o => o.attributes('value'))).toEqual(['active'])
    w.unmount()
  })

  it('writes nothing until reviewed, acknowledged and confirmed, then sends the evidenced revision once', async () => {
    const w = mounted()
    await fill(w)
    expect(api.appendRevision).not.toHaveBeenCalled()
    await review(w)
    expect(api.appendRevision).not.toHaveBeenCalled()
    expect(w.get('[data-action="confirm"]').attributes('disabled')).toBeDefined()
    await w.get('[data-field="ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.appendRevision).toHaveBeenCalledTimes(1)
    expect(api.appendRevision.mock.calls[0]).toEqual([5, 9, body])
    expect(w.emitted('done')).toHaveLength(1)
    w.unmount()
  })

  it('requires an effective date after the last revision', async () => {
    const w = mounted({ history: [revision(1, '2026-01-01')] })
    await fill(w, '2026-01-01')
    expect(w.get('[data-action="rev-review"]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-section="rev-invalid"]').text()).toContain(formatDate('2026-01-01'))
    await w.get('[data-field="rev-from"]').setValue('2026-01-02')
    expect(w.get('[data-action="rev-review"]').attributes('disabled')).toBeUndefined()
    w.unmount()
  })

  it('invalidates the review when evidence changes and never reuses the old token', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    await w.get('[data-field="rev-evidence"]').setValue('Different minutes')
    expect(w.find('[data-section="rev-preview"]').exists()).toBe(false)
    await review(w)
    await w.get('[data-field="ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.appendRevision.mock.calls[0]![2].legal_basis.evidence).toBe('Different minutes')
    w.unmount()
  })

  it('retries an uncertain outcome with the identical body and idempotency key', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    await w.get('[data-field="ack"]').setValue(true)
    api.appendRevision.mockRejectedValueOnce(new Error('network')).mockResolvedValueOnce({ id: 2 })
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-section="uncertain"]').exists()).toBe(true)
    expect(w.get('fieldset').attributes('disabled')).toBeDefined()
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.appendRevision).toHaveBeenCalledTimes(2)
    expect(api.appendRevision.mock.calls[1]).toEqual(api.appendRevision.mock.calls[0])
    w.unmount()
  })

  it('shows a definite server refusal and does not retry it', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    await w.get('[data-field="ack"]').setValue(true)
    api.appendRevision.mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'nonprofit_membership_revision_changed', message: 'Synthetic history changed' } } } })
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.appendRevision).toHaveBeenCalledTimes(1)
    expect(w.find('[data-section="uncertain"]').exists()).toBe(false)
    expect(w.get('[role="alert"]').text()).toContain('Synthetic history changed')
    w.unmount()
  })

  it('discards the review when the supplier changes', async () => {
    const w = mounted()
    await fill(w)
    await review(w)
    await w.setProps({ supplier: 6 })
    expect(w.find('[data-section="rev-preview"]').exists()).toBe(false)
    expect(api.appendRevision).not.toHaveBeenCalled()
    w.unmount()
  })

  it('renders no mutating action without write rights', () => {
    const w = mounted({ canManage: false })
    expect(w.find('[data-action="rev-review"]').exists()).toBe(false)
    w.unmount()
  })

  it('shows the previewed effective date in the app date format', async () => {
    const w = mounted({ history: [revision(1, '2026-01-01')] })
    await fill(w, '2026-02-03')
    await w.get('[data-action="rev-review"]').trigger('click')
    await flushPromises()
    const preview = w.get('[data-section="rev-preview"]').text()
    expect(preview).toContain(formatDate('2026-02-03'))
    expect(preview).not.toContain('2026-02-03')
    w.unmount()
  })
})
