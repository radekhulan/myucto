import type { PaymentAllocationPart, PaymentComponent, PaymentFacts, PaymentRefundPart } from '@/api/propertyPayments'
import type { AboPaymentSymbols, AboRequest, AccessoryBinding, AccessoryCandidate, ChargeDetail, ComponentDisposition, CorrectionOptions, CorrectionRequest, Credit, DebtRegistration, Reservation, ReservationRequest, SetoffRequest } from '@/api/propertyPaymentOperations'
import { paymentCents } from '../paymentWorkflowForm'
import { FormError, isoDay } from './format'

export const COMPONENTS: PaymentComponent[] = ['determined_cost', 'default_interest', 'interest', 'principal']

export interface DistributionState { on: boolean; evidence: string; amounts: Record<number, string> }
export interface PaymentFactsState {
  payer_id: number | null; debtor_id: number | null; received_at: string
  third_basis: '' | 'debtor_consent' | 'guarantor' | 'security_provider'; third_evidence: string
  choice_on: boolean; choice_evidence: string; choice_order: number[]
  security_on: boolean; security_evidence: string; tier: Record<number, string>; fact_kind: Record<number, '' | 'secured' | 'unsecured'>; fact_evidence: Record<number, string>
  reminders: Array<{ key: string; debt_id: number; evidence: string; recipients: Array<number | null>; delivered_at: string }>
  principal: Record<number, DistributionState>
  accessory: Record<number, Partial<Record<PaymentComponent, DistributionState>>>
}
export interface AllocationPartState { key: string; amount: string; reason: string; debts: number[]; unit_id: number; facts: PaymentFactsState; selection: Record<string, string> }
export interface RefundPartState { key: string; amount: string; reason: string; reservation: Reservation | null; recipient_id: number | null; recipient_name: string; receipt_evidence: string; selection: Record<string, string> }

export function emptyFacts(): PaymentFactsState {
  return { payer_id: null, debtor_id: null, received_at: '', third_basis: '', third_evidence: '', choice_on: false, choice_evidence: '', choice_order: [], security_on: false, security_evidence: '', tier: {}, fact_kind: {}, fact_evidence: {}, reminders: [], principal: {}, accessory: {} }
}
export function emptyAllocation(): AllocationPartState {
  return { key: `part.${crypto.randomUUID()}`, amount: '', reason: '', debts: [], unit_id: 0, facts: emptyFacts(), selection: {} }
}
export function emptyRefund(): RefundPartState {
  return { key: `part.${crypto.randomUUID()}`, amount: '', reason: '', reservation: null, recipient_id: null, recipient_name: '', receipt_evidence: '', selection: {} }
}

const filled = (value: string, code: string) => { if (!value.trim()) throw new FormError(code); return value }
const distinct = (ids: Array<number | null>) => ids.every(id => id !== null && id > 0) && new Set(ids).size === ids.length
export function absCents(cents: string): string { const value = BigInt(cents); return (value < 0n ? -value : value).toString() }

export function clearingSelection(cells: Array<{ key: string }>, selection: Record<string, string>): { clearing_selection?: Array<{ key: string; amount_cents: string }> } {
  if (cells.length <= 1) return {}
  return { clearing_selection: cells.filter(c => selection[c.key]?.trim()).map(c => ({ key: c.key, amount_cents: paymentCents(selection[c.key]!) })) }
}

function distribution(state: DistributionState | undefined, code: string): { parts: Array<{ id: number; amount_cents: string }>; evidence: string } | null {
  if (!state?.on) return null
  const parts = Object.entries(state.amounts).filter(([, amount]) => amount.trim()).map(([id, amount]) => ({ id: Number(id), amount_cents: paymentCents(amount) }))
  if (!parts.length) throw new FormError(code)
  filled(state.evidence, code)
  return { parts, evidence: state.evidence }
}

