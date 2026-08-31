<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { ZodError } from 'zod';
import {
  BannerImageKey,
  BannerImageUrl,
  type AdminBannerDTO,
  type CreateBannerInput,
  type UpdateBannerInput,
} from '@learn-site/contracts';
import {
  createBanner,
  deleteBanner,
  listBanners,
  updateBanner,
  type BannerListParams,
} from '@/api/banners';
import { uploadBannerImage, type UploadCoverInput, type UploadCoverResult } from '@/api/covers';
import CourseCoverUpload from '@/views/catalog/CourseCoverUpload.vue';

defineOptions({ name: 'BannerListView' });

type Draft = {
  id: number | null;
  expected_updated_at: string;
  image_url: string;
  link_url: string;
  sort_order: number;
  is_enabled: boolean;
};

function parseBannerImage(url: string): { image_url: string; image_key: string } | null {
  const trimmed = url.trim();
  if (!BannerImageUrl.safeParse(trimmed).success) return null;
  const imageKey = trimmed.slice('/api/media/'.length);
  if (!BannerImageKey.safeParse(imageKey).success) return null;
  return { image_url: trimmed, image_key: imageKey };
}

const items = ref<AdminBannerDTO[]>([]);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const errorMessage = ref('');
const dialogVisible = ref(false);
const filters = reactive<{ is_enabled: '' | 'true' | 'false'; page: number; limit: number }>({
  is_enabled: '',
  page: 1,
  limit: 20,
});
const draft = reactive<Draft>({
  id: null,
  expected_updated_at: '',
  image_url: '',
  link_url: '',
  sort_order: 0,
  is_enabled: true,
});

const dialogTitle = () => (draft.id === null ? '新增轮播图' : '编辑轮播图');

function readError(error: unknown, fallback: string): string {
  if (error instanceof ZodError) {
    const badImage = error.issues.some(
      (issue) => issue.path[0] === 'image_url' || issue.path[0] === 'image_key',
    );
    if (badImage) return '轮播图片无效，请重新上传';
  }
  const response = error as { response?: { data?: { error?: { message?: string } } } };
  const message = response.response?.data?.error?.message ?? (error as Error).message ?? fallback;
  if (message === 'BANNER_IMAGE_PAIR_INVALID') return '轮播图片无效，请重新上传';
  if (message === 'BANNER_VERSION_CONFLICT') return '轮播图已被其他管理员修改，请刷新后重试';
  if (message.includes('轮播图片上传响应无效')) return message;
  return message;
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    const params: BannerListParams = { page: filters.page, limit: filters.limit };
    if (filters.is_enabled !== '') params.is_enabled = filters.is_enabled === 'true';
    const result = await listBanners(params);
    items.value = result.items;
    total.value = result.total;
  } catch (error) {
    errorMessage.value = readError(error, '加载轮播图失败');
  } finally {
    loading.value = false;
  }
}

function resetDraft(): void {
  draft.id = null;
  draft.expected_updated_at = '';
  draft.image_url = '';
  draft.link_url = '';
  draft.sort_order = 0;
  draft.is_enabled = true;
}

function openCreate(): void {
  resetDraft();
  dialogVisible.value = true;
}

function openEdit(row: AdminBannerDTO): void {
  draft.id = row.id;
  draft.expected_updated_at = row.updated_at;
  draft.image_url = row.image_url;
  draft.link_url = row.link_url ?? '';
  draft.sort_order = row.sort_order;
  draft.is_enabled = row.is_enabled;
  dialogVisible.value = true;
}

async function uploadImage(input: UploadCoverInput): Promise<UploadCoverResult> {
  return uploadBannerImage(input);
}

async function save(): Promise<void> {
  const image = parseBannerImage(draft.image_url);
  if (!image) {
    ElMessage.warning('请先上传轮播图片');
    return;
  }
  saving.value = true;
  errorMessage.value = '';
  try {
    const linkUrl = draft.link_url.trim() === '' ? null : draft.link_url.trim();
    if (draft.id === null) {
      const input: CreateBannerInput = {
        image_url: image.image_url,
        image_key: image.image_key,
        link_url: linkUrl,
        sort_order: draft.sort_order,
        is_enabled: draft.is_enabled,
      };
      await createBanner(input);
    } else {
      const input: UpdateBannerInput = {
        expected_updated_at: draft.expected_updated_at,
        image_url: image.image_url,
        image_key: image.image_key,
        link_url: linkUrl,
        sort_order: draft.sort_order,
        is_enabled: draft.is_enabled,
      };
      await updateBanner(draft.id, input);
    }
    dialogVisible.value = false;
    ElMessage.success('已保存');
    await reload();
  } catch (error) {
    errorMessage.value = readError(error, '保存轮播图失败');
    ElMessage.error(errorMessage.value);
  } finally {
    saving.value = false;
  }
}

