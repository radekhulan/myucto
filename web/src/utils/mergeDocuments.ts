/**
 * Spojení více souborů jednoho dokladu (strany faktury nebo účtenky naskenované zvlášť)
 * do jednoho PDF, které pak jde do AI vytěžení jako jediný doklad. Běží v prohlížeči,
 * server dostane obyčejné PDF a jeho limity (velikost, typ) platí beze změny.
 */
import type { PDFDocument as PdfDocumentType } from 'pdf-lib'

/** Stejný strop jako MAX_PDF_BYTES v AiExtractPdf*Action. */
export const MERGE_MAX_BYTES = 32 * 1024 * 1024

export const MERGE_ACCEPT = 'application/pdf,.pdf,image/jpeg,image/png,image/webp,image/gif,image/bmp,.jpg,.jpeg,.png,.webp,.gif,.bmp'

/** Delší strana fotky v pixelech; víc AI nepotřebuje a PDF zbytečně roste. */
const IMAGE_MAX_PX = 2400
/** Delší strana stránky s fotkou v bodech (A4 = 842 pt). */
const IMAGE_PAGE_MAX_PT = 842

export type MergeErrorCode = 'encrypted' | 'unreadable' | 'unsupported' | 'too_large'

export class MergeError extends Error {
  constructor(public readonly code: MergeErrorCode, public readonly fileName: string) {
    super(`${code}: ${fileName}`)
  }
}

export function isPdfFile(file: File): boolean {
  return file.type === 'application/pdf' || /\.pdf$/i.test(file.name)
}

export function isMergeableImage(file: File): boolean {
  if (/heic|heif/i.test(file.type) || /\.(heic|heif)$/i.test(file.name)) return false
  return file.type.startsWith('image/') || /\.(jpe?g|png|webp|gif|bmp)$/i.test(file.name)
}

export function isMergeable(file: File): boolean {
  return isPdfFile(file) || isMergeableImage(file)
}

/** Název spojeného souboru podle první části: „faktura-1.pdf" → „faktura-1-spojeno.pdf". */
export function mergedFileName(files: File[]): string {
  const base = (files[0]?.name ?? 'doklad').replace(/\.[^.]+$/, '') || 'doklad'
  return `${base}-spojeno.pdf`
}

async function imageToJpeg(file: File): Promise<{ bytes: Uint8Array; width: number; height: number }> {
  let bitmap: ImageBitmap
  try {
    bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
  } catch {
    throw new MergeError('unreadable', file.name)
  }
  const scale = Math.min(1, IMAGE_MAX_PX / Math.max(bitmap.width, bitmap.height))
  const width = Math.max(1, Math.round(bitmap.width * scale))
  const height = Math.max(1, Math.round(bitmap.height * scale))
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new MergeError('unreadable', file.name)
  // Průhledné PNG by v JPEG zčernalo.
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, width, height)
  ctx.drawImage(bitmap, 0, 0, width, height)
  bitmap.close()
  const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.85))
  if (!blob) throw new MergeError('unreadable', file.name)
  return { bytes: new Uint8Array(await blob.arrayBuffer()), width, height }
}

async function appendPdf(target: PdfDocumentType, file: File): Promise<void> {
  const { PDFDocument, EncryptedPDFError } = await import('pdf-lib')
  let source: PdfDocumentType
  try {
    source = await PDFDocument.load(await file.arrayBuffer())
  } catch (e) {
    throw new MergeError(e instanceof EncryptedPDFError ? 'encrypted' : 'unreadable', file.name)
  }
  const pages = await target.copyPages(source, source.getPageIndices())
  pages.forEach(page => target.addPage(page))
}

async function appendImage(target: PdfDocumentType, file: File): Promise<void> {
  const { bytes, width, height } = await imageToJpeg(file)
  const image = await target.embedJpg(bytes)
  const scale = IMAGE_PAGE_MAX_PT / Math.max(width, height)
  const pageWidth = width * scale
  const pageHeight = height * scale
  target.addPage([pageWidth, pageHeight]).drawImage(image, { x: 0, y: 0, width: pageWidth, height: pageHeight })
}

/** Spojí soubory v daném pořadí do jednoho PDF. */
export async function mergeToPdf(files: File[]): Promise<File> {
  const { PDFDocument } = await import('pdf-lib')
  const merged = await PDFDocument.create()
  for (const file of files) {
    if (isPdfFile(file)) await appendPdf(merged, file)
    else if (isMergeableImage(file)) await appendImage(merged, file)
    else throw new MergeError('unsupported', file.name)
  }
  const bytes = await merged.save()
  const name = mergedFileName(files)
  if (bytes.byteLength > MERGE_MAX_BYTES) throw new MergeError('too_large', name)
  return new File([bytes as BlobPart], name, { type: 'application/pdf' })
}
