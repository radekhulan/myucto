import { describe, it, expect } from 'vitest'
import type { ChargeDetail, CorrectionOptions, Credit, Reservation } from '@/api/propertyPaymentOperations'
import { buildAbo, buildAccessory, emptyAboSymbol, emptyAccessory, buildAllocationPart, buildCorrection, buildDebtRegistration, buildPaymentFacts, buildRefundPart, buildReservation, buildSetoff, emptyAllocation, emptyCorrection, emptyFacts, emptyRefund, emptyRegistration, emptyReservation, emptySetoff } from '../partForms'
import { FormError } from '../format'
import { createRetryToken } from '../idempotency'
import { base64ToBytes } from '../aboDownload'

const credit: Credit = { id: 5, row_version: 7, unit_id: 1, unit_label: 'Jednotka 1', amount_cents: '10000', balance_cents: '10000', reserved_cents: '0', available_cents: '10000', due_date: '2026-03-01', parties: [{ id: 21, label: 'Owner A' }, { id: 22, label: 'Owner B' }], operation_id: 3 }
const reservation = (delivery: 'bank' | 'cash', overrides: Partial<Reservation> = {}): Reservation => ({ id: 9, credit_id: 5, row_version: 4, status: 'reserved', amount_cents: '5000', paid_cents: '0', remaining_cents: '5000', unit_id: 1, unit_label: 'Jednotka 1', parties: [{ id: 21, label: 'Owner A' }], request: { amount_cents: '5000', party_ids: [21], disposition: 'refund', delivery_method: delivery, ...(delivery === 'bank' ? { recipient_account: '1000000005/0100' } : { recipient_id: 21, recipient_evidence: 'ID' }) }, ...overrides })

function facts(over: Partial<ReturnType<typeof emptyFacts>> = {}) {
  return { ...emptyFacts(), payer_id: 31, debtor_id: 31, received_at: '2026-02-01T10:00:00+01:00', ...over }
}

describe('payment facts', () => {
  it('keeps a third-party payment as one claim and requires its basis', () => {
    const third = facts({ debtor_id: 32, third_basis: 'guarantor', third_evidence: 'Synthetic guarantee' })
    const built = buildPaymentFacts(third, [4], '2026-02-01')
    expect(built).toMatchObject({ payer_id: 31, debtor_id: 32, third_party: { basis: 'guarantor', evidence: 'Synthetic guarantee' } })
    expect(() => buildPaymentFacts(facts({ debtor_id: 32 }), [4], '2026-02-01')).toThrow(FormError)
    expect(buildPaymentFacts(facts(), [4], '2026-02-01').third_party).toBeUndefined()
  })
  it('refuses a received date that differs from the actual source date', () => {
    expect(() => buildPaymentFacts(facts({ received_at: '2026-02-02T01:00:00+01:00' }), [4], '2026-02-01')).toThrow(FormError)
  })
  it('uses the accounting timezone for an ISO instant near midnight', () => {
    const receivedAt = '2026-06-01T22:30:00Z'
    expect(buildPaymentFacts(facts({ received_at: receivedAt }), [4], '2026-06-02').received_at).toBe(receivedAt)
  })
  it('rejects an ISO instant whose accounting day differs from the source date', () => {
    expect(() => buildPaymentFacts(facts({ received_at: '2026-06-02T23:30:00-02:00' }), [4], '2026-06-02')).toThrow(FormError)
  })
  it('rejects an invalid received instant', () => {
    expect(() => buildPaymentFacts(facts({ received_at: 'not-an-instant' }), [4], '2026-06-01')).toThrow(FormError)
  })
  it('never guesses security tiers or the debtor choice', () => {
    const built = buildPaymentFacts(facts(), [4, 5], '2026-02-01')
    expect(built.choice).toBeUndefined(); expect(built.security_comparison).toBeUndefined()
    expect(() => buildPaymentFacts(facts({ security_on: true, security_evidence: 'e', tier: { 4: '1' }, fact_kind: { 4: 'secured' }, fact_evidence: { 4: 'x' } }), [4, 5], null)).toThrow(FormError)
    const tiers = buildPaymentFacts(facts({ security_on: true, security_evidence: 'Comparison', tier: { 4: '2', 5: '1', 6: '2' }, fact_kind: { 4: 'secured', 5: 'unsecured', 6: 'secured' }, fact_evidence: { 4: 'a', 5: 'b', 6: 'c' } }), [4, 5, 6], null)
    expect(tiers.security_comparison?.tiers).toEqual([[5], [4, 6]])
    expect(tiers.security_comparison?.facts[5]).toEqual({ kind: 'unsecured', evidence: 'b' })
    expect(() => buildPaymentFacts(facts({ choice_on: true, choice_order: [5], choice_evidence: 'x' }), [4, 5], null)).toThrow(FormError)
    expect(buildPaymentFacts(facts({ choice_on: true, choice_order: [5, 4], choice_evidence: 'Debtor letter' }), [4, 5], null).choice).toEqual({ claim_ids: [5, 4], evidence: 'Debtor letter' })
  })
  it('converts monetary distributions exactly via BigInt', () => {
    const state = facts({ principal: { 4: { on: true, evidence: 'Split', amounts: { 11: '90071992547409.93' } } } })
    expect(buildPaymentFacts(state, [4], null).principal_distribution?.[4]?.lines).toEqual([{ line_id: 11, amount_cents: '9007199254740993' }])
  })
})

