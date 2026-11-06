<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import AppBrand from './AppBrand.vue'
import AppMenuToggle from './AppMenuToggle.vue'
import AppUserMenu from './AppUserMenu.vue'
import ThemeToggle from './ThemeToggle.vue'
import LanguageToggle from './LanguageToggle.vue'
import {
  APP_BRAND_LINK,
  APP_HEADER,
  APP_HEADER_ROW,
  DRAWER_ITEM,
  DRAWER_ITEM_ACTIVE,
  DRAWER_ITEM_IDLE,
  TOP_NAV_ITEM,
  TOP_NAV_ITEM_ACTIVE,
  TOP_NAV_ITEM_PADDING,
} from './navStyles'

const PROFILE_PATH = '/instance-admin/profile'

const { t } = useI18n()
const auth = useAuthStore()
const security = useSessionSecurityStore()
const route = useRoute()
const router = useRouter()
const mobileOpen = ref(false)
const userMenuOpen = ref(false)
const busy = ref(false)
const error = ref('')

// Technický účet nemá firemní agendy: menu je záměrně pevné a nečte firemní oprávnění.
const navItems = computed(() => [
  { to: '/instance-admin', routeName: 'instance-admin', label: t('instance_admin.overview'), icon: ICONS.cycle },
  { to: PROFILE_PATH, routeName: 'instance-admin-profile', label: t('auth.profile_title'), icon: ICONS.edit },
])

watch(() => route.fullPath, () => {
  mobileOpen.value = false
  userMenuOpen.value = false
})

async function logout() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await auth.logout()
    security.clear()
    await router.replace('/login')
  } catch {
    error.value = t('auth.logout_failed')
  } finally { busy.value = false }
}
</script>

<template>
  <div style="--app-header-height: 3rem; --app-footer-height: 2.75rem" class="min-h-screen flex flex-col overflow-x-clip bg-neutral-50 text-neutral-800" @keydown.esc="userMenuOpen = false; mobileOpen = false">
    <header :class="APP_HEADER">
      <div :class="APP_HEADER_ROW">
        <RouterLink to="/instance-admin" :class="APP_BRAND_LINK">
          <AppBrand />
        </RouterLink>

        <nav class="hidden lg:flex ml-1 min-w-0 flex-1 h-full items-stretch" :aria-label="t('nav.main_menu')" data-instance-nav>
          <RouterLink
            v-for="item in navItems"
            :key="item.to"
            :to="item.to"
            :class="[TOP_NAV_ITEM, TOP_NAV_ITEM_PADDING, route.name === item.routeName ? ['bg-primary-50', TOP_NAV_ITEM_ACTIVE] : '']"
            :aria-current="route.name === item.routeName ? 'page' : undefined"
          >{{ item.label }}</RouterLink>
        </nav>

        <div class="ml-auto flex h-full shrink-0 items-center gap-1 text-sm">
          <AppUserMenu
            v-model:open="userMenuOpen"
            class="hidden lg:flex"
            :link="RouterLink"
            :profile-path="PROFILE_PATH"
            :logout-busy="busy"
            @logout="logout"
          >
            <template #preferences>
              <div class="my-1 border-t border-neutral-200" aria-hidden="true"></div>
              <div class="flex items-center justify-between gap-3 px-3 py-1.5 text-sm text-neutral-700">
                <span>{{ t('instance_admin.menu_theme') }}</span>
                <ThemeToggle />
              </div>
              <div class="flex items-center justify-between gap-3 px-3 py-1.5 text-sm text-neutral-700">
                <span>{{ t('instance_admin.menu_language') }}</span>
                <LanguageToggle />
              </div>
            </template>
          </AppUserMenu>
          <AppMenuToggle v-model="mobileOpen" />
        </div>
      </div>
    </header>

    <div v-if="mobileOpen" class="lg:hidden fixed inset-0 bg-black/50 z-20" aria-hidden="true" @click="mobileOpen = false"></div>
    <aside
      class="lg:hidden fixed right-0 z-30 top-12 h-[calc(100dvh-3rem)] w-[calc(100vw-3rem)] max-w-80 nav-inverted bg-surface border-l border-neutral-200 shadow-lg flex flex-col transition-transform duration-200 ease-in-out"
      :class="mobileOpen ? 'translate-x-0' : 'translate-x-full'"
      :inert="!mobileOpen"
      data-instance-drawer
    >
      <nav class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-2.5 py-3" :aria-label="t('nav.main_menu')">
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          :class="[DRAWER_ITEM, route.name === item.routeName ? DRAWER_ITEM_ACTIVE : DRAWER_ITEM_IDLE]"
          :aria-current="route.name === item.routeName ? 'page' : undefined"
          @click="mobileOpen = false"
        >
          <svg class="w-[15px] h-[15px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
          </svg>
          <span>{{ item.label }}</span>
        </RouterLink>
      </nav>
      <div class="shrink-0 border-t border-neutral-200 px-4 py-3 bg-neutral-50 space-y-3">
        <div class="min-w-0 text-sm">
          <div class="truncate font-medium text-neutral-900">{{ auth.user?.name }}</div>
          <div class="truncate text-xs text-neutral-500">{{ auth.user?.email }}</div>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-1.5">
            <LanguageToggle />
            <ThemeToggle />
          </div>
          <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="logout">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3-6 3 3m0 0-3 3m3-3H9"/></svg>
            {{ t('nav.logout') }}
          </button>
        </div>
      </div>
    </aside>

    <main class="flex-1 px-5 sm:px-8 py-6 w-full">
      <p v-if="error" role="alert" class="mb-5 rounded-lg border border-danger-200 bg-danger-50 p-3 text-danger-700">{{ error }}</p>
      <RouterView />
    </main>

    <footer class="nav-inverted sticky bottom-0 z-20 border-t border-neutral-200 bg-surface shadow-[0_-2px_10px_rgba(21,19,29,0.10)]">
      <div class="h-11 flex items-center justify-end gap-1.5 px-3 text-[11px] leading-none text-neutral-500">
        <a href="https://myucto.cz/" target="_blank" rel="noopener" class="whitespace-nowrap hover:text-primary-700 hover:underline transition-colors" title="MyÚčto.cz">MyÚčto.cz</a>
        <span aria-hidden="true">·</span>
        <a href="https://mywebdesign.cz" target="_blank" rel="noopener" class="whitespace-nowrap hover:text-neutral-700">© MyWebdesign.cz</a>
      </div>
    </footer>
  </div>
</template>
