interface AccountIdentity {
  account_number: string | null
  bank_code: string | null
  iban: string | null
}

function canonical(account: AccountIdentity): string | null {
  const iban = (account.iban || '').replace(/\s/g, '').toUpperCase()
  const raw = (account.account_number || '').replace(/\s/g, '').toUpperCase()
  const czechIban = /^CZ\d{22}$/.test(iban) ? iban : /^CZ\d{22}$/.test(raw) ? raw : null
  const ibanIdentity = czechIban ? `${czechIban.slice(4, 8)}:${czechIban.slice(8)}` : null
  if (ibanIdentity && (!raw || raw === czechIban)) {
    return account.bank_code && account.bank_code !== czechIban!.slice(4, 8) ? null : ibanIdentity
  }
  const match = /^(?:(\d{1,6})-)?(\d{1,10})(?:\/(\d{4}))?$/.exec(raw)
  const bank = match?.[3] || account.bank_code
  if (!match || !bank || !/^\d{4}$/.test(bank)) return null
  if (match[3] && account.bank_code && match[3] !== account.bank_code) return null
  const domesticIdentity = `${bank}:${(match[1] || '').padStart(6, '0')}${match[2].padStart(10, '0')}`
  if (iban && !ibanIdentity) return null
  return ibanIdentity && ibanIdentity !== domesticIdentity ? null : domesticIdentity
}

export function sameBankConnectionAccount(first: AccountIdentity, second: AccountIdentity): boolean {
  const identity = canonical(first)
  return identity !== null && identity === canonical(second)
}