export function buildPaymentFacts(state: PaymentFactsState, debts: number[], sourceDate: string | null): PaymentFacts {
  if (!state.payer_id || !state.debtor_id) throw new FormError('payer')
  if (!state.received_at) throw new FormError('receivedAt')
  if (sourceDate && isoDay(state.received_at) !== sourceDate) throw new FormError('receivedDate')
  const facts: PaymentFacts = { payer_id: state.payer_id, debtor_id: state.debtor_id, received_at: state.received_at }
  if (state.payer_id !== state.debtor_id) {
    if (!state.third_basis) throw new FormError('thirdParty')
    facts.third_party = { basis: state.third_basis, evidence: filled(state.third_evidence, 'thirdParty') }
  }
  if (state.choice_on) {
    if (state.choice_order.length !== debts.length || !debts.every(id => state.choice_order.includes(id))) throw new FormError('choice')
    facts.choice = { claim_ids: [...state.choice_order], evidence: filled(state.choice_evidence, 'choice') }
  }
  if (state.security_on) {
    const ranks = new Map<number, number[]>()
    const record: Record<number, { kind: 'secured' | 'unsecured'; evidence: string }> = {}
    for (const id of debts) {
      const rank = Number(state.tier[id])
      const kind = state.fact_kind[id]
      if (!/^[1-9][0-9]*$/.test(state.tier[id] ?? '') || !kind) throw new FormError('security')
      ranks.set(rank, [...(ranks.get(rank) ?? []), id])
      record[id] = { kind, evidence: filled(state.fact_evidence[id] ?? '', 'security') }
    }
    facts.security_comparison = { tiers: [...ranks.entries()].sort((a, b) => a[0] - b[0]).map(([, ids]) => ids), facts: record, evidence: filled(state.security_evidence, 'security') }
  }
  if (state.reminders.length) {
    facts.reminders = state.reminders.map(r => {
      if (!debts.includes(r.debt_id) || !r.delivered_at || !r.recipients.length || !distinct(r.recipients)) throw new FormError('reminder')
      return { debt_id: r.debt_id, evidence: filled(r.evidence, 'reminder'), recipient_ids: r.recipients as number[], delivered_at: r.delivered_at }
    })
  }
  const principal: NonNullable<PaymentFacts['principal_distribution']> = {}
  const accessory: NonNullable<PaymentFacts['accessory_distribution']> = {}
  for (const id of debts) {
    const lines = distribution(state.principal[id], 'principal')
    if (lines) principal[id] = { lines: lines.parts.map(p => ({ line_id: p.id, amount_cents: p.amount_cents })), evidence: lines.evidence }
    for (const component of COMPONENTS) {
      const parts = distribution(state.accessory[id]?.[component], 'accessory')
      if (parts) accessory[id] = { ...(accessory[id] ?? {}), [component]: parts }
    }
  }
  if (Object.keys(principal).length) facts.principal_distribution = principal
  if (Object.keys(accessory).length) facts.accessory_distribution = accessory
  return facts
}

export function buildAllocationPart(part: AllocationPartState, date: string, cells: Array<{ key: string }>, sourceDate: string | null): { key: string; request: PaymentAllocationPart } {
  if (!part.debts.length) throw new FormError('debts')
  return { key: part.key, request: { kind: 'allocation', amount_cents: paymentCents(part.amount), accounting_date: date, reason: filled(part.reason, 'reason'), debts: [...part.debts], payment: buildPaymentFacts(part.facts, part.debts, sourceDate), ...clearingSelection(cells, part.selection) } }
}

export function buildRefundPart(part: RefundPartState, source: { cashDocumentId: number | null }, date: string, cells: Array<{ key: string }>): { key: string; request: PaymentRefundPart } {
  const reservation = part.reservation
  if (!reservation) throw new FormError('reservation')
  const request: PaymentRefundPart = { kind: 'refund', amount_cents: paymentCents(part.amount), accounting_date: date, reason: filled(part.reason, 'reason'), reservation_id: reservation.id, expected_reservation_version: reservation.row_version, ...clearingSelection(cells, part.selection) }
  if (reservation.request.delivery_method === 'cash') {
    if (!part.recipient_id) throw new FormError('receipt')
    request.cash_receipt = { cash_document_id: source.cashDocumentId, recipient_id: part.recipient_id, recipient_name: filled(part.recipient_name, 'receipt'), evidence: filled(part.receipt_evidence, 'receipt') }
  }
  return { key: part.key, request }
}

