import { describe, expect, it } from 'vitest'
import { shouldUseAutomaticSideNavigation } from '@/utils/navigationLayout'

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
