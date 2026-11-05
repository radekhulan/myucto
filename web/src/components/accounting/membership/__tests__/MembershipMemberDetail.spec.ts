import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Detail from '../MembershipMemberDetail.vue'
import { formatDateRange } from '../membershipFormat'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import { i18n } from '@/i18n'
import type { ClaimRow, MemberDetail, MemberRevision } from '@/api/nonprofitMembership'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({ member: vi.fn(), claim: vi.fn() }))
vi.mock('@/api/nonprofitMembership', () => ({ membershipApi: api }))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: vi.fn() } }))
vi.mock('@/api/nonprofitDppo', () => ({ nonprofitDppoApi: { profile: vi.fn() } }))

const ActionBar = defineComponent({
  props: ['actions'],
  setup: p => () => h('div', (p.actions as Array<{ key: string; label: string; disabled?: boolean; show?: unknown; run: () => void }>)
    .filter(a => a.show !== false).map(a => h('button', { 'data-action': a.key, disabled: a.disabled, onClick: a.run }, a.label))),
})
const RevisionForm = defineComponent({ name: 'MembershipRevisionForm', props: ['supplier', 'canManage', 'member', 'history'], emits: ['done', 'close', 'lock'], setup: () => () => h('div', { 'data-stub': 'revision' }) })
const IssueFlow = defineComponent({ name: 'MembershipIssueFlow', props: ['supplier', 'canManage', 'member', 'history', 'periods'], emits: ['done', 'close', 'lock'], setup: () => () => h('div', { 'data-stub': 'issue' }) })
const ReverseFlow = defineComponent({ name: 'MembershipReverseFlow', props: ['supplier', 'canManage', 'member', 'claim', 'periods'], emits: ['done', 'close', 'lock'], setup: () => () => h('div', { 'data-stub': 'reverse' }) })

const revision = (no: number, from: string, status: MemberRevision['status'] = 'active'): MemberRevision => ({
  id: no, supplier_id: 5, member_id: 9, revision_no: no, previous_id: no > 1 ? no - 1 : null, effective_from: from, status,
  legal_basis: { organisation_form: 'association', relationship: 'nonreciprocal_membership', reference: `ref ${no}`, evidence: `evidence ${no}` },
  idempotency_key: `k${no}`, request_hash: 'a', parent_hash: 'b', record_hash: 'c', created_by: 1,
})
const claim = (id: number, cents: number, settlement: ClaimRow['settlement'], overrides: Partial<ClaimRow> = {}): ClaimRow => ({
  claim_id: id, case_id: 40 + id, operation_id: id, member_id: 9, client_id: 3, amount_cents: cents, reversal_claim_id: null,
  accounting_date: '2026-03-01', due_date: '2026-03-31', journal_entry_id: 100 + id, receivable_line_id: 200 + id, line_no: 1, account_id: 7,
  posted_at: '2026-03-01 10:00:00', reversed_by: null, title_key: `DUES-${id}`, period_from: '2026-01-01', period_to: '2026-12-31', settlement, ...overrides,
})
const unreconciled = { status: 'unreconciled', paid_cents: null, open_cents: null, pairing_id: null, evidence: 'no_native_pairing' } as const
const ambiguous = { status: 'ambiguous', paid_cents: null, open_cents: null, pairing_id: 4, evidence: 'pairing_not_exact_native_payment' } as const
const partial = { status: 'partially_settled', paid_cents: 50000, open_cents: 30000, pairing_id: 5, evidence: 'journal_line_pairing_bank_or_cash' } as const
const reversed = { status: 'reversed', paid_cents: null, open_cents: 0, pairing_id: null, evidence: 'membership_reversal_claim' } as const
const period = { id: 11, supplier_id: 5, fiscal_year: 2026, starts_on: '2000-01-01', ends_on: '2099-12-31', status: 'open' as const, closed_at: null, created_at: '' }

function detail(history: MemberRevision[], items: ClaimRow[], next: number | null = null): MemberDetail {
  return {
    member: { id: 9, supplier_id: 5, client_id: 3, member_number: 'M-1', created_by: 1 }, history,
    balance: { member_id: 9, page_active_claim_cents: 0, page_proven_open_cents: 0, page_unreconciled_count: 0, items, next_after_claim_id: next },
  }
}
function mounted(props: Record<string, unknown> = {}, locale = 'cs') {
  return mount(Detail, {
    props: { supplier: 5, canManage: true, memberId: 9, label: 'Synthetic member', periods: [period], ...props },
    global: {
      plugins: [createI18n({ legacy: false, locale, messages: { cs, en } })],
      stubs: { ActionBar, MembershipRevisionForm: RevisionForm, MembershipIssueFlow: IssueFlow, MembershipReverseFlow: ReverseFlow },
    },
  })
}
const mixed = [claim(31, 100000, unreconciled), claim(32, 80000, ambiguous), claim(33, 120050, partial), claim(34, 70000, reversed, { reversal_claim_id: 99 })]

