<script setup lang="ts">
import { computed, ref, type Component } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { userInitials } from '@/utils/navigationLayout'
import { USER_MENU_ITEM } from './navStyles'

withDefaults(defineProps<{
  /** Odkaz položek: hlavní aplikace naviguje v panelu, technická plocha routerem. */
  link: Component
  profilePath?: string
  compact?: boolean
  logoutBusy?: boolean
}>(), { profilePath: '/profile/password', compact: false, logoutBusy: false })

const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ logout: [] }>()

const { t } = useI18n()
const auth = useAuthStore()
const sessionSecurity = useSessionSecurityStore()
const displayName = computed(() => auth.user?.name || auth.user?.email || '')
const initials = computed(() => userInitials(auth.user?.name, auth.user?.email))
const canLockSession = computed(() => sessionSecurity.state?.session_state === 'active'
  && sessionSecurity.state.unlock_methods.includes('passkey'))

const button = ref<HTMLElement | null>(null)
defineExpose({ button })
</script>

<template>
  <div class="relative h-full items-center">
    <button
      ref="button"
      type="button"
      class="cursor-pointer inline-flex items-center h-8 rounded-md text-neutral-700 hover:bg-neutral-100 hover:text-primary-700"
      :class="[
        compact ? 'w-8 justify-center' : 'max-w-44 gap-1.5 px-2',
        { 'bg-neutral-100 text-primary-700': open },
      ]"
      :aria-expanded="open"
      :aria-label="compact ? `${t('nav.user_menu')}: ${displayName}` : undefined"
      :title="compact ? displayName : undefined"
      aria-haspopup="menu"
      @click="open = !open"
    >
      <span class="inline-flex w-6 h-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-700">
        <span v-if="compact && initials" class="text-[11px] font-semibold leading-none" aria-hidden="true">{{ initials }}</span>
        <svg v-else class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path fill-rule="evenodd" d="M12 2.75a5 5 0 1 0 0 10 5 5 0 0 0 0-10ZM4 20.25a8 8 0 0 1 16 0 1 1 0 0 1-1 1H5a1 1 0 0 1-1-1Z" clip-rule="evenodd" />
        </svg>
      </span>
      <span v-if="!compact" class="truncate">{{ auth.user?.name }}</span>
      <svg v-if="!compact" class="w-3 h-3 shrink-0 transition-transform" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
      </svg>
    </button>
    <transition
      enter-active-class="transition duration-100 ease-out"
      enter-from-class="opacity-0 -translate-y-1"
      enter-to-class="opacity-100 translate-y-0"
      leave-active-class="transition duration-75 ease-in"
      leave-from-class="opacity-100 translate-y-0"
      leave-to-class="opacity-0 -translate-y-1"
    >
      <div v-if="open" class="absolute right-0 top-full max-h-[calc(100dvh-var(--app-header-height,3rem))] overflow-y-auto overscroll-contain w-64 bg-surface border border-neutral-200 rounded-b-lg shadow-xl py-1.5 z-40" role="menu">
        <div class="px-3 pt-1 pb-2 border-b border-neutral-200 mb-1">
          <div class="font-medium text-neutral-900 truncate">{{ displayName }}</div>
          <div v-if="auth.user?.name" class="text-xs text-neutral-500 truncate">{{ auth.user?.email }}</div>
        </div>
        <component :is="link" :to="profilePath" :class="USER_MENU_ITEM" role="menuitem" @click="open = false">
          <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 1 1 2 2m4 0a6 6 0 1 1-7.7 5.75L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.6a1 1 0 0 1 .3-.7l6-6A6 6 0 0 1 21 9z"/></svg>
          {{ t('auth.change_password_title') }}
        </component>
        <component :is="link" :to="`${profilePath}?tab=totp`" :class="USER_MENU_ITEM" role="menuitem" @click="open = false">
          <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"/></svg>
          {{ t('auth.totp_tab') }}
        </component>
        <component :is="link" :to="`${profilePath}?tab=passkeys`" :class="USER_MENU_ITEM" role="menuitem" @click="open = false">
          <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 1 1 2 2m4 0a6 6 0 1 1-7.7 5.75L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.6a1 1 0 0 1 .3-.7l6-6A6 6 0 0 1 21 9z"/></svg>
          {{ t('passkeys.title') }}
        </component>
        <component :is="link" :to="`${profilePath}?tab=session-lock`" :class="USER_MENU_ITEM" role="menuitem" @click="open = false">
          <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m5-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
          {{ t('session_lock.preference_tab') }}
        </component>
        <!-- Stránka profilu technickému účtu záložku zkratek nenabízí. -->
        <component :is="link" v-if="!auth.isInstanceAdmin" :to="`${profilePath}?tab=shortcuts`" :class="USER_MENU_ITEM" role="menuitem" @click="open = false">
          <svg class="w-4 h-4 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6.75A1.75 1.75 0 0 1 4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75zM6 9h.01M9 9h.01M12 9h.01M15 9h.01M18 9h.01M7 13h.01M10 13h.01M13 13h.01M16 13h.01M8 16h8"/></svg>
          {{ t('keyboard_shortcuts.title') }}
        </component>
        <button
          v-if="canLockSession"
          type="button"
          class="cursor-pointer flex w-full items-center gap-2.5 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50 hover:text-primary-700"
          role="menuitem"
          @click="open = false; sessionSecurity.lock()"
        >
          <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 10V8a6 6 0 0 1 12 0v2m-11 0h10a2 2 0 0 1 2 2v7H5v-7a2 2 0 0 1 2-2Z"/></svg>
          {{ t('session_lock.lock_now') }}
        </button>
        <slot name="preferences" />
        <div class="my-1 border-t border-neutral-200" aria-hidden="true"></div>
        <button
          type="button"
          class="cursor-pointer flex w-full items-center gap-2.5 px-3 py-2 text-sm text-neutral-700 hover:bg-neutral-50 hover:text-danger-600 disabled:opacity-60"
          :disabled="logoutBusy"
          role="menuitem"
          @click="emit('logout')"
        >
          <svg class="w-4 h-4 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6A2.25 2.25 0 0 0 5.25 5.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3-6 3 3m0 0-3 3m3-3H9"/></svg>
          {{ t('nav.logout') }}
        </button>
      </div>
    </transition>
    <div v-if="open" class="fixed inset-0 z-10" aria-hidden="true" @click="open = false"></div>
  </div>
</template>
