import { defineStore } from 'pinia';
import { computed, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import type { LearnerProfileDTO } from '@learn-site/contracts';
import { fetchLearnerProfile } from '@/api/learner';
import { useLoginFamilyStore } from '@/api/login';

function profileLabel(profile: LearnerProfileDTO | null): string {
  const nickname = profile?.nickname?.trim();
  if (nickname) return nickname;
  if (profile?.phone) return profile.phone;
  return '学员';
}

function profileInitial(label: string): string {
  const trimmed = label.trim();
  return trimmed ? trimmed.charAt(0) : '学';
}

export const useLearnerProfileStore = defineStore('learnerProfile', () => {
  const profile = ref<LearnerProfileDTO | null>(null);
  let inflight: Promise<void> | null = null;
  let sessionWatchBound = false;

  const displayName = computed(() => profileLabel(profile.value));
  const userInitial = computed(() => profileInitial(displayName.value));

  function clear(): void {
    profile.value = null;
    inflight = null;
  }

  async function load(force = false): Promise<void> {
    const session = useLoginFamilyStore();
    if (!session.loggedIn) {
      clear();
      return;
    }
    if (profile.value && !force) return;
    if (inflight) return inflight;

    inflight = (async () => {
      try {
        profile.value = await fetchLearnerProfile();
      } catch {
        profile.value = null;
      } finally {
        inflight = null;
      }
    })();
    return inflight;
  }

  function setProfile(next: LearnerProfileDTO): void {
    profile.value = next;
  }

  function ensureSessionWatch(): void {
    if (sessionWatchBound) return;
    sessionWatchBound = true;
    const session = useLoginFamilyStore();
    const { loggedIn } = storeToRefs(session);
    watch(
      loggedIn,
      (value) => {
        if (value) {
          void load(true);
        } else {
          clear();
        }
      },
      { immediate: true },
    );
  }

  return {
    profile,
    displayName,
    userInitial,
    load,
    setProfile,
    clear,
    ensureSessionWatch,
  };
});
