import { describe, expect, it } from 'vitest'
import { isApplePlatform } from '@/utils/clientPlatform'

describe('client platform detection', () => {
  it('detects macOS from the platform reported by Safari', () => {
    expect(isApplePlatform({ platform: 'MacIntel', userAgent: 'Safari' })).toBe(true)
  })

  it('prefers userAgentData when Chromium provides it', () => {
    expect(isApplePlatform({
      platform: 'Win32',
      userAgent: 'Chrome',
      userAgentData: { platform: 'macOS' },
    })).toBe(true)
  })

  it('keeps Windows and Linux on Ctrl/Alt labels', () => {
    expect(isApplePlatform({ platform: 'Win32', userAgent: 'Edge' })).toBe(false)
    expect(isApplePlatform({ platform: 'Linux x86_64', userAgent: 'Firefox' })).toBe(false)
  })
})
