import { computed, onUnmounted, ref } from 'vue'
import {
  cancelImportJob,
  fetchImportJob,
  fetchImportJobReport,
  startImportJob,
  type FileImportJob,
  type ImportKind,
  type ImportReport,
  type PurchaseImportStatus,
} from '@/api/imports'

/**
 * Nahrání dokladů, které běží na pozadí.
 *
 * Dávka z jiného systému má běžně tisíce dokladů. Synchronní upload ji nepřežije a
 * jeho utnutí uprostřed je horší než selhání: doklady zůstanou založené, ale závěrečné
 * kroky importu (dorovnání číselných řad, přepočet statistik klientů) neproběhnou,
 * takže seznam klientů ukazuje stará čísla a další vystavená faktura dostane číslo,
 * které v importu už je.
 *
 * Obě strany dokladů (vydané i přijaté) sdílejí tenhle jeden průběh — liší se jen
 * `kind`, a proto tu není dvakrát.
 */
export function useFileImportJob(kind: ImportKind) {
  const purchaseStatus = ref<PurchaseImportStatus>('received')
  const files = ref<File[]>([])
  const running = ref(false)
  const error = ref('')
  const report = ref<ImportReport | null>(null)
  const job = ref<FileImportJob | null>(null)
  const cancelling = ref(false)

  let timer: ReturnType<typeof setTimeout> | null = null

  const percent = computed(() => {
    const j = job.value
    if (!j || !j.total_items) return null
    return Math.min(100, Math.round((j.processed / j.total_items) * 100))
  })

  function stopPolling() {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  function reset() {
    stopPolling()
    files.value = []
    report.value = null
    job.value = null
    error.value = ''
    running.value = false
    cancelling.value = false
  }

  function pick(list: FileList | null) {
    if (!list) return
    files.value = Array.from(list)
    report.value = null
    job.value = null
    error.value = ''
  }

  async function start(onError: (e: unknown) => string) {
    if (files.value.length === 0) return
    running.value = true
    error.value = ''
    report.value = null
    job.value = null
    try {
      const started = await startImportJob(files.value, kind, purchaseStatus.value)
      poll(started.job_id, onError)
    } catch (e) {
      error.value = onError(e)
      running.value = false
    }
  }

  function poll(jobId: number, onError: (e: unknown) => string) {
    // Job běží dál i bez nás, takže výpadek jednoho dotazu není důvod přestat se
    // ptát — jinak by uživateli import „zmizel" kvůli jednomu 502 z proxy.
    let consecutiveFailures = 0
    const tick = async () => {
      try {
        const j = await fetchImportJob(jobId)
        consecutiveFailures = 0
        job.value = j
        if (j.status === 'queued' || j.status === 'running') {
          timer = setTimeout(tick, 1500)
          return
        }
        // Zrušený běh report taky má — nese to, co se stihlo založit.
        if (j.status !== 'failed') {
          try {
            report.value = await fetchImportJobReport(jobId)
          } catch {
            // Report se nemusel uložit (nezapisovatelné úložiště); čísla z jobu
            // zůstávají, jen bez rozpisu po dokladech.
          }
        }
        if (j.status === 'failed') {
          error.value = j.last_error || onError(new Error('failed'))
        }
      } catch (e) {
        consecutiveFailures++
        if (consecutiveFailures < 5) {
          timer = setTimeout(tick, 3000)
        } else {
          error.value = onError(e)
        }
      } finally {
        if (timer === null) {
          running.value = false
          cancelling.value = false
        }
      }
    }
    timer = null
    void tick()
  }

  async function cancel() {
    const id = job.value?.id
    if (!id || cancelling.value) return
    cancelling.value = true
    try {
      await cancelImportJob(id)
    } catch {
      cancelling.value = false
    }
  }

  onUnmounted(stopPolling)

  return { files, running, cancelling, error, report, job, percent, purchaseStatus, pick, start, cancel, reset }
}
