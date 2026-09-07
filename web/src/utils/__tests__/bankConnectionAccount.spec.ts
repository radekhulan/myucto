import { describe, expect, it } from 'vitest'
import { sameBankConnectionAccount } from '../bankConnectionAccount'

describe('bank connection account identity', () => {
  const account = { account_number: '1000000005', bank_code: '0100', iban: null }
  it('normalizes leading zeroes and a zero prefix', () => {
    expect(sameBankConnectionAccount(account, { ...account, account_number: '000000-1000000005/0100' })).toBe(true)
  })
  it('does not confuse different banks or a nonzero prefix', () => {
    expect(sameBankConnectionAccount(account, { ...account, bank_code: '2010' })).toBe(false)
    expect(sameBankConnectionAccount(account, { ...account, account_number: '19-1000000005' })).toBe(false)
  })
  it('rejects empty and malformed identities', () => {
    expect(sameBankConnectionAccount({ ...account, account_number: null }, { ...account, account_number: null })).toBe(false)
    expect(sameBankConnectionAccount(account, { ...account, account_number: '100000000500000' })).toBe(false)
  })
})
