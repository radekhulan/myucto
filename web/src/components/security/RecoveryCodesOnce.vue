<script setup lang="ts">
/**
 * Jednorázové zobrazení čerstvě vydané sady záložních kódů.
 *
 * ⚠️ Server plaintext neukládá, takže tohle je JEDINÁ příležitost, kdy je
 * uživatel uvidí. Panel proto nejde přejít mimochodem: dokud nepotvrdí, že je
 * má uložené, pokračovat nelze.
 *
 * Liší se od {@link RecoveryCodes.vue} tím, že sadu nevydává — jen ukazuje tu,
 * kterou vrátilo zapnutí druhého faktoru. Generování si žádá silný faktor,
 * který v tu chvíli uživatel teprve zakládá.
 */
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ codes: string[]; busy?: boolean }>()
const emit = defineEmits<{ (e: 'confirm'): void }>()

const { t } = useI18n()
const toast = useToast()

function copy() {
  navigator.clipboard?.writeText(props.codes.join('\n'))
    .then(() => toast.success(t('recovery_codes.copied')))
    .catch(() => toast.error(t('common.error')))
}

function download() {
  // Blob, ne data: URI — kódy nemají co pohledávat v adresním řádku ani
  // v historii prohlížeče.
  const blob = new Blob([props.codes.join('\n') + '\n'], { type: 'text/plain;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'myucto-zalozni-kody.txt'
  a.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="rounded-lg border-2 border-warning-500/60 bg-warning-50/40 p-4" data-recovery-once>
    <h3 class="text-sm font-semibold text-neutral-900">{{ t('recovery_codes.once_title') }}</h3>
    <p class="mt-1 text-sm text-neutral-700">{{ t('recovery_codes.once_desc') }}</p>

    <ul class="mt-3 grid grid-cols-1 gap-x-6 gap-y-1 font-mono text-sm text-neutral-900 sm:grid-cols-2">
      <li v-for="code in codes" :key="code">{{ code }}</li>
    </ul>

    <div class="mt-4 flex flex-wrap gap-2">
      <button type="button" class="rounded-md border border-neutral-300 px-3 py-1.5 text-sm" @click="download">
        {{ t('recovery_codes.download') }}
      </button>
      <button type="button" class="rounded-md border border-neutral-300 px-3 py-1.5 text-sm" @click="copy">
        {{ t('recovery_codes.copy') }}
      </button>
      <button
        type="button"
        class="ml-auto rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-60"
        :disabled="busy"
        data-recovery-once-confirm
        @click="emit('confirm')"
      >
        {{ t('recovery_codes.saved') }}
      </button>
    </div>
  </div>
</template>
