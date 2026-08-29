<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { getStaff, listPermissions, setStaffOverrides } from '@/api/org';
import { hasPermission } from '@/api/http';
import type { PermissionDTO, StaffDetailDTO } from '@learn-site/contracts';

defineOptions({ name: 'StaffOverrideView' });

const route = useRoute();
const router = useRouter();

const staffId = computed(() => Number(route.params.id));

const detail = ref<StaffDetailDTO | null>(null);
const allPermissions = ref<PermissionDTO[]>([]);
const loading = ref(false);
const saving = ref(false);
const errorMsg = ref<string | null>(null);

// Local edit buffer: code → effect ('grant'|'deny'|null=clear).
const buffer = ref<Record<string, 'grant' | 'deny' | null>>({});
const dirty = ref(false);

async function reload(): Promise<void> {
  if (!Number.isFinite(staffId.value) || staffId.value <= 0) {
    detail.value = null;
    errorMsg.value = '员工编号无效';
    return;
  }
  loading.value = true;
  errorMsg.value = null;
  try {
    const [d, p] = await Promise.all([getStaff(staffId.value), listPermissions()]);
    detail.value = d;
    allPermissions.value = p.items;
    buffer.value = {};
    for (const o of d.overrides) {
      buffer.value[o.code] = o.effect;
    }
    dirty.value = false;
  } catch (err: unknown) {
    errorMsg.value = readError(err, '加载权限覆盖失败');
  } finally {
    loading.value = false;
  }
}

function setEffect(code: string, effect: 'grant' | 'deny' | null): void {
  if (!hasPermission(code) || detail.value?.staff.is_super_admin) return;
  buffer.value[code] = effect;
  dirty.value = true;
}

async function saveAll(): Promise<void> {
  if (!detail.value || saving.value) return;
  saving.value = true;
  errorMsg.value = null;
  try {
    const entries: Array<{ code: string; effect: 'grant' | 'deny' }> = [];
    for (const [code, effect] of Object.entries(buffer.value)) {
      if (effect === 'grant' || effect === 'deny') {
        entries.push({ code, effect });
      }
    }
    const out = await setStaffOverrides(detail.value.staff.account_id, { entries });
    detail.value = {
      ...detail.value,
      overrides: out.overrides,
    };
    buffer.value = {};
    for (const o of out.overrides) {
      buffer.value[o.code] = o.effect;
    }
    dirty.value = false;
  } catch (err: unknown) {
    errorMsg.value = readError(err, '保存权限覆盖失败');
  } finally {
    saving.value = false;
  }
}

function back(): void {
  router.push({ name: 'org-staff' });
}

function readError(err: unknown, fallback: string): string {
  const code = (err as { response?: { data?: { error?: { code?: string } } } })?.response?.data
    ?.error?.code;
  const message = (err as { response?: { data?: { error?: { message?: string } } } })?.response
    ?.data?.error?.message;
  if (code === 'FORBIDDEN') return '当前账号没有权限管理用户级覆盖';
  if (code === 'SELF_GUARD') return '不能修改自己的权限覆盖';
  if (code === 'OVERRIDE_NOT_HELD') return '不能覆盖当前账号未持有的权限';
  if (code === 'OVERRIDE_CODE_UNKNOWN') return '权限代码无效';
  if (code === 'OVERRIDE_EFFECT_INVALID') return '覆盖效果无效';
  if (code === 'OVERRIDE_ENTRIES_REQUIRED') return '请提供权限覆盖列表';
  if (code === 'VALIDATION_FAILED') return message ?? '请求校验失败';
  return fallback;
}

watch(
  staffId,
  () => {
    void reload();
  },
  { immediate: true },
);
</script>

