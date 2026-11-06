import type { SettlementEntitlement, SettlementClaimTitle } from '@/api/propertySettlements'
import { propertyBox, propertyInput } from '../propertyFormStyles'
import { chargeMoney } from '../charges/chargeMoney'
export const settlementMoney = chargeMoney
export function settlementCents(value: string): string {
 const normalized = value.trim().replace(',', '.')
 if (!/^(0|[1-9][0-9]{0,27})(\.[0-9]{1,2})?$/.test(normalized)) throw new Error('invalid_amount')
 const [whole, fraction = ''] = normalized.split('.')
 return (BigInt(whole!) * 100n + BigInt(fraction.padEnd(2, '0'))).toString()
}
export function settlementDecimal(value: string): string { const cents = BigInt(value); return `${cents / 100n}.${(cents % 100n).toString().padStart(2, '0')}` }
export const emptyEntitlement = (): SettlementEntitlement => ({ party_ids: [], evidence: '', due_date: '', account_code: '' })
export const emptyClaimTitle = (): SettlementClaimTitle => ({ effective_at: '', title_ref: '', liability_groups: [], legal: { basis: 'legal_act', legal_act_date: '', evidence: '', regime: 'general' }, security: { kind: 'unsecured', evidence: '' } })
export const settlementInput = propertyInput
export const settlementBox = propertyBox
