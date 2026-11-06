import { beforeEach, expect, it, vi } from 'vitest'

type Env = { standalone?: boolean; mq?: Record<string, boolean>; width?: number; ua?: string; platform?: string; touch?: number }

function setEnv(env: Env = {}) {
  const mq = { '(display-mode: standalone)': false, '(pointer: coarse)': true, ...(env.mq ?? {}) }
  window.matchMedia = ((q: string) => ({ matches: !!mq[q as keyof typeof mq] })) as typeof window.matchMedia
  Object.defineProperty(window, 'innerWidth', { value: env.width ?? 390, configurable: true })
  Object.defineProperty(navigator, 'userAgent', { value: env.ua ?? 'Mozilla/5.0 (Linux; Android 14) Chrome/120', configurable: true })
  Object.defineProperty(navigator, 'platform', { value: env.platform ?? 'Linux armv8l', configurable: true })
  Object.defineProperty(navigator, 'maxTouchPoints', { value: env.touch ?? 5, configurable: true })
  Object.defineProperty(navigator, 'standalone', { value: env.standalone, configurable: true })
}

async function load() {
  vi.resetModules()
  const mod = await import('../usePwaInstall')
  mod.initPwaInstall()
  return mod
}

function fireInstallPrompt(outcome: 'accepted' | 'dismissed') {
  const event = new Event('beforeinstallprompt', { cancelable: true }) as Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: string }> }
  event.prompt = vi.fn().mockResolvedValue(undefined)
  event.userChoice = Promise.resolve({ outcome })
  window.dispatchEvent(event)
  return event
}

beforeEach(() => setEnv())

it('offers the native prompt on a mobile browser and consumes it after accepting', async () => {
  const { usePwaInstall } = await load()
  const { mode, install } = usePwaInstall()
  expect(mode.value).toBe('manual')
  const event = fireInstallPrompt('accepted')
  expect(event.defaultPrevented).toBe(true)
  expect(mode.value).toBe('prompt')
  expect(await install()).toBe(true)
  expect(event.prompt).toHaveBeenCalledOnce()
  expect(mode.value).toBeNull()
})

it('falls back to the manual hint after the prompt is dismissed (event is single-use)', async () => {
  const { usePwaInstall } = await load()
  const { mode, install } = usePwaInstall()
  fireInstallPrompt('dismissed')
  expect(await install()).toBe(false)
  expect(mode.value).toBe('manual')
})

it('offers nothing when already running as an installed app', async () => {
  setEnv({ mq: { '(display-mode: standalone)': true } })
  const { usePwaInstall } = await load()
  fireInstallPrompt('accepted')
  expect(usePwaInstall().mode.value).toBeNull()
})

it('detects iOS standalone through navigator.standalone', async () => {
  setEnv({ standalone: true, ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)' })
  const { usePwaInstall } = await load()
  expect(usePwaInstall().mode.value).toBeNull()
})

it('shows the manual hint on iPhone without beforeinstallprompt', async () => {
  setEnv({ ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)' })
  const { usePwaInstall } = await load()
  expect(usePwaInstall().mode.value).toBe('ios')
})

it('treats iPadOS reporting as a touch Mac as iOS', async () => {
  setEnv({ ua: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: 'MacIntel', touch: 5, width: 1024 })
  const { usePwaInstall } = await load()
  expect(usePwaInstall().mode.value).toBe('ios')
})

it('offers the manual hint on desktop and on mobile browsers without the event', async () => {
  setEnv({ mq: { '(pointer: coarse)': false }, width: 1280, ua: 'Mozilla/5.0 (Windows NT 10.0) Chrome/120', platform: 'Win32', touch: 0 })
  const desktop = await load()
  expect(desktop.usePwaInstall().mode.value).toBe('manual')
  fireInstallPrompt('accepted')
  expect(desktop.usePwaInstall().mode.value).toBe('prompt')

  setEnv()
  const android = await load()
  expect(android.usePwaInstall().mode.value).toBe('manual')
})

it('hides the offer after appinstalled', async () => {
  const { usePwaInstall } = await load()
  const { mode } = usePwaInstall()
  fireInstallPrompt('accepted')
  expect(mode.value).toBe('prompt')
  window.dispatchEvent(new Event('appinstalled'))
  expect(mode.value).toBeNull()
})
