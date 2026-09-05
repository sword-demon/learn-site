<script setup lang="ts">
import { computed, inject, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { storeToRefs } from 'pinia';
import type { TabPaneName } from 'element-plus';
import { Check, Delete, RefreshRight } from '@element-plus/icons-vue';
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
import { hasRichHtml } from '@/utils/richHtml';
import { useLearnerProfileStore } from '@/stores/learnerProfile';
import { useNotificationStore } from '@/stores/notifications';

defineOptions({ name: 'StudentCenterView' });

type TabKey =
  'learning' | 'favorites' | 'orders' | 'messages' | 'checkins' | 'account' | 'coupons' | 'redeem';

type CheckinPrompt = {
  dialogVisible: { value: boolean };
  checkedInToday: { value: boolean };
  refreshStatus: (options?: { forceOpen?: boolean }) => Promise<void>;
  afterSuccess: (hook: () => void) => () => void;
};

const HUES = ['#34566b', '#4c7a5a', '#a8842c', '#6b4a5e', '#3d6b6b', '#5a6470'] as const;
const WEEKDAYS = ['周一', '周二', '周三', '周四', '周五', '周六', '周日'] as const;

const route = useRoute();
const router = useRouter();

// ponytail: H1 activeTab 从 path 单一守卫点派生
const TAB_BY_PATH: Record<string, TabKey> = {
  '/me/learning': 'learning',
  '/me/favorites': 'favorites',
  '/me/orders': 'orders',
  '/me/messages': 'messages',
  '/me/checkins': 'checkins',
  '/me/account': 'account',
  '/me/coupons': 'coupons',
  '/me/redeem': 'redeem',
};

const activeTab = computed<TabKey>(() => TAB_BY_PATH[route.path] ?? 'learning');

function gotoTab(next: TabKey): void {
  const target = Object.entries(TAB_BY_PATH).find(([, k]) => k === next)?.[0];
  if (target) void router.replace(target);
}

// ponytail: el-tabs v-model 需要 writable ref，computed 套一层
const activeTabProxy = ref<TabKey>(activeTab.value);
watch(activeTab, (v) => {
  activeTabProxy.value = v;
});
function onTabChange(name: TabPaneName): void {
  if (typeof name === 'string') gotoTab(name as TabKey);
}

// Stores
const profileStore = useLearnerProfileStore();
const notifStore = useNotificationStore();
const { unreadCount } = storeToRefs(notifStore);

const checkinPrompt = inject<CheckinPrompt | null>('dailyCheckinPrompt', null);
let openingCheckin = false;

// ── 顶部全局 STREAK ──
const streakDays = ref(0);
const streakBest = ref(0);
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
    let n = 0;
    const today = new Date();
    for (let i = 0; i < 365; i += 1) {
      const d = new Date(today);
      d.setDate(today.getDate() - i);
      const iso = dateKey(d);
      if (dates.has(iso)) n += 1;
      else break;
    }
    streakDays.value = n;
    // ponytail: 后端无 best 字段，用 items.length 占位
    streakBest.value = items.length;
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
    streakDays.value = 0;
    streakBest.value = 0;
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
const rejoiningCourseId = ref<number | null>(null);
const rejoinErrorCourseId = ref<number | null>(null);

function learningCoverStyle(item: MyLearningItemDTO) {
  return { '--hue': HUES[item.course_id % HUES.length] };
}

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

function favCoverStyle(course: FavoriteCourseDTO) {
  return { '--hue': HUES[course.course_id % HUES.length] };
}

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
const readingId = ref<number | null>(null);

function kindLabel(kind: LearnerNotificationDTO['kind']): string {
  return {
    question_update: '问答',
    progress_reset: '进度',
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
    entitlement_revoked: 'danger',
    announcement: 'primary',
    internal_message: 'info',
    course_published: 'success',
    learning_reminder: 'warning',
  };
  return types[kind];
}

function resourcePath(message: LearnerNotificationDTO): string | null {
  return message.resource_path;
}

async function openMessageResource(message: LearnerNotificationDTO): Promise<void> {
  const target = resourcePath(message);
  if (!target || !message.resource_available) return;
  if (!message.read) {
    await markRead(message.id);
  }
  await router.push(target);
}

async function loadMessages(): Promise<void> {
  try {
    messages.value = (await listNotifications()).items;
    messagesError.value = '';
  } catch {
    messagesError.value = '消息加载失败，请稍后重试。';
  }
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
  if (tab === 'learning') await loadLearning();
  else if (tab === 'favorites') await loadFavorites();
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
  <main class="page student-center-page">
    <!-- 顶部全局 STREAK 横幅：6 tab 共用同一签到面板 -->
    <section class="streak-banner" data-testid="streak-banner">
      <div class="streak-numbers">
        <span class="streak-current">连续签到 {{ streakDays }} 天</span>
        <span class="streak-best">历史最长 {{ streakBest }} 天</span>
      </div>
      <el-button
        class="streak-cta"
        type="primary"
        :icon="Check"
        data-action="open-checkin"
        @click="openCheckinDialog"
      >
        今日签到
      </el-button>
    </section>
    <section class="streak-heatmap" aria-label="近 30 天签到日历">
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

    <el-tabs
      v-model="activeTabProxy"
      class="sc-tabs"
      data-testid="sc-tabs"
      @tab-change="onTabChange"
    >
      <el-tab-pane label="我的学习" name="learning" />
      <el-tab-pane label="收藏" name="favorites" />
      <el-tab-pane label="订单" name="orders" />
      <el-tab-pane label="消息" name="messages">
        <template #label>
          消息
          <el-badge
            v-if="unreadCount > 0"
            :value="unreadCount > 99 ? '99+' : unreadCount"
            class="sc-tab-badge"
          />
        </template>
      </el-tab-pane>
      <el-tab-pane label="每日签到" name="checkins" />
      <el-tab-pane label="优惠券" name="coupons" />
      <el-tab-pane label="激活码兑换" name="redeem" />
      <el-tab-pane label="账户" name="account" />
    </el-tabs>

    <!-- 学习 -->
    <section v-if="activeTab === 'learning'" class="sc-section" data-tab="learning">
      <div class="list-head">
        <h2>我的学习</h2>
        <span v-if="!learningLoading && !learningLoadError" class="cnt">
          {{ learningItems.length }} 门进行中
        </span>
      </div>
      <el-skeleton v-if="learningLoading" animated :rows="5" />
      <el-alert
        v-else-if="learningLoadError"
        title="学习记录暂时读不到，请稍后再试。"
        type="error"
        :closable="false"
        show-icon
      />
      <el-empty v-else-if="learningItems.length === 0" description="还没有开始任何课程">
        <router-link to="/" class="btn btn-primary btn-sm">去首页选课</router-link>
      </el-empty>
      <div v-else class="entry-list">
        <article v-for="item in learningItems" :key="item.course_id" class="rec">
          <router-link
            :to="`/courses/${item.course_id}`"
            class="cover"
            :style="learningCoverStyle(item)"
          >
            <img
              v-if="item.course.cover_url"
              :src="item.course.cover_url"
              :alt="item.course.title"
            />
            <b v-else>{{ item.course.title.slice(0, 1) }}</b>
          </router-link>
          <div>
            <h3>
              <router-link :to="`/courses/${item.course_id}`"
                >《{{ item.course.title }}》</router-link
              >
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
          <router-link
            :to="`/courses/${course.course_id}`"
            class="cover"
            :style="favCoverStyle(course)"
          >
            <img v-if="course.cover_url" :src="course.cover_url" :alt="course.title" />
            <b v-else>{{ course.title.slice(0, 1) }}</b>
          </router-link>
          <div>
            <h3>
              <router-link :to="`/courses/${course.course_id}`">《{{ course.title }}》</router-link>
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
      <el-empty v-else description="收藏夹还是空的，在课程卡片或详情页收藏想稍后学的课" />
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
      <el-empty
        v-else-if="orders.length === 0"
        description="还没有订单，购买收费课程后这里会保留价格快照"
      />
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
          {{ unreadCount }} 条未读 · {{ messages.length }} 条
        </span>
      </div>
      <p class="muted small" style="margin: 0 0 14px">
        公告与站内信来自系统通知，问答 / 进度 / 授权为课程相关提醒。
      </p>
      <el-skeleton v-if="messagesLoading" animated :rows="5" />
      <el-alert
        v-else-if="messagesError"
        :title="messagesError"
        type="error"
        :closable="false"
        show-icon
      />
      <el-empty
        v-else-if="messages.length === 0"
        description="没有消息，公告、站内信与课程相关通知会出现在这里"
      />
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
              v-if="message.resource_available && resourcePath(message)"
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
    </section>

    <!-- 签到 -->
    <section v-else-if="activeTab === 'checkins'" class="sc-section" data-tab="checkins">
      <header class="page-head">
        <div>
          <h1>每日签到</h1>
          <p>回顾你的学习计划，坚持每日打卡。</p>
        </div>
      </header>
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
      <footer v-if="checkinTotal > checkinLimit" class="pager">
        <el-pagination
          v-model:current-page="checkinPage"
          v-model:page-size="checkinLimit"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next"
          :total="checkinTotal"
          @current-change="loadCheckins"
          @size-change="onCheckinSizeChange"
        />
      </footer>
    </section>

    <!-- 激活码兑换 -->
    <section v-else-if="activeTab === 'redeem'" class="sc-section" data-tab="redeem">
      <header class="account-head">
        <p class="eyebrow"><span class="eyebrow-rule" />课程获取 · 激活码</p>
        <h1 class="display">兑换课程</h1>
      </header>
      <ActivationCodeRedeemForm />
    </section>

    <!-- 账户 -->
    <section v-else-if="activeTab === 'account'" class="sc-section" data-tab="account">
      <header class="account-head">
        <p class="eyebrow"><span class="eyebrow-rule" />个人书架 · 账户</p>
        <h1 class="display">账户资料</h1>
        <p class="lede">管理你的公开称呼和课程页显示偏好。</p>
      </header>
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
  </main>
</template>

<style scoped>
.message-resource-link.el-button {
  height: auto;
  margin: 6px 0 0;
  padding: 0;
}

.student-center-page {
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr);
  grid-template-areas:
    'streak heatmap'
    'tabs content';
  gap: 20px 24px;
  max-width: 1200px;
  margin: 0 auto;
  padding: 32px 24px 48px;
  align-items: start;
}

.streak-banner {
  grid-area: streak;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 16px;
  min-height: 170px;
  padding: 20px 16px;
  border: 1px solid var(--line, #d9e5df);
  border-radius: var(--r);
  background: var(--paper, #fff);
}

.streak-numbers {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.streak-current {
  font-size: 1.1rem;
  font-weight: 600;
  color: var(--pine-deep);
}

.streak-best {
  font-size: 0.85rem;
  color: var(--ink-soft, #6b7b6e);
}

.streak-cta {
  flex-shrink: 0;
  width: 100%;
}

.streak-heatmap {
  grid-area: heatmap;
  min-height: 170px;
  padding: 20px;
  border: 1px solid var(--line, #d9e5df);
  border-radius: var(--r);
  background: var(--paper, #fff);
}

.streak-heatmap h3 {
  margin: 0;
  font-size: 0.95rem;
  color: var(--muted);
}

.heatmap-heading {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.heatmap-range {
  color: var(--ink-soft, #6b7b6e);
  font-size: 0.75rem;
}

.heatmap-weekdays,
.heatmap-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  max-width: 640px;
  gap: 8px;
}

.heatmap-weekdays {
  margin-bottom: 6px;
}

.heatmap-weekday {
  color: var(--muted);
  font-size: 0.72rem;
  font-weight: 600;
  text-align: center;
}

.heatmap-cell {
  position: relative;
  display: flex;
  min-width: 0;
  min-height: 48px;
  align-items: flex-start;
  justify-content: flex-start;
  padding: 8px;
  border: 1px solid var(--line, #d9e5df);
  aspect-ratio: 1;
  border-radius: 6px;
  background: color-mix(in srgb, var(--line, #e3e9e4) 32%, var(--paper, #fff));
  color: var(--ink-soft, #6b7b6e);
  font-size: 0.82rem;
  font-weight: 600;
}

.heatmap-cell.empty {
  visibility: hidden;
}

.heatmap-cell.hit {
  border-color: transparent;
  background: var(--pine-deep, #34566b);
  color: #fff;
}

.heatmap-cell.today {
  box-shadow: inset 0 0 0 2px var(--seal, #9a1f37);
}

.heatmap-check {
  position: absolute;
  right: 7px;
  bottom: 7px;
  width: 14px;
  height: 14px;
}

.sc-tabs {
  grid-area: tabs;
  margin-top: 0;
}

.sc-tabs :deep(.el-tabs__header) {
  margin: 0;
}

.sc-tabs :deep(.el-tabs__nav-wrap::after),
.sc-tabs :deep(.el-tabs__active-bar) {
  display: none;
}

.sc-tabs :deep(.el-tabs__nav) {
  display: grid;
  width: 100%;
  gap: 4px;
}

.sc-tabs :deep(.el-tabs__item) {
  justify-content: flex-start;
  height: 38px;
  padding: 0 12px;
  border-radius: 4px;
  color: var(--ink-2);
  font-size: 13px;
}

.sc-tabs :deep(.el-tabs__item.is-active) {
  color: #fff;
  background: var(--seal);
}

.sc-tab-badge {
  margin-left: 4px;
}

.sc-section {
  grid-area: content;
  min-width: 0;
  padding: 0;
}

.sc-section :deep(.list-head) {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 14px;
}

.sc-section :deep(.list-head h2) {
  margin: 0;
  color: var(--pine-deep);
  font-size: 1.25rem;
}

.sc-section :deep(.list-head .cnt) {
  color: var(--muted);
  font-size: 0.85rem;
}

.profile-form {
  display: grid;
  max-width: 520px;
  gap: 18px;
}

.profile-form :deep(.el-form-item) {
  margin-bottom: 0;
}

.profile-form :deep(.el-form-item__label) {
  color: var(--muted);
  font-size: 0.78rem;
  font-weight: 700;
}

.profile-form > .el-button {
  justify-self: start;
}

.page-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
}

.page-head h1 {
  margin: 0 0 6px;
  font-size: 1.6rem;
  color: var(--pine-deep);
}

.page-head p {
  margin: 0;
  color: var(--ink-soft);
}

.list {
  display: grid;
  gap: 14px;
}

.card {
  border: 1px solid var(--line, #d9e5df);
  border-radius: 12px;
  background: var(--paper, #fff);
  padding: 16px;
}

.card-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
  color: var(--ink-soft);
  font-size: 0.9rem;
}

.empty-plan {
  margin: 0;
  color: var(--ink-soft);
}

.pager {
  margin-top: 16px;
}

.account-head {
  padding: 18px 0 26px;
  border-bottom: 1px solid var(--line);
}

.account-head .eyebrow {
  margin-bottom: 16px;
}

.account-head .display {
  margin: 0 0 9px;
  color: var(--pine-deep);
}

@media (max-width: 760px) {
  .student-center-page {
    grid-template-columns: 1fr;
    grid-template-areas:
      'streak'
      'heatmap'
      'tabs'
      'content';
    padding-inline: 16px;
  }

  .streak-banner,
  .streak-heatmap {
    min-height: 0;
  }

  .streak-banner {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }

  .streak-cta {
    width: auto;
  }

  .sc-tabs :deep(.el-tabs__nav) {
    display: flex;
    overflow-x: auto;
  }

  .sc-tabs :deep(.el-tabs__item) {
    flex: 0 0 auto;
    justify-content: center;
  }
}
</style>
