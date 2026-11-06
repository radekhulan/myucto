import { afterEach, describe, expect, it, vi } from 'vitest'
import { DESKTOP_CROP_MAX_PHOTOS, usePhotoCropQueue } from '../usePhotoCropQueue'

function photo(name: string): File {
  return new File(['photo'], name, { type: 'image/jpeg' })
}

const pdf = new File(['%PDF-1.7'], 'b.pdf', { type: 'application/pdf' })

describe('usePhotoCropQueue', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('výřezy vrátí na místa fotek a PDF nechá ve výběru', () => {
    const deliver = vi.fn()
    const crop = usePhotoCropQueue(deliver)
    const a = photo('a.jpg')
    const c = photo('c.jpg')

    crop.start([a, pdf, c])
    expect(crop.current.value).toBe(a)
    expect(crop.remaining.value).toBe(2)
    const cutA = photo('a-cut.jpg')
    crop.done(cutA)
    expect(crop.current.value).toBe(c)
    expect(crop.remaining.value).toBe(1)
    const cutC = photo('c-cut.jpg')
    crop.done(cutC)

    expect(deliver).toHaveBeenCalledExactlyOnceWith([cutA, pdf, cutC])
    expect(crop.current.value).toBeNull()
  })

  it('přeskočení vydá otevřenou a další fotky v originále', () => {
    const deliver = vi.fn()
    const crop = usePhotoCropQueue(deliver)
    const files = [photo('a.jpg'), pdf, photo('c.jpg'), photo('d.jpg')]

    crop.start(files)
    const cutA = photo('a-cut.jpg')
    crop.done(cutA)
    crop.skipRest()

    expect(deliver).toHaveBeenCalledExactlyOnceWith([cutA, pdf, files[2], files[3]])
    expect(crop.current.value).toBeNull()
  })

  it('na desktopu dávku nad limit vydá rovnou, na dotykovém zařízení ji pošle do ořezu', () => {
    const files = Array.from({ length: DESKTOP_CROP_MAX_PHOTOS + 1 }, (_, i) => photo(`${i}.jpg`))
    const desktop = vi.fn()
    const onDesktop = usePhotoCropQueue(desktop)
    onDesktop.start(files)
    expect(desktop).toHaveBeenCalledExactlyOnceWith(files)
    expect(onDesktop.current.value).toBeNull()

    const atLimit = vi.fn()
    usePhotoCropQueue(atLimit).start(files.slice(0, DESKTOP_CROP_MAX_PHOTOS))
    expect(atLimit).not.toHaveBeenCalled()

    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query === '(pointer: coarse)', media: query }))
    const touch = vi.fn()
    const onTouch = usePhotoCropQueue(touch)
    onTouch.start(files)
    expect(touch).not.toHaveBeenCalled()
    expect(onTouch.steps.value).toBe(files.length)
  })

  it('zrušení zahodí celý výběr', () => {
    const deliver = vi.fn()
    const crop = usePhotoCropQueue(deliver)
    crop.start([photo('a.jpg'), photo('b.jpg')])
    crop.cancel()
    expect(deliver).not.toHaveBeenCalled()
    expect(crop.current.value).toBeNull()
  })
})
