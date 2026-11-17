import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Erasure from '../MembershipErasure.vue'
import type { MembershipErasureDetail, MembershipErasureItem, MembershipErasureProposal } from '@/api/nonprofitMembership'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const api = vi.hoisted(() => ({ assessment: vi.fn(), proposals: vi.fn(), proposal: vi.fn(), create: vi.fn(), approve: vi.fn(), revoke: vi.fn(), reject: vi.fn(), execute: vi.fn() }))
vi.mock('@/api/nonprofitMembership', () => ({ membershipErasureApi: api }))

const ActionBar = defineComponent({
  props: ['actions'],
  setup: p => () => h('div', (p.actions as Array<{ key: string; label: string; show?: unknown; disabled?: boolean; run: () => void }>)
    .filter(a => a.show === undefined || a.show).map(a => h('button', { 'data-action': a.key, disabled: a.disabled, onClick: a.run }, a.label))),
})
const plugins = (locale = 'cs') => [createI18n({ legacy: false, locale, messages: { cs, en } })]
const proposal = (over: Partial<MembershipErasureProposal> = {}): MembershipErasureProposal => ({
  id: 7, status: 'pending', as_of: '2032-01-02', note: null, row_version: 1, created_by: 3, created_at: '2032-01-02 08:00:00', approved_by: null, approved_at: null,
  executable_from: null, revoked_at: null, rejected_at: null, executed_at: null, item_count: 2, pending_count: 2, done_count: 0, skipped_count: 0, ...over,
})
const item = (over: Partial<MembershipErasureItem> = {}): MembershipErasureItem => ({
  id: 1, member_id: 41, subject: 'member', governing_rule: 'accounting_records', ended_on: '2026-06-30', last_record_period_end: '2026-12-31', retained_until: '2031-12-31',
  outcome: 'pending', skip_reason: null, counts: null, executed_at: null, member_number: 'SYN-41', company_name: 'Synthetic member', first_name: null, last_name: null, ...over,
})
const detail = (p: MembershipErasureProposal, items: MembershipErasureItem[]): MembershipErasureDetail => ({ proposal: p, items, next_after_member_id: null, confirmation: 'TRVALE SMAZAT', cooling_off_hours: 72 })
const mountIt = (canWrite = true, locale = 'cs') => mount(Erasure, { props: { supplier: 5, canWrite, open: true }, global: { plugins: plugins(locale), stubs: { ActionBar } } })

beforeEach(() => {
  vi.clearAllMocks()
  api.assessment.mockResolvedValue({ as_of: '2032-01-02', next_after: null, deleted_members: [], items: [
    { member_id: 41, subject: 'member', ended_on: '2026-06-30', last_record_period_end: '2026-12-31', retained_until: '2031-12-31', governing_rule: 'accounting_records', blocked_by: null, erasable: true },
    { member_id: 42, subject: 'member', ended_on: '2026-06-30', last_record_period_end: null, retained_until: '2027-06-30', governing_rule: 'membership_only', blocked_by: 'open_receivable', erasable: false },
  ] })
  api.proposals.mockResolvedValue({ items: [] })
})

