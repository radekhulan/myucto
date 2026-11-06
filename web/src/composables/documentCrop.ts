/**
 * Návrh ořezu fotky dokladu a jeho narovnání, celé v prohlížeči.
 *
 * Postup je stejný jako u knihovny jscanify (MIT, puffinsoft/jscanify), jen bez
 * OpenCV.js, aby telefon kvůli focení účtenky nestahoval 9 MB: zmenšený šedotón,
 * práh podle Otsu, největší souvislá světlá oblast (papír na tmavším podkladu)
 * a její čtyři krajní body jako rohy. Narovnání je perspektivní transformace
 * (homografie) s bilineárním vzorkováním.
 *
 * Detekce selže tiše (vrátí null) a dialog pak nabídne celou fotku jako dosud.
 */

export interface Point { x: number; y: number }

/** Rohy dokladu v pořadí levý horní, pravý horní, pravý dolní, levý dolní. */
export type Quad = [Point, Point, Point, Point]

export interface Pixels {
  width: number
  height: number
  /** RGBA po řádcích, jako `ImageData.data`. */
  data: Uint8ClampedArray
}

/** Delší strana obrazu, na kterém se hledá doklad. */
export const DETECT_MAX_SIDE = 400
/** Doklad musí zabírat aspoň tuto část fotky, jinak jde nejspíš o šum. */
export const MIN_AREA_RATIO = 0.08
/** Oblast přes téměř celou fotku = doklad nejspíš přesahuje záběr, ořez nemá smysl. */
export const MAX_AREA_RATIO = 0.97

export function grayscale(px: Pixels): Float32Array {
  const out = new Float32Array(px.width * px.height)
  for (let i = 0, j = 0; i < out.length; i++, j += 4) {
    out[i] = 0.299 * px.data[j] + 0.587 * px.data[j + 1] + 0.114 * px.data[j + 2]
  }
  return out
}

/** Rozmazání 3 × 3, potlačí zrno a text na papíře. */
export function boxBlur(gray: Float32Array, width: number, height: number): Float32Array {
  const out = new Float32Array(gray.length)
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      let sum = 0
      let n = 0
      for (let dy = -1; dy <= 1; dy++) {
        const yy = y + dy
        if (yy < 0 || yy >= height) continue
        for (let dx = -1; dx <= 1; dx++) {
          const xx = x + dx
          if (xx < 0 || xx >= width) continue
          sum += gray[yy * width + xx]
          n++
        }
      }
      out[y * width + x] = sum / n
    }
  }
  return out
}

/** Práh, který nejlépe odděluje dvě skupiny jasů (Otsu). */
export function otsuThreshold(gray: Float32Array): number {
  const hist = new Array<number>(256).fill(0)
  for (const v of gray) hist[Math.max(0, Math.min(255, Math.round(v)))]++
  const total = gray.length
  let sumAll = 0
  for (let i = 0; i < 256; i++) sumAll += i * hist[i]
  let sumBack = 0
  let weightBack = 0
  let best = 0
  let threshold = 127
  for (let t = 0; t < 256; t++) {
    weightBack += hist[t]
    if (weightBack === 0) continue
    const weightFore = total - weightBack
    if (weightFore === 0) break
    sumBack += t * hist[t]
    const meanBack = sumBack / weightBack
    const meanFore = (sumAll - sumBack) / weightFore
    const between = weightBack * weightFore * (meanBack - meanFore) ** 2
    if (between > best) {
      best = between
      threshold = t
    }
  }
  return threshold
}

