export function paymentCents(value: string): string {
  const normalized = value.trim().replace(',', '.')
  if (!/^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/.test(normalized)) throw new Error('invalid_money')
  const [whole = '0', fraction = ''] = normalized.split('.')
  const result = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'))
  if (result <= 0n) throw new Error('invalid_money')
  return result.toString()
}

export function paymentDecimal(cents: string): string {
  if (!/^-?(0|[1-9][0-9]*)$/.test(cents)) throw new Error('invalid_money')
  const amount = BigInt(cents)
  const sign = amount < 0n ? '-' : ''
  const absolute = amount < 0n ? -amount : amount
  return `${sign}${absolute / 100n}.${(absolute % 100n).toString().padStart(2, '0')}`
}