describe('Membership erasure', () => {
  it('loads only when opened and summarises erasable and blocked members', async () => {
    const w = mount(Erasure, { props: { supplier: 5, canWrite: true, open: false }, global: { plugins: plugins(), stubs: { ActionBar } } })
    await flushPromises()
    expect(api.assessment).not.toHaveBeenCalled()
    await w.setProps({ open: true })
    await flushPromises()
    expect(w.get('[data-section="erasure-assessment"]').text()).toContain('1')
    expect(w.get('[data-field="erasure-blocked"]').text()).toContain(cs.nonprofitMembership.erasure.reason.open_receivable)
    expect(w.get('[data-action="erasure-create"]').attributes('disabled')).toBeUndefined()
    w.unmount()
  })

  it('creates a proposal and shows names only for items that were not executed', async () => {
    const created = proposal()
    api.create.mockResolvedValue({ proposal: created })
    api.proposal.mockResolvedValue(detail(created, [item(), item({ id: 2, member_id: 43, outcome: 'done', member_number: null, company_name: null })]))
    const w = mountIt()
    await flushPromises()
    api.proposals.mockResolvedValue({ items: [created] })
    await w.get('[data-action="erasure-create"]').trigger('click')
    await flushPromises()
    expect(api.create).toHaveBeenCalledWith(5, null)
    const rows = w.findAll('[data-field="erasure-item"]').map(r => r.text())
    expect(rows[0]).toContain('SYN-41 · Synthetic member')
    expect(rows[1]).toContain('Vymazaný člen č. 43')
    expect(rows[1]).not.toContain('SYN')
    w.unmount()
  })

  it('blocks a second proposal while one is open and hides write actions without permission', async () => {
    const open = proposal()
    api.proposals.mockResolvedValue({ items: [open] })
    api.proposal.mockResolvedValue(detail(open, [item()]))
    const w = mountIt()
    await flushPromises()
    expect(w.get('[data-action="erasure-create"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-action="erasure-approve"]').exists()).toBe(true)
    w.unmount()
    const r = mountIt(false)
    await flushPromises()
    expect(r.find('[data-action="erasure-create"]').exists()).toBe(false)
    expect(r.find('[data-action="erasure-approve"]').exists()).toBe(false)
    expect(r.find('[data-section="erasure-execute"]').exists()).toBe(false)
    r.unmount()
  })

  it('shows the cooling-off period and requires the exact phrase before executing', async () => {
    const future = new Date(Date.now() + 3600_000).toISOString().slice(0, 19).replace('T', ' ')
    const approved = proposal({ status: 'approved', approved_by: 3, executable_from: future })
    api.proposals.mockResolvedValue({ items: [approved] })
    api.proposal.mockResolvedValue(detail(approved, [item()]))
    const w = mountIt()
    await flushPromises()
    expect(w.find('[data-section="erasure-cooling"]').exists()).toBe(true)
    expect(w.find('[data-section="erasure-execute"]').exists()).toBe(false)
    expect(w.find('[data-action="erasure-revoke"]').exists()).toBe(true)
    w.unmount()

    const past = new Date(Date.now() - 3600_000).toISOString().slice(0, 19).replace('T', ' ')
    const ready = proposal({ status: 'approved', approved_by: 3, executable_from: past })
    api.proposals.mockResolvedValue({ items: [ready] })
    api.proposal.mockResolvedValue(detail(ready, [item()]))
    api.execute.mockResolvedValue({ proposal: { ...ready, status: 'approved' }, processed: 50, remaining: 12, summary: { done: 50, skipped_changed: 0, skipped_hold: 0 } })
    const x = mountIt()
    await flushPromises()
    const button = x.get('[data-action="erasure-execute"]')
    expect(button.attributes('disabled')).toBeDefined()
    await x.get('[data-field="erasure-confirmation"]').setValue('trvale smazat')
    expect(button.attributes('disabled')).toBeDefined()
    await x.get('[data-field="erasure-confirmation"]').setValue('TRVALE SMAZAT')
    expect(button.attributes('disabled')).toBeUndefined()
    await x.get('[data-section="erasure-execute"]').trigger('submit')
    await flushPromises()
    expect(api.execute).toHaveBeenCalledWith(5, 7, 'TRVALE SMAZAT')
    expect(x.get('[data-section="erasure-notice"]').text()).toContain('12')
    expect(x.emitted('erased')).toHaveLength(1)
    x.unmount()
  })

  it('translates skip reasons and server errors in English', async () => {
    const done = proposal({ status: 'executed' })
    api.proposals.mockResolvedValue({ items: [done] })
    api.proposal.mockResolvedValue(detail(done, [item({ outcome: 'skipped_changed', skip_reason: 'not_ended', member_number: null, company_name: null })]))
    const w = mountIt(true, 'en')
    await flushPromises()
    // Provedený návrh se neotevře sám, jen otevřený (ke schválení nebo schválený).
    expect(w.find('[data-section="erasure-detail"]').exists()).toBe(false)
    await w.get('[data-action="erasure-show"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-field="erasure-item"]').text()).toContain(en.nonprofitMembership.erasure.reason.not_ended)
    // Přeskočený člen není vymazaný; bez jména (uzavřený návrh) ukazuje jen číslo člena.
    expect(w.get('[data-field="erasure-item"]').text()).toContain('Member (ID 41)')
    w.unmount()
  })

  it('shows a translated server error when there is nothing to propose', async () => {
    api.create.mockRejectedValue({ isAxiosError: true, response: { status: 409, data: { error: { code: 'nonprofit_membership_erasure_nothing', message: 'x' } } } })
    const w = mountIt()
    await flushPromises()
    await w.get('[data-action="erasure-create"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-section="erasure-error"]').text()).toBe(cs.nonprofitMembership.serverErrors.nonprofit_membership_erasure_nothing)
    w.unmount()
  })
})
