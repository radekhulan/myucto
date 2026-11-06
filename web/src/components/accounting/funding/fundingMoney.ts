export function fundingCents(value: string): number | null {
 const text = value.trim().replace(',', '.')
 const match = /^(\d{1,13})(?:\.(\d{1,2}))?$/.exec(text)
 if (!match) return null
 const amount = BigInt(match[1]!) * 100n + BigInt((match[2] || '').padEnd(2, '0'))
 return amount > 0n && amount <= 999999999999999n ? Number(amount) : null
}
