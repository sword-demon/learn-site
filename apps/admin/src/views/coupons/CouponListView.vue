<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch, type Ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  CreateCouponInput,
  GrantCouponInput,
  type AdminCouponCampaignDTO,
  type CategoryDTO,
  type CourseDTO,
  type PatchCouponInput,
} from '@learn-site/contracts';
type RedemptionRow = NonNullable<Awaited<ReturnType<typeof listRedemptions>>['items']>[number];
import {
  createCoupon,
  disableCoupon,
  grantCoupon,
  listCoupons,
  listRedemptions,
  patchCoupon,
  type CouponListParams,
} from '@/api/coupons';
import { listCategoriesFlat, listCourses } from '@/api/catalog';
import AdminListPager from '@/components/AdminListPager.vue';

defineOptions({ name: 'CouponListView' });

interface Draft {
  name: string;
  scope_type: 'all' | 'category' | 'course';
  scope_category_ids: number[];
  scope_course_ids: number[];
  min_amount: number;
  discount_amount: number;
  claim_mode: 'public' | 'admin_only';
  claim_starts_at: string;
  claim_ends_at: string;
  use_ends_at: string;
  total_quota: number | null;
  per_learner_claim_limit: number;
  per_learner_use_limit: number;
}

const SHANGHAI_OFFSET_MS = 8 * 60 * 60 * 1000;

function toDatetimeLocal(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  return new Date(date.getTime() + SHANGHAI_OFFSET_MS).toISOString().slice(0, 19).replace('T', ' ');
}

function fromDatetimeLocal(value: string): string {
  return `${value.trim().replace(' ', 'T')}+08:00`;
}

function nowIsoLocal(): string {
  return toDatetimeLocal(new Date().toISOString());
}

function futureIsoLocal(days: number): string {
  return toDatetimeLocal(new Date(Date.now() + days * 86_400_000).toISOString());
}

function emptyDraft(): Draft {
  return {
    name: '',
    scope_type: 'all',
    scope_category_ids: [],
    scope_course_ids: [],
    min_amount: 0,
    discount_amount: 0,
    claim_mode: 'public',
    claim_starts_at: nowIsoLocal(),
    claim_ends_at: futureIsoLocal(30),
    use_ends_at: '',
    total_quota: null,
    per_learner_claim_limit: 1,
    per_learner_use_limit: 1,
  };
}

const items = ref<AdminCouponCampaignDTO[]>([]);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const errorMessage = ref('');
const dialogVisible = ref(false);
const grantDialogVisible = ref(false);
const redemptionDialogVisible = ref(false);
const redemptions: Ref<RedemptionRow[]> = ref<RedemptionRow[]>([]);
const redemptionTotal = ref(0);
const redemptionLoading = ref(false);
const grantLearnerIds = ref('');
const currentCoupon = ref<AdminCouponCampaignDTO | null>(null);
const editingCoupon = ref<AdminCouponCampaignDTO | null>(null);
const categoryOptions = ref<CategoryDTO[]>([]);
const courseOptions = ref<CourseDTO[]>([]);
const categoryOptionsLoading = ref(false);
const courseOptionsLoading = ref(false);
const courseOptionsQuery = ref('');
let courseRequestId = 0;

const filters = reactive<{
  scope_type: '' | 'all' | 'category' | 'course';
  status: '' | 'active' | 'disabled' | 'scheduled' | 'ended';
  page: number;
  limit: number;
}>({
  scope_type: '',
  status: '',
  page: 1,
  limit: 20,
});

const draft = reactive<Draft>(emptyDraft());
const dialogTitle = computed(() => (editingCoupon.value ? '编辑优惠券' : '新建优惠券'));
const isEditing = computed(() => editingCoupon.value !== null);

watch(
  () => filters.page,
  (next) => {
    if (next < 1) filters.page = 1;
  },
);

const params = computed<CouponListParams>(() => ({
  page: filters.page,
  limit: filters.limit,
  scope_type: filters.scope_type === '' ? undefined : filters.scope_type,
  status: filters.status === '' ? undefined : filters.status,
}));

type DerivedStatus = 'scheduled' | 'active' | 'ended' | 'disabled';

