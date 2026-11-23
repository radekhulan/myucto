/**
 * CSV export výsledku hromadné účetní úlohy (přeúčtování, bankovní pohyby).
 *
 * Soubor se skládá na klientovi z položek běhu: UTF-8 s BOM a středník jako oddělovač,
 * aby ho český Excel otevřel rovnou do sloupců, částky s desetinnou čárkou.
 */
export type CsvCell = string | number | null | undefined

export const CSV_PAGE_SIZE = 200

export function csvCell(value: CsvCell): string {
  if (value === null || value === undefined) return ''
  const s = String(value)
  return /[;"\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
}

/** Částka s desetinnou čárkou, bez oddělovače tisíců (Excel ji vezme jako číslo). */
export function csvAmount(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''
  const n = Number(value)
  return Number.isNaN(n) ? '' : n.toFixed(2).replace('.', ',')
}

export function buildCsv(header: string[], rows: CsvCell[][]): string {
  return '﻿' + [header, ...rows].map(r => r.map(csvCell).join(';')).join('\r\n') + '\r\n'
}

/** Stáhne všechny položky běhu po stránkách, dokud jich není `total`. */
export async function fetchAllPages<T>(
  fetchPage: (page: number, perPage: number) => Promise<{ items: T[]; total: number }>,
  perPage = CSV_PAGE_SIZE,
): Promise<T[]> {
  const out: T[] = []
  for (let page = 1; ; page++) {
    const res = await fetchPage(page, perPage)
    out.push(...res.items)
    if (res.items.length === 0 || out.length >= res.total) return out
  }
}

export function downloadCsv(filename: string, content: string): void {
  const url = URL.createObjectURL(new Blob([content], { type: 'text/csv;charset=utf-8' }))
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
