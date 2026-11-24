/**
 * Rozdílový vstup příplatku (nebo dosažené mzdy za přesčas) z docházky.
 *
 * Změněná docházka nezapisuje novou plnou částku, ale jen rozdíl proti tomu,
 * co už za měsíc a druh příplatku vzniklo (`surcharge:<druh>:<měsíc>:<pořadí>`,
 * pořadí od 2). Po změně zaokrouhlení příplatků na celé koruny tak vedle
 * původního vstupu přibyly haléřové řádky, které vypadaly jako chyba, přestože
 * součet sedí. Seznam vstupů je proto označí.
 */
const TIME_SURCHARGE_EXTERNAL_ID = /^surcharge:[a-z_]+:\d{4}-\d{2}-01:(\d+)$/

export function isTimeSurchargeDelta(input: { source_kind: string, external_id: string | null }): boolean {
  if (input.source_kind !== 'time' || input.external_id === null) {
    return false
  }
  const match = TIME_SURCHARGE_EXTERNAL_ID.exec(input.external_id)

  return match !== null && Number(match[1]) > 1
}
