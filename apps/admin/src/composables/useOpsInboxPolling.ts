import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { OpsException, OpsInboxListRequest } from '@contracts/opsInbox';
import { fetchOpsInbox } from '@/api/opsInbox';

export function useOpsInboxPolling(request: () => OpsInboxListRequest) {
  const items = ref<OpsException[]>([]);
  const total = ref(0);
  const countsBySource = ref<Record<string, number>>({});
  const loading = ref(false);
  const listError = ref('');
  const degraded = ref(false);
  let timer: number | undefined;

  async function reload(): Promise<void> {
    loading.value = true;
    listError.value = '';
    try {
      const result = await fetchOpsInbox(request());
      items.value = result.items;
      total.value = result.total;
      countsBySource.value = result.counts_by_source;
      degraded.value = result.degraded === true;
    } catch (error) {
      listError.value = error instanceof Error ? error.message : '加载失败';
    } finally {
      loading.value = false;
    }
  }

  onMounted(() => {
    void reload();
    timer = window.setInterval(() => void reload(), 30_000);
  });
  onBeforeUnmount(() => {
    if (timer !== undefined) window.clearInterval(timer);
  });

  return { items, total, counts_by_source: countsBySource, loading, listError, degraded, reload };
}