/** Indexy pixelů největší souvislé oblasti masky (4-okolí). */
export function largestComponent(mask: Uint8Array, width: number): Int32Array | null {
  const label = new Int32Array(mask.length)
  const stack = new Int32Array(mask.length)
  let bestLabel = 0
  let bestSize = 0
  let next = 0
  for (let start = 0; start < mask.length; start++) {
    if (!mask[start] || label[start]) continue
    next++
    let size = 0
    let top = 0
    stack[top++] = start
    label[start] = next
    while (top > 0) {
      const i = stack[--top]
      size++
      const x = i % width
      const neighbours = [
        x > 0 ? i - 1 : -1,
        x < width - 1 ? i + 1 : -1,
        i >= width ? i - width : -1,
        i < mask.length - width ? i + width : -1,
      ]
      for (const n of neighbours) {
        if (n >= 0 && mask[n] && !label[n]) {
          label[n] = next
          stack[top++] = n
        }
      }
    }
    if (size > bestSize) {
      bestSize = size
      bestLabel = next
    }
  }
  if (bestLabel === 0) return null
  const out = new Int32Array(bestSize)
  let k = 0
  for (let i = 0; i < label.length; i++) if (label[i] === bestLabel) out[k++] = i
  return out
}

/** Obsah čtyřúhelníku (shoelace), kladný bez ohledu na orientaci. */
export function quadArea(q: Quad): number {
  let s = 0
  for (let i = 0; i < 4; i++) {
    const a = q[i]
    const b = q[(i + 1) % 4]
    s += a.x * b.y - b.x * a.y
  }
  return Math.abs(s) / 2
}

/** Konvexní a neprotínající se čtyřúhelník (všechna vektorová součinová znaménka stejná). */
export function isConvexQuad(q: Quad): boolean {
  let sign = 0
  for (let i = 0; i < 4; i++) {
    const a = q[i]
    const b = q[(i + 1) % 4]
    const c = q[(i + 2) % 4]
    const cross = (b.x - a.x) * (c.y - b.y) - (b.y - a.y) * (c.x - b.x)
    if (cross === 0) return false
    const s = Math.sign(cross)
    if (sign === 0) sign = s
    else if (s !== sign) return false
  }
  return true
}

/**
 * Najde doklad na (zmenšené) fotce. Rohy vrací v souřadnicích předaných pixelů.
 * Krajní body podle součtu a rozdílu souřadnic fungují pro doklad natočený
 * o méně než 45°, což je u fotky účtenky běžné.
 */
export function detectDocumentQuad(px: Pixels): Quad | null {
  const { width, height } = px
  if (width < 16 || height < 16) return null
  const gray = boxBlur(grayscale(px), width, height)
  const threshold = otsuThreshold(gray)
  const mask = new Uint8Array(gray.length)
  for (let i = 0; i < gray.length; i++) mask[i] = gray[i] > threshold ? 1 : 0
  const component = largestComponent(mask, width)
  if (!component) return null
  const ratio = component.length / (width * height)
  if (ratio < MIN_AREA_RATIO || ratio > MAX_AREA_RATIO) return null

  let tl = { x: 0, y: 0, v: Infinity }
  let br = { x: 0, y: 0, v: -Infinity }
  let tr = { x: 0, y: 0, v: -Infinity }
  let bl = { x: 0, y: 0, v: Infinity }
  for (const i of component) {
    const x = i % width
    const y = (i - x) / width
    const sum = x + y
    const diff = x - y
    if (sum < tl.v) tl = { x, y, v: sum }
    if (sum > br.v) br = { x, y, v: sum }
    if (diff > tr.v) tr = { x, y, v: diff }
    if (diff < bl.v) bl = { x, y, v: diff }
  }
  // Rohy na hraně pixelu, ne na jeho levém horním okraji.
  const quad: Quad = [
    { x: tl.x, y: tl.y },
    { x: tr.x + 1, y: tr.y },
    { x: br.x + 1, y: br.y + 1 },
    { x: bl.x, y: bl.y + 1 },
  ]
  if (!isConvexQuad(quad)) return null
  if (quadArea(quad) < MIN_AREA_RATIO * width * height) return null
  return quad
}

export function scaleQuad(q: Quad, sx: number, sy: number = sx): Quad {
  return q.map(p => ({ x: p.x * sx, y: p.y * sy })) as Quad
}

const dist = (a: Point, b: Point) => Math.hypot(a.x - b.x, a.y - b.y)

/** Rozměr narovnaného dokladu: delší z protilehlých stran, delší strana nejvýš `maxSide`. */
export function rectifiedSize(q: Quad, maxSide: number): { width: number; height: number } {
  const w = Math.max(dist(q[0], q[1]), dist(q[3], q[2]))
  const h = Math.max(dist(q[0], q[3]), dist(q[1], q[2]))
  const ratio = Math.min(1, maxSide / Math.max(w, h))
  return { width: Math.max(1, Math.round(w * ratio)), height: Math.max(1, Math.round(h * ratio)) }
}