function derivedStatus(row: AdminCouponCampaignDTO): DerivedStatus {
  if (row.status === 'disabled') return 'disabled';
  const now = Date.now();
  if (new Date(row.claim_starts_at).getTime() > now) return 'scheduled';
  if (new Date(row.claim_ends_at).getTime() <= now) return 'ended';
  return 'active';
}

function statusLabel(row: AdminCouponCampaignDTO): string {
  const map: Record<DerivedStatus, string> = {
    scheduled: '未开始',
    active: '进行中',
    ended: '已结束',
    disabled: '已停用',
  };
  return map[derivedStatus(row)];
}

function statusTagType(
  row: AdminCouponCampaignDTO,
): '' | 'success' | 'warning' | 'info' | 'danger' {
  const map: Record<DerivedStatus, '' | 'success' | 'warning' | 'info' | 'danger'> = {
    scheduled: 'info',
    active: 'success',
    ended: 'warning',
    disabled: 'danger',
  };
  return map[derivedStatus(row)];
}

const SCOPE_LABEL: Record<AdminCouponCampaignDTO['scope_type'], string> = {
  all: '无门槛',
  category: '指定分类',
  course: '指定课程',
};

function scopeLabel(scope: AdminCouponCampaignDTO['scope_type']): string {
  return SCOPE_LABEL[scope];
}

function readCouponError(err: unknown, fallback: string): string {
  const candidate = (err && typeof err === 'object' ? err : {}) as {
    code?: unknown;
    response?: { data?: { error?: { code?: unknown; message?: unknown } } };
  };
  const responseError = candidate.response?.data?.error;
  const candidates = [responseError?.message, candidate.code, responseError?.code];
  const messages: Record<string, string> = {
    COUPON_DATE_INVALID:
      '时间设置无效：领取结束时间需晚于开始时间，使用截止时间不得早于领取结束时间',
    COUPON_RULE_INVALID: '优惠券金额或限额设置不合法，请检查后重试',
    COUPON_SCOPE_REQUIRED: '请选择正确的优惠券适用范围',
    COUPON_NOT_FOUND: '优惠券不存在或已被删除',
    COUPON_NOT_GRANTABLE: '当前优惠券不可发放',
    COUPON_QUOTA_EXCEEDED: '优惠券剩余数量不足',
    COUPON_VERSION_CONFLICT: '优惠券已被其他人修改，请刷新后重试',
    COUPON_ALREADY_USED: '优惠券已使用，不能重复操作',
    COUPON_LOCKED: '优惠券正在使用中，请稍后重试',
  };
  for (const code of candidates) {
    if (typeof code === 'string' && messages[code]) return messages[code];
  }
  if (responseError?.code === 'VALIDATION_FAILED') return '优惠券信息校验失败，请检查填写内容';
  if (responseError?.code === 'CONFLICT') return '优惠券状态已变化，请刷新后重试';
  if (responseError?.code === 'NOT_FOUND') return '优惠券不存在或已被删除';
  return fallback;
}

async function load(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    const res = await listCoupons(params.value);
    items.value = res.items;
    total.value = res.total;
  } catch (err) {
    errorMessage.value = readCouponError(err, '加载失败');
  } finally {
    loading.value = false;
  }
}

function refreshAfterFilter(): void {
  filters.page = 1;
  void load();
}

async function loadCategoryOptions(): Promise<void> {
  categoryOptionsLoading.value = true;
  try {
    const result = await listCategoriesFlat({ page: 1, limit: 100 });
    categoryOptions.value = result.items;
  } catch {
    categoryOptions.value = [];
    ElMessage.error('分类加载失败');
  } finally {
    categoryOptionsLoading.value = false;
  }
}

async function loadCourseOptions(query = courseOptionsQuery.value): Promise<void> {
  const requestId = ++courseRequestId;
  courseOptionsLoading.value = true;
  const trimmedQuery = query.trim();
  courseOptionsQuery.value = trimmedQuery;
  try {
    const result = await listCourses({
      ...(trimmedQuery ? { q: trimmedQuery } : {}),
      page: 1,
      limit: 20,
    });
    if (requestId !== courseRequestId) return;
    const selected = courseOptions.value.filter((course) =>
      draft.scope_course_ids.includes(course.id),
    );
    const options = new Map<number, CourseDTO>();
    [...selected, ...result.items].forEach((course) => options.set(course.id, course));
    courseOptions.value = [...options.values()];
  } catch {
    if (requestId === courseRequestId) courseOptions.value = [];
  } finally {
    if (requestId === courseRequestId) courseOptionsLoading.value = false;
  }
}

