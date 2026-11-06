import { describe, expect, it } from 'vitest'
import { compressPhoto, computeTargetSize, photoFileName, type PhotoSource } from '../usePhotoCapture'

function fakeSource(width: number, height: number, sizeFor: (w: number, h: number, q: number) => number) {
  const calls: { w: number; h: number; q: number }[] = []
  const source: PhotoSource = {
    width, height,
    createCanvas(w, h) {
      return {
        width: w, height: h,
        draw() {},
        async toBlob(_type, q) {
          calls.push({ w, h, q })
          return new Blob([new Uint8Array(sizeFor(w, h, q))], { type: 'image/jpeg' })
        },
      }
    },
  }
  return { source, calls }
}

describe('computeTargetSize', () => {
  it('zmenší delší stranu na 1200 při zachování poměru', () => {
    expect(computeTargetSize(4000, 3000)).toEqual({ width: 1200, height: 900 })
    expect(computeTargetSize(3000, 4000)).toEqual({ width: 900, height: 1200 })
  })
  it('menší obrázek nezvětšuje', () => {
    expect(computeTargetSize(800, 600)).toEqual({ width: 800, height: 600 })
  })
  it('použije dodatečné měřítko', () => {
    expect(computeTargetSize(4000, 3000, 1200, 0.5)).toEqual({ width: 600, height: 450 })
  })
})

describe('photoFileName', () => {
  it('formátuje foto-YYYYMMDD-HHMMSS.jpg', () => {
    expect(photoFileName(new Date(2026, 9, 24, 8, 5, 3))).toBe('foto-20261024-080503.jpg')
  })
})

describe('compressPhoto', () => {
  it('skončí při první kvalitě, vejde-li se do limitu', async () => {
    const { source, calls } = fakeSource(4000, 3000, () => 1000)
    const file = await compressPhoto(source)
    expect(calls).toHaveLength(1)
    expect(calls[0]).toMatchObject({ w: 1200, h: 900, q: 0.85 })
    expect(file.type).toBe('image/jpeg')
    expect(file.name).toMatch(/^foto-\d{8}-\d{6}\.jpg$/)
  })
  it('snižuje kvalitu, dokud se nevejde', async () => {
    const { source, calls } = fakeSource(4000, 3000, (_w, _h, q) => (q > 0.6 ? 200_000 : 100_000))
    const file = await compressPhoto(source)
    expect(file.size).toBe(100_000)
    expect(calls.every(c => c.w === 1200)).toBe(true)
    expect(calls[calls.length - 1].q).toBeLessThanOrEqual(0.6)
  })
  it('při nejnižší kvalitě zmenší rozměr o 15 %', async () => {
    const { source, calls } = fakeSource(4000, 3000, (w) => (w >= 1200 ? 400_000 : 100_000))
    const file = await compressPhoto(source)
    expect(file.size).toBe(100_000)
    const last = calls[calls.length - 1]
    expect(last.w).toBe(1020)
    expect(last.q).toBe(0.85)
  })
  it('má strop iterací a vrátí nejmenší výsledek', async () => {
    const { source, calls } = fakeSource(4000, 3000, (w) => w * 1000)
    const file = await compressPhoto(source)
    expect(calls.length).toBeLessThan(200)
    expect(file.size).toBeGreaterThan(150 * 1024)
    expect(file.size).toBe(Math.min(...calls.map(c => c.w * 1000)))
  })
})
