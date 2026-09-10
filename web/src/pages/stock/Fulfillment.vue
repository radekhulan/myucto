<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useI18n } from "vue-i18n";
import {
  stockApi,
  type FulfillmentDisposition,
  type FulfillmentShipment,
  type FulfillmentTask,
  type FulfillmentTaskLine,
  type StockTrackingMode,
  type StockTrackingOverview,
  type Warehouse,
  type WarehouseLocation,
} from "@/api/stock";
import { useAuthStore } from "@/stores/auth";
import { useToast } from "@/composables/useToast";
import { ICONS, btnFilled, btnOutline } from "@/components/ui/buttonStyles";
import {
  isTracked,
  returnTrackingChoices,
  resetTrackingChoiceLocations,
  trackingChoiceKey,
  trackingPayload,
  type TrackingChoice,
} from "./fulfillmentTracking";

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const toast = useToast();
const tasks = ref<FulfillmentTask[]>([]);
const task = ref<FulfillmentTask | null>(null);
const warehouses = ref<Warehouse[]>([]);
const loading = ref(false);
const sourceType = ref<FulfillmentTask["source_type"]>("stock_issue_draft");
const sourceId = ref("");
const scanCode = ref("");
const scanOperationId = ref("");
const scanBusy = ref(false);
const carrier = ref("");
const tracking = ref("");
const shipmentQty = ref<Record<number, string>>({});
const packingBusy = ref(false);
const dispatchBusyId = ref<number | null>(null);
const trackingOverview = ref<Record<number, StockTrackingOverview>>({});
const trackingErrors = ref<Record<number, string>>({});
const trackingLoading = ref<Record<number, boolean>>({});
const shipmentTracking = ref<Record<number, TrackingChoice[]>>({});
const returnShipment = ref<FulfillmentShipment | null>(null);
const returnDisposition = ref<FulfillmentDisposition>("sellable");
const returnWarehouseId = ref("");
const returnQty = ref<Record<number, string>>({});
const returnTracking = ref<Record<number, TrackingChoice[]>>({});
const returnLocations = ref<WarehouseLocation[]>([]);
const returnLocationsLoading = ref(false);
const returnLocationsError = ref("");
const returnNote = ref("");
const returnBusy = ref(false);
let trackingGeneration = 0;
let returnLocationGeneration = 0;
const canWrite = computed(() =>
  auth.canWrite(
    "stock.fulfillment.write" as Parameters<typeof auth.canWrite>[0],
  ),
);
const availableWarehouses = computed(() =>
  warehouses.value.filter(
    (w) =>
      w.is_active &&
      (returnDisposition.value === "sellable" ? w.is_sellable : !w.is_sellable),
  ),
);

function errorMessage(e: any) {
  const code = e?.response?.data?.error?.code;
  const translated = code ? t(code) : code;
  return translated && translated !== code
    ? translated
    : e?.response?.data?.error?.message || t("common.error");
}
function trackingMessage(error: unknown) {
  return error instanceof Error && error.message === "tracking-total-mismatch"
    ? t("stock.fulfillment.tracking_total_mismatch")
    : error instanceof Error && error.message === "invalid-serial-quantity"
      ? t("stock.fulfillment.serial_quantity_invalid")
      : t("stock.fulfillment.tracking_invalid");
}
function remainingPicked(line: FulfillmentTaskLine) {
  const packed = (task.value?.shipments || [])
    .filter((shipment) => shipment.status === "packing")
    .flatMap((shipment) => shipment.items)
    .filter((item) => item.task_line_id === line.id)
    .reduce((sum, item) => sum + Number(item.qty), 0);
  return Math.max(
    0,
    Number(line.picked_qty) - Number(line.shipped_qty) - packed,
  ).toFixed(3);
}
function trackingMode(lineId: number): StockTrackingMode | undefined {
  return (
    trackingOverview.value[lineId]?.tracking_mode ??
    (trackingLoading.value[lineId] || trackingErrors.value[lineId]
      ? "lot"
      : undefined)
  );
}
function shipmentChoices(lineId: number) {
  return shipmentTracking.value[lineId] ?? [];
}
function visibleShipmentChoices(line: FulfillmentTaskLine) {
  return shipmentChoices(line.id).filter(
    (choice) => choice.available !== "0.000",
  );
}
function choiceLabel(choice: TrackingChoice) {
  return (
    choice.serial_number ||
    choice.lot_code ||
    `#${choice.stock_tracking_unit_id}`
  );
}
function locationLabel(choice: TrackingChoice) {
  const line = (task.value?.lines ?? []).find((candidate) =>
    shipmentChoices(candidate.id).includes(choice),
  );
  const row = line
    ? trackingOverview.value[line.id]?.inventory.find(
        (unit) => trackingChoiceKey(unit) === choice.key,
      )
    : null;
  return row?.location_code || t("stock.tracking.no_location");
}