async function loadScopeOptions(): Promise<void> {
  await Promise.all([loadCategoryOptions(), loadCourseOptions('')]);
}

function searchCourses(query: string): void {
  void loadCourseOptions(query);
}

function onCourseVisibleChange(visible: boolean): void {
  if (visible && courseOptions.value.length === 0) void loadCourseOptions();
}

function categoryOptionLabel(category: CategoryDTO): string {
  return `#${category.id} ${'— '.repeat(category.depth - 1)}${category.name}`;
}

function courseOptionLabel(course: CourseDTO): string {
  return `#${course.id} ${course.title}`;
}

function openCreate(): void {
  editingCoupon.value = null;
  Object.assign(draft, emptyDraft());
  dialogVisible.value = true;
  void loadScopeOptions();
}

function openEdit(row: AdminCouponCampaignDTO): void {
  editingCoupon.value = row;
  Object.assign(draft, {
    name: row.name,
    scope_type: row.scope_type,
    scope_category_ids: [...row.scope_category_ids],
    scope_course_ids: [...row.scope_course_ids],
    min_amount: row.min_amount,
    discount_amount: row.discount_amount,
    claim_mode: row.claim_mode,
    claim_starts_at: toDatetimeLocal(row.claim_starts_at),
    claim_ends_at: toDatetimeLocal(row.claim_ends_at),
    use_ends_at: row.use_ends_at ? toDatetimeLocal(row.use_ends_at) : '',
    total_quota: row.total_quota,
    per_learner_claim_limit: row.per_learner_claim_limit,
    per_learner_use_limit: row.per_learner_use_limit,
  });
  dialogVisible.value = true;
  void loadScopeOptions();
}

function openDisable(row: AdminCouponCampaignDTO): void {
  void ElMessageBox.confirm(
    `确认停用「${row.name}」?停用后学员不可新领取,未使用实例将在下单校验时被拒绝。`,
    '停用优惠券',
    {
      type: 'warning',
      confirmButtonText: '停用',
      cancelButtonText: '取消',
    },
  )
    .then(async () => {
      try {
        await disableCoupon(row.id);
        ElMessage.success('已停用');
        await load();
      } catch (err) {
        ElMessage.error(readCouponError(err, '停用失败'));
      }
    })
    .catch(() => {
      /* cancelled */
    });
}

function openGrant(row: AdminCouponCampaignDTO): void {
  currentCoupon.value = row;
  grantLearnerIds.value = '';
  grantDialogVisible.value = true;
}

async function submitGrant(): Promise<void> {
  const coupon = currentCoupon.value;
  if (!coupon) return;
  const learnerIds = grantLearnerIds.value
    .split(/[\s,]+/)
    .map((s) => Number(s.trim()))
    .filter((n) => Number.isInteger(n) && n > 0);
  if (learnerIds.length === 0) {
    ElMessage.error('请填写至少一个学员 ID');
    return;
  }
  const payload: GrantCouponInput = { learner_ids: learnerIds };
  try {
    const res = await grantCoupon(coupon.id, payload);
    ElMessage.success(`发放 ${res.granted} 条,跳过 ${res.skipped} 条`);
    grantDialogVisible.value = false;
    await load();
  } catch (err) {
    ElMessage.error(readCouponError(err, '发放失败'));
  }
}

function validateDialog(): boolean {
  if (!draft.name.trim()) {
    ElMessage.warning('请输入优惠券名称');
    return false;
  }
  if (!draft.claim_starts_at || !draft.claim_ends_at) {
    ElMessage.warning('请选择领取时间');
    return false;
  }
  const claimStartsAt = Date.parse(fromDatetimeLocal(draft.claim_starts_at));
  const claimEndsAt = Date.parse(fromDatetimeLocal(draft.claim_ends_at));
  if (
    !Number.isFinite(claimStartsAt) ||
    !Number.isFinite(claimEndsAt) ||
    claimEndsAt <= claimStartsAt
  ) {
    ElMessage.warning('领取结束时间必须晚于领取开始时间');
    return false;
  }
  if (draft.use_ends_at) {
    const useEndsAt = Date.parse(fromDatetimeLocal(draft.use_ends_at));
    if (!Number.isFinite(useEndsAt) || useEndsAt < claimEndsAt) {
      ElMessage.warning('使用截止时间不能早于领取结束时间');
      return false;
    }
  }
  if (draft.scope_type === 'category' && draft.scope_category_ids.length === 0) {
    ElMessage.warning('请选择适用分类');
    return false;
  }
  if (draft.scope_type === 'course' && draft.scope_course_ids.length === 0) {
    ElMessage.warning('请选择适用课程');
    return false;
  }
  return true;
}

