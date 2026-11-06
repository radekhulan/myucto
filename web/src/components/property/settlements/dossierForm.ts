import type { SettlementDossier, SettlementPersonChange, SettlementDelivery } from '@/api/propertySettlements'
export const blankDossier = (): SettlementDossier => ({ schema: 1, ref: '', issuer: { name: '' }, issued_at: '', allocation_ref: '', segments: [], deliveries: [], person_changes: [] })
export const blankPersonChange = (): SettlementPersonChange => ({ role: 'service_recipient', from_ids: [], to_ids: [], effective_at: '', basis: 'agreement', evidence: '' })
export const pendingDelivery = (segment: number, recipient: number): SettlementDelivery => ({ segment_ordinal: segment, recipient_id: recipient, issued_to_id: recipient, delivered_to_id: null, recipient_evidence: '', status: 'pending', delivered_at: null, method: null, delivery_evidence: '', due: { date: null, basis_ref: '' } })
export const dossierRecipients = (dossier: SettlementDossier): number[] => [...new Set(dossier.segments.flatMap(s => s.recipient_ids))].sort((a, b) => a - b)
export const dossierCopy = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T
