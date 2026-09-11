import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import {
  fetchDistributionStatus,
  fetchMyCommissions,
  fetchMyDownline,
  fetchMyShareEntries,
} from '@/api/distribution';
import type { CommissionListDTO, DownlineListDTO, ShareEntryListDTO } from '@learn-site/contracts';

export function useDistribution() {
  const route = useRoute();
  const loading = ref(false);
  const hidden = ref(false);
  const detailHidden = ref(false);
  const shares = ref<ShareEntryListDTO['items']>([]);
  const commissions = ref<CommissionListDTO | null>(null);
  const downline = ref<DownlineListDTO['items']>([]);
  let cancelled = false;

  async function reload(): Promise<void> {
    loading.value = true;
    try {
      const status = await fetchDistributionStatus();
      if (cancelled) return;
      hidden.value = !status.enabled;
      detailHidden.value = !status.learner_can_view_detail;
      if (!status.enabled) {
        shares.value = [];
        commissions.value = null;
        downline.value = [];
        return;
      }
      if (!status.learner_can_view_detail) {
        shares.value = [];
        commissions.value = null;
        downline.value = [];
        return;
      }
      const [s, c, d] = await Promise.all([
        fetchMyShareEntries(),
        fetchMyCommissions({ page: 1, limit: 20 }),
        fetchMyDownline(),
      ]);
      if (cancelled) return;
      shares.value = s.items;
      commissions.value = c;
      downline.value = d.items;
    } catch {
      if (!cancelled) hidden.value = true;
    } finally {
      if (!cancelled) loading.value = false;
    }
  }

  onMounted(() => {
    void reload();
  });
  onBeforeUnmount(() => {
    cancelled = true;
  });

  return { route, loading, hidden, detailHidden, shares, commissions, downline, reload };
}
