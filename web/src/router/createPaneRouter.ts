import {
  createMemoryHistory,
  createRouter,
  type RouteLocationNormalized,
  type RouteLocationRaw,
  type RouteRecordRaw,
  type Router,
} from 'vue-router'
import { ensureNamespaces, namespacesForRoute } from '@/i18n'
import { createWorkspaceRoutes } from './workspaceRoutes'
import { payrollStartPeriodGuard } from './payrollStartPeriodGuard'

interface PaneRouterOptions {
  prepareRoutes: (routes: RouteRecordRaw[]) => void
  guard: (
    to: RouteLocationNormalized,
    from: RouteLocationNormalized,
  ) => boolean | RouteLocationRaw | Promise<boolean | RouteLocationRaw>
  onGlobalNavigation: (to: RouteLocationRaw) => void
}

export function createPaneRouter(options: PaneRouterOptions): Router {
  const routes: RouteRecordRaw[] = [{
    path: '/',
    meta: { requiresAuth: true },
    children: createWorkspaceRoutes(),
  }]
  options.prepareRoutes(routes)

  const paneRouter = createRouter({
    history: createMemoryHistory(),
    routes,
  })

  paneRouter.beforeEach(async (to, from) => {
    // Přímý odkaz z obsahu iframe může mířit mimo pracovní plochu.
    if (!to.matched.length) {
      options.onGlobalNavigation(to.fullPath)
      return false
    }
    const decision = await options.guard(to, from)
    if (decision && decision !== true && typeof decision !== 'boolean') {
      let internal = false
      try {
        internal = paneRouter.resolve(decision).matched.length > 0
      } catch {
        internal = false
      }
      if (!internal) {
        options.onGlobalNavigation(decision)
        return false
      }
    }
    if (decision !== true) return decision
    return payrollStartPeriodGuard(to)
  })

  paneRouter.beforeResolve(async (to) => {
    const needed = namespacesForRoute(to.name as string | undefined)
    if (needed.length > 0) {
      try {
        await ensureNamespaces(needed)
      } catch {
        // Chybějící překlad nesmí zablokovat navigaci panelu.
      }
    }
    return true
  })

  return paneRouter
}
