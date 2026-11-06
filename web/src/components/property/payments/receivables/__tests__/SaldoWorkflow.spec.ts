import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/api/client'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import SaldoPanel from '../SaldoPanel.vue'
import TransferForm from '../TransferForm.vue'
import CertificatePanel from '../CertificatePanel.vue'
import LegalHistoryPanel from '../LegalHistoryPanel.vue'
import type { LegalContext, CertificateSnapshot, SaldoPage, LiabilityGroup } from '@/api/propertyReceivables'
const time = '2026-02-03T10:15:00+01:00'
const known = '2026-10-17T12:00:00+02:00'
const group: LiabilityGroup = { group_key: 'joint', role: 'debtor', mode: 'solidary', basis: 'documented_title', members: [11, 12], extent: { num: '1', den: '1' }, evidence_ref: 'Synthetic joint deed', legal_reason: 'Synthetic title', valid_from: time, valid_to: null, remaining_cap_cents: '15000' }
const totals = { gross_receivable_cents: '15000', gross_credit_cents: null, reserved_credit_cents: '2000', available_credit_cents: null, guarantee_exposure_cents: '0' }
const ctx: LegalContext = { as_of: time, known_at: known, unit: { id: 7, code: 'A7', kind: 'unit', parent_id: 1, current_revision: 2, dimension_value_id: 1, revision: { id: 41, revision: 2, state: { unit_no: '7', kind: 'flat', active: true, ownerships: [{ members: [11, 12], group_key: 'joint', kind: 'marital_community', share: { num: '1', den: '1' }, title_ref: 'Synthetic title' }], roles: [], identifiers: [], services: [], attributes: {} }, status: 'confirmed', effective_from: time, effective_to: null, recorded_at: known, reason: 'Synthetic', title_ref: 'Synthetic', resolves_revision_id: null, state_hash: 'a'.repeat(64) }, pending_revision_ids: [], as_of: time, history: [] }, projection: { as_of: time, known_at: known, totals, precision: 'instant', coverage: { complete: false, financial_complete: true }, claims: [{ claim_key: 'debt:3', debt_id: 3, title_ref: 'Synthetic joint debt', due_date: '2026-01-15', amount_cents: '15000', groups: [group], precision: 'instant', complete: true, liability: { head_event_id: 8, event_id: 8, complete: true, review_required: false } }] }, events: [], certificates: [{ id: 5, as_of: time, known_at: known, recorded_at: known, snapshot_hash: 'a'.repeat(64) }], parties: [{ id: 11, company_name: 'Owner A', first_name: null, last_name: null }, { id: 12, company_name: 'Owner B', first_name: null, last_name: null }], party_labels_scope: 'current_directory', capabilities: { manage: true, confirm: true, certificates: true } }
const certificate: CertificateSnapshot = { request: { unit_id: 7, as_of: time, known_at: known, requester_id: 11, authority_evidence: 'Synthetic authority', purpose: 'Synthetic historic certificate', coverage_acknowledgement: 'Recorded only' }, projection: structuredClone(ctx.projection), registry: structuredClone(ctx.unit), requester: structuredClone(ctx.parties[0]!), statement_scope: 'recorded_s3_s4', unconditional_no_debt: false }
const page = (id = 7, next: string | null = 'opaque-next'): SaldoPage => ({ kind: 'recorded_property_saldo_page', as_of: time, known_at: known, items: [{ unit_id: id, unit_code: `A${id}`, totals: { ...totals, principal_receivable_cents: '13000', accessory_receivable_cents: '2000' }, aging: { not_due: '0', '1_30': '15000', '31_90': null, '91_plus': '0' }, claim_count: 1, credit_count: 1, precision: 'day', uncertain_event_count: 1, liability_review_count: 1, coverage: { complete: false, financial_complete: false, unresolved_negative_charge_line_count: 1 } }], next_cursor: next, read_scope_digest: 'b'.repeat(64), proof_scope: { kind: 'selected_unit_financial_closure', unit_ids: [id], schema: 1 }, consistency: 'request_snapshot', coverage: { scope: 'recorded_s3_s4', complete: false } })
let calls: Array<{ url: string; params: Record<string, unknown>; body: any }>
let respond: (url: string, body: any) => any
const original = api.defaults.adapter
const globals = () => ({ plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })], stubs: { PropertyPartyPicker: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input data-party :value="modelValue" @input="$emit(\'update:modelValue\', Number($event.target.value))" />' } } })
function button(w: ReturnType<typeof mount>, text: string) { const b = w.findAll('button').find(b => b.text() === text); if (!b) throw new Error(`Missing ${text}`); return b }
beforeEach(() => { calls = []; respond = () => page(); api.defaults.adapter = async config => { const body = typeof config.data === 'string' ? JSON.parse(config.data) : config.data; calls.push({ url: config.url!, params: config.params ?? {}, body }); const data = await respond(config.url!, body); return { data, status: 200, statusText: 'OK', headers: {}, config } }; vi.spyOn(HTMLFormElement.prototype, 'reportValidity').mockReturnValue(true) })
afterEach(() => { api.defaults.adapter = original; vi.restoreAllMocks(); document.body.innerHTML = '' })
describe('bounded saldo with real HTTP serialization and locales', () => {
  it('keeps gross/null/day/coverage and replaces pages using the opaque cursor', async () => {
    const w = mount(SaldoPanel, { props: { supplier: 2, asOf: time, units: [] }, global: globals() }); await button(w, cs.propertySaldo.load).trigger('click'); await flushPromises()
    expect(w.get('[data-total="gross_receivable_cents"]').text()).toBe('150.00 CZK'); expect(w.get('[data-total="gross_credit_cents"]').text()).toBe(cs.propertyPayments.unknown)
    expect(w.find('[data-day]').exists()).toBe(true); expect(w.get('[data-coverage]').text()).toContain('nejde o úplnou finanční pozici')
    respond = () => page(8, null); await w.get('[data-action="saldo-next"]').trigger('click'); await flushPromises()
    expect(calls[1]!.params.cursor).toBe('opaque-next'); expect(calls[1]!.params.limit).toBe(25); expect(calls[1]!.params.as_of).toBe(time)
    expect(w.findAll('[data-saldo-unit]')).toHaveLength(1); expect(w.get('[data-saldo-unit]').text()).toContain('A8'); expect(w.get('[data-action="saldo-next"]').attributes('disabled')).toBeDefined(); w.unmount()
  })
  it('forwards normalized page times and selected unit to full detail without using page digest', async () => {
    const w = mount(SaldoPanel, { props: { supplier: 2, asOf: time, units: [] }, global: globals() }); await button(w, cs.propertySaldo.load).trigger('click'); await flushPromises(); await w.get('[data-detail="7"]').trigger('click')
    respond = () => ({ ...ctx.projection, claims: [], credits: [], snapshot_hash: 'c'.repeat(64) }); await button(w, cs.propertyPayments.card.load).trigger('click'); await flushPromises()
    expect(calls.at(-1)).toMatchObject({ url: '/property/payments/card', params: { unit_id: 7, as_of: time, known_at: known } }); expect(JSON.stringify(calls)).not.toContain('b'.repeat(64)); w.unmount()
  })
  it('drops a late page after tenant change and resets the cursor on page size change', async () => {
    let resolve!: (value: SaldoPage) => void; respond = () => new Promise(r => { resolve = r })
    const w = mount(SaldoPanel, { props: { supplier: 2, asOf: time, units: [] }, global: globals() }); await button(w, cs.propertySaldo.load).trigger('click'); await flushPromises(); await w.setProps({ supplier: 3 }); resolve(page()); await flushPromises(); expect(w.find('[data-saldo-unit]').exists()).toBe(false)
    respond = () => page(); await w.get('[data-field="saldo-limit"]').setValue('100'); await button(w, cs.propertySaldo.load).trigger('click'); await flushPromises(); expect(calls.at(-1)!.params, JSON.stringify(calls)).toMatchObject({ limit: 100 }); expect(calls.at(-1)!.params.cursor).toBeUndefined(); w.unmount()
  })
})
describe('S5 confirmed workflow', () => {
  async function previewTransfer() { const w = mount(TransferForm, { props: { supplier: 2, context: structuredClone(ctx) }, global: globals() }); await w.get('[data-select-debt="3"]').setValue(true); await w.get('[data-field="transfer-reason"]').setValue('Synthetic transfer assessment'); respond = (_url, request) => ({ preview_hash: 'd'.repeat(64), snapshot: { request, parties: ctx.parties, debts: [{ debt_id: 3, remaining_cents: '15000', groups: request.debts[0].groups, previous_liability: { groups: [group] } }] } }); await button(w, cs.propertySaldo.preview).trigger('click'); await flushPromises(); return w }
  it('sends one debt with two persons, exact predecessor and registry revision; uses writer preview hash', async () => {
    const w = await previewTransfer(); expect(calls[0]!.body.debts).toHaveLength(1); expect(calls[0]!.body.debts[0]).toMatchObject({ debt_id: 3, expected_event_id: 8, groups: [{ members: [11, 12] }] }); expect(calls[0]!.body.debts[0].groups[0]).not.toHaveProperty('remaining_cap_cents'); expect(calls[0]!.body.registry_revision_id).toBe(41)
    const expected = structuredClone(calls[0]!.body); respond = () => ({ id: 9 }); await button(w, cs.propertySaldo.confirmTransfer).trigger('click'); await flushPromises(); expect(calls[1]!.body.request).toEqual(expected); expect(calls[1]!.body.preview_hash).toBe('d'.repeat(64)); expect(w.emitted('saved')).toHaveLength(1); w.unmount()
  })
  it('invalidates a reviewed transfer when evidence changes and clears a stale CAS preview', async () => {
    const w = await previewTransfer(); await w.get('[data-field="transfer-reason"]').setValue('Changed title'); expect(w.find('[data-section="transfer-preview"]').exists()).toBe(false)
    await button(w, cs.propertySaldo.preview).trigger('click'); await flushPromises(); respond = () => Promise.reject({ response: { status: 409, data: { error: { code: 'property_receivable_preview_stale', message: 'Synthetic stale' } } } }); await button(w, cs.propertySaldo.confirmTransfer).trigger('click'); await flushPromises(); expect(w.emitted('stale')).toHaveLength(1); expect(w.find('[data-section="transfer-preview"]').exists()).toBe(false); w.unmount()
  })
  it('renders immutable certificate data rather than recalculating from the current context', async () => {
    const w = mount(CertificatePanel, { props: { supplier: 2, context: structuredClone(ctx) }, global: globals() }); respond = () => ({ id: 5, recorded_at: known, snapshot_hash: 'e'.repeat(64), snapshot: certificate }); await w.get('[data-certificate="5"]').trigger('click'); await flushPromises()
    expect(calls[0]!.url).toBe('/property/receivables/certificates/5'); expect(w.findAll('[data-certificate-claim]')).toHaveLength(1); expect(w.get('[data-total="gross_receivable_cents"]').text()).toBe('150.00 CZK'); expect(w.get('[data-section="certificate-snapshot"]').text()).toContain(certificate.request.purpose); expect(w.text()).toContain('e'.repeat(64)); w.unmount()
  })
  it('loads the historical legal context with both instants and never multiplies a joint debt', async () => {
    respond = () => ctx; const w = mount(LegalHistoryPanel, { props: { supplier: 2, unitId: 7, asOf: time, knownAt: known }, global: globals() }); await flushPromises(); expect(calls[0]!.params).toEqual({ unit_id: 7, as_of: time, known_at: known }); expect(w.findAll('[data-legal-claim]')).toHaveLength(1); expect(w.get('[data-legal-claim]').text()).toContain('Owner A, Owner B'); w.unmount()
  })
  it('issues the reviewed certificate with exact historic inputs and invalidates edits', async () => {
    const w = mount(CertificatePanel, { props: { supplier: 2, context: structuredClone(ctx) }, global: globals() })
    await w.get('[data-party]').setValue('11')
    for (const key of ['authority_evidence', 'purpose', 'coverage_acknowledgement']) await w.get(`[data-field="${key}"]`).setValue(`Synthetic ${key}`)
    respond = () => ({ preview_hash: 'f'.repeat(64), snapshot: certificate })
    await button(w, cs.propertySaldo.preview).trigger('click'); await flushPromises(); expect(calls[0]!.body).toMatchObject({ unit_id: 7, as_of: time, known_at: known, requester_id: 11 })
    await w.get('[data-field="purpose"]').setValue('Changed evidence'); expect(button(w, cs.propertySaldo.issueCertificate).attributes('disabled')).toBeDefined()
    await button(w, cs.propertySaldo.preview).trigger('click'); await flushPromises(); const request = structuredClone(calls.at(-1)!.body)
    respond = () => ({ id: 6, recorded_at: known, snapshot_hash: 'f'.repeat(64), snapshot: certificate })
    await button(w, cs.propertySaldo.issueCertificate).trigger('click'); await flushPromises(); expect(calls.at(-1)!.body).toMatchObject({ request, preview_hash: 'f'.repeat(64) }); expect(w.text()).toContain('Potvrzení č. 6'); w.unmount()
  })

})