async function submitDialog(): Promise<void> {
  if (!validateDialog()) return;
  saving.value = true;
  errorMessage.value = '';
  try {
    const claimEndsAt = fromDatetimeLocal(draft.claim_ends_at);
    const useEndsAt = draft.use_ends_at ? fromDatetimeLocal(draft.use_ends_at) : null;
    if (editingCoupon.value) {
      const payload: PatchCouponInput = {
        name: draft.name.trim(),
        claim_ends_at: claimEndsAt,
        use_ends_at: useEndsAt,
        total_quota: draft.total_quota,
        expected_updated_at: editingCoupon.value.updated_at,
      };
      await patchCoupon(editingCoupon.value.id, payload);
      ElMessage.success('已更新');
    } else {
      const payload: CreateCouponInput = {
        name: draft.name.trim(),
        scope_type: draft.scope_type,
        scope_category_ids: draft.scope_category_ids,
        scope_course_ids: draft.scope_course_ids,
        min_amount: draft.min_amount,
        discount_amount: draft.discount_amount,
        claim_mode: draft.claim_mode,
        claim_starts_at: fromDatetimeLocal(draft.claim_starts_at),
        claim_ends_at: claimEndsAt,
        use_ends_at: useEndsAt,
        total_quota: draft.total_quota,
        per_learner_claim_limit: draft.per_learner_claim_limit,
        per_learner_use_limit: draft.per_learner_use_limit,
      };
      await createCoupon(payload);
      ElMessage.success('已创建');
    }
    dialogVisible.value = false;
    editingCoupon.value = null;
    await load();
  } catch (err) {
    ElMessage.error(readCouponError(err, editingCoupon.value ? '保存失败' : '创建失败'));
  } finally {
    saving.value = false;
  }
}