<template>
  <section class="page override-view">
    <header class="head">
      <el-button class="btn back" @click="back">返回员工列表</el-button>
      <h1 class="display">
        权限覆盖 ·
        <span v-if="detail">{{ detail.staff.display_name }} (#{{ detail.staff.account_id }})</span>
        <span v-else>加载中…</span>
      </h1>
      <p class="hint">用户级 grant / deny · deny 优先 · 仅可覆盖你自身持有的权限</p>
    </header>

    <p v-if="loading" class="notice">加载中…</p>
    <p v-else-if="errorMsg" class="notice error">{{ errorMsg }}</p>

    <template v-else-if="detail">
      <p v-if="detail.staff.is_super_admin" class="notice warn">
        该员工为超级管理员,权限覆盖对其不生效.
      </p>

      <div class="grid-wrap">
        <el-table :data="allPermissions" stripe class="grid">
          <el-table-column label="权限代码" min-width="180">
            <template #default="{ row }"
              ><code>{{ row.code }}</code></template
            >
          </el-table-column>
          <el-table-column prop="module" label="模块" min-width="120" />
          <el-table-column prop="description" label="说明" min-width="180" />
          <el-table-column label="覆盖" min-width="180" fixed="right" class-name="actions">
            <template #default="{ row }">
              <div class="actions">
                <el-button
                  class="btn"
                  :class="{ active: buffer[row.code] === 'grant' }"
                  :disabled="detail.staff.is_super_admin || !hasPermission(row.code)"
                  :title="hasPermission(row.code) ? '授予或清除覆盖' : '当前账号未持有此权限'"
                  @click="setEffect(row.code, buffer[row.code] === 'grant' ? null : 'grant')"
                >
                  允许
                </el-button>
                <el-button
                  class="btn deny"
                  :class="{ active: buffer[row.code] === 'deny' }"
                  :disabled="detail.staff.is_super_admin || !hasPermission(row.code)"
                  :title="hasPermission(row.code) ? '禁用或清除覆盖' : '当前账号未持有此权限'"
                  @click="setEffect(row.code, buffer[row.code] === 'deny' ? null : 'deny')"
                >
                  禁止
                </el-button>
              </div>
            </template>
          </el-table-column>
          <template #empty><el-empty description="暂无可配置权限" :image-size="88" /></template>
        </el-table>
      </div>

      <footer class="footer">
        <span class="muted">deny 永远优先于 grant;留空表示清除覆盖,沿用角色权限.</span>
        <el-button class="btn btn-primary" :disabled="!dirty || saving" @click="saveAll">
          保存覆盖
        </el-button>
      </footer>
    </template>
  </section>
</template>

<style scoped>
.page {
  display: grid;
  gap: 16px;
}
.head {
  display: grid;
  gap: 4px;
}
.display {
  margin: 0;
  font-size: 1.3rem;
}
.hint {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
  font-size: 0.85rem;
}
.notice {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
}
.notice.error {
  color: #b42318;
}
.notice.warn {
  color: #b45309;
}
.grid-wrap {
  overflow-x: auto;
}
.grid {
  width: 100%;
  min-width: 680px;
  border-collapse: collapse;
  font-size: 0.9rem;
  background: #fff;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  overflow: hidden;
}
.grid th,
.grid td {
  border-bottom: 1px solid var(--color-border, #e6e8ee);
  padding: 8px 12px;
  text-align: left;
}
.grid th {
  background: var(--color-bg-soft, #fafbfd);
  font-weight: 600;
}
.actions {
  display: flex;
  gap: 6px;
}
.actions .btn {
  padding: 4px 10px;
}
.empty {
  color: var(--color-text-muted, #5b6472);
  text-align: center !important;
}
.btn {
  padding: 6px 12px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  background: transparent;
  font: inherit;
  cursor: pointer;
}
.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.btn.active {
  background: var(--color-primary, #2563eb);
  color: #fff;
  border-color: transparent;
}
.btn.deny.active {
  background: #b42318;
  color: #fff;
  border-color: transparent;
}
.btn-primary {
  background: var(--color-primary, #2563eb);
  color: #fff;
  border-color: transparent;
}
.btn.back {
  align-self: start;
  width: max-content;
}
.footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.muted {
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
}
</style>
