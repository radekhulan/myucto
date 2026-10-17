<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Toaster from '@/components/Toaster.vue'
import SessionLockOverlay from '@/components/SessionLockOverlay.vue'
import WebAuthnCeremonyPrompt from '@/components/WebAuthnCeremonyPrompt.vue'
import TableViewportScrollbars from '@/components/ui/TableViewportScrollbars.vue'
import { useTheme } from '@/composables/useTheme'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'

// Inicializace barevného režimu (System/Light/Dark) — aplikuje .dark na <html>.
useTheme()
const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const security = useSessionSecurityStore()
const protectedPrivateRoute = computed(() => route.matched.some(record => record.meta.requiresAuth)
  && route.name !== 'setup-mfa')
const privateContentCovered = computed(() => security.privacyCurtain && protectedPrivateRoute.value)
const privateSessionReady = computed(() => auth.isAuthenticated
  && auth.profileHydrated
  && security.state?.session_state === 'active')
const privateTreeMounted = ref(false)
watch(
  [privateSessionReady, () => auth.isAuthenticated],
  ([ready, authenticated]) => {
    if (!authenticated) {
      privateTreeMounted.value = false
    } else if (ready) {
      privateTreeMounted.value = true
    }
  },
  { immediate: true },
)
const showRoutedContent = computed(() => !protectedPrivateRoute.value
  || (auth.isAuthenticated && privateTreeMounted.value))
const showColdStartGate = computed(() => protectedPrivateRoute.value
  && !privateTreeMounted.value
  && security.state?.session_state !== 'locked')
</script>

<template>
  <div :inert="privateContentCovered" :aria-hidden="privateContentCovered ? 'true' : undefined">
    <RouterView v-if="showRoutedContent" />
    <div v-else-if="showColdStartGate"
         class="min-h-screen bg-neutral-50 flex items-center justify-center p-4"
         role="status" aria-live="polite">
      <div class="w-full max-w-sm rounded-xl bg-surface border border-neutral-200 p-6 text-center shadow-sm">
        <h1 class="text-lg font-semibold text-neutral-800">{{ t('session_lock.checking_title') }}</h1>
        <p class="mt-2 text-sm text-neutral-500">
          {{ security.error === 'session_status_failed'
            ? t('session_lock.initial_status_failed')
            : t('session_lock.checking') }}
        </p>
        <button v-if="security.error === 'session_status_failed'" type="button"
                @click="security.refresh({ force: true })"
                class="mt-4 h-10 px-4 rounded-md bg-primary-600 hover:bg-primary-700 text-white font-medium">
          {{ t('session_lock.retry_status') }}
        </button>
      </div>
    </div>
    <Toaster />
  </div>
  <SessionLockOverlay />
  <WebAuthnCeremonyPrompt />
  <TableViewportScrollbars v-if="showRoutedContent && !privateContentCovered" />
</template>
