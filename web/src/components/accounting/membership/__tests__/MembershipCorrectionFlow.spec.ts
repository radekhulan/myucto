import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Flow from '../MembershipCorrectionFlow.vue'
import type { MemberRevision } from '@/api/nonprofitMembership'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({ updateMember: vi.fn(), deleteMember: vi.fn(), voidRevision: vi.fn() }))
vi.mock('@/api/nonprofitMembership', () => ({ membershipApi: api }))

const ActionBar = defineComponent({
  props: ['actions'],
  setup: p => () => h('div', (p.actions as Array<{ key: string; label: string; disabled?: boolean; show?: unknown; run: () => void }>)
    .filter(a => a.show !== false).map(a => h('button', { 'data-action': a.key, disabled: a.disabled, onClick: a.run }, a.label))),
})
const ClientSearchSelect = defineComponent({
  props: ['modelValue', 'selectedLabel'], emits: ['update:modelValue', 'selected'],
  setup: (_p, { emit }) => () => h('button', { 'data-stub': 'client', onClick: () => { emit('update:modelValue', 44); emit('selected', { id: 44, company_name: 'Synthetic other contact' }) } }),
})
const member = { id: 9, supplier_id: 5, client_id: 3, member_number: 'M-1', created_by: 1 }
const revision: MemberRevision = {
  id: 7, supplier_id: 5, member_id: 9, revision_no: 2, previous_id: 6, effective_from: '2026-07-01', status: 'suspended',
  legal_basis: { organisation_form: 'association', relationship: 'nonreciprocal_membership', reference: 'r', evidence: 'e' },
  idempotency_key: 'k', request_hash: 'a', parent_hash: 'b', record_hash: 'c', created_by: 1,
}

function mounted(props: { mode: 'identity' | 'delete' | 'void' } & Record<string, unknown>, locale = 'cs') {
  return mount(Flow, {
    props: { supplier: 5, canManage: true, member, label: 'Synthetic member', headRevisionNo: 2, revision: null, ...props },
    global: { plugins: [createI18n({ legacy: false, locale, messages: { cs, en } })], stubs: { ActionBar, ClientSearchSelect } },
  })
}
async function confirm(w: ReturnType<typeof mounted>) {
  await w.get('[data-action="corr-review"]').trigger('click')
  expect(w.find('[data-section="corr-review"]').exists()).toBe(true)
  await w.get('[data-field="ack"]').setValue(true)
  await w.get('[data-action="confirm"]').trigger('click')
  await flushPromises()
}

beforeEach(() => {
  vi.clearAllMocks()
  api.updateMember.mockResolvedValue({ ...member, member_number: 'M-2' })
  api.deleteMember.mockResolvedValue({ deleted: true, member_id: 9 })
  api.voidRevision.mockResolvedValue({ id: 8 })
})

describe('Membership correction flow', () => {
  it.each(['cs', 'en'])('renders translated %s texts for every mode', locale => {
    for (const mode of ['identity', 'delete', 'void'] as const) {
      const w = mounted({ mode, revision }, locale)
      const messages = (locale === 'cs' ? cs : en).nonprofitMembership.correction as Record<string, string>
      expect(w.text()).toContain(messages[`title_${mode}`])
      w.unmount()
    }
  })

  it('sends the corrected identity with the values it was opened with', async () => {
    const w = mounted({ mode: 'identity' })
    expect(w.get('[data-action="corr-review"]').attributes('disabled')).toBeDefined()
    await w.get('[data-stub="client"]').trigger('click')
    await w.get('[data-field="corr-number"]').setValue(' M-2 ')
    await confirm(w)
    expect(api.updateMember).toHaveBeenCalledWith(5, 9, { client_id: 44, member_number: 'M-2', expected_client_id: 3, expected_member_number: 'M-1' })
    expect(w.emitted('done')?.[0]).toEqual(['identity', 'Synthetic other contact'])
    w.unmount()
  })

  it('requires a reason and voids the given revision against the chain head', async () => {
    const w = mounted({ mode: 'void', revision })
    expect(w.get('[data-action="corr-review"]').attributes('disabled')).toBeDefined()
    await w.get('[data-field="corr-reason"]').setValue('Synthetic wrong status')
    await confirm(w)
    expect(api.voidRevision).toHaveBeenCalledWith(5, 9, 7, { expected_revision: 2, reason: 'Synthetic wrong status', idempotency_key: expect.stringMatching(/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,79}$/) })
    w.unmount()
  })

  it('deletes only after explicit acknowledgement with a danger confirmation', async () => {
    const w = mounted({ mode: 'delete' })
    await w.get('[data-action="corr-review"]').trigger('click')
    expect(w.get('[data-action="confirm"]').attributes('disabled')).toBeDefined()
    expect(api.deleteMember).not.toHaveBeenCalled()
    await w.get('[data-field="ack"]').setValue(true)
    await w.get('[data-action="confirm"]').trigger('click')
    await flushPromises()
    expect(api.deleteMember).toHaveBeenCalledTimes(1)
    expect(w.emitted('done')?.[0]?.[0]).toBe('delete')
    w.unmount()
  })

  it('treats a missing member on a repeated deletion as already deleted', async () => {
    api.deleteMember.mockRejectedValue({ response: { status: 404, data: { error: { code: 'nonprofit_membership_member_not_found' } } } })
    const w = mounted({ mode: 'delete' })
    await confirm(w)
    expect(w.emitted('done')?.[0]?.[0]).toBe('delete')
    expect(w.find('[data-section="corr-error"]').exists()).toBe(false)
    w.unmount()
  })

  it('shows the translated server reason when the correction is rejected', async () => {
    api.deleteMember.mockRejectedValue({ response: { status: 409, data: { error: { code: 'nonprofit_membership_member_in_batch' } } } })
    const w = mounted({ mode: 'delete' })
    await confirm(w)
    expect(w.get('[data-section="corr-error"]').text()).toBe(cs.nonprofitMembership.serverErrors.nonprofit_membership_member_in_batch)
    expect(w.emitted('done')).toBeUndefined()
    w.unmount()
  })
})
