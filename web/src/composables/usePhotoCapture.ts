import type { Pixels } from './documentCrop'

export const PHOTO_MAX_SIDE = 1200
export const PHOTO_MAX_BYTES = 150 * 1024
export const PHOTO_QUALITY_START = 0.85
export const PHOTO_QUALITY_MIN = 0.4
export const PHOTO_QUALITY_STEP = 0.05
export const PHOTO_SHRINK_FACTOR = 0.85
export const PHOTO_MAX_SHRINKS = 8
/** Delší strana obrazu, ze kterého se narovnává doklad (větší fotka se před tím zmenší). */
export const CROP_WORK_MAX_SIDE = 2400
/** Narovnaný doklad je užší než celá fotka, proto smí mít delší stranu větší (čitelnost dlouhé účtenky). */
export const CROPPED_MAX_SIDE = 1600

export interface PhotoCanvas {
  width: number
  height: number
  /** Vykreslí zdroj do plátna o daném rozměru s bílým pozadím. */
  draw(width: number, height: number): void
  toBlob(type: string, quality: number): Promise<Blob | null>
}

export interface PhotoSource {
  width: number
  height: number
  createCanvas(width: number, height: number): PhotoCanvas
  /** Pixely zdroje zmenšené na daný rozměr (detekce a narovnání dokladu). */
  pixels?(width: number, height: number): Pixels
  close?(): void
}

export interface PhotoOptions {
  maxSide?: number
  maxBytes?: number
}

export class PhotoDecodeError extends Error {}

/** Cílový rozměr: poměr stran zachován, delší strana nejvýš `maxSide`, nikdy se nezvětšuje. */
export function computeTargetSize(width: number, height: number, maxSide = PHOTO_MAX_SIDE, scale = 1): { width: number; height: number } {
  const ratio = Math.min(1, maxSide / Math.max(width, height)) * scale
  return {
    width: Math.max(1, Math.round(width * ratio)),
    height: Math.max(1, Math.round(height * ratio)),
  }
}

/** Název souboru `foto-YYYYMMDD-HHMMSS.jpg`. */
export function photoFileName(now = new Date()): string {
  const p = (n: number) => String(n).padStart(2, '0')
  return `foto-${now.getFullYear()}${p(now.getMonth() + 1)}${p(now.getDate())}-${p(now.getHours())}${p(now.getMinutes())}${p(now.getSeconds())}.jpg`
}

/**
 * Zmenší a zkomprimuje zdroj do JPEG: kvalitu snižuje od 0.85 k 0.4, a nevejde-li se
 * ani tak do limitu, zmenší rozměr o 15 % a zkouší znovu (omezený počet kol).
 * Vrací nejmenší dosažený výsledek, i když limit nesplní.
 */
export async function compressPhoto(source: PhotoSource, opts: PhotoOptions = {}, now = new Date()): Promise<File> {
  const maxSide = opts.maxSide ?? PHOTO_MAX_SIDE
  const maxBytes = opts.maxBytes ?? PHOTO_MAX_BYTES
  let best: Blob | null = null
  let scale = 1
  for (let shrink = 0; shrink <= PHOTO_MAX_SHRINKS; shrink++) {
    const size = computeTargetSize(source.width, source.height, maxSide, scale)
    const canvas = source.createCanvas(size.width, size.height)
    canvas.draw(size.width, size.height)
    for (let q = PHOTO_QUALITY_START; q >= PHOTO_QUALITY_MIN - 1e-9; q -= PHOTO_QUALITY_STEP) {
      const blob = await canvas.toBlob('image/jpeg', Math.round(q * 100) / 100)
      if (!blob) throw new PhotoDecodeError('toBlob failed')
      if (!best || blob.size < best.size) best = blob
      if (blob.size <= maxBytes) return new File([blob], photoFileName(now), { type: 'image/jpeg', lastModified: now.getTime() })
    }
    scale *= PHOTO_SHRINK_FACTOR
  }
  return new File([best as Blob], photoFileName(now), { type: 'image/jpeg', lastModified: now.getTime() })
}

function canvasSource(width: number, height: number, drawSource: (ctx: CanvasRenderingContext2D, w: number, h: number) => void, close?: () => void): PhotoSource {
  return {
    width, height, close,
    pixels(w, h) {
      const el = document.createElement('canvas')
      el.width = w
      el.height = h
      const ctx = el.getContext('2d', { willReadFrequently: true })
      if (!ctx) throw new PhotoDecodeError('canvas unavailable')
      ctx.fillStyle = '#ffffff'
      ctx.fillRect(0, 0, w, h)
      drawSource(ctx, w, h)
      return ctx.getImageData(0, 0, w, h)
    },
    createCanvas(w, h) {
      const el = document.createElement('canvas')
      el.width = w
      el.height = h
      return {
        width: w, height: h,
        draw(dw, dh) {
          const ctx = el.getContext('2d')
          if (!ctx) throw new PhotoDecodeError('canvas unavailable')
          ctx.fillStyle = '#ffffff'
          ctx.fillRect(0, 0, dw, dh)
          drawSource(ctx, dw, dh)
        },
        toBlob: (type, quality) => new Promise(resolve => el.toBlob(resolve, type, quality)),
      }
    },
  }
}

/** Zdroj z hotových pixelů (narovnaný doklad) pro {@link compressPhoto}. */
export function pixelsSource(px: Pixels): PhotoSource {
  const holder = document.createElement('canvas')
  holder.width = px.width
  holder.height = px.height
  const ctx = holder.getContext('2d')
  if (!ctx) throw new PhotoDecodeError('canvas unavailable')
  ctx.putImageData(new ImageData(new Uint8ClampedArray(px.data), px.width, px.height), 0, 0)
  return canvasSource(px.width, px.height, (c, w, h) => c.drawImage(holder, 0, 0, w, h))
}

/** Dekóduje soubor s respektováním EXIF orientace; fallback přes `<img>`. */
export async function decodePhoto(file: File): Promise<PhotoSource> {
  if (typeof createImageBitmap === 'function') {
    try {
      const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' })
      return canvasSource(bmp.width, bmp.height, (ctx, w, h) => ctx.drawImage(bmp, 0, 0, w, h), () => bmp.close())
    } catch { /* fallback přes <img> */ }
  }
  const url = URL.createObjectURL(file)
  try {
    const img = await new Promise<HTMLImageElement>((resolve, reject) => {
      const el = new Image()
      el.onload = () => resolve(el)
      el.onerror = () => reject(new PhotoDecodeError('decode failed'))
      el.src = url
    })
    // Moderní prohlížeče aplikují EXIF orientaci u <img> samy (image-orientation: from-image).
    return canvasSource(img.naturalWidth, img.naturalHeight, (ctx, w, h) => ctx.drawImage(img, 0, 0, w, h))
  } catch (e) {
    throw e instanceof PhotoDecodeError ? e : new PhotoDecodeError('decode failed')
  } finally {
    URL.revokeObjectURL(url)
  }
}

export async function processPhotoFile(file: File, opts: PhotoOptions = {}): Promise<File> {
  const source = await decodePhoto(file)
  try {
    return await compressPhoto(source, opts)
  } finally {
    source.close?.()
  }
}

/** Zařízení s dotykovým ovládáním (telefon, tablet), kde dává „Vyfotit“ smysl. */
export function isCaptureDevice(): boolean {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false
  return window.matchMedia('(pointer: coarse)').matches || window.matchMedia('(max-width: 767px)').matches
}