async function toggle(row: AdminBannerDTO): Promise<void> {
  try {
    await updateBanner(row.id, {
      expected_updated_at: row.updated_at,
      is_enabled: !row.is_enabled,
    });
    ElMessage.success(row.is_enabled ? '已禁用' : '已启用');
    await reload();
  } catch (error) {
    ElMessage.error(readError(error, '状态切换失败'));
  }
}

async function onDelete(row: AdminBannerDTO): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定删除该轮播图吗？`, '确认删除', {
      type: 'warning',
      confirmButtonText: '删除',
      cancelButtonText: '取消',
    });
  } catch {
    return;
  }
  try {
    await deleteBanner(row.id);
    ElMessage.success('已删除');
    await reload();
  } catch (error) {
    ElMessage.error(readError(error, '删除失败'));
  }
}

function formatDateTime(value: string): string {
  return value
    .replace('T', ' ')
    .replace(/\+\d{2}:\d{2}$/, '')
    .slice(0, 19);
}

onMounted(() => void reload());
</script>

<template>
  <section class="page">
    <header class="bar">
      <div>
        <h1>轮播图管理</h1>
        <p class="muted">上传首页展示图片，配置跳转地址与展示顺序。</p>
      </div>
      <el-button type="primary" data-action="create" @click="openCreate">新增轮播图</el-button>
    </header>

    <div class="filters">
      <el-select
        v-model="filters.is_enabled"
        data-filter="enabled"
        clearable
        placeholder="全部状态"
        style="width: 140px"
      >
        <el-option label="仅启用" value="true" />
        <el-option label="仅禁用" value="false" />
      </el-select>
      <el-button
        @click="
          filters.page = 1;
          reload();
        "
        >筛选</el-button
      >
    </div>

    <el-alert v-if="errorMessage" type="error" :title="errorMessage" show-icon :closable="false" />

    <el-table v-loading="loading" :data="items" stripe row-key="id" empty-text="暂无轮播图">
      <el-table-column label="图片" width="190">
        <template #default="{ row }">
          <el-image :src="row.image_url" fit="cover" class="thumb" />
        </template>
      </el-table-column>
      <el-table-column prop="link_url" label="跳转地址" min-width="240">
        <template #default="{ row }">{{ row.link_url || '未配置' }}</template>
      </el-table-column>
      <el-table-column prop="sort_order" label="排序" width="90" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="row.is_enabled ? 'success' : 'info'">
            {{ row.is_enabled ? '启用' : '禁用' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="更新时间" width="180">
        <template #default="{ row }">{{ formatDateTime(row.updated_at) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="250" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" data-action="edit" @click="openEdit(row)">编辑</el-button>
          <el-button
            link
            :type="row.is_enabled ? 'warning' : 'success'"
            data-action="toggle"
            @click="toggle(row)"
          >
            {{ row.is_enabled ? '禁用' : '启用' }}
          </el-button>
          <el-button link type="danger" data-action="delete" @click="onDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="filters.page"
      class="pager"
      :page-size="filters.limit"
      layout="total, prev, pager, next"
      :total="total"
      @current-change="reload"
    />

    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle()"
      width="620px"
      data-dialog="banner"
      destroy-on-close
    >
      <el-form :model="draft" label-position="top">
        <el-form-item label="展示图片" required>
          <CourseCoverUpload v-model="draft.image_url" :upload="uploadImage" />
        </el-form-item>
        <el-form-item label="跳转地址">
          <el-input
            v-model="draft.link_url"
            data-field="link"
            clearable
            maxlength="2048"
            placeholder="站内路径或 http(s) URL，可留空"
          />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="draft.sort_order" data-field="sort" :min="0" :max="9999" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch
            v-model="draft.is_enabled"
            data-field="enabled"
            active-text="启用"
            inactive-text="禁用"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" data-action="save" @click="save"
          >保存</el-button
        >
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.page {
  display: grid;
  gap: 16px;
}
.bar {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}
.bar h1 {
  margin: 0 0 4px;
}
.muted {
  margin: 0;
  color: var(--el-text-color-secondary);
}
.filters {
  display: flex;
  gap: 12px;
  align-items: center;
}
.thumb {
  width: 160px;
  height: 90px;
  border: 1px solid var(--el-border-color-lighter);
  background: var(--el-fill-color-light);
}
.pager {
  justify-content: flex-end;
}
</style>
