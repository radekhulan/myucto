import { describe, expect, it, vi } from 'vitest'
import type { RouteLocationRaw, RouteRecordNormalized } from 'vue-router'

vi.mock('@/i18n', () => ({
  ensureNamespaces: vi.fn().mockResolvedValue(undefined),
  namespacesForRoute: () => [],
}))

import routePolicy from '@shared/client-route-policy.json'
import appLayoutSourceRaw from '@/components/layout/AppLayout.vue?raw'
import routerSourceRaw from '@/router/index.ts?raw'
import { router } from '@/router'
import {
  canonicalDomainLoginHandoffPath,
  clientDomainCanonicalHandoffPath,
  clientDomainRedirect,
  clientDomainRoutes,
  isClientDomainAuthenticatedPath,
  isClientDomainFlowRouteName,
  isClientDomainRouteName,
  usesClientNavigation,
} from '@/security/clientRoutePolicy'
import { isClientPermission, type PermissionKey } from '@/security/permissions'

// `?raw` vrací zdroják přesně tak, jak leží na disku — na Windows checkoutu
// tedy s CRLF. Řádkové regexy níže porovnávají strukturu kódu, ne konce řádků,
// takže se zdroj normalizuje na LF a test padá jen na obsahu.
const appLayoutSource = appLayoutSourceRaw.replace(/\r\n/g, '\n')
const routerSource = routerSourceRaw.replace(/\r\n/g, '\n')

type ManifestRoute = (typeof routePolicy.routes)[number]

interface ClientRouteParitySnapshot {
  clientRedirects: Map<string, string[]>
  clientPermissionRoutes: string[]
  directSelfServiceRoutes: string[]
  errors: string[]
  routerRedirects: Map<string, string[]>
}

function policyPatternForRouterPath(path: string): string {
  const numericParameter = '__CLIENT_NUMERIC_PARAMETER__'
  const withPlaceholders = path.replace(/:([A-Za-z_]\w*)\(\\d\+\)/g, numericParameter)
  const escaped = withPlaceholders.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    .replaceAll(numericParameter, '[0-9]+')
  return path === '/' ? '^/$' : `^${escaped}/?$`
}

function redirectedFullPath(route: RouteRecordNormalized, query: Record<string, string> = {}): string {
  expect(route.redirect, String(route.name)).toBeDefined()
  const raw = typeof route.redirect === 'function'
    ? route.redirect({ query } as never, {} as never)
    : route.redirect
  return router.resolve(raw as RouteLocationRaw).fullPath
}

function clientNavigationPaths(): string[] {
  const match = appLayoutSource.match(
    /if \(clientExperience\.value\) \{\s*return filterNavigation\(\[(?<body>[\s\S]*?)\n\s{4}]\)\n\s{2}}/,
  )
  expect(match?.groups?.body, 'clientExperience navigation block').toBeDefined()
  return [...match!.groups!.body.matchAll(/\b(?:to|newTo):\s*'(?<path>\/[^']+)'/g)]
    .map(item => item.groups!.path)
}

function sortedUnique(values: Iterable<string>): string[] {
  return [...new Set(values)].sort()
}

function setParityErrors(label: string, expected: Iterable<string>, actual: Iterable<string>): string[] {
  const expectedSet = new Set(expected)
  const actualSet = new Set(actual)
  const missing = sortedUnique([...expectedSet].filter(value => !actualSet.has(value)))
  const extra = sortedUnique([...actualSet].filter(value => !expectedSet.has(value)))
  return [
    ...(missing.length > 0 ? [`${label} missing: ${missing.join(', ')}`] : []),
    ...(extra.length > 0 ? [`${label} extra: ${extra.join(', ')}`] : []),
  ]
}

