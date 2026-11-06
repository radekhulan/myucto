export function chargeMoney(cents: string): string {
  const value = BigInt(cents)
  const negative = value < 0n
  const absolute = negative ? -value : value
  return `${negative ? '-' : ''}${(absolute / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0')},${(absolute % 100n).toString().padStart(2, '0')}`
}
