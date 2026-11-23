import { api } from './client'
import type { OtherItemMatchReason } from './otherItems'

/** Kandidát je konkrétní otevřená pohledávka, nikoli člen nebo sazba. */
export interface MembershipBankCandidate {
  id: number
  ref: string | null
  party: string | null
  member_id: number
  variable_symbol: string | null
  period_from: string
  period_to: string
  amount: number
  amount_cents: string
  currency: string
  /** Pořadí a důvody shody jako u ostatní položky (#275): VS, zbývající částka, účet protistrany. */
  match_score?: number
  match_reasons?: OtherItemMatchReason[]
}
export interface MembershipBankCandidatePage {
  candidates: MembershipBankCandidate[]
  /** Neprůhledná pozice další stránky (fáze a ID); null = žádná další. */
  next_cursor: string | null
}

const base = '/accounting/nonprofit-membership'
export const membershipBankApi = {
  candidates: (supplier: number, transaction: number, search = '', cursor = '0') =>
    api.get<MembershipBankCandidatePage>(`${base}/bank-candidates`, {
      headers: { 'X-Supplier-Id': String(supplier) },
      params: { transaction_id: transaction, search, cursor },
    }).then(r => r.data),
  match: (supplier: number, transaction: number, claimIds: number[]) =>
    api.post(`${base}/bank-match`, { transaction_id: transaction, claim_ids: claimIds }, {
      headers: { 'X-Supplier-Id': String(supplier) },
    }).then(r => r.data),
}