function resetReturnLocations() {
  returnTracking.value = Object.fromEntries(
    Object.entries(returnTracking.value).map(([itemId, choices]) => [
      itemId,
      resetTrackingChoiceLocations(choices),
    ]),
  );
}

async function loadReturnLocations() {
  const generation = ++returnLocationGeneration;
  const warehouseId = Number(returnWarehouseId.value);
  resetReturnLocations();
  returnLocations.value = [];
  returnLocationsError.value = "";
  if (returnDisposition.value === "scrap" || warehouseId <= 0) return;
  returnLocationsLoading.value = true;
  try {
    const locations = await stockApi.listLocations(warehouseId);
    if (generation !== returnLocationGeneration) return;
    returnLocations.value = locations.filter((location) => location.is_active);
  } catch (error) {
    if (generation === returnLocationGeneration)
      returnLocationsError.value = errorMessage(error);
  } finally {
    if (generation === returnLocationGeneration)
      returnLocationsLoading.value = false;
  }
}

async function loadTaskTracking(current: FulfillmentTask) {
  const lines = current.lines ?? [];
  const generation = ++trackingGeneration;
  trackingOverview.value = {};
  trackingErrors.value = {};
  shipmentTracking.value = {};
  trackingLoading.value = Object.fromEntries(
    lines.map((line) => [line.id, true]),
  );
  const byItem = new Map<number, Promise<StockTrackingOverview>>();
  const overviewFor = (itemId: number) =>
    byItem.get(itemId) ??
    (byItem.set(itemId, stockApi.itemTracking(itemId)), byItem.get(itemId)!);
  await Promise.all(
    lines.map(async (line) => {
      try {
        const overview = await overviewFor(line.stock_item_id);
        if (generation !== trackingGeneration) return;
        trackingOverview.value[line.id] = overview;
        shipmentTracking.value[line.id] = overview.inventory
          .filter(
            (unit) =>
              unit.warehouse_id === line.warehouse_id &&
              Number(unit.quantity) > 0,
          )
          .map((unit) => ({
            key: trackingChoiceKey(unit),
            stock_tracking_unit_id: unit.stock_tracking_unit_id,
            serial_number: unit.serial_number,
            lot_code: unit.lot_code,
            expires_on: unit.expires_on,
            location_id: unit.location_id,
            available: unit.quantity,
            quantity: "0.000",
          }));
      } catch (error) {
        if (generation === trackingGeneration)
          trackingErrors.value[line.id] = errorMessage(error);
      } finally {
        if (generation === trackingGeneration)
          trackingLoading.value[line.id] = false;
      }
    }),
  );
}
async function load() {
  loading.value = true;
  try {
    [tasks.value, warehouses.value] = await Promise.all([
      stockApi.listFulfillmentTasks(),
      stockApi.listWarehouses(true),
    ]);
    const id = Number(route.query.task || 0);
    if (id > 0) await openTask(id, false);
    if (id <= 0) {
      sourceType.value =
        route.query.source_type === "sales_order"
          ? "sales_order"
          : "stock_issue_draft";
      const querySourceId = route.query.source_id;
      sourceId.value = Array.isArray(querySourceId)
        ? querySourceId[0] || ""
        : querySourceId || "";
    }
  } catch (e: any) {
    toast.error(errorMessage(e));
  } finally {
    loading.value = false;
  }
}
async function openTask(id: number, navigate = true) {
  const next = await stockApi.getFulfillmentTask(id);
  task.value = next;
  tasks.value = tasks.value.map((row) => row.id === next.id ? { ...row, status: next.status } : row);
  if (navigate) await router.replace({ query: { task: String(id) } });
  shipmentQty.value = Object.fromEntries(
    (next.lines ?? []).map((line) => [line.id, remainingPicked(line)]),
  );
  void loadTaskTracking(next);
}
async function createTask() {
  const id = String(sourceId.value).trim();
  const validSource =
    sourceType.value === "sales_order"
      ? /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
          id,
        )
      : /^\d+$/.test(id) && Number(id) > 0;
  if (!validSource) {
    toast.error(t("stock.fulfillment.source_invalid"));
    return;
  }
  try {
    const created = await stockApi.createFulfillmentTask(id, sourceType.value);
    sourceId.value = "";
    await load();
    await openTask(created.id);
    toast.success(t("stock.fulfillment.created"));
  } catch (e: any) {
    toast.error(errorMessage(e));
  }
}
async function scan() {
  if (!task.value || !scanCode.value.trim()) return;
  scanBusy.value = true;
  try {
    scanOperationId.value ||= crypto.randomUUID();
    await stockApi.scanFulfillmentItem(task.value.id, {
      client_operation_id: scanOperationId.value,
      code: scanCode.value.trim(),
      quantity: "1.000",
    });
    scanCode.value = "";
    scanOperationId.value = "";
    await openTask(task.value.id, false);
    toast.success(t("stock.fulfillment.scanned"));
  } catch (e: any) {
    toast.error(errorMessage(e));
  } finally {
    scanBusy.value = false;
  }
}

