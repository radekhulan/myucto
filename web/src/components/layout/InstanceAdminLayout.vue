<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import ThemeToggle from './ThemeToggle.vue'
import LanguageToggle from './LanguageToggle.vue'

const { t } = useI18n()
const auth = useAuthStore()
const security = useSessionSecurityStore()
const router = useRouter()
const busy = ref(false)
const error = ref('')
async function logout() {
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
  <div class="min-h-screen bg-neutral-50 text-neutral-800">
    <header class="border-b border-neutral-200 bg-surface px-4 py-3">
      <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3">
        <RouterLink to="/instance-admin" class="font-semibold text-primary-700">{{ t('instance_admin.title') }}</RouterLink>
        <nav class="flex flex-wrap items-center gap-2" :aria-label="t('instance_admin.title')">
          <RouterLink to="/instance-admin" :class="btnOutline('neutral')"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('instance_admin.overview') }}</RouterLink>
          <RouterLink to="/instance-admin/profile" :class="btnOutline('neutral')"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('auth.profile_title') }}</RouterLink>
          <ThemeToggle /><LanguageToggle />
          <button :class="btnOutline('neutral')" :disabled="busy" @click="logout"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6H5v12h4m5-14h5v16h-5M9 12h8m-3-3 3 3-3 3" /></svg>{{ t('nav.logout') }}</button>
        </nav>
      </div>
    </header>
    <main class="mx-auto max-w-6xl p-4 sm:p-6">
      <p v-if="error" role="alert" class="mb-4 text-danger-600">{{ error }}</p>
      <RouterView />
    </main>
  </div>
</template>
