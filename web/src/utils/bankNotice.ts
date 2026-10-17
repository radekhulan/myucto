import type { BankTransaction } from '@/api/bank'

/** Odstranění výpisu vrací avízo ke kontrole, ale neobnovuje jeho původní úhradu. */
export function isBankNoticeReadOnly(tx: Pick<BankTransaction, 'confirmation_state' | 'confirmation_reason'>): boolean {
  return tx.confirmation_state === 'confirmed' || tx.confirmation_reason === 'authoritative_removed'
}