beforeEach(() => {
  vi.clearAllMocks()
  api.member.mockResolvedValue(detail([revision(1, '2020-01-01')], mixed))
})

describe('Membership member detail', () => {
  it.each(['cs', 'en'])('renders translated %s texts', async locale => {
    const w = mounted({}, locale)
    await flushPromises()
    expect(w.text()).toContain((locale === 'cs' ? cs : en).nonprofitMembership.claims)
    expect(w.text()).not.toContain('nonprofitMembership.')
    w.unmount()
  })

  it('does not let a future revision replace today\'s status and marks it as not yet valid', async () => {
    api.member.mockResolvedValue(detail([revision(1, '2020-01-01', 'active'), revision(2, '2999-01-01', 'ended')], []))
    const w = mounted()
    await flushPromises()
    expect(w.get('[data-field="status-today"]').text()).toContain(cs.nonprofitMembership.status_active)
    expect(w.get('[data-field="status-today"]').text()).toContain(formatDate(appIsoDate()))
    expect(w.get('[data-field="status-today"]').text()).not.toContain(cs.nonprofitMembership.status_ended)
    expect(w.get('[data-field="status-upcoming"]').text()).toContain(formatDate('2999-01-01'))
    expect(w.get('[data-field="status-upcoming"]').text()).toContain(cs.nonprofitMembership.status_ended)
    const rows = w.findAll('[data-section="history-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain(cs.nonprofitMembership.validToday)
    expect(rows[1]!.text()).toContain(cs.nonprofitMembership.notYetValid)
    expect(rows[1]!.text()).toContain(formatDate('2999-01-01'))
    w.unmount()
  })

  it('says that no revision is in effect yet when all revisions start in the future', async () => {
    api.member.mockResolvedValue(detail([revision(1, '2999-01-01')], []))
    const w = mounted()
    await flushPromises()
    expect(w.get('[data-field="status-today"]').classes()).toContain('text-warning-700')
    expect(w.get('[data-field="status-today"]').text()).not.toContain(cs.nonprofitMembership.status_active)
    w.unmount()
  })

  it('shows ambiguous and unreconciled payments as unproven and never mixes the issued total with a debt', async () => {
    const w = mounted()
    await flushPromises()
    const rows = w.findAll('[data-section="claim-row"]')
    expect(rows).toHaveLength(4)
    expect(rows[0]!.get('[data-field="settlement-status"]').text()).toBe(cs.nonprofitMembership.settlement_unreconciled)
    expect(rows[0]!.find('[data-field="settlement-amounts"]').exists()).toBe(false)
    expect(rows[0]!.find('[data-field="settlement-unknown"]').exists()).toBe(true)
    expect(rows[1]!.get('[data-field="settlement-status"]').text()).toBe(cs.nonprofitMembership.settlement_ambiguous)
    expect(rows[1]!.find('[data-field="settlement-amounts"]').exists()).toBe(false)
    expect(rows[2]!.get('[data-field="settlement-amounts"]').text()).toContain(formatMoneyMinor(50000))
    expect(rows[2]!.get('[data-field="settlement-amounts"]').text()).toContain(formatMoneyMinor(30000))
    expect(rows[3]!.get('[data-field="settlement-status"]').text()).toBe(cs.nonprofitMembership.settlement_reversed)
    expect(w.get('[data-field="sum-issued"]').text()).toBe(formatMoneyMinor(100000 + 80000 + 120050))
    expect(w.get('[data-field="sum-proven-open"]').text()).toBe(formatMoneyMinor(30000))
    expect(w.get('[data-field="sum-unproven"]').text()).toBe('2')
    expect(w.get('[data-section="unproven-note"]').text()).toContain(cs.nonprofitMembership.unprovenNote)
    expect(w.get('[data-section="claims-summary"]').text()).not.toMatch(/neuhrazen|dluh:/i)
    w.unmount()
  })

  it('pages claims by cursor and appends them', async () => {
    api.member.mockResolvedValueOnce(detail([revision(1, '2020-01-01')], [claim(31, 100000, unreconciled)], 31))
    api.member.mockResolvedValueOnce(detail([revision(1, '2020-01-01')], [claim(32, 80000, ambiguous)], null))
    const w = mounted()
    await flushPromises()
    expect(w.find('[data-section="partial-note"]').exists()).toBe(true)
    expect(api.member.mock.calls[0]).toEqual([5, 9, 0, 50])
    await w.get('[data-action="more-claims"]').trigger('click')
    await flushPromises()
    expect(api.member.mock.calls[1]).toEqual([5, 9, 31, 50])
    expect(w.findAll('[data-section="claim-row"]')).toHaveLength(2)
    expect(w.find('[data-action="more-claims"]').exists()).toBe(false)
    expect(w.find('[data-section="partial-note"]').exists()).toBe(false)
    w.unmount()
  })

  it('hides every mutation without write rights', async () => {
    const w = mounted({ canManage: false })
    await flushPromises()
    expect(w.find('[data-action="new-charge"]').exists()).toBe(false)
    expect(w.find('[data-action="reverse-claim"]').exists()).toBe(false)
    expect(w.find('[data-stub="revision"]').exists()).toBe(false)
    expect(w.find('[data-stub="issue"]').exists()).toBe(false)
    expect(w.find('[data-stub="reverse"]').exists()).toBe(false)
    expect(w.findAll('[data-section="claim-row"]')).toHaveLength(4)
    w.unmount()
  })

  it('reverses the chosen existing claim and offers no reversal for a reversed one', async () => {
    const w = mounted()
    await flushPromises()
    expect(w.find('[data-claim="34"] [data-action="reverse-claim"]').exists()).toBe(false)
    await w.get('[data-claim="32"] [data-action="reverse-claim"]').trigger('click')
    const flow = w.findComponent(ReverseFlow)
    expect(flow.exists()).toBe(true)
    expect(flow.props('claim')).toMatchObject({ claim_id: 32, case_id: 72 })
    expect(flow.props('member')).toEqual({ id: 9, member_number: 'M-1', label: 'Synthetic member' })
    w.unmount()
  })

  it('locks the other actions while a flow reports an uncertain or pending write', async () => {
    const w = mounted()
    await flushPromises()
    await w.get('[data-action="new-charge"]').trigger('click')
    const issue = w.findComponent(IssueFlow)
    expect(issue.props('history')).toHaveLength(1)
    expect(issue.props('periods')).toHaveLength(1)
    issue.vm.$emit('lock', true)
    await flushPromises()
    expect(w.get('[data-claim="31"] [data-action="reverse-claim"]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-action="new-charge"]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-action="close-detail"]').attributes('disabled')).toBeDefined()
    issue.vm.$emit('lock', false)
    await flushPromises()
    expect(w.get('[data-claim="31"] [data-action="reverse-claim"]').attributes('disabled')).toBeUndefined()
    w.unmount()
  })

  it('reloads and tells the parent after a finished operation', async () => {
    const w = mounted()
    await flushPromises()
    await w.get('[data-action="new-charge"]').trigger('click')
    w.findComponent(IssueFlow).vm.$emit('done')
    await flushPromises()
    expect(api.member).toHaveBeenCalledTimes(2)
    expect(w.emitted('changed')).toHaveLength(1)
    expect(w.find('[data-stub="issue"]').exists()).toBe(false)
    expect(w.get('[data-section="detail-notice"]').text()).toBe(cs.nonprofitMembership.issueDone)
    w.unmount()
  })

  it('refreshes a single claim settlement from the claim endpoint', async () => {
    api.claim.mockResolvedValue(claim(31, 100000, { status: 'settled', paid_cents: 100000, open_cents: 0, pairing_id: 8, evidence: 'journal_line_pairing_bank_or_cash' }))
    const w = mounted()
    await flushPromises()
    await w.get('[data-claim="31"] [data-action="refresh-claim"]').trigger('click')
    await flushPromises()
    expect(api.claim).toHaveBeenCalledWith(5, 31)
    expect(w.get('[data-claim="31"] [data-field="settlement-status"]').text()).toBe(cs.nonprofitMembership.settlement_settled)
    w.unmount()
  })

  it('discards a detail response that arrives after the supplier changed', async () => {
    let resolve!: (value: MemberDetail) => void
    api.member.mockImplementationOnce(() => new Promise<MemberDetail>(r => { resolve = r }))
    const w = mounted()
    await w.setProps({ supplier: 6 })
    resolve(detail([revision(1, '2020-01-01')], mixed))
    await flushPromises()
    expect(w.findAll('[data-section="claim-row"]')).toHaveLength(0)
    expect(w.find('[data-section="member-status"]').exists()).toBe(false)
    w.unmount()
  })

  it('shows the load error without any claims', async () => {
    api.member.mockRejectedValue({ response: { status: 404, data: { error: { code: 'nonprofit_membership_member_not_found', message: 'Synthetic missing member' } } } })
    const w = mounted()
    await flushPromises()
    expect(w.get('[data-section="detail-error"]').text()).toContain('Synthetic missing member')
    expect(w.findAll('[data-section="claim-row"]')).toHaveLength(0)
    w.unmount()
  })

  it('formats claim dates and amounts like the rest of the app and separates the open amount', async () => {
    const w = mounted()
    await flushPromises()
    const row = w.findAll('[data-section="claim-row"]')[2]!
    expect(row.get('[data-field="claim-period"]').text()).toBe(formatDateRange('2026-01-01', '2026-12-31'))
    expect(row.get('[data-field="claim-amounts"]').text()).toContain(formatDate('2026-03-31'))
    expect(row.text()).not.toMatch(/\d{4}-\d{2}-\d{2}/)
    expect(row.get('[data-field="settlement-amounts"]').text()).toContain(`${cs.nonprofitMembership.openAmount}: ${formatMoneyMinor(30000)}`)
    expect(row.get('[data-field="settlement-status"]').classes()).toContain('rounded-full')
    w.unmount()
  })

  it('shows a loading state and no orphan separator in the heading before the member arrives', async () => {
    api.member.mockReturnValue(new Promise(() => {}))
    const w = mounted()
    await flushPromises()
    expect(w.find('[data-section="detail-loading"]').exists()).toBe(true)
    expect(w.get('[data-focus="member-heading"]').text()).toBe('Synthetic member')
    w.unmount()
  })

  it('formats amounts with the app currency suffix in English too', async () => {
    const previous = i18n.global.locale.value
    i18n.global.locale.value = 'en'
    try {
      const w = mounted({}, 'en')
      await flushPromises()
      const issued = w.get('[data-field="sum-issued"]').text()
      expect(issued).toBe('3,000.50 Kč')
      expect(w.get('[data-section="claims"]').text()).not.toMatch(/CZK\s*\d/)
      w.unmount()
    } finally {
      i18n.global.locale.value = previous
    }
  })

  it('keeps the revision form behind the change status action once the member has a history', async () => {
    const w = mounted()
    await flushPromises()
    expect(w.find('[data-stub="revision"]').exists()).toBe(false)
    await w.get('[data-action="change-status"]').trigger('click')
    expect(w.find('[data-stub="revision"]').exists()).toBe(true)
    expect(w.find('[data-action="change-status"]').exists()).toBe(false)
    w.findComponent(RevisionForm).vm.$emit('close')
    await flushPromises()
    expect(w.find('[data-stub="revision"]').exists()).toBe(false)
    w.unmount()
  })

  it('opens the revision form right away for a member without any revision', async () => {
    api.member.mockResolvedValue(detail([], []))
    const w = mounted()
    await flushPromises()
    expect(w.find('[data-stub="revision"]').exists()).toBe(true)
    expect(w.find('[data-action="change-status"]').exists()).toBe(false)
    w.unmount()
  })

  it('names the contact without its internal id', async () => {
    const w = mounted()
    await flushPromises()
    expect(w.get('[data-section="member-status"]').text()).toContain(cs.nonprofitMembership.clientRef.replace('{client}', 'Synthetic member'))
    expect(w.get('[data-section="member-status"]').text()).not.toContain('č. 3')
    w.unmount()
  })

  it('tells the user the revision was recorded although the form folds away', async () => {
    const w = mounted()
    await flushPromises()
    await w.get('[data-action="change-status"]').trigger('click')
    w.findComponent(RevisionForm).vm.$emit('done')
    await flushPromises()
    expect(w.find('[data-stub="revision"]').exists()).toBe(false)
    expect(w.get('[data-section="detail-notice"]').text()).toBe(cs.nonprofitMembership.revisionDone)
    expect(w.emitted('changed')).toHaveLength(1)
    w.unmount()
  })

  it('keeps the revision form open while its write is pending or uncertain', async () => {
    const w = mounted()
    await flushPromises()
    await w.get('[data-action="change-status"]').trigger('click')
    w.findComponent(RevisionForm).vm.$emit('lock', true)
    w.findComponent(RevisionForm).vm.$emit('close')
    await flushPromises()
    expect(w.find('[data-stub="revision"]').exists()).toBe(true)
    w.unmount()
  })
})
