<script setup lang="ts">
import { computed, inject, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { storeToRefs } from 'pinia';
import { Bell, Check, Delete, Picture, RefreshRight } from '@element-plus/icons-vue';
import {
  fetchFavorites,
  fetchLearnerProfile,
  fetchMyLearning,
  fetchOrders,
  removeFavorite,
  startCourse,
  updateLearnerProfile,
} from '@/api/learner';
import { listNotifications, markNotificationRead } from '@/api/notifications';
import { listCheckins } from '@/api/checkins';
import type {
  FavoriteCourseDTO,
  LearnerCheckinDTO,
  LearnerNotificationDTO,
  LearnerProfileDTO,
  MyLearningItemDTO,
  OrderDTO,
  OrderStatus,
} from '@learn-site/contracts';
import MarkdownRenderer from '@/components/MarkdownRenderer.vue';
import ActivationCodeRedeemForm from '@/components/ActivationCodeRedeemForm.vue';
import LearnerAvatarUpload from '@/components/LearnerAvatarUpload.vue';
import { hasRichHtml } from '@/utils/richHtml';
import { useLearnerProfileStore } from '@/stores/learnerProfile';
import { useNotificationStore } from '@/stores/notifications';

defineOptions({ name: 'StudentCenterView' });

type TabKey = 'learning' | 'favorites' | 'orders' | 'messages' | 'checkins' | 'account' | 'redeem';

type CheckinPrompt = {
  dialogVisible: { value: boolean };
  checkedInToday: { value: boolean };
  refreshStatus: (options?: { forceOpen?: boolean }) => Promise<void>;
  afterSuccess: (hook: () => void) => () => void;
};

const WEEKDAYS = ['周一', '周二', '周三', '周四', '周五', '周六', '周日'] as const;

const route = useRoute();
const router = useRouter();

const TAB_BY_PATH: Record<string, TabKey> = {
  '/me/learning': 'learning',
  '/me/favorites': 'favorites',
  '/me/orders': 'orders',
  '/me/messages': 'messages',
  '/me/checkins': 'checkins',
  '/me/account': 'account',
  '/me/redeem': 'redeem',
};

const activeTab = computed<TabKey>(() => TAB_BY_PATH[route.path] ?? 'learning');

// Stores
const profileStore = useLearnerProfileStore();
const notifStore = useNotificationStore();
const { unreadCount } = storeToRefs(notifStore);

const checkinPrompt = inject<CheckinPrompt | null>('dailyCheckinPrompt', null);
let openingCheckin = false;

// ── 顶部全局 STREAK ──
const streakError = ref(false);
type HeatmapCell = {
  key: string;
  date: string;
  day: number;
  hit: boolean;
  today: boolean;
  empty: boolean;
};

const heatmapCells = ref<HeatmapCell[]>([]);

function dateKey(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

async function loadStreak(): Promise<void> {
  try {
    const { items } = await listCheckins(1, 100);
    const dates = new Set(items.map((i: LearnerCheckinDTO) => i.checkin_date));
    streakError.value = false;
    const today = new Date();
    const start = new Date(today);
    start.setDate(today.getDate() - 29);
    const leadingEmptyCells = (start.getDay() + 6) % 7;
    heatmapCells.value = Array.from({ length: leadingEmptyCells + 30 }, (_, i) => {
      if (i < leadingEmptyCells) {
        return {
          key: `empty-${i}`,
          date: '',
          day: 0,
          hit: false,
          today: false,
          empty: true,
        };
      }
      const d = new Date(today);
      d.setDate(today.getDate() - (29 - (i - leadingEmptyCells)));
      const iso = dateKey(d);
      return {
        key: iso,
        date: iso,
        day: d.getDate(),
        hit: dates.has(iso),
        today: i === leadingEmptyCells + 29,
        empty: false,
      };
    });
  } catch {
    streakError.value = true;
    heatmapCells.value = [];
  }
}

async function openCheckinDialog(): Promise<void> {
  if (
    !checkinPrompt ||
    checkinPrompt.checkedInToday.value ||
    checkinPrompt.dialogVisible.value ||
    openingCheckin
  ) {
    return;
  }
  openingCheckin = true;
  try {
    await checkinPrompt.refreshStatus({ forceOpen: true });
  } finally {
    openingCheckin = false;
  }
}

// ── 学习 tab ──
const learningItems = ref<MyLearningItemDTO[]>([]);
const learningLoading = ref(true);
const learningLoadError = ref(false);
const learningFilter = ref<'all' | 'active' | 'completed'>('all');
const filteredLearning = computed(() =>
  learningItems.value.filter((item) => {
    if (learningFilter.value === 'all') return true;
    if (learningFilter.value === 'completed') return Boolean(item.completed_at);
    return !item.completed_at;
  }),
);
const completedCount = computed(
  () => learningItems.value.filter((item) => item.completed_at).length,
);
const weekCells = computed(() => {
  if (!heatmapCells.value.length) return [];
  const today = new Date();
  const monday = new Date(today);
  monday.setDate(today.getDate() - ((today.getDay() + 6) % 7));
  return WEEKDAYS.map((label, index) => {
    const date = new Date(monday);
    date.setDate(monday.getDate() + index);
    const key = dateKey(date);
    return {
      label,
      date: key,
      day: date.getDate(),
      hit: heatmapCells.value.some((cell) => cell.date === key && cell.hit),
      today: key === dateKey(today),
    };
  });
});
const tabTitles: Record<TabKey, string> = {
  learning: '我的学习',
  favorites: '我的收藏',
  orders: '我的订单',
  messages: '消息中心',
  checkins: '每日签到',
  account: '账户设置',
  redeem: '兑换课程',
};
const rejoiningCourseId = ref<number | null>(null);
const rejoinErrorCourseId = ref<number | null>(null);

async function rejoin(item: MyLearningItemDTO): Promise<void> {
  if (!item.can_rejoin || rejoiningCourseId.value !== null) return;
  rejoiningCourseId.value = item.course_id;
  rejoinErrorCourseId.value = null;
  try {
    const result = await startCourse(item.course_id);
    if (item.last_lesson_id) {
      await router.push(`/learn/${item.course_id}/${item.last_lesson_id}`);
    } else if (result.first_lesson) {
      await router.push(`/learn/${item.course_id}/${result.first_lesson.id}`);
    } else {
      await router.push(`/courses/${item.course_id}`);
    }
  } catch {
    rejoinErrorCourseId.value = item.course_id;
  } finally {
    rejoiningCourseId.value = null;
  }
}

async function loadLearning(): Promise<void> {
  learningLoading.value = true;
  learningLoadError.value = false;
  try {
    const result = await fetchMyLearning();
    learningItems.value = result.items;
  } catch {
    learningLoadError.value = true;
  } finally {
    learningLoading.value = false;
  }
}

// ── 收藏 tab ──
const favorites = ref<{ items: FavoriteCourseDTO[]; total: number } | null>(null);
const favoritesLoading = ref(false);
const favoritesError = ref<string | null>(null);
const submittingFavId = ref<number | null>(null);

function formatPrice(n: number): string {
  return n % 1 === 0 ? String(n) : n.toFixed(2);
}

async function loadFavorites(): Promise<void> {
  favoritesLoading.value = true;
  favoritesError.value = null;
  try {
    favorites.value = await fetchFavorites(1, 50);
  } catch (err) {
    favoritesError.value = (err as Error).message || 'load_failed';
  } finally {
    favoritesLoading.value = false;
  }
}

async function unfavorite(courseId: number): Promise<void> {
  if (submittingFavId.value !== null) return;
  submittingFavId.value = courseId;
  try {
    await removeFavorite(courseId);
    if (favorites.value) {
      favorites.value = {
        items: favorites.value.items.filter((item) => item.course_id !== courseId),
        total: Math.max(0, favorites.value.total - 1),
      };
    }
  } catch (err) {
    favoritesError.value = (err as Error).message || 'unfavorite_failed';
  } finally {
    submittingFavId.value = null;
  }
}

// ── 订单 tab ──
const orders = ref<OrderDTO[]>([]);
const ordersLoading = ref(true);
const ordersLoadError = ref(false);

async function loadOrders(): Promise<void> {
  ordersLoading.value = true;
  ordersLoadError.value = false;
  try {
    const result = await fetchOrders();
    orders.value = result.items;
  } catch {
    ordersLoadError.value = true;
  } finally {
    ordersLoading.value = false;
  }
}

function orderStatusLabel(status: OrderStatus): string {
  switch (status) {
    case 'pending':
      return '支付处理中…';
    case 'succeeded':
      return '✓ 支付成功 · 已开通';
    case 'failed':
      return '支付失败';
    case 'cancelled':
      return '已取消';
    case 'unknown':
      return '待确认';
    default:
      return status;
  }
}

function orderStatusTagType(status: OrderStatus): 'success' | 'warning' | 'danger' | 'info' {
  if (status === 'succeeded') return 'success';
  if (status === 'pending' || status === 'unknown') return 'warning';
  if (status === 'failed') return 'danger';
  return 'info';
}

function orderCanRetry(status: OrderStatus): boolean {
  return (
    status === 'pending' || status === 'failed' || status === 'cancelled' || status === 'unknown'
  );
}

function formatOrderDate(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  const pad = (value: number): string => value.toString().padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

// ── 消息 tab ──
const messages = ref<LearnerNotificationDTO[]>([]);
const messagesLoading = ref(true);
const messagesError = ref('');
const messagesTotal = ref(0);
const messagesPage = ref(1);
const messagesLimit = ref(20);
const readingId = ref<number | null>(null);

function kindLabel(kind: LearnerNotificationDTO['kind']): string {
  return {
    question_update: '问答',
    progress_reset: '进度',
    progress_catalog_changed: '目录更新',
    entitlement_revoked: '授权',
    announcement: '公告',
    internal_message: '站内信',
    course_published: '新课',
    learning_reminder: '学习提醒',
  }[kind];
}

function kindTagType(
  kind: LearnerNotificationDTO['kind'],
): 'primary' | 'success' | 'warning' | 'danger' | 'info' {
  const types: Record<
    LearnerNotificationDTO['kind'],
    'primary' | 'success' | 'warning' | 'danger' | 'info'
  > = {
    question_update: 'warning',
    progress_reset: 'success',
    progress_catalog_changed: 'info',
    entitlement_revoked: 'danger',
    announcement: 'primary',
    internal_message: 'info',
    course_published: 'success',
    learning_reminder: 'warning',
  };
  return types[kind];
}

async function openMessageResource(message: LearnerNotificationDTO): Promise<void> {
  const target = message.resource_path;
  if (!target || !message.resource_available) return;
  if (!message.read) {
    await markRead(message.id);
  }
  await router.push(target);
}

async function loadMessages(): Promise<void> {
  try {
    const result = await listNotifications(messagesPage.value, messagesLimit.value);
    messages.value = result.items;
    messagesTotal.value = result.total;
    messagesError.value = '';
  } catch {
    messagesError.value = '消息加载失败，请稍后重试。';
  }
}

function onMessagesCurrentChange(next: number): void {
  messagesPage.value = next;
  void loadMessages();
}

function onMessagesSizeChange(next: number): void {
  messagesLimit.value = next;
  messagesPage.value = 1;
  void loadMessages();
}

async function markRead(id: number): Promise<void> {
  readingId.value = id;
  try {
    await markNotificationRead(id);
    const target = messages.value.find((message) => message.id === id);
    if (target) target.read = true;
    await notifStore.refreshUnreadCount();
  } catch {
    messagesError.value = '消息状态更新失败，请稍后重试。';
  } finally {
    readingId.value = null;
  }
}

watch(
  () => notifStore.inboxVersion,
  () => {
    messagesPage.value = 1;
    void loadMessages();
  },
);

// ── 签到 tab ──
const checkinItems = ref<LearnerCheckinDTO[]>([]);
const checkinTotal = ref(0);
const checkinPage = ref(1);
const checkinLimit = ref(20);
const checkinLoading = ref(false);
const checkinErrorMessage = ref('');
let checkinUnsub: (() => void) | undefined;

async function loadCheckins(): Promise<void> {
  checkinLoading.value = true;
  checkinErrorMessage.value = '';
  try {
    const result = await listCheckins(checkinPage.value, checkinLimit.value);
    checkinItems.value = result.items;
    checkinTotal.value = result.total;
  } catch (err) {
    checkinErrorMessage.value = (err as Error).message || '加载失败';
  } finally {
    checkinLoading.value = false;
  }
}

function onCheckinSizeChange(next: number): void {
  checkinLimit.value = next;
  checkinPage.value = 1;
  void loadCheckins();
}

// ── 账户 tab ──
const profile = ref<LearnerProfileDTO | null>(null);
const profileLoading = ref(true);
const profileSaving = ref(false);
const profileSaved = ref(false);
const profileError = ref(false);
const profileForm = reactive({ nickname: '', show_on_course: false });

async function loadProfile(): Promise<void> {
  profileLoading.value = true;
  profileError.value = false;
  try {
    profile.value = await fetchLearnerProfile();
    profileForm.nickname = profile.value.nickname ?? '';
    profileForm.show_on_course = profile.value.show_on_course;
  } catch {
    profileError.value = true;
  } finally {
    profileLoading.value = false;
  }
}

function onAvatarUpdated(next: LearnerProfileDTO): void {
  profile.value = next;
  profileStore.setProfile(next);
}

async function saveProfile(): Promise<void> {
  profileSaving.value = true;
  profileSaved.value = false;
  profileError.value = false;
  try {
    profile.value = await updateLearnerProfile({
      nickname: profileForm.nickname.trim() || null,
      show_on_course: profileForm.show_on_course,
    });
    profileStore.setProfile(profile.value);
    profileSaved.value = true;
  } catch {
    profileError.value = true;
  } finally {
    profileSaving.value = false;
  }
}

// ── Tab 切换按需加载 ──
let loadedTabs = new Set<TabKey>();
async function ensureLoaded(tab: TabKey): Promise<void> {
  if (loadedTabs.has(tab)) return;
  loadedTabs.add(tab);
  if (tab === 'learning') {
    await Promise.all([loadLearning(), loadMessages()]);
    messagesLoading.value = false;
  } else if (tab === 'favorites') await loadFavorites();
  else if (tab === 'orders') await loadOrders();
  else if (tab === 'messages') {
    notifStore.init();
    await loadMessages();
    messagesLoading.value = false;
  } else if (tab === 'checkins') await loadCheckins();
  else if (tab === 'account') await loadProfile();
}

watch(
  activeTab,
  (next) => {
    void ensureLoaded(next);
  },
  { immediate: true },
);

onMounted(() => {
  void loadStreak();
  if (checkinPrompt) {
    checkinUnsub = checkinPrompt.afterSuccess(() => {
      void loadCheckins();
      void loadStreak();
    });
  }
});

onBeforeUnmount(() => {
  checkinUnsub?.();
});
</script>

<template>
  <main class="student-center-page" :class="{ 'is-learning': activeTab === 'learning' }">
    <header class="center-heading" data-testid="streak-banner">
      <div>
        <h1>
          {{
            activeTab === 'learning' ? `你好，${profileStore.displayName}` : tabTitles[activeTab]
          }}
        </h1>
        <p v-if="activeTab === 'learning' && !learningLoading && !learningLoadError">
          {{ learningItems.length - completedCount }} 门学习中 <span>·</span>
          {{ completedCount }} 门已完成
        </p>
      </div>
      <div class="checkin-status">
        <span>{{ checkinPrompt?.checkedInToday.value ? '今日已签到' : '今日未签到' }}</span>
        <el-button
          type="primary"
          :icon="Check"
          :disabled="checkinPrompt?.checkedInToday.value"
          data-action="open-checkin"
          @click="openCheckinDialog"
          >{{ checkinPrompt?.checkedInToday.value ? '已签到' : '今日签到' }}</el-button
        >
      </div>
    </header>
    <section v-if="activeTab === 'checkins'" class="streak-heatmap" aria-label="近 30 天签到日历">
      <div class="heatmap-heading">
        <h3>近 30 天</h3>
        <span class="heatmap-range">按周查看签到记录</span>
      </div>
      <div class="heatmap-weekdays" data-testid="checkin-calendar-weekdays" aria-hidden="true">
        <span v-for="weekday in WEEKDAYS" :key="weekday" class="heatmap-weekday">{{
          weekday
        }}</span>
      </div>
      <div class="heatmap-grid" data-testid="checkin-calendar">
        <div
          v-for="cell in heatmapCells"
          :key="cell.key"
          class="heatmap-cell"
          :class="{ empty: cell.empty, hit: cell.hit, today: cell.today }"
          :title="cell.empty ? undefined : `${cell.date} ${cell.hit ? '已签到' : '未签到'}`"
          :aria-label="cell.empty ? undefined : `${cell.date} ${cell.hit ? '已签到' : '未签到'}`"
          :aria-hidden="cell.empty ? 'true' : undefined"
          data-testid="checkin-calendar-cell"
        >
          <template v-if="!cell.empty">
            <time :datetime="cell.date" data-testid="checkin-calendar-date">{{ cell.day }}</time>
            <Check v-if="cell.hit" class="heatmap-check" aria-hidden="true" />
          </template>
        </div>
      </div>
    </section>

    <!-- 学习 -->
    <section v-if="activeTab === 'learning'" class="sc-section" data-tab="learning">
      <el-tabs v-model="learningFilter" class="learning-tabs" aria-label="学习状态">
        <el-tab-pane :label="`全部课程 (${learningItems.length})`" name="all" />
        <el-tab-pane :label="`学习中 (${learningItems.length - completedCount})`" name="active" />
        <el-tab-pane :label="`已完成 (${completedCount})`" name="completed" />
      </el-tabs>
      <el-skeleton v-if="learningLoading" animated :rows="5" />
      <el-alert
        v-else-if="learningLoadError"
        title="学习记录暂时读不到，请稍后再试。"
        type="error"
        :closable="false"
        show-icon
      />
      <el-empty
        v-else-if="filteredLearning.length === 0"
        :description="learningItems.length === 0 ? '还没有开始任何课程' : '暂无这类课程'"
      >
        <router-link to="/" class="btn btn-primary btn-sm">去首页选课</router-link>
      </el-empty>
      <div v-else class="entry-list">
        <article v-for="item in filteredLearning" :key="item.course_id" class="rec">
          <router-link :to="`/courses/${item.course_id}`" class="cover">
            <img
              v-if="item.course.cover_url"
              :src="item.course.cover_url"
              :alt="item.course.title"
            />
            <el-icon v-else :size="32"><Picture /></el-icon>
          </router-link>
          <div>
            <h3>
              <router-link :to="`/courses/${item.course_id}`">{{ item.course.title }}</router-link>
            </h3>
            <el-progress
              style="max-width: 300px"
              :percentage="item.progress_percent"
              :stroke-width="6"
            />
            <div class="lmeta">
              {{ item.progress_percent }}% · 讲师 {{ item.course.teacher_name || '未知' }}
              <el-tag v-if="item.entitlement_status === 'revoked'" type="danger" size="small"
                >访问已撤销</el-tag
              >
              <el-tag v-else-if="item.completed_at" type="success" size="small">已完成</el-tag>
            </div>
            <p
              v-if="item.entitlement_status === 'revoked'"
              class="small"
              style="color: var(--seal)"
            >
              {{
                item.revoked_reason ? `撤销原因：${item.revoked_reason}` : '课程访问权已被撤销。'
              }}
            </p>
          </div>
          <div>
            <el-button
              v-if="item.entitlement_status === 'revoked' && item.can_rejoin"
              type="primary"
              size="small"
              :icon="RefreshRight"
              data-action="rejoin"
              :loading="rejoiningCourseId === item.course_id"
              @click="rejoin(item)"
            >
              再次加入
            </el-button>
            <span v-else-if="item.entitlement_status === 'revoked'" class="small muted">
              {{ item.course.status === 'published' ? '当前无法重新加入' : '课程已下架' }}
            </span>
            <router-link
              v-else-if="item.last_lesson_id"
              :to="`/learn/${item.course_id}/${item.last_lesson_id}`"
              class="btn btn-primary btn-sm"
            >
              继续学习
            </router-link>
            <router-link v-else :to="`/courses/${item.course_id}`" class="btn btn-ghost btn-sm">
              进入课程
            </router-link>
            <p
              v-if="rejoinErrorCourseId === item.course_id"
              class="small"
              style="color: var(--seal)"
            >
              重新加入失败，请稍后再试。
            </p>
          </div>
        </article>
      </div>
    </section>

    <!-- 收藏 -->
    <section v-else-if="activeTab === 'favorites'" class="sc-section" data-tab="favorites">
      <div class="list-head">
        <h2>我的收藏</h2>
        <span v-if="!favoritesLoading && !favoritesError" class="cnt">
          {{ favorites?.total ?? 0 }} 门
        </span>
      </div>
      <el-skeleton v-if="favoritesLoading" animated :rows="5" />
      <el-alert
        v-else-if="favoritesError"
        :title="favoritesError"
        type="error"
        :closable="false"
        show-icon
      />
      <div v-else-if="favorites && favorites.items.length" class="entry-list">
        <article v-for="course in favorites.items" :key="course.course_id" class="rec">
          <router-link :to="`/courses/${course.course_id}`" class="cover">
            <img v-if="course.cover_url" :src="course.cover_url" :alt="course.title" />
            <el-icon v-else :size="32"><Picture /></el-icon>
          </router-link>
          <div>
            <h3>
              <router-link :to="`/courses/${course.course_id}`">{{ course.title }}</router-link>
            </h3>
            <div class="lmeta">
              {{ course.teacher_name }}
              <el-tag v-if="course.status !== 'published'" type="info" size="small"
                >暂不可用</el-tag
              >
            </div>
          </div>
          <div style="display: flex; flex-direction: column; gap: 8px; align-items: flex-end">
            <el-tag v-if="course.price_mode === 'free'" type="success" size="small">免费</el-tag>
            <span v-else class="price-now" style="font-size: 17px"
              >¥ {{ formatPrice(course.list_price) }}</span
            >
            <el-button
              link
              type="danger"
              :icon="Delete"
              :loading="submittingFavId === course.course_id"
              data-action="remove-favorite"
              @click="unfavorite(course.course_id)"
            >
              取消收藏
            </el-button>
          </div>
        </article>
      </div>
      <el-empty v-else description="还没有收藏课程" />
    </section>

    <!-- 订单 -->
    <section v-else-if="activeTab === 'orders'" class="sc-section" data-tab="orders">
      <div class="list-head">
        <h2>我的订单</h2>
        <span v-if="!ordersLoading && !ordersLoadError" class="cnt">{{ orders.length }} 笔</span>
      </div>
      <el-skeleton v-if="ordersLoading" animated :rows="5" />
      <el-alert
        v-else-if="ordersLoadError"
        title="订单暂时读不到，请稍后再试。"
        type="error"
        :closable="false"
        show-icon
      />
      <el-empty v-else-if="orders.length === 0" description="还没有订单" />
      <div v-else>
        <article v-for="order in orders" :key="order.order_id" class="panel order-row">
          <div>
            <router-link :to="`/courses/${order.course_id}`" class="o-course">
              课程 #{{ order.course_id }}
            </router-link>
            <div class="o-snap">
              订单 {{ order.order_id }} · 标准价 ¥{{ order.list_price_snapshot.toFixed(2) }}
              <template v-if="order.sale_price_snapshot > 0">
                · 优惠价 ¥{{ order.sale_price_snapshot.toFixed(2) }}
              </template>
              · 实付 ¥{{ order.paid_amount.toFixed(2) }} · {{ formatOrderDate(order.created_at) }}
            </div>
          </div>
          <div class="pay-state">
            <el-tag :type="orderStatusTagType(order.status)" effect="plain">
              {{ orderStatusLabel(order.status) }}
            </el-tag>
            <router-link
              :to="`/me/orders/${order.order_id}`"
              class="btn-link"
              style="display: block; margin-top: 6px"
            >
              查看详情
            </router-link>
            <router-link
              v-if="orderCanRetry(order.status)"
              :to="`/checkout/${order.course_id}`"
              class="btn-link"
              style="display: block; margin-top: 6px"
            >
              {{ order.status === 'pending' ? '继续支付' : '重新购买' }}
            </router-link>
          </div>
        </article>
      </div>
    </section>

    <!-- 消息 -->
    <section v-else-if="activeTab === 'messages'" class="sc-section" data-tab="messages">
      <div class="list-head">
        <h2>消息中心</h2>
        <span v-if="!messagesLoading && !messagesError" class="cnt">
          {{ unreadCount }} 条未读 · 共 {{ messagesTotal }} 条
        </span>
      </div>
      <el-skeleton v-if="messagesLoading" animated :rows="5" />
      <el-alert
        v-else-if="messagesError"
        :title="messagesError"
        type="error"
        :closable="false"
        show-icon
      />
      <el-empty v-else-if="messages.length === 0" description="暂无消息" />
      <div v-else class="panel">
        <article
          v-for="message in messages"
          :key="message.id"
          class="msg-row"
          :class="{ unread: !message.read }"
        >
          <span class="msg-dot" :class="{ read: message.read }" aria-hidden="true" />
          <div>
            <div class="mtitle">
              <el-tag v-if="!message.read" type="danger" size="small" style="margin-right: 6px">
                未读
              </el-tag>
              <el-tag
                :type="kindTagType(message.kind)"
                size="small"
                effect="plain"
                style="margin-right: 6px"
              >
                {{ kindLabel(message.kind) }}
              </el-tag>
              {{ message.title }}
            </div>
            <div v-if="message.body" class="mbody">{{ message.body }}</div>
            <el-button
              v-if="message.resource_available && message.resource_path"
              link
              type="primary"
              class="btn-link message-resource-link"
              :data-resource-id="message.id"
              :loading="readingId === message.id"
              @click="openMessageResource(message)"
            >
              查看关联内容
            </el-button>
            <span v-else-if="message.resource_type" class="small muted">
              {{ message.resource_unavailable_reason ?? '关联内容已不可用' }}
            </span>
          </div>
          <div style="display: grid; gap: 8px; justify-items: end">
            <time class="when small muted">{{ message.created_at }}</time>
            <el-button
              v-if="!message.read"
              size="small"
              :icon="Check"
              :data-read-id="message.id"
              :loading="readingId === message.id"
              @click="markRead(message.id)"
            >
              标记已读
            </el-button>
          </div>
        </article>
      </div>
      <footer
        v-if="!messagesLoading && !messagesError && messagesTotal > 0"
        class="pager messages-pager"
      >
        <el-pagination
          v-model:current-page="messagesPage"
          v-model:page-size="messagesLimit"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next"
          :total="messagesTotal"
          background
          data-action="messages-pager"
          @current-change="onMessagesCurrentChange"
          @size-change="onMessagesSizeChange"
        />
      </footer>
    </section>

    <!-- 签到 -->
    <section v-else-if="activeTab === 'checkins'" class="sc-section" data-tab="checkins">
      <el-alert
        v-if="checkinErrorMessage"
        type="error"
        :title="checkinErrorMessage"
        show-icon
        :closable="false"
      />
      <el-skeleton v-if="checkinLoading && checkinItems.length === 0" :rows="4" animated />
      <el-empty
        v-else-if="!checkinLoading && checkinItems.length === 0"
        description="还没有签到记录"
      >
        <el-button type="primary" data-action="open-checkin" @click="openCheckinDialog">
          立即签到
        </el-button>
      </el-empty>
      <section v-else class="list">
        <article v-for="item in checkinItems" :key="item.id" class="card">
          <header class="card-head">
            <time>{{ item.checkin_date }}</time>
            <span>{{ item.checked_in_at }}</span>
          </header>
          <MarkdownRenderer v-if="hasRichHtml(item.plan_html)" :html="item.plan_html" />
          <p v-else class="empty-plan">（无计划内容）</p>
        </article>
      </section>
      <footer v-if="checkinItems.length > 0" class="pager">
        <el-pagination
          v-model:current-page="checkinPage"
          v-model:page-size="checkinLimit"
          :page-sizes="[5, 10, 20, 50]"
          layout="total, sizes, prev, pager, next"
          :total="checkinTotal"
          @current-change="loadCheckins"
          @size-change="onCheckinSizeChange"
        />
      </footer>
    </section>

    <!-- 激活码兑换 -->
    <section v-else-if="activeTab === 'redeem'" class="sc-section" data-tab="redeem">
      <ActivationCodeRedeemForm />
    </section>

    <!-- 账户 -->
    <section v-else-if="activeTab === 'account'" class="sc-section" data-tab="account">
      <el-skeleton v-if="profileLoading" animated :rows="4" />
      <el-alert
        v-else-if="profileError"
        title="资料暂时读不到，请稍后再试。"
        type="error"
        :closable="false"
        show-icon
      />
      <el-form
        v-else
        class="profile-form"
        :model="profileForm"
        label-position="top"
        @submit.prevent="saveProfile"
      >
        <el-form-item label="个人头像">
          <LearnerAvatarUpload
            :avatar-url="profile?.avatar_url ?? null"
            :initial="profileStore.userInitial"
            @updated="onAvatarUpdated"
          />
        </el-form-item>
        <el-form-item label="手机号">
          <el-input :model-value="profile?.phone ?? ''" disabled />
        </el-form-item>
        <el-form-item label="公开称呼">
          <el-input v-model="profileForm.nickname" maxlength="32" autocomplete="nickname" />
        </el-form-item>
        <el-form-item label="课程页公开显示">
          <el-switch v-model="profileForm.show_on_course" active-text="在课程页显示我的称呼" />
        </el-form-item>
        <el-alert
          v-if="profileSaved"
          title="资料已更新。"
          type="success"
          :closable="false"
          show-icon
        />
        <el-button type="primary" native-type="submit" :loading="profileSaving">保存资料</el-button>
      </el-form>
    </section>
    <aside v-if="activeTab === 'learning'" class="learning-rail">
      <section aria-label="本周签到记录">
        <h2>本周签到记录</h2>
        <p v-if="streakError" class="muted">签到记录加载失败</p>
        <div v-else class="week-checkins">
          <div v-for="day in weekCells" :key="day.date">
            <span>{{ day.label.slice(1) }}</span>
            <time
              :datetime="day.date"
              :class="{ hit: day.hit, today: day.today }"
              :title="`${day.date} ${day.hit ? '已签到' : '未签到'}`"
            >
              <el-icon v-if="day.hit"><Check /></el-icon><template v-else>{{ day.day }}</template>
            </time>
          </div>
        </div>
        <router-link to="/me/checkins" class="rail-link">查看签到记录</router-link>
      </section>
      <section class="latest-message">
        <h2>
          <el-icon><Bell /></el-icon>最新通知
        </h2>
        <p v-if="messagesError" class="muted">{{ messagesError }}</p>
        <template v-else-if="messages[0]">
          <h3>{{ messages[0].title }}</h3>
          <p>{{ messages[0].body }}</p>
          <router-link to="/me/messages" class="rail-link">查看消息</router-link>
        </template>
        <p v-else class="muted">暂无新消息</p>
      </section>
    </aside>
  </main>
</template>

<style scoped>
.student-center-page {
  min-width: 0;
}
.student-center-page.is-learning {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 240px;
  gap: 28px;
  align-items: start;
}
.center-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  margin-bottom: 32px;
  grid-column: 1 / -1;
}
.is-learning .center-heading {
  margin-bottom: 4px;
}
.center-heading h1 {
  font-size: 28px;
  font-weight: 700;
  line-height: 1.4;
  margin: 0;
  overflow-wrap: anywhere;
}
.center-heading p {
  margin: 10px 0 0;
  color: var(--ink-2);
}
.center-heading p span {
  margin: 0 10px;
  color: var(--line-2);
}
.checkin-status {
  display: flex;
  align-items: center;
  flex-shrink: 0;
  gap: 16px;
  color: var(--ink-2);
  font-size: 13px;
}
.sc-section {
  min-width: 0;
}
.sc-section > .list-head {
  display: flex;
  justify-content: flex-end;
  padding-bottom: 16px;
  margin: 0;
  border-bottom: 1px solid var(--line);
}
.sc-section > .list-head h2 {
  display: none;
}
.sc-section .cnt {
  color: var(--ink-3);
  font-size: 13px;
}
.learning-tabs :deep(.el-tabs__header) {
  margin: 0 0 20px;
}
.entry-list {
  gap: 16px;
}
.rec {
  grid-template-columns: 148px minmax(0, 1fr) auto;
  gap: 20px;
  padding: 18px;
  border: 1px solid var(--line);
  border-radius: 6px;
  background: var(--card);
}
.rec > div {
  min-width: 0;
}
.rec .cover {
  width: 148px;
  height: 100px;
  border-radius: 4px;
  background: var(--paper-2);
  color: var(--ink-3);
}
.rec .cover::before,
.rec .cover::after {
  display: none;
}
.rec .cover img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.rec h3 {
  font-size: 17px;
  line-height: 1.5;
  margin-bottom: 12px;
}
.rec .lmeta {
  margin-top: 8px;
  font-size: 12px;
}
.learning-rail {
  padding-left: 4px;
}
.learning-rail section {
  padding: 12px 0 24px;
  border-bottom: 1px solid var(--line);
  margin-bottom: 16px;
}
.learning-rail h2 {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  margin: 0 0 20px;
}
.week-checkins {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 4px;
}
.week-checkins > div {
  display: flex;
  align-items: center;
  flex-direction: column;
  gap: 8px;
  font-size: 11px;
  color: var(--ink-3);
}
.week-checkins time {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 27px;
  height: 27px;
  border-radius: 50%;
  background: var(--paper-2);
  color: var(--ink-2);
}
.week-checkins time.hit {
  color: #fff;
  background: var(--seal);
}
.week-checkins time.today {
  outline: 1px solid var(--seal);
  outline-offset: 2px;
}
.rail-link {
  display: inline-block;
  font-size: 12px;
  margin-top: 18px;
}
.latest-message h3 {
  font-size: 14px;
  margin: 0 0 8px;
}
.latest-message p {
  margin: 0;
  font-size: 13px;
  color: var(--ink-2);
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.streak-heatmap {
  margin-bottom: 32px;
  padding-bottom: 28px;
  border-bottom: 1px solid var(--line);
}
.heatmap-heading {
  display: flex;
  justify-content: space-between;
  max-width: 640px;
  align-items: center;
  margin-bottom: 16px;
}
.heatmap-heading h3 {
  font-size: 16px;
  margin: 0;
}
.heatmap-range {
  font-size: 12px;
  color: var(--ink-3);
}
.heatmap-weekdays,
.heatmap-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  max-width: 640px;
  gap: 8px;
}
.heatmap-weekdays {
  text-align: center;
  color: var(--ink-3);
  margin-bottom: 8px;
  font-size: 12px;
}
.heatmap-cell {
  position: relative;
  padding: 10px;
  aspect-ratio: 1.2;
  border: 1px solid var(--line);
  border-radius: 6px;
  background: var(--paper-2);
  color: var(--ink-2);
  font-size: 14px;
}
.heatmap-cell.empty {
  visibility: hidden;
}
.heatmap-cell.hit {
  background: var(--seal-soft);
  color: var(--seal);
  border-color: var(--seal-soft);
}
.heatmap-cell.today {
  outline: 1px solid var(--seal);
}
.heatmap-check {
  position: absolute;
  width: 16px;
  right: 10px;
  bottom: 10px;
}
.profile-form {
  display: grid;
  gap: 20px;
  max-width: 520px;
}
.profile-form :deep(.el-form-item) {
  margin-bottom: 0;
}
.profile-form > .el-button {
  justify-self: start;
}
.list {
  display: grid;
  gap: 16px;
}
.card {
  border: 1px solid var(--line);
  border-radius: 6px;
  background: var(--card);
  padding: 20px;
}
.card-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
  font-size: 13px;
  color: var(--ink-2);
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 24px;
}
.message-resource-link.el-button {
  height: auto;
  margin: 6px 0 0;
  padding: 0;
}
.order-row,
.msg-row {
  background: var(--card);
}
@media (max-width: 1250px) {
  .student-center-page.is-learning {
    grid-template-columns: minmax(0, 1fr);
  }
  .learning-rail {
    display: grid;
    grid-template-columns: 240px minmax(0, 1fr);
    gap: 32px;
  }
}
</style>
