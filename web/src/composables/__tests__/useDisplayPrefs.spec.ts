import { describe, expect, it } from 'vitest'
import { DISPLAY_DEFAULTS, resolveDisplayPrefs } from '@/composables/useDisplayPrefs'

describe('resolveDisplayPrefs', () => {
  it('bez uložených předvoleb vrací chování aplikace jako dosud', () => {
    expect(resolveDisplayPrefs(null)).toEqual(DISPLAY_DEFAULTS)
    expect(DISPLAY_DEFAULTS).toMatchObject({
      posting_open: false, dimensions_open: false, preview_open: false, preview_placement: 'side',
      statement_hide_zero: true, statement_analytics: true, statement_unit: 'czk', estimate_open: false,
    })
  })

  it('převezme platné hodnoty a neplatné zahodí', () => {
    const resolved = resolveDisplayPrefs({
      posting_open: true,
      dimensions_open: 'ano' as unknown as boolean,
      preview_placement: 'top' as unknown as 'side',
      statement_unit: 'thousands',
    })
    expect(resolved.posting_open).toBe(true)
    expect(resolved.dimensions_open).toBe(false)
    expect(resolved.preview_placement).toBe('side')
    expect(resolved.statement_unit).toBe('thousands')
  })
})
