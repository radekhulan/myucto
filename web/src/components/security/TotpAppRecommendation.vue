<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const apps = [
  { name: 'Proton Authenticator', url: 'https://proton.me/authenticator' },
  { name: 'Ente Auth', url: 'https://ente.com/auth/' },
  { name: 'Google Authenticator', url: 'https://support.google.com/accounts/answer/1066447' },
  { name: 'Microsoft Authenticator', url: 'https://support.microsoft.com/cs-cz/authenticator/download-microsoft-authenticator' },
]
const MARK = '\u0000'
const parts = computed(() => t('auth.totp_setup_step1', { apps: MARK }).split(MARK))
</script>

<template>
  <p class="text-sm text-neutral-700" data-totp-apps>
    {{ parts[0] }}<template v-if="parts.length > 1"><template v-for="(app, i) in apps" :key="app.url"><template v-if="i">, </template><a :href="app.url" target="_blank" rel="noopener" class="text-primary-700 underline hover:no-underline">{{ app.name }}</a></template>{{ parts[1] }}</template>
  </p>
</template>