export interface DebtRegistrationState {
  charge: ChargeDetail | null; lines: number[]; title_ref: string; due_date: string
  basis: 'statutory' | 'legal_act'; legal_act_date: string; legal_evidence: string; regime: 'general' | 'consumer_in_default'
  security_kind: '' | 'secured' | 'unsecured'; security_evidence: string; damages_for: number | null
  disposition_on: boolean; order: Record<PaymentComponent, string>; debtor_evidence: string; creditor_consent: string
  old_on: boolean; old_resolution: 'none_documented' | 'separately_evidenced'; old_evidence: string
}
export function emptyRegistration(): DebtRegistrationState {
  return { charge: null, lines: [], title_ref: '', due_date: '', basis: 'statutory', legal_act_date: '', legal_evidence: '', regime: 'general', security_kind: '', security_evidence: '', damages_for: null, disposition_on: false, order: { determined_cost: '', default_interest: '', interest: '', principal: '' }, debtor_evidence: '', creditor_consent: '', old_on: false, old_resolution: 'none_documented', old_evidence: '' }
}
export function buildDebtRegistration(state: DebtRegistrationState): DebtRegistration {
  if (!state.charge || !state.lines.length) throw new FormError('lines')
  if (!state.security_kind) throw new FormError('security')
  const legal: DebtRegistration['legal'] = { basis: state.basis, evidence: filled(state.legal_evidence, 'legal'), regime: state.regime }
  if (state.basis === 'legal_act') legal.legal_act_date = filled(state.legal_act_date, 'legal')
  if (state.disposition_on) {
    const ranks = COMPONENTS.map(c => state.order[c])
    if (!ranks.every(r => /^[1-4]$/.test(r)) || new Set(ranks).size !== 4) throw new FormError('order')
    const disposition: ComponentDisposition = { order: [...COMPONENTS].sort((a, b) => Number(state.order[a]) - Number(state.order[b])), debtor_evidence: filled(state.debtor_evidence, 'order') }
    if (state.creditor_consent.trim()) disposition.creditor_consent = state.creditor_consent
    if (state.old_on) disposition.old_interest_effect = { resolution: state.old_resolution, evidence: filled(state.old_evidence, 'order') }
    legal.component_disposition = disposition
  }
  return { charge_id: state.charge.id, expected_version: state.charge.row_version, principal_line_ids: [...state.lines], title_ref: filled(state.title_ref, 'title'), due_date: filled(state.due_date, 'title'), legal, security: { kind: state.security_kind, evidence: filled(state.security_evidence, 'security') }, ...(state.damages_for ? { damages_for: state.damages_for } : {}) }
}

export interface ReservationState { amount: string; disposition: string; delivery: 'bank' | 'cash'; account: string; recipient_id: number | null; recipient_evidence: string }
export function emptyReservation(): ReservationState { return { amount: '', disposition: '', delivery: 'bank', account: '', recipient_id: null, recipient_evidence: '' } }
export function buildReservation(state: ReservationState, credit: Credit): ReservationRequest {
  const request: ReservationRequest = { amount_cents: paymentCents(state.amount), party_ids: credit.parties.map(p => p.id), disposition: filled(state.disposition, 'disposition'), delivery_method: state.delivery }
  if (state.delivery === 'bank') request.recipient_account = filled(state.account, 'recipient')
  else {
    if (!state.recipient_id) throw new FormError('recipient')
    request.recipient_id = state.recipient_id
    request.recipient_evidence = filled(state.recipient_evidence, 'recipient')
  }
  return request
}

export interface SetoffState {
  amount: string; date: string; reason: string; debts: number[]; unit_id: number; facts: PaymentFactsState
  certain: boolean; unconditional: boolean; scope: string; mutuality: string; eligibility: string; declaration: string; delivery: string; delivered_at: string; effect: string; early: string; scope1984: string
}
export function emptySetoff(): SetoffState {
  return { amount: '', date: '', reason: '', debts: [], unit_id: 0, facts: emptyFacts(), certain: false, unconditional: false, scope: '', mutuality: '', eligibility: '', declaration: '', delivery: '', delivered_at: '', effect: '', early: '', scope1984: '' }
}
export function buildSetoff(state: SetoffState, credit: Credit): SetoffRequest {
  if (!state.debts.length) throw new FormError('debts')
  if (!state.certain || !state.unconditional) throw new FormError('setoffBooleans')
  if (!state.delivered_at) throw new FormError('setoffLegal')
  const legal: SetoffRequest['legal'] = { certain: true, unconditional: true, scope_cents: paymentCents(state.scope), mutuality_evidence: filled(state.mutuality, 'setoffLegal'), eligibility_evidence: filled(state.eligibility, 'setoffLegal'), declaration_evidence: filled(state.declaration, 'setoffLegal'), delivery_evidence: filled(state.delivery, 'setoffLegal'), delivered_at: state.delivered_at, effect_evidence: filled(state.effect, 'setoffLegal') }
  if (state.early.trim()) legal.early_performance_evidence = state.early
  if (state.scope1984.trim()) legal.scope_1984_evidence = state.scope1984
  return { kind: 'setoff', credit_id: credit.id, expected_credit_version: credit.row_version, amount_cents: paymentCents(state.amount), accounting_date: filled(state.date, 'date'), reason: filled(state.reason, 'reason'), debts: [...state.debts], payment: buildPaymentFacts(state.facts, state.debts, null), legal }
}

