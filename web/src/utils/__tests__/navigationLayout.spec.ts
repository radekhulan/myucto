import { describe, expect, it } from 'vitest'
import {
  canFitCompactNavigation,
  prefersCompactNavigation,
  selectNavCompactLevel,
  shouldUseAutomaticSideNavigation,
  userInitials,
} from '@/utils/navigationLayout'

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

describe('progressive top menu compaction', () => {
  const savings = [114, 126, 216, 144, 52]

  it('keeps the full menu while it fits', () => {
    expect(selectNavCompactLevel(12, 0, savings)).toBe(0)
    expect(selectNavCompactLevel(300, 0, savings)).toBe(0)
  })

  it('picks the lowest level that fits', () => {
    expect(selectNavCompactLevel(11, 0, savings)).toBe(1)
    expect(selectNavCompactLevel(-102, 0, savings)).toBe(1)
    expect(selectNavCompactLevel(-103, 0, savings)).toBe(2)
    expect(selectNavCompactLevel(-165, 0, savings)).toBe(2)
    expect(selectNavCompactLevel(-250, 0, savings)).toBe(3)
    expect(selectNavCompactLevel(-460, 0, savings)).toBe(4)
    expect(selectNavCompactLevel(-600, 0, savings)).toBe(5)
  })

  it('falls back to the side navigation as the last step', () => {
    expect(selectNavCompactLevel(-640, 0, savings)).toBe(5)
    expect(selectNavCompactLevel(-641, 0, savings)).toBe(6)
    expect(selectNavCompactLevel(-1_000, 0, savings)).toBe(6)
    expect(selectNavCompactLevel(Number.NaN, 0, savings)).toBe(6)
  })

  it('removes the savings already applied when measured on a compact level', () => {
    const atLevel2 = -165 + 114 + 126
    expect(selectNavCompactLevel(atLevel2, 2, savings)).toBe(2)
    expect(selectNavCompactLevel(-640 + 652, 5, savings)).toBe(5)
    expect(selectNavCompactLevel(-641 + 652, 5, savings)).toBe(6)
  })

  it('measures the side navigation as the full variant and needs the buffer to leave it', () => {
    expect(selectNavCompactLevel(-165, 6, savings)).toBe(3)
    expect(selectNavCompactLevel(-100, 6, savings)).toBe(2)
    expect(selectNavCompactLevel(-1_000, 6, savings)).toBe(6)
  })

  it('needs a release buffer to go back to a lower level', () => {
    // změřeno při úrovni 2: slack0 + 240; úroveň 1 chce slack0 + 114 >= 12 + 80
    expect(selectNavCompactLevel(-22 + 240, 2, savings)).toBe(1)
    expect(selectNavCompactLevel(-23 + 240, 2, savings)).toBe(2)
    expect(selectNavCompactLevel(1_000, 3, savings)).toBe(0)
  })

  it('does not oscillate between two neighbouring levels', () => {
    for (let slack0 = -700; slack0 <= 100; slack0 += 7) {
      let level = 0
      let previous = -1
      for (let i = 0; i < 4 && level !== previous; i++) {
        previous = level
        const applied = level === 6 ? 0 : savings.slice(0, level).reduce((sum, value) => sum + value, 0)
        level = selectNavCompactLevel(slack0 + applied, level, savings)
      }
      const applied = level === 6 ? 0 : savings.slice(0, level).reduce((sum, value) => sum + value, 0)
      expect(selectNavCompactLevel(slack0 + applied, level, savings)).toBe(level)
    }
  })
})

describe('user initials', () => {
  it('takes the first and last word of the name', () => {
    expect(userInitials('Radek Hulán', null)).toBe('RH')
    expect(userInitials('  jan  karel novák ', null)).toBe('JN')
    expect(userInitials('Šárka Čermáková', 'x@y.cz')).toBe('ŠČ')
  })

  it('uses one word of the name as up to two letters', () => {
    expect(userInitials('Madonna', null)).toBe('MA')
  })

  it('falls back to the e-mail address', () => {
    expect(userInitials('', 'radek.hulan@example.com')).toBe('RH')
    expect(userInitials(null, 'info@example.com')).toBe('IN')
    expect(userInitials(undefined, undefined)).toBe('')
    expect(userInitials('   ', '@example.com')).toBe('')
  })
})