function explicitSelfServiceRouteNames(): string[] {
  const match = routerSource.match(
    /const selfServiceRouteNames = new Set\(\[(?<names>[\s\S]*?)]\)/,
  )
  if (!match?.groups?.names) return []
  return sortedUnique(
    [...match.groups.names.matchAll(/['"](?<name>[a-z0-9-]+)['"]/g)]
      .map(item => item.groups!.name),
  )
}

function namesFromRedirectSource(source: string): string[] {
  const paths = [...source.matchAll(/['"`](?<path>\/[^'"`]*)['"`]/g)]
    .map(item => String(router.resolve(item.groups!.path).name))
  const names = [...source.matchAll(/\bname\s*:\s*['"](?<name>[a-z0-9-]+)['"]/g)]
    .map(item => item.groups!.name)
  return sortedUnique([...paths, ...names])
}

function redirectDestinationNames(route: RouteRecordNormalized): string[] {
  if (!route.redirect) return []
  if (typeof route.redirect === 'function') {
    return namesFromRedirectSource(String(route.redirect))
  }
  return [String(router.resolve(route.redirect as RouteLocationRaw).name)]
}

function beforeEnterDestinationNames(route: RouteRecordNormalized): string[] {
  const guards = Array.isArray(route.beforeEnter) ? route.beforeEnter : [route.beforeEnter]
  return sortedUnique(guards.flatMap(guard => guard ? namesFromRedirectSource(String(guard)) : []))
}

function clientRoleLandingRedirects(): Map<string, string[]> {
  const redirects = new Map<string, string[]>()
  const pattern = /if\s*\(auth\.isClientRole\s*&&\s*to\.name\s*===\s*['"](?<source>[a-z0-9-]+)['"]\s*\)\s*{\s*return\s*{\s*name:\s*['"](?<destination>[a-z0-9-]+)['"]/g
  for (const match of routerSource.matchAll(pattern)) {
    redirects.set(match.groups!.source, [match.groups!.destination])
  }
  return redirects
}

function deriveClientRouteParity(): ClientRouteParitySnapshot {
  const routes = router.getRoutes()
  const byName = new Map(routes.map(route => [String(route.name), route]))
  const errors: string[] = []
  const clientPermissionRoutes = routes
    .filter(route => typeof route.name === 'string'
      && typeof route.meta.permission === 'string'
      && isClientPermission(route.meta.permission as PermissionKey)
      && route.meta.superadminOnly !== true)
    .map(route => String(route.name))

  const explicitSelfService = explicitSelfServiceRouteNames()
  const missingExplicitRoutes = explicitSelfService.filter(name => !byName.has(name))
  if (missingExplicitRoutes.length > 0) {
    throw new Error(`Router self-service declarations without routes: ${missingExplicitRoutes.join(', ')}`)
  }
  const semanticSelfService = sortedUnique([
    ...explicitSelfService,
    ...routes.filter(route => route.meta.mfaSetupOnly === true).map(route => String(route.name)),
  ])
  const directSelfServiceRoutes = semanticSelfService
    .filter(name => !byName.get(name)?.redirect)

  const allRedirects = new Map(
    routes
      .filter(route => typeof route.name === 'string' && route.redirect)
      .map(route => [String(route.name), redirectDestinationNames(route)]),
  )
  const covered = new Set([...clientPermissionRoutes, ...directSelfServiceRoutes])
  const routerRedirects = new Map<string, string[]>()
  const mandatoryRedirects = semanticSelfService
    .filter(name => byName.get(name)?.redirect)
    .concat(clientPermissionRoutes.filter(name => byName.get(name)?.redirect))
  for (const source of sortedUnique(mandatoryRedirects)) {
    const destinations = allRedirects.get(source) ?? []
    routerRedirects.set(source, destinations)
    if (destinations.length === 0) errors.push(`router redirect destinations unresolved: ${source}`)
  }
  let changed = true
  while (changed) {
    changed = false
    for (const [source, destinations] of allRedirects) {
      if (destinations.length === 0 || destinations.some(destination => !covered.has(destination))) continue
      if (!routerRedirects.has(source)) routerRedirects.set(source, destinations)
      if (!covered.has(source)) {
        covered.add(source)
        changed = true
      }
    }
  }
  for (const source of mandatoryRedirects) {
    const destinations = routerRedirects.get(source) ?? []
    const outsideSurface = destinations.filter(destination => !covered.has(destination))
    if (outsideSurface.length > 0) {
      errors.push(`router redirect destinations outside client surface for ${source}: ${outsideSurface.join(', ')}`)
    }
  }

  const clientRedirects = clientRoleLandingRedirects()
  for (const name of clientPermissionRoutes) {
    const route = byName.get(name)
    if (!route || route.redirect) continue
    const destinations = beforeEnterDestinationNames(route)
    if (destinations.length > 0 && destinations.every(destination => covered.has(destination))) {
      clientRedirects.set(name, destinations)
    }
  }

  return {
    clientRedirects,
    clientPermissionRoutes: sortedUnique(clientPermissionRoutes),
    directSelfServiceRoutes: sortedUnique(directSelfServiceRoutes),
    errors,
    routerRedirects,
  }
}

function manifestDestinationNames(route: ManifestRoute): string[] {
  const destinations: string[] = []
  if ('redirect_to' in route && typeof route.redirect_to === 'string') {
    destinations.push(String(router.resolve(route.redirect_to).name))
  }
  if ('redirect_destinations' in route && Array.isArray(route.redirect_destinations)) {
    destinations.push(...route.redirect_destinations)
  }
  return sortedUnique(destinations)
}

function clientRouteParityErrors(manifestRoutes: readonly ManifestRoute[]): string[] {
  const parity = deriveClientRouteParity()
  const errors: string[] = [...parity.errors]
  const manifestByName = new Map(manifestRoutes.map(route => [route.name, route]))
  const manifestNames = [...manifestByName.keys()]
  const manifestSelfService = manifestRoutes
    .filter(route => route.kind === 'self_service')
    .map(route => route.name)
  const manifestRouterRedirects = manifestRoutes
    .filter(route => route.kind === 'router_redirect')
    .map(route => route.name)
  const manifestClientRedirects = manifestRoutes
    .filter(route => route.kind === 'client_redirect')
    .map(route => route.name)
  const manifestPermissionRoutes = manifestRoutes
    .filter(route => 'permission' in route && typeof route.permission === 'string')
    .map(route => route.name)

  errors.push(...setParityErrors(
    'manifest permission routes',
    parity.clientPermissionRoutes,
    manifestPermissionRoutes,
  ))
  errors.push(...setParityErrors(
    'manifest self-service routes',
    parity.directSelfServiceRoutes,
    manifestSelfService,
  ))
  errors.push(...setParityErrors(
    'manifest router redirects',
    parity.routerRedirects.keys(),
    manifestRouterRedirects,
  ))
  errors.push(...setParityErrors(
    'manifest client redirects',
    parity.clientRedirects.keys(),
    manifestClientRedirects,
  ))

  const expectedManifestNames = sortedUnique([
    ...parity.clientPermissionRoutes,
    ...parity.directSelfServiceRoutes,
    ...parity.routerRedirects.keys(),
    ...parity.clientRedirects.keys(),
  ])
  errors.push(...setParityErrors('manifest routes', expectedManifestNames, manifestNames))

  const expectedKinds = new Map<string, ManifestRoute['kind']>()
  for (const name of parity.clientPermissionRoutes) expectedKinds.set(name, 'permission')
  for (const name of parity.directSelfServiceRoutes) expectedKinds.set(name, 'self_service')
  for (const name of parity.clientRedirects.keys()) expectedKinds.set(name, 'client_redirect')
  for (const name of parity.routerRedirects.keys()) expectedKinds.set(name, 'router_redirect')
  for (const [name, expected] of expectedKinds) {
    const actual = manifestByName.get(name)?.kind
    if (actual !== expected) errors.push(`manifest route kind mismatch: ${name} expected ${expected}, got ${actual ?? 'missing'}`)
  }

  for (const [source, destinations] of [
    ...parity.clientRedirects,
    ...parity.routerRedirects,
  ] as Array<[string, string[]]>) {
    const definition = manifestByName.get(source)
    if (!definition) continue
    errors.push(...setParityErrors(
      `manifest redirect destinations for ${source}`,
      destinations,
      manifestDestinationNames(definition),
    ))
    const uncovered = destinations.filter(destination => !manifestByName.has(destination))
    if (uncovered.length > 0) {
      errors.push(`manifest redirect destinations for ${source} unresolved: ${uncovered.join(', ')}`)
    }
  }

  return errors
}

describe('sdílená klientská plocha vlastní domény', () => {
  it('obsahuje auditovanou klientskou plochu včetně nastavení firmy a legacy aliasů', () => {
    expect(clientDomainRoutes).toHaveLength(37)
    expect(new Set(clientDomainRoutes.map(route => route.name)).size).toBe(37)
    expect(clientDomainRoutes.slice(-3).map(route => route.name))
      .toEqual(['data-exchange', 'admin-export', 'admin-import'])
  })

  it('drží přesnou paritu názvů a cest manifestu s Vue routerem', () => {
    const routerRoutes = new Map(router.getRoutes().map(route => [String(route.name), route]))
    const rendered: string[] = []
    const redirects: string[] = []
    const parameterized: string[] = []

    for (const definition of clientDomainRoutes) {
      const route = routerRoutes.get(definition.name)
      expect(route, definition.name).toBeDefined()
      expect(definition.path_pattern, definition.name)
        .toBe(policyPatternForRouterPath(route!.path))

      if (route!.redirect) redirects.push(definition.name)
      else rendered.push(definition.name)
      if (route!.path.includes(':')) parameterized.push(definition.name)
    }

    expect(rendered).toHaveLength(29)
    expect(redirects).toEqual([
      'profile-totp',
      'profile-shortcuts',
      'profile-passkeys',
      'profile-session-lock',
      'setup-totp',
      'data-exchange',
      'admin-export',
      'admin-import',
    ])
    expect(parameterized).toEqual([
      'client-detail',
      'client-edit',
      'invoice-detail',
      'invoice-edit',
      'purchase-invoice-detail',
      'purchase-invoice-edit',
      'recurring-detail',
      'recurring-edit',
    ])

    const kindCounts = new Map<string, number>()
    for (const definition of clientDomainRoutes) {
      kindCounts.set(definition.kind, (kindCounts.get(definition.kind) ?? 0) + 1)
    }
    expect(Object.fromEntries(kindCounts)).toEqual({
      client_redirect: 3,
      permission: 23,
      router_redirect: 8,
      self_service: 3,
    })
  })

  it('klasifikuje veřejné, přihlašovací a statické plochy mimo autentizovaný manifest', () => {
    const routerRoutes = new Map(router.getRoutes().map(route => [String(route.name), route]))
    const publicRoutes = router.getRoutes()
      .filter(route => route.meta.public === true)
      .map(route => String(route.name))
      .sort()
    expect(publicRoutes).toEqual([
      'approval',
      'domain-login-callback',
      'forgot',
      'invoice-public',
      'login',
      'payroll-document-access',
      'reset',
      'setup',
      'work-report-tracking',
    ])

    expect(routePolicy.flow_paths.map(route => route.name).sort())
      .toEqual(['domain-login-callback', 'login'])
    for (const flow of routePolicy.flow_paths) {
      const route = routerRoutes.get(flow.name)
      expect(route, flow.name).toBeDefined()
      expect(flow.path_pattern, flow.name).toBe(policyPatternForRouterPath(route!.path))
      expect(route!.meta.public, flow.name).toBe(true)
      expect(isClientDomainRouteName(flow.name), flow.name).toBe(false)
    }

    for (const path of [
      '/setup',
      '/forgot',
      '/reset',
      '/approval/0123456789abcdef0123456789abcdef',
      '/work-report/0123456789abcdef0123456789abcdef',
      // Zaměstnanec není uživatel aplikace — odkaz na výplatní pásku musí
      // zůstat mimo autentizovaný manifest, jinak by ho brána poslala na login.
      '/payroll-document/' + '0123456789abcdef'.repeat(4),
      '/invoice/0123456789abcdef0123456789abcdef',
      '/manual',
      '/manual/generated/search-index.json',
      '/assets/app.js',
      '/fonts/inter.woff2',
    ]) {
      expect(isClientDomainAuthenticatedPath(path), path).toBe(false)
    }
  })

  it('každou permission routu odvozuje z route meta a client PermissionCatalog parity', () => {
    const routerRoutes = new Map(router.getRoutes().map(route => [String(route.name), route]))
    const manifestPermissionRoutes = clientDomainRoutes.filter(route => route.permission)

    for (const definition of clientDomainRoutes) {
      const route = routerRoutes.get(definition.name)
      expect(route, definition.name).toBeDefined()
      if (!definition.permission) continue
      expect(route?.meta.permission, definition.name).toBe(definition.permission)
      expect(route?.meta.access, definition.name).toBe(definition.access)
      expect(isClientPermission(definition.permission), definition.name).toBe(true)
    }

    const legitimatePermissionRoutes = router.getRoutes()
      .filter(route => typeof route.name === 'string'
        && typeof route.meta.permission === 'string'
        && isClientPermission(route.meta.permission as PermissionKey)
        && route.meta.superadminOnly !== true)
      .map(route => String(route.name))
      .sort()

    expect(manifestPermissionRoutes.map(route => route.name).sort())
      .toEqual(legitimatePermissionRoutes)
  })

  it('drží obousměrnou paritu permission, self-service, MFA a redirect grafu', () => {
    expect(clientRouteParityErrors(routePolicy.routes)).toEqual([])
  })

  it('regresně odhalí self-service nebo MFA routu vynechanou z manifestu', () => {
    const withoutMfaRoute = routePolicy.routes.filter(route => route.name !== 'setup-mfa')

    expect(clientRouteParityErrors(withoutMfaRoute)).toContain(
      'manifest self-service routes missing: setup-mfa',
    )
  })

  it('regresně odhalí self-service alias vynechaný z manifestu', () => {
    const withoutSelfServiceAlias = routePolicy.routes.filter(route => route.name !== 'setup-totp')

    expect(clientRouteParityErrors(withoutSelfServiceAlias)).toContain(
      'manifest router redirects missing: setup-totp',
    )
  })

  it('regresně odhalí router redirect vynechaný z manifestu', () => {
    const withoutRedirect = routePolicy.routes.filter(route => route.name !== 'data-exchange')

    expect(clientRouteParityErrors(withoutRedirect)).toContain(
      'manifest router redirects missing: data-exchange',
    )
  })

  it('pokrývá každý literální cíl klientské navigace manifestem a routerem', () => {
    const paths = [...new Set(clientNavigationPaths())]
    expect(paths).toHaveLength(14)

    const routeNames = new Set(clientDomainRoutes.map(route => route.name))
    for (const path of paths) {
      expect(isClientDomainAuthenticatedPath(path), path).toBe(true)
      const resolved = router.resolve(path)
      expect(routeNames.has(String(resolved.name)), path).toBe(true)
      expect(isClientPermission(resolved.meta.permission as PermissionKey), path).toBe(true)
    }

    expect([...new Set(paths.map(path => String(router.resolve(path).name)))].sort()).toEqual([
      'client-new',
      'clients',
      'invoice-new',
      'invoices',
      'portal',
      'portal-company-settings',
      'portal-document-requests',
      'portal-purchase-invoice-submissions',
      'purchase-invoice-new',
      'purchase-invoices',
      'recurring',
      'recurring-new',
    ])
  })

  it('drží self-service obrazovky, jejich aliasy a query handoff odděleně od path parity', () => {
    // `isds-gateway-callback` je návratová stránka brány ISDS: routa sama nemá
    // permission, protože o právu rozhoduje až API (podání dokumentů vs. mzdové
    // podání — stránka na 403 přepadá na druhý endpoint). Na plochu vlastní
    // domény patří proto, že návratová adresa se skládá z `window.location.origin`
    // toho, kdo odesílání spustil; kdyby tu nebyla, návrat z brány by na klientské
    // doméně skončil přesměrováním na canonical origin i s tokeny v query.
    expect(clientDomainRoutes.filter(route => route.kind === 'self_service').map(route => route.name))
      .toEqual(['profile-password', 'isds-gateway-callback', 'setup-mfa'])
    expect(clientDomainRoutes.filter(route => route.kind === 'router_redirect').slice(0, 5)
      .map(route => route.name))
      .toEqual([
        'profile-totp',
        'profile-shortcuts',
        'profile-passkeys',
        'profile-session-lock',
        'setup-totp',
      ])

    const profileMenuPaths = [...appLayoutSource.matchAll(
      /<(?:RouterLink|WorkspaceNavLink)[^>]*\bto="(?<path>\/profile\/password[^"]*)"/g,
    )].map(item => item.groups!.path)
    expect([...new Set(profileMenuPaths)].sort()).toEqual([
      '/profile/password',
      '/profile/password?tab=passkeys',
      '/profile/password?tab=session-lock',
      '/profile/password?tab=shortcuts',
      '/profile/password?tab=totp',
    ])
    for (const path of profileMenuPaths) {
      expect(isClientDomainAuthenticatedPath(path), path).toBe(true)
    }

    expect(clientDomainCanonicalHandoffPath('/profile/password')).toBeNull()
    expect(clientDomainCanonicalHandoffPath('/profile/password?tab=totp')).toBeNull()
    expect(clientDomainCanonicalHandoffPath('/profile/password?tab=session-lock')).toBeNull()
    expect(clientDomainCanonicalHandoffPath('/profile/password?tab=shortcuts')).toBeNull()
    expect(clientDomainCanonicalHandoffPath('/profile/password?tab=passkeys'))
      .toBe('/profile/password?tab=passkeys')
    expect(clientDomainCanonicalHandoffPath('/profile/password?tab=passkeys&tab=totp'))
      .toBeNull()
  })

  it('povolí zástupce každé klientské rodiny a odmítne interní i veřejné routy', () => {
    for (const path of [
      '/portal/document-requests',
      '/clients/42/edit',
      '/invoices/new?type=proforma',
      '/purchase-invoices/42',
      '/recurring/42/edit',
      '/profile/password?tab=passkeys',
      '/setup-mfa',
      '/exchange?tab=export-purchase',
      '/admin/export',
      '/admin/import?tab=purchase',
    ]) {
      expect(isClientDomainAuthenticatedPath(path), path).toBe(true)
    }

    for (const path of [
      '/projects',
      '/purchase-invoices/incoming',
      '/purchase-invoices/payment-orders',
      '/admin/settings',
      '/profile/api-tokens',
      '/login',
      '/forgot',
      '/invoice/public-token',
      '/manual',
      '//attacker.example',
      '/clients/../admin/settings',
    ]) {
      expect(isClientDomainAuthenticatedPath(path), path).toBe(false)
    }
  })

  it('na vlastní doméně používá klientskou navigaci i staff náhled', () => {
    expect(usesClientNavigation(false, { locked: true })).toBe(true)
    expect(usesClientNavigation(true, { locked: false })).toBe(true)
    expect(usesClientNavigation(false, { locked: false })).toBe(false)
  })

  it('vynutí klientský cíl rout, které jsou pro staff jinak importní obrazovkou', () => {
    expect(clientDomainRedirect('home')).toBe('/portal')
    expect(clientDomainRedirect('invoices-import')).toBe('/invoices/export')
    expect(clientDomainRedirect('purchase-invoices-import')).toBe('/purchase-invoices/export')
    expect(clientDomainRedirect('invoices-export')).toBeNull()
  })

  it('drží legacy aliasy svázané jen s existujícími klientskými cíli', () => {
    const byName = new Map(clientDomainRoutes.map(route => [route.name, route]))
    for (const route of clientDomainRoutes) {
      if (route.redirect_to) {
        expect(isClientDomainAuthenticatedPath(route.redirect_to), route.name).toBe(true)
      }
      for (const destination of route.redirect_destinations ?? []) {
        expect(byName.has(destination), `${route.name} -> ${destination}`).toBe(true)
      }
    }

    expect(byName.get('data-exchange')?.redirect_destinations).toEqual([
      'invoices-export', 'invoices-import', 'purchase-invoices-export', 'purchase-invoices-import',
    ])
    expect(byName.get('admin-import')?.redirect_destinations)
      .toEqual(['invoices-import', 'purchase-invoices-import'])
  })

  it('ověřuje skutečné router redirecty včetně všech query variant', () => {
    const routerRoutes = new Map(router.getRoutes().map(route => [String(route.name), route]))
    const manifestRoutes = new Map<string, ManifestRoute>(
      routePolicy.routes.map(route => [route.name, route]),
    )
    const cases: Array<[string, Record<string, string>, string]> = [
      ['profile-totp', {}, '/profile/password?tab=totp'],
      ['profile-shortcuts', {}, '/profile/password?tab=shortcuts'],
      ['profile-passkeys', {}, '/profile/password?tab=passkeys'],
      ['profile-session-lock', {}, '/profile/password?tab=session-lock'],
      ['setup-totp', {}, '/setup-mfa?method=totp'],
      ['data-exchange', {}, '/invoices/export'],
      ['data-exchange', { tab: 'export-issued' }, '/invoices/export'],
      ['data-exchange', { tab: 'import-issued' }, '/invoices/import'],
      ['data-exchange', { tab: 'export-purchase' }, '/purchase-invoices/export'],
      ['data-exchange', { tab: 'import-purchase' }, '/purchase-invoices/import'],
      ['admin-export', {}, '/invoices/export'],
      ['admin-import', {}, '/invoices/import'],
      ['admin-import', { tab: 'purchase' }, '/purchase-invoices/import'],
    ]
    const observedDestinations = new Map<string, Set<string>>()

    for (const [name, query, expected] of cases) {
      const route = routerRoutes.get(name)
      expect(route, name).toBeDefined()
      const actual = redirectedFullPath(route!, query)
      expect(actual, `${name} ${JSON.stringify(query)}`).toBe(expected)
      const destination = String(router.resolve(actual).name)
      const destinations = observedDestinations.get(name) ?? new Set<string>()
      destinations.add(destination)
      observedDestinations.set(name, destinations)
    }

    for (const name of ['profile-totp', 'profile-shortcuts', 'profile-passkeys', 'profile-session-lock', 'setup-totp', 'admin-export']) {
      const definition = manifestRoutes.get(name)
      expect(definition && 'redirect_to' in definition ? definition.redirect_to : null, name)
        .toBe(cases.find(item => item[0] === name)![2])
    }
    expect([...observedDestinations.get('data-exchange')!]).toEqual(
      (manifestRoutes.get('data-exchange') as ManifestRoute & { redirect_destinations: string[] })
        .redirect_destinations,
    )
    expect([...observedDestinations.get('admin-import')!]).toEqual(
      (manifestRoutes.get('admin-import') as ManifestRoute & { redirect_destinations: string[] })
        .redirect_destinations,
    )
  })

  it('drží login callback odděleně od autentizované plochy', () => {
    expect(isClientDomainFlowRouteName('login')).toBe(true)
    expect(isClientDomainFlowRouteName('domain-login-callback')).toBe(true)
    expect(isClientDomainRouteName('login')).toBe(false)
    expect(isClientDomainAuthenticatedPath('/login')).toBe(false)
  })

  it('převádí WebAuthn obrazovky na pevné canonical cíle a ostatní profil nechá lokální', () => {
    expect(clientDomainCanonicalHandoffPath('/profile/passkeys'))
      .toBe('/profile/password?tab=passkeys')
    expect(clientDomainCanonicalHandoffPath('/profile/password?tab=passkeys'))
      .toBe('/profile/password?tab=passkeys')
    expect(clientDomainCanonicalHandoffPath('/setup-mfa')).toBe('/setup-mfa')
    expect(clientDomainCanonicalHandoffPath('/setup-mfa?method=totp'))
      .toBe('/setup-mfa?method=totp')
    expect(clientDomainCanonicalHandoffPath('/setup-totp')).toBe('/setup-mfa?method=totp')

    expect(clientDomainCanonicalHandoffPath('/profile/password')).toBeNull()
    expect(clientDomainCanonicalHandoffPath('/profile/password?tab=totp')).toBeNull()
    expect(clientDomainCanonicalHandoffPath('//attacker.example/profile/passkeys')).toBeNull()
    expect(canonicalDomainLoginHandoffPath('https://attacker.example')).toBeNull()
    expect(canonicalDomainLoginHandoffPath('/admin/settings')).toBeNull()
    expect(canonicalDomainLoginHandoffPath('/profile/password?tab=passkeys'))
      .toBe('/profile/password?tab=passkeys')
  })
})
