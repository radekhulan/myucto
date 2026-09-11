<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { backupsApi, type BackupFile, type BackupOverview } from '@/api/backups'
import { authApi } from '@/api/auth'
import { apiErrorMessage } from '@/api/errors'
import { getCredential, isWebAuthnAvailable } from '@/security/webauthn'
import { useToast } from '@/composables/useToast'
import { formatDateTime } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

/**
 * Historie automatických záloh ke stažení z prohlížeče.
 *
 * Protějšek „Kompletního exportu dat": ten vyrobí balíček aktuálního stavu jedné
 * firmy na vyžádání, tady leží to, co noční crony odkládají samy — a k čemu se
 * dosud dalo dostat jen přes SSH.
 *
 * Heslo k šifrovaným ZIPům se nenačítá s přehledem. Chodí se pro něj zvlášť a až
 * proti čerstvému ověření, takže ho neuvidí ani otevřená session na cizím stroji.
 */

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const overview = ref<BackupOverview | null>(null)
const loading = ref(false)
const downloading = ref<string | null>(null)

const passwordModalOpen = ref(false)
const revealing = ref(false)
const revealedPassword = ref<string | null>(null)
const password = ref('')
const totpCode = ref('')
const passkeyToken = ref('')
const passkeyBusy = ref(false)
const passkeySupported = isWebAuthnAvailable()

const hasPasskey = computed(() =>
  auth.user?.mfa_methods?.includes('passkey') === true
  || (auth.user?.passkey_count ?? 0) > 0,
)
const sections = computed(() => overview.value?.sections ?? [])
const canReveal = computed(() => passkeyToken.value !== '' || password.value !== '')

