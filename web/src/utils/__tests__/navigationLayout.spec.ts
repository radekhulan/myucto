import { describe, expect, it } from 'vitest'
import { canFitCompactNavigation, prefersCompactNavigation, shouldUseAutomaticSideNavigation } from '@/utils/navigationLayout'

describe('compact navigation on smaller screens', () => {
  it('fits the rail and its menu within 90 percent of the viewport', () => {
    expect(canFitCompactNavigation(399, 16)).toBe(false)
    expect(canFitCompactNavigation(400, 16)).toBe(true)
    expect(canFitCompactNavigation(600, 16)).toBe(true)
    expect(canFitCompactNavigation(768, 16)).toBe(true)
  })

  it('uses the actual rem size for enlarged interface text', () => {
    expect(canFitCompactNavigation(499, 20)).toBe(false)
    expect(canFitCompactNavigation(500, 20)).toBe(true)
    expect(canFitCompactNavigation(0, 16)).toBe(false)
    expect(canFitCompactNavigation(500, 0)).toBe(false)
  })

  it('inherits compact desktop navigation until the smaller-screen choice is set', () => {
    expect(prefersCompactNavigation(null, true)).toBe(true)
    expect(prefersCompactNavigation(null, false)).toBe(false)
    expect(prefersCompactNavigation(false, true)).toBe(false)
    expect(prefersCompactNavigation(true, false)).toBe(true)
  })
})

describe('automatic navigation layout', () => {
  it('switches to the side navigation before the top menu overflows', () => {
    expect(shouldUseAutomaticSideNavigation(1_000, 1_020, false)).toBe(false)
    expect(shouldUseAutomaticSideNavigation(1_000, 1_005, false)).toBe(true)
  })

  it('does not oscillate around the layout breakpoint', () => {
    expect(shouldUseAutomaticSideNavigation(1_000, 1_005, false)).toBe(true)
    expect(shouldUseAutomaticSideNavigation(1_000, 1_020, true)).toBe(true)
    expect(shouldUseAutomaticSideNavigation(1_000, 1_050, true)).toBe(true)
    expect(shouldUseAutomaticSideNavigation(1_000, 1_100, true)).toBe(false)
  })
})