async function openRedemptions(row: AdminCouponCampaignDTO): Promise<void> {
  currentCoupon.value = row;
  redemptionDialogVisible.value = true;
  redemptionLoading.value = true;
  try {
    const res = await listRedemptions({ campaign_id: row.id, limit: 50 });
    redemptions.value = res.items;
    redemptionTotal.value = res.total;
  } catch (err) {
    ElMessage.error(readCouponError(err, '加载失败'));
  } finally {
    redemptionLoading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <main class="coupons" data-view="coupons">
    <header class="coupons__head">
      <div>
        <h1 class="coupons__title">优惠券管理</h1>
        <p class="coupons__lede">创建满减规则与适用范围,跟踪领取与使用情况。</p>
      </div>
      <el-button type="primary" data-action="create-coupon" @click="openCreate">
        新建优惠券
      </el-button>
    </header>

    <section class="coupons__filters">
      <el-select
        v-model="filters.scope_type"
        placeholder="适用范围"
        clearable
        data-field="scope_type"
        @change="refreshAfterFilter"
      >
        <el-option label="无门槛" value="all" />
        <el-option label="指定分类" value="category" />
        <el-option label="指定课程" value="course" />
      </el-select>
      <el-select
        v-model="filters.status"
        placeholder="状态"
        clearable
        data-field="status"
        @change="refreshAfterFilter"
      >
        <el-option label="进行中" value="active" />
        <el-option label="未开始" value="scheduled" />
        <el-option label="已结束" value="ended" />
        <el-option label="已停用" value="disabled" />
      </el-select>
      <el-button data-action="refresh" @click="load">刷新</el-button>
    </section>

    <el-alert
      v-if="errorMessage"
      :title="errorMessage"
      type="error"
      :closable="false"
      show-icon
      class="coupons__alert"
    />

    <el-table v-loading="loading" :data="items" stripe class="coupons__table" data-table="coupons">
      <el-table-column prop="name" label="名称" min-width="180" />
      <el-table-column label="适用范围" min-width="120">
        <template #default="{ row }">
          <el-tag :type="row.scope_type === 'all' ? 'info' : 'primary'" effect="plain">
            {{ scopeLabel(row.scope_type) }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="满减规则" min-width="140">
        <template #default="{ row }">满 {{ row.min_amount }} 减 {{ row.discount_amount }}</template>
      </el-table-column>
      <el-table-column label="领取方式" min-width="100">
        <template #default="{ row }">
          {{ row.claim_mode === 'public' ? '公开领取' : '仅后台发放' }}
        </template>
      </el-table-column>
      <el-table-column label="状态" min-width="100">
        <template #default="{ row }">
          <el-tag :type="statusTagType(row)" effect="dark">{{ statusLabel(row) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="已领 / 总量" min-width="140">
        <template #default="{ row }">
          {{ row.claimed_count }} / {{ row.total_quota ?? '不限' }}
        </template>
      </el-table-column>
      <el-table-column label="已使用" min-width="80" prop="used_count" />
      <el-table-column label="操作" min-width="280" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" data-action="edit" @click="openEdit(row)">
            编辑
          </el-button>
          <el-button
            link
            type="primary"
            data-action="open-redemptions"
            @click="openRedemptions(row)"
          >
            使用记录
          </el-button>
          <el-button link type="primary" data-action="open-grant" @click="openGrant(row)">
            定向发放
          </el-button>
          <el-button
            v-if="derivedStatus(row) !== 'disabled'"
            link
            type="danger"
            data-action="disable"
            @click="openDisable(row)"
          >
            停用
          </el-button>
        </template>
      </el-table-column>
    </el-table>

    <AdminListPager
      v-model:page="filters.page"
      v-model:page-size="filters.limit"
      :total="total"
      :page-sizes="[20, 50, 100]"
      data-action="pager"
      @change="load"
    />

    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="640px"
      data-dialog="create-coupon"
    >
      <el-form label-position="top" data-form="create-coupon">
        <el-form-item label="名称">
          <el-input
            v-model="draft.name"
            clearable
            maxlength="120"
            placeholder="请输入优惠券名称"
            data-field="name"
          />
        </el-form-item>
        <el-form-item label="适用范围">
          <el-radio-group v-model="draft.scope_type" :disabled="isEditing" data-field="scope_type">
            <el-radio value="all">无门槛</el-radio>
            <el-radio value="category">指定分类</el-radio>
            <el-radio value="course">指定课程</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="draft.scope_type === 'category'" label="指定分类">
          <el-select
            v-model="draft.scope_category_ids"
            multiple
            filterable
            clearable
            collapse-tags
            collapse-tags-tooltip
            :disabled="isEditing"
            :loading="categoryOptionsLoading"
            placeholder="选择分类"
            no-data-text="暂无可选分类"
            style="width: 100%"
            data-field="scope_category_ids"
          >
            <el-option
              v-for="category in categoryOptions"
              :key="category.id"
              :label="categoryOptionLabel(category)"
              :value="category.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item v-if="draft.scope_type === 'course'" label="指定课程">
          <el-select
            v-model="draft.scope_course_ids"
            multiple
            filterable
            remote
            clearable
            collapse-tags
            collapse-tags-tooltip
            :disabled="isEditing"
            :loading="courseOptionsLoading"
            :remote-method="searchCourses"
            :reserve-keyword="false"
            placeholder="搜索并选择课程"
            no-data-text="暂无可选课程"
            no-match-text="无匹配课程"
            style="width: 100%"
            data-field="scope_course_ids"
            @visible-change="onCourseVisibleChange"
          >
            <el-option
              v-for="course in courseOptions"
              :key="course.id"
              :label="courseOptionLabel(course)"
              :value="course.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="满减门槛 (元)">
          <el-input-number
            v-model="draft.min_amount"
            :disabled="isEditing"
            :min="0"
            :precision="2"
            :step="10"
            data-field="min_amount"
          />
        </el-form-item>
        <el-form-item label="减免金额 (元)">
          <el-input-number
            v-model="draft.discount_amount"
            :disabled="isEditing"
            :min="0.01"
            :precision="2"
            :step="1"
            data-field="discount_amount"
          />
        </el-form-item>
        <el-form-item label="领取方式">
          <el-radio-group v-model="draft.claim_mode" :disabled="isEditing" data-field="claim_mode">
            <el-radio value="public">公开领取</el-radio>
            <el-radio value="admin_only">仅后台发放</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="领取开始时间">
          <el-date-picker
            v-model="draft.claim_starts_at"
            type="datetime"
            format="YYYY-MM-DD HH:mm"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="选择领取开始时间"
            :disabled="isEditing"
            style="width: 100%"
            data-field="claim_starts_at"
          />
        </el-form-item>
        <el-form-item label="领取结束时间">
          <el-date-picker
            v-model="draft.claim_ends_at"
            type="datetime"
            format="YYYY-MM-DD HH:mm"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="选择领取结束时间"
            style="width: 100%"
            data-field="claim_ends_at"
          />
        </el-form-item>
        <el-form-item label="使用截止时间 (可空)">
          <el-date-picker
            v-model="draft.use_ends_at"
            type="datetime"
            format="YYYY-MM-DD HH:mm"
            value-format="YYYY-MM-DD HH:mm:ss"
            clearable
            placeholder="选择使用截止时间"
            style="width: 100%"
            data-field="use_ends_at"
          />
        </el-form-item>
        <el-form-item label="全站总量 (可空)">
          <el-input-number
            v-model="draft.total_quota"
            :min="0"
            :step="100"
            data-field="total_quota"
          />
        </el-form-item>
        <el-form-item label="每人限领">
          <el-input-number
            v-model="draft.per_learner_claim_limit"
            :min="1"
            :step="1"
            data-field="per_learner_claim_limit"
          />
        </el-form-item>
        <el-form-item label="每人限用">
          <el-input-number
            v-model="draft.per_learner_use_limit"
            :min="1"
            :step="1"
            data-field="per_learner_use_limit"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button
          type="primary"
          :loading="saving"
          data-action="submit-coupon"
          @click="submitDialog"
        >
          保存
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="grantDialogVisible"
      :title="`定向发放 - ${currentCoupon?.name ?? ''}`"
      width="480px"
      data-dialog="grant"
    >
      <p class="coupons__grant-hint">
        每行或逗号分隔一个学员 ID。已持有该券达到限领次数的学员会被自动跳过。
      </p>
      <el-input
        v-model="grantLearnerIds"
        type="textarea"
        :rows="5"
        placeholder="例如:101,102,103"
        data-field="learner_ids"
      />
      <template #footer>
        <el-button @click="grantDialogVisible = false">取消</el-button>
        <el-button type="primary" data-action="submit-grant" @click="submitGrant">发放</el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="redemptionDialogVisible"
      :title="`使用记录 - ${currentCoupon?.name ?? ''}`"
      width="720px"
      data-dialog="redemptions"
    >
      <el-table v-loading="redemptionLoading" :data="redemptions" stripe>
        <el-table-column prop="learner_masked_phone" label="学员" min-width="120" />
        <el-table-column prop="course_title" label="课程" min-width="160" />
        <el-table-column prop="order_id" label="订单" min-width="80" />
        <el-table-column label="抵扣金额" min-width="120">
          <template #default="{ row }">¥ {{ row.discount_amount.toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="used_at" label="使用时间" min-width="180" />
      </el-table>
      <p v-if="redemptionTotal === 0 && !redemptionLoading" class="coupons__empty">尚无使用记录</p>
    </el-dialog>
  </main>
</template>

<style scoped>
.coupons {
  display: grid;
  gap: 16px;
  padding-bottom: 48px;
}
.coupons__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.coupons__title {
  margin: 0;
  font-size: 22px;
}
.coupons__lede {
  margin: 4px 0 0;
  font-size: 13px;
  color: var(--ink-2, #606266);
}
.coupons__filters {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
}
.coupons__filters :deep(.el-select) {
  width: 100%;
}
.coupons__alert {
  margin-bottom: 8px;
}
.coupons__grant-hint {
  margin: 0 0 8px;
  font-size: 13px;
  color: var(--ink-2, #606266);
}
.coupons__empty {
  text-align: center;
  color: var(--ink-2, #909399);
  margin: 24px 0 8px;
}
@media (max-width: 640px) {
  .coupons__filters {
    grid-template-columns: 1fr;
  }
}
</style>
