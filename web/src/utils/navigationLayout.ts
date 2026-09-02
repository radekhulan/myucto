const NAV_FIT_RESERVE = 12
const NAV_FIT_RELEASE_BUFFER = 80

export function shouldUseAutomaticSideNavigation(
  requiredWidth: number,
  availableWidth: number,
  currentlySide: boolean,
): boolean {
  const releaseBuffer = currentlySide ? NAV_FIT_RELEASE_BUFFER : 0
  return requiredWidth > availableWidth - NAV_FIT_RESERVE - releaseBuffer
}