async function createShipment() {
  if (!task.value || packingBusy.value) return;
  const items = [] as Array<{
    task_line_id: number;
    quantity: string;
    tracking_allocations?: ReturnType<typeof trackingPayload>;
  }>;
  try {
    for (const line of task.value.lines ?? []) {
      const quantity = shipmentQty.value[line.id] || "0";
      if (Number(quantity) <= 0) continue;
      if (trackingErrors.value[line.id])
        throw new Error("tracking-overview-failed");
      if (trackingLoading.value[line.id] || !trackingMode(line.id))
        throw new Error("tracking-overview-loading");
      const mode = trackingMode(line.id)!;
      items.push({
        task_line_id: line.id,
        quantity,
        tracking_allocations: isTracked(mode)
          ? trackingPayload(mode, quantity, shipmentChoices(line.id))
          : undefined,
      });
    }
    if (!items.length) return;
  } catch (error) {
    toast.error(
      error instanceof Error && error.message === "tracking-overview-loading"
        ? t("stock.fulfillment.tracking_loading")
        : error instanceof Error && error.message === "tracking-overview-failed"
          ? t("stock.fulfillment.tracking_unavailable")
          : trackingMessage(error),
    );
    return;
  }
  packingBusy.value = true;
  try {
    await stockApi.createFulfillmentShipment(task.value.id, {
      carrier: carrier.value,
      tracking_number: tracking.value,
      items,
    });
    carrier.value = "";
    tracking.value = "";
    await openTask(task.value.id, false);
    toast.success(t("stock.fulfillment.shipment_created"));
  } catch (e: any) {
    toast.error(errorMessage(e));
  } finally {
    packingBusy.value = false;
  }
}
async function dispatch(shipment: FulfillmentShipment) {
  if (dispatchBusyId.value !== null) return;
  dispatchBusyId.value = shipment.id;
  try {
    const dispatched = await stockApi.dispatchFulfillmentShipment(shipment.id);
    await load();
    await openTask(dispatched.id, false);
    toast.success(t("stock.fulfillment.dispatched"));
  } catch (e: any) {
    toast.error(errorMessage(e));
  } finally {
    dispatchBusyId.value = null;
  }
}
function returnMode(
  item: FulfillmentShipment["items"][number],
): StockTrackingMode {
  return item.tracking_snapshot.some((allocation) => allocation.serial_number)
    ? "serial"
    : "lot";
}
function beginReturn(shipment: FulfillmentShipment) {
  returnShipment.value = shipment;
  returnDisposition.value = "sellable";
  returnWarehouseId.value = String(
    warehouses.value.find((w) => w.is_active && w.is_sellable)?.id || "",
  );
  returnQty.value = Object.fromEntries(
    shipment.items.map((item) => [
      item.id,
      Math.max(0, Number(item.qty) - Number(item.returned_qty)).toFixed(3),
    ]),
  );
  returnTracking.value = Object.fromEntries(
    shipment.items
      .filter((item) => item.tracking_snapshot.length > 0)
      .map((item) => [
        item.id,
        returnTrackingChoices(
          item.tracking_snapshot,
          shipment.returns,
          item.id,
        ),
      ]),
  );
  returnNote.value = "";
  void loadReturnLocations();
}
async function receiveReturn() {
  if (!task.value || !returnShipment.value || returnBusy.value) return;
  const shipment = returnShipment.value;
  const items = [] as Array<{
    shipment_item_id: number;
    quantity: string;
    tracking_allocations?: ReturnType<typeof trackingPayload>;
  }>;
  try {
    for (const item of shipment.items) {
      const quantity = returnQty.value[item.id] || "0";
      if (Number(quantity) <= 0) continue;
      items.push({
        shipment_item_id: item.id,
        quantity,
        tracking_allocations:
          item.tracking_snapshot.length > 0
            ? trackingPayload(
                returnMode(item),
                quantity,
                returnTracking.value[item.id] ?? [],
              )
            : undefined,
      });
    }
    if (!items.length) return;
  } catch (error) {
    toast.error(trackingMessage(error));
    return;
  }
  returnBusy.value = true;
  try {
    await stockApi.receiveFulfillmentReturn(shipment.id, {
      disposition: returnDisposition.value,
      warehouse_id:
        returnDisposition.value === "scrap"
          ? undefined
          : Number(returnWarehouseId.value),
      note: returnNote.value,
      items,
    });
    returnShipment.value = null;
    await openTask(task.value.id, false);
    toast.success(t("stock.fulfillment.return_received"));
  } catch (e: any) {
    toast.error(errorMessage(e));
  } finally {
    returnBusy.value = false;
  }
}
watch(returnDisposition, (disposition) => {
  if (!returnShipment.value) return;
  returnWarehouseId.value =
    disposition === "scrap"
      ? ""
      : String(availableWarehouses.value[0]?.id || "");
  void loadReturnLocations();
});
watch(returnWarehouseId, () => {
  if (returnShipment.value) void loadReturnLocations();
});
onMounted(load);
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">
          {{ t("stock.fulfillment.title") }}
        </h1>
        <p class="text-sm text-neutral-500 mt-0.5">
          {{ t("stock.fulfillment.subtitle") }}
        </p>
      </div>
      <form
        v-if="canWrite"
        class="flex flex-wrap items-end gap-2"
        @submit.prevent="createTask"
      >
        <label class="text-xs text-neutral-500"
          >{{ t("stock.fulfillment.source_type") }}
          <select
            v-model="sourceType"
            class="block mt-1 h-9 px-2 border border-neutral-300 rounded-md text-sm"
          >
            <option value="stock_issue_draft">
              {{ t("stock.fulfillment.source_type_draft") }}
            </option>
            <option value="sales_order">
              {{ t("stock.fulfillment.source_type_order") }}
            </option>
          </select>
        </label>
        <label class="text-xs text-neutral-500"
          >{{
            sourceType === "sales_order"
              ? t("stock.fulfillment.source_order")
              : t("stock.fulfillment.source_document")
          }}<input
            v-model="sourceId"
            :type="sourceType === 'sales_order' ? 'text' : 'number'"
            :min="sourceType === 'sales_order' ? undefined : '1'"
            :placeholder="
              sourceType === 'sales_order'
                ? t('stock.fulfillment.order_uuid')
                : undefined
            "
            required
            class="block mt-1 w-36 h-9 px-2 border border-neutral-300 rounded-md text-sm" /></label
        ><button type="submit" :class="btnFilled('primary')">
          <svg
            class="w-4 h-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              :d="ICONS.plus"
            /></svg
          >{{ t("stock.fulfillment.create") }}
        </button>
      </form>
    </div>
    <div v-if="loading" class="py-10 text-center text-neutral-500">
      {{ t("common.loading") }}
    </div>
    <div v-else class="grid gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
      <div
        class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden"
      >
        <button
          v-for="row in tasks"
          :key="row.id"
          type="button"
          class="w-full text-left px-3 py-3 border-b border-neutral-100 hover:bg-neutral-50"
          :class="{ 'bg-primary-50': task?.id === row.id }"
          @click="openTask(row.id)"
        >
          <div class="flex justify-between gap-2">
            <span class="font-medium">#{{ row.id }}</span
            ><span class="text-xs text-neutral-500">{{
              t(`stock.fulfillment.status.${row.status}`)
            }}</span>
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t("stock.fulfillment.source_short", { id: row.source_id }) }}
          </div>
          <div class="text-xs mt-1">
            {{ row.shipped_qty || "0.000" }} / {{ row.expected_qty || "0.000" }}
          </div>
        </button>
        <div v-if="tasks.length === 0" class="p-6 text-sm text-neutral-500">
          {{ t("stock.fulfillment.empty") }}
        </div>
      </div>
      <div v-if="task" class="space-y-4 min-w-0">
        <section
          class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4"
        >
          <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <h2 class="font-semibold">
              {{ t("stock.fulfillment.task", { id: task.id }) }}
            </h2>
            <span
              class="text-xs px-2 py-1 rounded bg-accent-50 text-accent-700"
              >{{ t(`stock.fulfillment.status.${task.status}`) }}</span
            >
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-xs text-neutral-500">
                <tr>
                  <th class="text-left py-2">
                    {{ t("stock.fulfillment.item") }}
                  </th>
                  <th class="text-right">
                    {{ t("stock.fulfillment.expected") }}
                  </th>
                  <th class="text-right">
                    {{ t("stock.fulfillment.picked") }}
                  </th>
                  <th class="text-right">
                    {{ t("stock.fulfillment.shipped") }}
                  </th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="line in task.lines" :key="line.id">
                  <td class="py-2">
                    <div class="font-mono">
                      {{ line.component_snapshot.sku }}
                    </div>
                    <div class="text-xs text-neutral-500">
                      {{ line.component_snapshot.name }}
                    </div>
                  </td>
                  <td class="text-right tabular-nums">
                    {{ line.expected_qty }}
                  </td>
                  <td class="text-right tabular-nums">{{ line.picked_qty }}</td>
                  <td class="text-right tabular-nums">
                    {{ line.shipped_qty }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <form
            v-if="canWrite && task.status !== 'shipped'"
            class="mt-4 flex flex-wrap gap-2"
            @submit.prevent="scan"
          >
            <input
              v-model="scanCode"
              autofocus
              autocomplete="off"
              enterkeyhint="done"
              :placeholder="t('stock.fulfillment.scan_placeholder')"
              class="h-11 flex-1 min-w-48 px-3 border-2 border-primary-300 rounded-md text-base"
            /><button
              type="submit"
              :disabled="scanBusy"
              :class="btnFilled('success')"
            >
              <svg
                class="w-4 h-4"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  :d="ICONS.check"
                /></svg
              >{{ t("stock.fulfillment.scan") }}
            </button>
          </form>
        </section>
        <section
          v-if="canWrite && task.status !== 'shipped'"
          class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4"
        >
          <h2 class="font-semibold mb-3">
            {{ t("stock.fulfillment.new_shipment") }}
          </h2>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="text-xs text-neutral-500"
              >{{ t("stock.fulfillment.carrier")
              }}<input
                v-model="carrier"
                required
                class="block mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" /></label
            ><label class="text-xs text-neutral-500"
              >{{ t("stock.fulfillment.tracking")
              }}<input
                v-model="tracking"
                required
                class="block mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md text-sm"
            /></label>
          </div>
          <div class="mt-3 space-y-3">
            <div
              v-for="line in task.lines"
              :key="line.id"
              class="rounded-md border border-neutral-200 p-3"
            >
              <label
                class="flex flex-wrap items-center justify-between gap-3 text-sm"
                ><span
                  >{{ line.component_snapshot.sku }} -
                  {{ line.component_snapshot.name }}</span
                ><input
                  v-model="shipmentQty[line.id]"
                  inputmode="decimal"
                  class="w-28 h-9 px-2 text-right border border-neutral-300 rounded-md"
              /></label>
              <div
                v-if="
                  Number(shipmentQty[line.id] || 0) > 0 &&
                  isTracked(trackingMode(line.id))
                "
                class="mt-3 rounded border border-primary-100 bg-primary-50/30 p-3"
              >
                <div
                  class="flex flex-wrap items-center justify-between gap-2 text-xs"
                >
                  <strong>{{
                    t("stock.fulfillment.tracking_allocation")
                  }}</strong
                  ><span class="text-neutral-500">{{
                    t("stock.fulfillment.tracking_source_hint")
                  }}</span>
                </div>
                <div
                  v-if="trackingLoading[line.id]"
                  class="mt-2 text-sm text-neutral-500"
                >
                  {{ t("stock.fulfillment.tracking_loading") }}
                </div>
                <div
                  v-else-if="trackingErrors[line.id]"
                  class="mt-2 text-sm text-danger-600"
                >
                  {{ t("stock.fulfillment.tracking_unavailable") }}:
                  {{ trackingErrors[line.id] }}
                </div>
                <div
                  v-else-if="visibleShipmentChoices(line).length === 0"
                  class="mt-2 text-sm text-warning-600"
                >
                  {{ t("stock.fulfillment.tracking_empty") }}
                </div>
                <div v-else class="mt-2 space-y-2">
                  <div
                    v-for="choice in visibleShipmentChoices(line)"
                    :key="choice.key"
                    class="flex flex-wrap items-center justify-between gap-2 text-sm"
                  >
                    <span class="min-w-40 font-mono">{{
                      choiceLabel(choice)
                    }}</span
                    ><span class="text-xs text-neutral-500"
                      >{{ locationLabel(choice) }} ·
                      {{
                        t("stock.fulfillment.available", {
                          quantity: choice.available,
                        })
                      }}</span
                    ><label class="flex items-center gap-2"
                      ><input
                        v-if="trackingMode(line.id) === 'serial'"
                        v-model="choice.quantity"
                        :aria-label="choiceLabel(choice)"
                        type="checkbox"
                        true-value="1.000"
                        false-value="0.000"
                      /><input
                        v-else
                        v-model="choice.quantity"
                        :aria-label="choiceLabel(choice)"
                        inputmode="decimal"
                        class="w-24 h-8 px-2 text-right border border-neutral-300 rounded-md"
                      /><span
                        v-if="trackingMode(line.id) !== 'serial'"
                        class="text-xs text-neutral-500"
                        >{{ line.component_snapshot.unit }}</span
                      ></label
                    >
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="mt-3 flex flex-wrap justify-end">
            <button
              type="button"
              :disabled="packingBusy"
              :class="btnFilled('primary')"
              @click="createShipment"
            >
              <svg
                class="w-4 h-4"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  :d="ICONS.plus"
                /></svg
              >{{ t("stock.fulfillment.pack") }}
            </button>
          </div>
        </section>
        <section
          v-for="shipment in task.shipments"
          :key="shipment.id"
          class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 class="font-semibold">
                {{ t("stock.fulfillment.shipment", { id: shipment.id }) }}
              </h2>
              <p class="text-xs text-neutral-500">
                {{ shipment.carrier }} · {{ shipment.tracking_number }}
              </p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button
                v-if="canWrite && shipment.status === 'packing'"
                type="button"
                :disabled="dispatchBusyId === shipment.id"
                :class="btnFilled('success')"
                @click="dispatch(shipment)"
              >
                <svg
                  class="w-4 h-4"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="2"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    :d="ICONS.check"
                  /></svg
                >{{ t("stock.fulfillment.dispatch") }}</button
              ><button
                v-if="canWrite && shipment.status === 'shipped'"
                type="button"
                :class="btnOutline('warning')"
                @click="beginReturn(shipment)"
              >
                <svg
                  class="w-4 h-4"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke="currentColor"
                  stroke-width="2"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    :d="ICONS.uturn"
                  /></svg
                >{{ t("stock.fulfillment.return") }}
              </button>
            </div>
          </div>
          <ul class="mt-3 text-sm space-y-1">
            <li
              v-for="item in shipment.items"
              :key="item.id"
              class="flex justify-between gap-3"
            >
              <span>{{ item.component_snapshot.sku }}</span
              ><span
                >{{ item.qty }} ({{ t("stock.fulfillment.returned") }}
                {{ item.returned_qty }})</span
              >
            </li>
          </ul>
          <div
            v-for="ret in shipment.returns"
            :key="ret.id"
            class="mt-2 text-xs text-neutral-500"
          >
            {{
              t("stock.fulfillment.return_record", {
                id: ret.id,
                disposition: t(
                  `stock.fulfillment.disposition.${ret.disposition}`,
                ),
              })
            }}
          </div>
        </section>
      </div>
      <div
        v-else
        class="bg-surface border border-neutral-200 rounded-lg p-8 text-center text-neutral-500"
      >
        {{ t("stock.fulfillment.select_task") }}
      </div>
    </div>
    <div
      v-if="returnShipment"
      class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4"
    >
      <div
        class="bg-surface rounded-lg shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto p-4 space-y-3"
      >
        <h2 class="font-semibold">
          {{
            t("stock.fulfillment.return_shipment", { id: returnShipment.id })
          }}
        </h2>
        <select
          v-model="returnDisposition"
          class="w-full h-9 px-2 border border-neutral-300 rounded-md"
        >
          <option value="sellable">
            {{ t("stock.fulfillment.disposition.sellable") }}
          </option>
          <option value="quarantine">
            {{ t("stock.fulfillment.disposition.quarantine") }}
          </option>
          <option value="scrap">
            {{ t("stock.fulfillment.disposition.scrap") }}
          </option></select
        ><select
          v-if="returnDisposition !== 'scrap'"
          v-model="returnWarehouseId"
          class="w-full h-9 px-2 border border-neutral-300 rounded-md"
        >
          <option value="">
            {{ t("stock.fulfillment.choose_warehouse") }}
          </option>
          <option
            v-for="warehouse in availableWarehouses"
            :key="warehouse.id"
            :value="String(warehouse.id)"
          >
            {{ warehouse.code }} - {{ warehouse.name }}
          </option>
        </select>
        <div
          v-for="item in returnShipment.items"
          :key="item.id"
          class="rounded-md border border-neutral-200 p-3"
        >
          <label class="flex justify-between items-center gap-3 text-sm"
            ><span>{{ item.component_snapshot.sku }}</span
            ><input
              v-model="returnQty[item.id]"
              inputmode="decimal"
              class="w-28 h-9 px-2 text-right border border-neutral-300 rounded-md"
          /></label>
          <div v-if="item.tracking_snapshot.length > 0" class="mt-3 space-y-2">
            <p class="text-xs text-neutral-500">
              {{ t("stock.fulfillment.return_tracking_hint") }}
            </p>
            <p v-if="returnLocationsLoading" class="text-xs text-neutral-500">
              {{ t("stock.fulfillment.return_locations_loading") }}
            </p>
            <p v-else-if="returnLocationsError" class="text-xs text-danger-600">
              {{ t("stock.fulfillment.return_locations_unavailable") }}:
              {{ returnLocationsError }}
            </p>
            <div
              v-for="choice in returnTracking[item.id]"
              :key="choice.key"
              class="flex flex-wrap items-center justify-between gap-2 text-sm"
            >
              <span class="font-mono">{{ choiceLabel(choice) }}</span
              ><span class="text-xs text-neutral-500">{{
                t("stock.fulfillment.available", { quantity: choice.available })
              }}</span
              ><label class="flex items-center gap-2"
                ><input
                  v-if="returnMode(item) === 'serial'"
                  v-model="choice.quantity"
                  :aria-label="choiceLabel(choice)"
                  type="checkbox"
                  true-value="1.000"
                  false-value="0.000" /><input
                  v-else
                  v-model="choice.quantity"
                  :aria-label="choiceLabel(choice)"
                  inputmode="decimal"
                  class="w-24 h-8 px-2 text-right border border-neutral-300 rounded-md" /></label
              ><label
                v-if="returnDisposition !== 'scrap'"
                class="flex items-center gap-2 text-xs text-neutral-500"
                >{{ t("stock.fulfillment.return_location") }}
                <select
                  v-model="choice.location_id"
                  class="h-8 max-w-44 px-2 border border-neutral-300 rounded-md text-sm"
                >
                  <option :value="null">
                    {{ t("stock.tracking.no_location") }}
                  </option>
                  <option
                    v-for="location in returnLocations"
                    :key="location.id"
                    :value="location.id"
                  >
                    {{ location.code }} - {{ location.name }}
                  </option>
                </select></label
              >
            </div>
          </div>
        </div>
        <textarea
          v-model="returnNote"
          :placeholder="t('stock.fulfillment.return_note')"
          class="w-full px-2 py-2 border border-neutral-300 rounded-md"
        ></textarea>
        <div class="flex flex-wrap justify-end gap-2">
          <button
            type="button"
            :class="btnOutline('neutral')"
            @click="returnShipment = null"
          >
            <svg
              class="w-4 h-4"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                :d="ICONS.x"
              /></svg
            >{{ t("common.cancel") }}</button
          ><button
            type="button"
            :disabled="returnBusy"
            :class="btnFilled('warning')"
            @click="receiveReturn"
          >
            <svg
              class="w-4 h-4"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                :d="ICONS.check"
              /></svg
            >{{ t("stock.fulfillment.receive_return") }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