describe('part builders', () => {
  it('builds an allocation with explicit debts and payment facts', () => {
    const part = { ...emptyAllocation(), amount: '60', reason: 'Synthetic payment', debts: [4], facts: facts() }
    const built = buildAllocationPart(part, '2026-02-01', [{ key: 'unresolved' }], '2026-02-01')
    expect(built.request).toMatchObject({ kind: 'allocation', amount_cents: '6000', debts: [4], payment: { payer_id: 31 } })
    expect('clearing_selection' in built.request).toBe(false)
    expect(() => buildAllocationPart({ ...part, debts: [] }, '2026-02-01', [], null)).toThrow(FormError)
  })
  it('builds a partial cash refund with the actual receiving person', () => {
    const part = { ...emptyRefund(), amount: '30', reason: 'Partial refund', reservation: reservation('cash'), recipient_id: 21, recipient_name: 'Owner A', receipt_evidence: 'Signed receipt', selection: { a: '10', b: '20' } }
    const built = buildRefundPart(part, { cashDocumentId: null }, '2026-02-01', [{ key: 'a' }, { key: 'b' }])
    expect(built.request).toEqual({ kind: 'refund', amount_cents: '3000', accounting_date: '2026-02-01', reason: 'Partial refund', reservation_id: 9, expected_reservation_version: 4, cash_receipt: { cash_document_id: null, recipient_id: 21, recipient_name: 'Owner A', evidence: 'Signed receipt' }, clearing_selection: [{ key: 'a', amount_cents: '1000' }, { key: 'b', amount_cents: '2000' }] })
    expect(() => buildRefundPart({ ...part, receipt_evidence: '' }, { cashDocumentId: null }, '2026-02-01', [])).toThrow(FormError)
    const bank = buildRefundPart({ ...part, reservation: reservation('bank') }, { cashDocumentId: 12 }, '2026-02-01', [])
    expect(bank.request.cash_receipt).toBeUndefined()
    expect(buildRefundPart(part, { cashDocumentId: 12 }, '2026-02-01', []).request.cash_receipt?.cash_document_id).toBe(12)
  })
})