/**
 * Homografie, která zobrazí obdélník `width × height` (rohy ve stejném pořadí
 * jako Quad) na čtyřúhelník `q`. Vrací 3 × 3 matici po řádcích (h33 = 1).
 */
export function rectToQuadHomography(width: number, height: number, q: Quad): number[] {
  const src: Point[] = [{ x: 0, y: 0 }, { x: width, y: 0 }, { x: width, y: height }, { x: 0, y: height }]
  const a: number[][] = []
  const b: number[] = []
  for (let i = 0; i < 4; i++) {
    const { x, y } = src[i]
    const { x: u, y: v } = q[i]
    a.push([x, y, 1, 0, 0, 0, -u * x, -u * y]); b.push(u)
    a.push([0, 0, 0, x, y, 1, -v * x, -v * y]); b.push(v)
  }
  const h = solveLinear(a, b)
  return [...h, 1]
}

/** Gaussova eliminace s výběrem hlavního prvku. */
export function solveLinear(a: number[][], b: number[]): number[] {
  const n = b.length
  const m = a.map((row, i) => [...row, b[i]])
  for (let col = 0; col < n; col++) {
    let pivot = col
    for (let r = col + 1; r < n; r++) if (Math.abs(m[r][col]) > Math.abs(m[pivot][col])) pivot = r
    if (Math.abs(m[pivot][col]) < 1e-12) throw new Error('singular')
    ;[m[col], m[pivot]] = [m[pivot], m[col]]
    for (let r = 0; r < n; r++) {
      if (r === col) continue
      const f = m[r][col] / m[col][col]
      for (let c = col; c <= n; c++) m[r][c] -= f * m[col][c]
    }
  }
  return m.map((row, i) => row[n] / row[i])
}

/**
 * Narovná čtyřúhelník `q` ze zdrojových pixelů do obdélníku `width × height`
 * (zpětné zobrazení, bilineární vzorkování, mimo zdroj bílá).
 */
export function warpQuad(src: Pixels, q: Quad, width: number, height: number): Pixels {
  const h = rectToQuadHomography(width, height, q)
  const out = new Uint8ClampedArray(width * height * 4)
  const sw = src.width
  const sh = src.height
  const sd = src.data
  for (let y = 0; y < height; y++) {
    const yy = y + 0.5
    for (let x = 0; x < width; x++) {
      const xx = x + 0.5
      const w = h[6] * xx + h[7] * yy + h[8]
      const u = (h[0] * xx + h[1] * yy + h[2]) / w - 0.5
      const v = (h[3] * xx + h[4] * yy + h[5]) / w - 0.5
      const o = (y * width + x) * 4
      if (u < -0.5 || v < -0.5 || u > sw - 0.5 || v > sh - 0.5) {
        out[o] = out[o + 1] = out[o + 2] = out[o + 3] = 255
        continue
      }
      const x0 = Math.max(0, Math.min(sw - 1, Math.floor(u)))
      const y0 = Math.max(0, Math.min(sh - 1, Math.floor(v)))
      const x1 = Math.min(sw - 1, x0 + 1)
      const y1 = Math.min(sh - 1, y0 + 1)
      const fx = Math.max(0, Math.min(1, u - x0))
      const fy = Math.max(0, Math.min(1, v - y0))
      const i00 = (y0 * sw + x0) * 4
      const i10 = (y0 * sw + x1) * 4
      const i01 = (y1 * sw + x0) * 4
      const i11 = (y1 * sw + x1) * 4
      for (let c = 0; c < 3; c++) {
        const top = sd[i00 + c] + (sd[i10 + c] - sd[i00 + c]) * fx
        const bottom = sd[i01 + c] + (sd[i11 + c] - sd[i01 + c]) * fx
        out[o + c] = top + (bottom - top) * fy
      }
      out[o + 3] = 255
    }
  }
  return { width, height, data: out }
}