export interface CorrectionState {
  date: string; reason: string; anchors: Record<number, { original: number; amount: string; evidence: string }>; reliefs: Record<number, string>
  credit_on: boolean; credit_parties: number[]; credit_evidence: string; credit_due: string
}
export function emptyCorrection(): CorrectionState { return { date: '', reason: '', anchors: {}, reliefs: {}, credit_on: false, credit_parties: [], credit_evidence: '', credit_due: '' } }
export function buildCorrection(state: CorrectionState, options: CorrectionOptions): CorrectionRequest {
  const anchors = Object.entries(state.anchors).filter(([, a]) => a.original > 0).map(([line, a]) => ({ adjustment_line_id: Number(line), original_line_id: a.original, amount_cents: paymentCents(a.amount), evidence: filled(a.evidence, 'anchors') }))
  if (!anchors.length) throw new FormError('anchors')
  const reliefs = Object.entries(state.reliefs).filter(([, amount]) => amount.trim()).map(([id, amount]) => {
    const allocation = options.allocations.find(a => a.id === Number(id))
    const cents = paymentCents(amount)
    if (!allocation || BigInt(cents) > BigInt(allocation.available_relief_cents)) throw new FormError('reliefBound')
    return { allocation_id: allocation.id, amount_cents: cents }
  })
  const request: CorrectionRequest = { adjustment_charge_id: options.charge.id, expected_version: options.charge.row_version, accounting_date: filled(state.date, 'date'), reason: filled(state.reason, 'reason'), anchors, reliefs }
  if (state.credit_on) {
    if (!state.credit_parties.length || !state.credit_due) throw new FormError('creditEntitlement')
    request.credit_entitlement = { party_ids: [...state.credit_parties], evidence: filled(state.credit_evidence, 'creditEntitlement'), due_date: state.credit_due }
  }
  return request
}

export interface AboSymbolState { variable: string; constant: string; specific: string; message: string; withoutVariable: boolean }
export interface AboState { selected: number[]; bank_account_id: number; due_date: string; reason: string; symbols: Record<number, AboSymbolState> }
export function emptyAboSymbol(): AboSymbolState { return { variable: '', constant: '', specific: '', message: '', withoutVariable: false } }
const symbolPattern = /^[0-9]{1,10}$/
export function buildAboSymbols(state: AboSymbolState | undefined): AboPaymentSymbols {
  if (!state) throw new FormError('aboSymbols')
  const symbols: AboPaymentSymbols = {}
  const variable = state.variable.trim()
  if (state.withoutVariable) {
    if (variable) throw new FormError('aboSymbols')
    symbols.allow_missing_variable_symbol = true
  } else {
    if (!symbolPattern.test(variable)) throw new FormError('aboSymbols')
    symbols.variable_symbol = variable
  }
  for (const [key, value] of [['constant_symbol', state.constant], ['specific_symbol', state.specific]] as const) {
    if (value.trim()) { if (!symbolPattern.test(value.trim())) throw new FormError('aboSymbols'); symbols[key] = value.trim() }
  }
  if (state.message.trim()) symbols.message = state.message.trim()
  return symbols
}
export function buildAbo(state: AboState, reservations: Reservation[]): AboRequest {
  const chosen = state.selected.map(id => reservations.find(r => r.id === id))
  if (!chosen.length || chosen.some(r => !r || r.status !== 'reserved' || r.request.delivery_method !== 'bank') || new Set(state.selected).size !== state.selected.length || !state.bank_account_id) throw new FormError('abo')
  return {
    reservation_ids: chosen.map(r => r!.id),
    expected_versions: Object.fromEntries(chosen.map(r => [r!.id, r!.row_version])),
    payment_symbols: Object.fromEntries(chosen.map(r => [r!.id, buildAboSymbols(state.symbols[r!.id])])),
    bank_account_id: state.bank_account_id, due_date: filled(state.due_date, 'abo'), reason: filled(state.reason, 'abo'),
  }
}

export type AccessoryComponent = AccessoryBinding['component']
export interface AccessoryState { candidate: AccessoryCandidate | null; component: '' | AccessoryComponent; oldEffect: boolean; evidence: string; liability: string }
export function emptyAccessory(): AccessoryState { return { candidate: null, component: '', oldEffect: false, evidence: '', liability: '' } }
export function buildAccessory(state: AccessoryState): AccessoryBinding {
  if (!state.candidate || !state.component) throw new FormError('accessoryBinding')
  if (state.oldEffect && state.component !== 'interest') throw new FormError('accessoryBinding')
  return { journal_entry_id: state.candidate.journal_entry_id, journal_line_id: state.candidate.id, component: state.component, evidence: filled(state.evidence, 'accessoryBinding'), liability_evidence: filled(state.liability, 'accessoryBinding'), ...(state.oldEffect ? { purpose: 'old_1932_interest_effect' as const } : {}) }
}