describe('reservation, setoff, correction, registration and ABO requests', () => {
  it('reserves a partial amount for the complete original entitlement group', () => {
    const request = buildReservation({ ...emptyReservation(), amount: '30', disposition: 'Refund', account: '1000000005/0100' }, credit)
    expect(request).toEqual({ amount_cents: '3000', party_ids: [21, 22], disposition: 'Refund', delivery_method: 'bank', recipient_account: '1000000005/0100' })
    const cash = buildReservation({ ...emptyReservation(), amount: '30', disposition: 'Refund', delivery: 'cash', recipient_id: 21, recipient_evidence: 'ID card', account: 'ignored' }, credit)
    expect(cash.recipient_account).toBeUndefined(); expect(cash).toMatchObject({ recipient_id: 21, recipient_evidence: 'ID card' })
  })
  it('requires both legal booleans checked before a setoff request exists', () => {
    const state = { ...emptySetoff(), amount: '10', date: '2026-03-01', reason: 'Setoff', debts: [4], facts: facts(), scope: '10', mutuality: 'm', eligibility: 'e', declaration: 'd', delivery: 'dl', delivered_at: '2026-03-01T09:00:00+01:00', effect: 'ef' }
    expect(state.certain).toBe(false); expect(state.unconditional).toBe(false)
    expect(() => buildSetoff(state, credit)).toThrow(FormError)
    expect(() => buildSetoff({ ...state, certain: true }, credit)).toThrow(FormError)
    const built = buildSetoff({ ...state, certain: true, unconditional: true }, credit)
    expect(built).toMatchObject({ kind: 'setoff', credit_id: 5, expected_credit_version: 7, amount_cents: '1000', legal: { certain: true, unconditional: true, scope_cents: '1000' } })
    expect(built.legal.early_performance_evidence).toBeUndefined()
  })
  it('links negative differences to original lines and bounds the relief', () => {
    const charge = { id: 8, row_version: 2 } as ChargeDetail
    const options: CorrectionOptions = { charge, original_lines: [{ id: 50, label: 'Original', amount_cents: '10000' }], allocations: [{ id: 70, original_line_id: 50, label: 'Paid', amount_cents: '10000', available_relief_cents: '2500' }], parties: [{ id: 21, label: 'Owner A' }] }
    const state = { ...emptyCorrection(), date: '2026-03-01', reason: 'Fix', anchors: { 61: { original: 50, amount: '25', evidence: 'Statement' }, 62: { original: 0, amount: '1', evidence: '' } }, reliefs: { 70: '25' } }
    const built = buildCorrection(state, options)
    expect(built).toEqual({ adjustment_charge_id: 8, expected_version: 2, accounting_date: '2026-03-01', reason: 'Fix', anchors: [{ adjustment_line_id: 61, original_line_id: 50, amount_cents: '2500', evidence: 'Statement' }], reliefs: [{ allocation_id: 70, amount_cents: '2500' }] })
    expect(() => buildCorrection({ ...state, reliefs: { 70: '25.01' } }, options)).toThrow(FormError)
    expect(() => buildCorrection({ ...state, anchors: {} }, options)).toThrow(FormError)
  })
  it('registers a real legal debt only from explicit principal lines and legal facts', () => {
    const charge = { id: 8, row_version: 2 } as ChargeDetail
    const state = { ...emptyRegistration(), charge, lines: [1], title_ref: 'Title', due_date: '2026-03-01', legal_evidence: 'Law', security_kind: 'unsecured' as const, security_evidence: 'None' }
    expect(buildDebtRegistration(state)).toEqual({ charge_id: 8, expected_version: 2, principal_line_ids: [1], title_ref: 'Title', due_date: '2026-03-01', legal: { basis: 'statutory', evidence: 'Law', regime: 'general' }, security: { kind: 'unsecured', evidence: 'None' } })
    expect(() => buildDebtRegistration({ ...state, basis: 'legal_act' })).toThrow(FormError)
    expect(() => buildDebtRegistration({ ...state, disposition_on: true })).toThrow(FormError)
    const ordered = buildDebtRegistration({ ...state, basis: 'legal_act', legal_act_date: '2026-01-05', disposition_on: true, order: { determined_cost: '1', default_interest: '2', interest: '3', principal: '4' }, debtor_evidence: 'Letter' })
    expect(ordered.legal.component_disposition?.order).toEqual(['determined_cost', 'default_interest', 'interest', 'principal'])
  })
  it('exports only reserved bank reservations with their current versions', () => {
    const rows = [reservation('bank'), reservation('bank', { id: 10, row_version: 8 }), reservation('cash', { id: 11 })]
    const symbols = { 9: { ...emptyAboSymbol(), variable: '2026001', constant: '308' }, 10: { ...emptyAboSymbol(), withoutVariable: true, message: ' Vratka ' } }
    expect(buildAbo({ selected: [9, 10], bank_account_id: 3, due_date: '2026-03-02', reason: 'Refunds', symbols }, rows)).toEqual({ reservation_ids: [9, 10], expected_versions: { 9: 4, 10: 8 }, payment_symbols: { 9: { variable_symbol: '2026001', constant_symbol: '308' }, 10: { allow_missing_variable_symbol: true, message: 'Vratka' } }, bank_account_id: 3, due_date: '2026-03-02', reason: 'Refunds' })
    expect(() => buildAbo({ selected: [11], bank_account_id: 3, due_date: '2026-03-02', reason: 'x', symbols }, rows)).toThrow(FormError)
  })
  it('never guesses a payment symbol and needs an explicit variable symbol or the without-VS choice per reservation', () => {
    const rows = [reservation('bank'), reservation('bank', { id: 10, row_version: 8 })]
    const base = { selected: [9, 10], bank_account_id: 3, due_date: '2026-03-02', reason: 'Refunds' }
    expect(() => buildAbo({ ...base, symbols: { 9: { ...emptyAboSymbol(), variable: '1' } } }, rows)).toThrow(FormError)
    expect(() => buildAbo({ ...base, symbols: { 9: { ...emptyAboSymbol(), variable: '1' }, 10: emptyAboSymbol() } }, rows)).toThrow(FormError)
    expect(() => buildAbo({ ...base, symbols: { 9: { ...emptyAboSymbol(), variable: '1' }, 10: { ...emptyAboSymbol(), variable: '2', withoutVariable: true } } }, rows)).toThrow(FormError)
    expect(() => buildAbo({ ...base, symbols: { 9: { ...emptyAboSymbol(), variable: '12345678901' }, 10: { ...emptyAboSymbol(), withoutVariable: true } } }, rows)).toThrow(FormError)
    expect(() => buildAbo({ ...base, symbols: { 9: { ...emptyAboSymbol(), variable: '1', constant: 'x' }, 10: { ...emptyAboSymbol(), withoutVariable: true } } }, rows)).toThrow(FormError)
    const built = buildAbo({ ...base, symbols: { 9: { ...emptyAboSymbol(), variable: '1' }, 10: { ...emptyAboSymbol(), withoutVariable: true }, 99: { ...emptyAboSymbol(), variable: '5' } } }, rows)
    expect(Object.keys(built.payment_symbols)).toEqual(['9', '10'])
  })
  it('binds an accessory only to a chosen real receivable with both pieces of evidence', () => {
    const candidate = { id: 41, journal_entry_id: 7, date: '2026-03-01', document_number: 'FV-1', label: 'Late fee', account_code: '311', amount_cents: '12345' }
    const state = { ...emptyAccessory(), candidate, component: 'default_interest' as const, evidence: 'Contract art. 4', liability: 'Both owners jointly' }
    expect(buildAccessory(state)).toEqual({ journal_entry_id: 7, journal_line_id: 41, component: 'default_interest', evidence: 'Contract art. 4', liability_evidence: 'Both owners jointly' })
    expect(buildAccessory({ ...state, component: 'interest', oldEffect: true }).purpose).toBe('old_1932_interest_effect')
    expect(() => buildAccessory({ ...state, oldEffect: true })).toThrow(FormError)
    expect(() => buildAccessory({ ...state, liability: ' ' })).toThrow(FormError)
    expect(() => buildAccessory({ ...state, candidate: null })).toThrow(FormError)
    expect(() => buildAccessory({ ...state, component: '' })).toThrow(FormError)
  })
})

describe('exact retry token', () => {
  it('keeps the token for the same payload and replaces it after an edit or success', () => {
    const retry = createRetryToken()
    const first = retry.for({ a: 1 })
    expect(retry.for({ a: 1 })).toBe(first)
    expect(retry.for({ a: 2 })).not.toBe(first)
    const second = retry.for({ a: 2 }); retry.reset()
    expect(retry.for({ a: 2 })).not.toBe(second)
  })
})

describe('ABO bytes', () => {
  it('decodes content_base64 to the exact bytes without any Unicode re-encoding', () => {
    const bytes = new Uint8Array([0x41, 0xe8, 0xf8, 0x9e, 0x00, 0xff])
    expect([...base64ToBytes(btoa(String.fromCharCode(...bytes)))]).toEqual([...bytes])
  })
})
