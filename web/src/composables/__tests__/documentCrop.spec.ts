import { describe, it, expect } from 'vitest'
import {
  detectDocumentQuad, isConvexQuad, otsuThreshold, quadArea, rectifiedSize,
  rectToQuadHomography, warpQuad, type Pixels, type Quad,
} from '../documentCrop'

/** Tmavý podklad s bílým čtyřúhelníkem (bod uvnitř podle znaménka vektorových součinů). */
function scene(width: number, height: number, quad: Quad, background = 70, paper = 235): Pixels {
  const data = new Uint8ClampedArray(width * height * 4)
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      let inside = true
      for (let i = 0; i < 4; i++) {
        const a = quad[i]
        const b = quad[(i + 1) % 4]
        if ((b.x - a.x) * (y + 0.5 - a.y) - (b.y - a.y) * (x + 0.5 - a.x) < 0) inside = false
      }
      const v = inside ? paper : background
      const o = (y * width + x) * 4
      data[o] = data[o + 1] = data[o + 2] = v
      data[o + 3] = 255
    }
  }
  return { width, height, data }
}

function expectNear(actual: Quad, expected: Quad, tolerance: number) {
  actual.forEach((p, i) => {
    expect(Math.abs(p.x - expected[i].x)).toBeLessThanOrEqual(tolerance)
    expect(Math.abs(p.y - expected[i].y)).toBeLessThanOrEqual(tolerance)
  })
}

describe('documentCrop', () => {
  it('najde rovnou účtenku na tmavém stole', () => {
    const quad: Quad = [{ x: 60, y: 20 }, { x: 140, y: 20 }, { x: 140, y: 280 }, { x: 60, y: 280 }]
    expectNear(detectDocumentQuad(scene(200, 300, quad))!, quad, 2)
  })

  it('najde natočený doklad i se šikmou perspektivou', () => {
    const quad: Quad = [{ x: 50, y: 40 }, { x: 160, y: 25 }, { x: 175, y: 260 }, { x: 40, y: 275 }]
    expectNear(detectDocumentQuad(scene(220, 300, quad))!, quad, 3)
  })

  it('na fotce bez dokladu nebo s dokladem přes celý záběr nic nenavrhne', () => {
    const blank = scene(100, 100, [{ x: 0, y: 0 }, { x: 0, y: 0 }, { x: 0, y: 0 }, { x: 0, y: 0 }])
    expect(detectDocumentQuad(blank)).toBeNull()
    const full: Quad = [{ x: 0, y: 0 }, { x: 100, y: 0 }, { x: 100, y: 100 }, { x: 0, y: 100 }]
    expect(detectDocumentQuad(scene(100, 100, full))).toBeNull()
    const tiny: Quad = [{ x: 45, y: 45 }, { x: 52, y: 45 }, { x: 52, y: 52 }, { x: 45, y: 52 }]
    expect(detectDocumentQuad(scene(100, 100, tiny))).toBeNull()
  })

  it('Otsu oddělí dvě skupiny jasů', () => {
    const gray = new Float32Array([...Array(50).fill(60), ...Array(50).fill(220)])
    const t = otsuThreshold(gray)
    expect(t).toBeGreaterThanOrEqual(60)
    expect(t).toBeLessThan(220)
  })

  it('homografie zobrazí rohy obdélníku na rohy čtyřúhelníku', () => {
    const quad: Quad = [{ x: 10, y: 5 }, { x: 90, y: 12 }, { x: 95, y: 140 }, { x: 4, y: 130 }]
    const h = rectToQuadHomography(80, 120, quad)
    const map = (x: number, y: number) => {
      const w = h[6] * x + h[7] * y + h[8]
      return { x: (h[0] * x + h[1] * y + h[2]) / w, y: (h[3] * x + h[4] * y + h[5]) / w }
    }
    expectNear([map(0, 0), map(80, 0), map(80, 120), map(0, 120)], quad, 1e-6)
  })

  it('narovnání osově zarovnaného výřezu vrátí tytéž pixely', () => {
    const quad: Quad = [{ x: 20, y: 10 }, { x: 60, y: 10 }, { x: 60, y: 70 }, { x: 20, y: 70 }]
    const src = scene(100, 100, quad)
    const out = warpQuad(src, quad, 40, 60)
    expect(out.width).toBe(40)
    expect(out.height).toBe(60)
    // Uvnitř dokladu je všude papír.
    expect(out.data[(30 * 40 + 20) * 4]).toBe(235)
    expect(out.data[3]).toBe(255)
  })

  it('rozměr narovnaného dokladu podle delších stran a stropu', () => {
    // Šikmá horní hrana (≈ 361) je delší než spodní (300), pravá strana (1200) delší než levá (1000).
    const quad: Quad = [{ x: 0, y: 200 }, { x: 300, y: 0 }, { x: 300, y: 1200 }, { x: 0, y: 1200 }]
    expect(rectifiedSize(quad, 2000)).toEqual({ width: 361, height: 1200 })
    expect(rectifiedSize(quad, 600)).toEqual({ width: 180, height: 600 })
  })

  it('pomocné kontroly čtyřúhelníku', () => {
    const square: Quad = [{ x: 0, y: 0 }, { x: 10, y: 0 }, { x: 10, y: 10 }, { x: 0, y: 10 }]
    expect(quadArea(square)).toBe(100)
    expect(isConvexQuad(square)).toBe(true)
    const bowtie: Quad = [{ x: 0, y: 0 }, { x: 10, y: 10 }, { x: 10, y: 0 }, { x: 0, y: 10 }]
    expect(isConvexQuad(bowtie)).toBe(false)
  })
})
