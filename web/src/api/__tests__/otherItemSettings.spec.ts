import { describe, expect, it, vi } from 'vitest'

vi.mock('../client', () => ({ api: {} }))

import { resolveOtherItemFolder } from '../otherItemSettings'

describe('resolveOtherItemFolder', () => {
  it('doplní rok a měsíc z data vystavení', () => {
    expect(resolveOtherItemFolder('Ostatní pohledávky a závazky/{YYYY}/{MM}', '2026-09-24')).toBe('Ostatní pohledávky a závazky/2026/09')
    expect(resolveOtherItemFolder('Smlouvy/Pozemky {YYYY}', '2026-01-05')).toBe('Smlouvy/Pozemky 2026')
  })

  it('bez platného data nebo s prázdnou šablonou složku nevrátí', () => {
    expect(resolveOtherItemFolder('Smlouvy/{YYYY}', '')).toBeUndefined()
    expect(resolveOtherItemFolder('Smlouvy/{YYYY}', null)).toBeUndefined()
    expect(resolveOtherItemFolder(' / ', '2026-01-05')).toBeUndefined()
  })

  it('vynechá prázdné segmenty a okrajové mezery', () => {
    expect(resolveOtherItemFolder('/Smlouvy// {MM} /', '2026-03-01')).toBe('Smlouvy/03')
  })
})
