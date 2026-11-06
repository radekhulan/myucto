import type { Router } from 'vue-router'

/**
 * Cesta vedoucí na konkrétní záznam (`/invoices/123`) nedává v jiné firmě smysl.
 * Vrací nejbližší nadřazenou stránku bez parametrů záznamu (`/invoices`) nebo
 * původní cestu, pokud žádný parametr nemá. Dotaz a hash se u seznamu zahazují.
 */
export function withoutRecordParams(router: Router, fullPath: string): { path: string; changed: boolean } {
  const url = new URL(fullPath, 'http://localhost')
  if (!hasRecordParams(router, url.pathname)) return { path: fullPath, changed: false }

  const segments = url.pathname.split('/').filter(Boolean)
  while (segments.length > 0) {
    segments.pop()
    const candidate = segments.length ? `/${segments.join('/')}` : '/'
    if (!hasRecordParams(router, candidate)) return { path: candidate, changed: true }
  }
  return { path: '/', changed: true }
}

function hasRecordParams(router: Router, pathname: string): boolean {
  const resolved = router.resolve(pathname)
  if (!resolved.matched.length) return true
  return Object.keys(resolved.params).length > 0
}
