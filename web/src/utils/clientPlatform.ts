/**
 * Klávesové popisky a výchozí kombinace se řídí platformou zařízení,
 * ne prohlížečem. Safari neposkytuje userAgentData, proto je
 * navigator.platform stále nutný fallback.
 */
type ClientNavigator = Pick<Navigator, 'platform' | 'userAgent'> & {
  userAgentData?: { platform?: string }
}

export function isApplePlatform(
  source: ClientNavigator | null = typeof navigator === 'undefined' ? null : navigator,
): boolean {
  if (!source) return false
  const nav = source as ClientNavigator
  const platform = nav.userAgentData?.platform || nav.platform || ''
  return /mac|iphone|ipad|ipod/i.test(platform)
    || /macintosh|mac os x|iphone|ipad|ipod/i.test(nav.userAgent)
}
