<script setup lang="ts">
import { ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { createShareEntry, revokeShareEntry } from '@/api/distribution';
import { useDistribution } from '@/composables/useDistribution';

defineOptions({ name: 'DistributionView' });

const { loading, hidden, detailHidden, shares, commissions, downline, reload } = useDistribution();
const creating = ref(false);

async function createSiteLink(): Promise<void> {
  creating.value = true;
  try {
    const created = await createShareEntry({ scope: 'site' });
    ElMessage.success(created.share_url);
    await reload();
  } catch (error) {
    ElMessage.error((error as Error).message);
  } finally {
    creating.value = false;
  }
}

async function revoke(id: number): Promise<void> {
  try {
    await ElMessageBox.confirm('撤销后该分享入口立即失效, 且不可恢复。确认撤销?', '撤销分享入口', {
      type: 'warning',
    });
  } catch {
    return;
  }
  await revokeShareEntry(id);
  await reload();
}
</script>

<template>
  <main class="page">
    <h1>我的分销</h1>
    <p v-if="loading">加载中…</p>
    <el-alert v-else-if="hidden" title="分销未开启" type="info" :closable="false" />
    <el-alert
      v-else-if="detailHidden"
      title="佣金明细当前不可查看"
      type="info"
      :closable="false"
      description="站点已开启分销, 但学员明细开关已关闭。"
    />
    <template v-else>
      <section>
        <h2>分享链接</h2>
        <el-button type="primary" :loading="creating" @click="createSiteLink">生成全站链接</el-button>
        <el-table :data="shares">
          <el-table-column prop="masked_code" label="短码" />
          <el-table-column prop="visit_count" label="访问" />
          <el-table-column prop="bound_count" label="绑定" />
          <el-table-column label="操作" width="100">
            <template #default="{ row }">
              <el-button v-if="!row.revoked_at" text @click="revoke(row.id)">撤销</el-button>
            </template>
          </el-table-column>
        </el-table>
      </section>
      <section v-if="commissions">
        <h2>佣金总览</h2>
        <p>
          待结算 {{ commissions.summary.pending_cents }} 分 / 已结算
          {{ commissions.summary.settled_cents }} 分 / 已撤销
          {{ commissions.summary.voided_cents }} 分 / 累计
          {{ commissions.summary.total_cents }} 分
        </p>
        <el-table :data="commissions.items">
          <el-table-column prop="course_title" label="课程" />
          <el-table-column prop="referee_masked_phone" label="被推荐人" />
          <el-table-column prop="level" label="级别" />
          <el-table-column prop="amount_cents" label="金额(分)" />
          <el-table-column prop="status" label="状态" />
        </el-table>
      </section>
      <section>
        <h2>我的下级</h2>
        <el-table :data="downline">
          <el-table-column prop="masked_phone" label="手机号" />
          <el-table-column prop="level" label="级别" />
          <el-table-column prop="registered_at" label="注册时间" />
        </el-table>
      </section>
    </template>
  </main>
</template>

<style scoped>
.page { padding: 24px; max-width: 960px; margin: 0 auto; }
section { margin-top: 24px; }
</style>