async function load() {
  loading.value = true
  try {
    overview.value = await backupsApi.overview()
  } catch (e: unknown) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function download(file: BackupFile) {
  downloading.value = file.name
  try {
    const r = await backupsApi.download(file.name)
    const url = URL.createObjectURL(r.data as unknown as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = file.name
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e: unknown) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    downloading.value = null
  }
}

function openPasswordModal() {
  password.value = ''
  totpCode.value = ''
  passkeyToken.value = ''
  revealedPassword.value = null
  passwordModalOpen.value = true
}

function closePasswordModal() {
  passwordModalOpen.value = false
  // Heslo nesmí zůstat viset v paměti komponenty do dalšího otevření.
  revealedPassword.value = null
  password.value = ''
  totpCode.value = ''
  passkeyToken.value = ''
}

async function verifyPasskey() {
  if (!passkeySupported) return
  passkeyBusy.value = true
  try {
    const flow = await authApi.passkeyStepUpOptions('backup.password')
    const credential = await getCredential(flow.public_key)
    passkeyToken.value = await authApi.passkeyStepUpVerify(flow.flow_token, 'backup.password', credential)
  } catch (e: unknown) {
    toast.error(apiErrorMessage(e, t('backups.passkey_failed')))
  } finally {
    passkeyBusy.value = false
  }
}

async function reveal() {
  revealing.value = true
  try {
    const result = await backupsApi.revealPassword({
      password: password.value || undefined,
      totp_code: totpCode.value.trim() || undefined,
      step_up_token: passkeyToken.value || undefined,
    })
    revealedPassword.value = result.password
    if (!result.encrypted) toast.error(t('backups.not_encrypted'))
  } catch (e: unknown) {
    // Proof i heslo jsou jednorázové — po chybě se musí zadat znovu.
    passkeyToken.value = ''
    password.value = ''
    totpCode.value = ''
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    revealing.value = false
  }
}

async function copyPassword() {
  if (!revealedPassword.value) return
  try {
    await navigator.clipboard.writeText(revealedPassword.value)
    toast.success(t('backups.password_copied'))
  } catch {
    toast.error(t('common.error'))
  }
}

function formatBytes(bytes: number | null): string {
  if (!bytes) return '—'
  const units = ['B', 'kB', 'MB', 'GB', 'TB']
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  return `${(bytes / 1024 ** i).toFixed(i ? 1 : 0)} ${units[i]}`
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('backups.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('backups.subtitle') }}</p>
    </div>

    <div class="bg-primary-50/50 border border-primary-100 rounded-lg p-3 mb-4 text-sm text-neutral-700">
      {{ t('backups.info') }}
      <RouterLink to="/admin/instance-export" class="text-primary-700 hover:text-primary-800 font-medium">
        {{ t('backups.info_export_link') }}
      </RouterLink>
    </div>

    <!-- Šifrování: buď je heslo k dispozici na vyžádání, nebo musí být hlasitě vidět, že chybí. -->
    <div v-if="overview?.encrypted"
      class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4 flex flex-wrap items-center justify-between gap-3">
      <div class="text-sm text-neutral-700">
        <span class="font-medium text-neutral-800">{{ t('backups.encrypted_title') }}</span>
        <span class="block text-xs text-neutral-500 mt-0.5">{{ t('backups.encrypted_hint') }}</span>
      </div>
      <button type="button" :class="btnOutline('primary')" @click="openPasswordModal">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" />
        </svg>
        <span class="whitespace-nowrap">{{ t('backups.show_password') }}</span>
      </button>
    </div>
    <div v-else-if="overview"
      class="bg-warning-50 border border-warning-200 rounded-lg p-3 mb-4 text-sm text-warning-800">
      {{ t('backups.not_encrypted_warning') }}
    </div>

    <div v-if="overview" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4">
      <dl class="grid grid-cols-1 sm:grid-cols-4 gap-3 text-sm">
        <div class="sm:col-span-2 min-w-0">
          <dt class="text-xs text-neutral-500">{{ t('backups.directory') }}</dt>
          <dd class="font-mono text-xs break-all">{{ overview.directory }}</dd>
        </div>
        <div>
          <dt class="text-xs text-neutral-500">{{ t('backups.total_files') }}</dt>
          <dd class="font-mono">{{ overview.total_files }}</dd>
        </div>
        <div>
          <dt class="text-xs text-neutral-500">{{ t('backups.total_size') }}</dt>
          <dd class="font-mono">{{ formatBytes(overview.total_size_bytes) }}</dd>
        </div>
      </dl>
      <p class="text-xs text-neutral-500 mt-3">{{ t('backups.retention', { policy: overview.retention }) }}</p>
    </div>

    <div v-if="loading && !overview" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="sections.length === 0" boxed icon="archive"
      :title="t('backups.empty')" :message="t('backups.empty_hint')" />

    <div v-else class="space-y-4">
      <section v-for="section in sections" :key="section.kind"
        class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-neutral-200 flex flex-wrap items-baseline justify-between gap-2">
          <div>
            <h2 class="text-sm font-medium text-neutral-800">{{ t(`backups.kind.${section.kind}`) }}</h2>
            <p class="text-xs text-neutral-500 mt-0.5">{{ t(`backups.kind_hint.${section.kind}`) }}</p>
          </div>
          <span class="text-xs text-neutral-500 whitespace-nowrap">
            {{ section.total_files > section.files.length
              ? t('backups.section_summary_latest', { shown: section.files.length, count: section.total_files, size: formatBytes(section.size_bytes) })
              : t('backups.section_summary', { count: section.total_files, size: formatBytes(section.size_bytes) }) }}
          </span>
        </div>
        <div class="overflow-auto max-h-96">
          <table class="w-full min-w-[40rem] text-sm">
            <thead class="sticky top-0 bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-4 py-2 text-left font-medium">{{ t('backups.col_file') }}</th>
                <th class="px-4 py-2 text-left font-medium w-44">{{ t('backups.col_taken') }}</th>
                <th class="px-4 py-2 text-right font-medium w-28">{{ t('backups.col_size') }}</th>
                <th class="px-4 py-2 text-right font-medium w-36"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="file in section.files" :key="file.name" class="hover:bg-neutral-50">
                <td class="px-4 py-2 font-mono text-xs whitespace-nowrap">{{ file.name }}</td>
                <td class="px-4 py-2 whitespace-nowrap text-xs text-neutral-600">
                  {{ formatDateTime(file.taken_at ?? file.modified_at) }}
                </td>
                <td class="px-4 py-2 text-right font-mono whitespace-nowrap">{{ formatBytes(file.size_bytes) }}</td>
                <td class="px-4 py-2 text-right">
                  <button type="button" :class="btnOutlineSm('primary')"
                    :disabled="downloading === file.name" @click="download(file)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" />
                    </svg>
                    <span class="whitespace-nowrap">
                      {{ downloading === file.name ? t('backups.downloading') : t('backups.download') }}
                    </span>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <!-- Odhalení šifrovacího hesla — passkey, nebo heslo (+ TOTP). -->
    <div v-if="passwordModalOpen" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4"
      @click.self="closePasswordModal">
      <div class="w-full max-w-lg rounded-xl bg-surface shadow-xl border border-neutral-200">
        <div class="px-5 py-4 border-b border-neutral-200 flex items-start justify-between gap-3">
          <div>
            <h2 class="font-semibold">{{ t('backups.password_title') }}</h2>
            <p class="text-xs text-neutral-500 mt-1">{{ t('backups.password_hint') }}</p>
          </div>
          <button type="button" class="p-2 text-neutral-500 hover:text-neutral-900" @click="closePasswordModal">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
            </svg>
          </button>
        </div>

        <div v-if="revealedPassword" class="p-5 space-y-3">
          <div class="rounded-lg border border-success-500/30 bg-success-50 p-3">
            <div class="text-xs text-success-700 mb-1">{{ t('backups.password_label') }}</div>
            <code class="block text-sm font-mono break-all text-neutral-900">{{ revealedPassword }}</code>
          </div>
          <p class="text-xs text-neutral-500">{{ t('backups.password_usage') }}</p>
          <div class="flex flex-wrap gap-2">
            <button type="button" :class="btnOutline('primary')" @click="copyPassword">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.copy" />
              </svg>
              <span class="whitespace-nowrap">{{ t('backups.copy_password') }}</span>
            </button>
            <button type="button" :class="btnOutline('neutral')" @click="closePasswordModal">
              <span class="whitespace-nowrap">{{ t('common.close') }}</span>
            </button>
          </div>
        </div>

        <template v-else>
          <div class="p-5 space-y-4">
            <div v-if="hasPasskey" class="flex flex-wrap items-center gap-3">
              <div v-if="passkeyToken" class="text-sm font-medium text-success-600">
                ✓ {{ t('backups.passkey_verified') }}
              </div>
              <template v-else-if="passkeySupported">
                <button type="button" :class="btnOutlineSm('primary')" :disabled="passkeyBusy || revealing"
                  @click="verifyPasskey">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" />
                  </svg>
                  {{ passkeyBusy ? t('auth.passkey_verifying') : t('backups.verify_passkey') }}
                </button>
                <span class="text-xs text-neutral-500">{{ t('backups.or_password') }}</span>
              </template>
              <p v-else class="text-sm text-warning-700">{{ t('backups.passkey_unsupported') }}</p>
            </div>
            <template v-if="!passkeyToken">
              <label class="block text-sm">
                {{ t('backups.current_password') }}
                <input v-model="password" type="password" autocomplete="current-password"
                  class="mt-1 w-full h-10 rounded-md border border-neutral-300 bg-surface px-3">
              </label>
              <label class="block text-sm">
                {{ t('backups.totp_optional') }}
                <input v-model="totpCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                  class="mt-1 w-full h-10 rounded-md border border-neutral-300 bg-surface px-3">
              </label>
            </template>
          </div>
          <div class="px-5 py-4 border-t border-neutral-200 flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnOutline('neutral')" @click="closePasswordModal">
              <span class="whitespace-nowrap">{{ t('common.cancel') }}</span>
            </button>
            <button type="button" :class="btnFilled('primary')" :disabled="revealing || !canReveal" @click="reveal">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" />
              </svg>
              <span class="whitespace-nowrap">{{ revealing ? t('common.loading') : t('backups.show_password') }}</span>
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
