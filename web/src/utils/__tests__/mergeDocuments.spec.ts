import { describe, expect, it } from 'vitest'
import { PDFDocument } from 'pdf-lib'
import { MergeError, isMergeable, mergeToPdf, mergedFileName } from '@/utils/mergeDocuments'

async function pdfFile(name: string, widths: number[]): Promise<File> {
  const doc = await PDFDocument.create()
  widths.forEach(w => doc.addPage([w, 500]))
  return new File([(await doc.save()) as BlobPart], name, { type: 'application/pdf' })
}

describe('mergeToPdf', () => {
  it('spojí strany všech PDF v zadaném pořadí', async () => {
    const merged = await mergeToPdf([
      await pdfFile('faktura-1.pdf', [301]),
      await pdfFile('faktura-2.pdf', [302, 303]),
    ])
    expect(merged.name).toBe('faktura-1-spojeno.pdf')
    expect(merged.type).toBe('application/pdf')
    const doc = await PDFDocument.load(await merged.arrayBuffer())
    expect(doc.getPages().map(p => Math.round(p.getWidth()))).toEqual([301, 302, 303])
  })

  it('poškozené PDF ohlásí s názvem souboru', async () => {
    const broken = new File(['není to pdf'], 'rozbite.pdf', { type: 'application/pdf' })
    await expect(mergeToPdf([await pdfFile('a.pdf', [300]), broken]))
      .rejects.toMatchObject({ code: 'unreadable', fileName: 'rozbite.pdf' })
    await expect(mergeToPdf([broken])).rejects.toBeInstanceOf(MergeError)
  })
})

describe('isMergeable', () => {
  it('pustí PDF a běžné fotky, HEIC a ISDOC ne', () => {
    expect(isMergeable(new File([''], 'a.pdf'))).toBe(true)
    expect(isMergeable(new File([''], 'foto.JPG'))).toBe(true)
    expect(isMergeable(new File([''], 'x', { type: 'image/png' }))).toBe(true)
    expect(isMergeable(new File([''], 'foto.heic', { type: 'image/heic' }))).toBe(false)
    expect(isMergeable(new File([''], 'faktura.isdoc'))).toBe(false)
  })

  it('název odvodí od první části', () => {
    expect(mergedFileName([new File([''], 'uctenka.str1.pdf')])).toBe('uctenka.str1-spojeno.pdf')
    expect(mergedFileName([])).toBe('doklad-spojeno.pdf')
  })
})
