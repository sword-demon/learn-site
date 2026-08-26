<template>
  <div class="access-gate">
    <slot v-if="!lessonLocked" />
    <div v-else class="gate-card">
      <p class="gate-title">{{ headline }}</p>
      <p class="gate-detail">{{ detail }}</p>
      <p v-if="errorMessage" class="gate-error">{{ errorMessage }}</p>
      <div class="gate-actions">
        <button
          v-if="!authed"
          type="button"
          class="btn btn-primary"
          @click="goLogin"
        >
          登录后学习
        </button>
        <button
          v-else-if="priceMode === 'free'"
          type="button"
          class="btn btn-primary"
          :disabled="busy"
          @click="startFree"
        >
          {{ busy ? '授权中…' : '开始学习' }}
        </button>
        <template v-else>
          <button
            type="button"
            class="btn btn-primary"
            :disabled="busy"
            @click="buy"
          >
            {{ busy ? '下单中…' : '立即购买' }}
          </button>
          <button type="button" class="btn btn-ghost" @click="goOrders">
            查看订单
          </button>
          <router-link :to="`/courses/${courseId}`" class="btn btn-ghost">
            返回课程
          </router-link>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { hasTokens } from '@/api/http'
import { createCourseOrder, startCourse } from '@/api/learner'

interface AccessGateProps {
  locked: boolean
  viewerAuthorized: boolean
  priceMode: 'free' | 'paid'
  courseId: number
  lessonTitle?: string
}

const props = defineProps<AccessGateProps>()
const emit = defineEmits<{ (e: 'entitled'): void }>()
const router = useRouter()
const route = useRoute()

const busy = ref(false)
const errorMessage = ref('')

const lessonLocked = computed(() => props.locked)
const authed = computed(() => hasTokens())

const headline = computed(() => {
  if (!authed.value) return '登录后才能学习这节课'
  if (props.priceMode === 'free') return '尚未获得访问权'
  return '这节课需要购买'
})

const detail = computed(() => {
  if (!authed.value) {
    return props.lessonTitle
      ? `「${props.lessonTitle}」为完整课节, 登录后即可继续.`
      : '登录后即可继续.'
  }
  if (props.priceMode === 'free') {
    return '点击下方按钮立即获得完整访问权.'
  }
  return '完成支付后即可访问全部课节, 现在去下单吗?'
})

function goLogin(): void {
  const redirect = route.fullPath
  router.push(`/login?redirect=${encodeURIComponent(redirect)}`)
}

function goOrders(): void {
  router.push('/me/orders')
}

async function startFree(): Promise<void> {
  busy.value = true
  errorMessage.value = ''
  try {
    const result = await startCourse(props.courseId)
    if (result.first_lesson) {
      router.push(`/learn/${result.course_id}/${result.first_lesson.id}`)
      return
    }
    emit('entitled')
    router.push(`/courses/${result.course_id}`)
  } catch (err: unknown) {
    const code = (err as { code?: string }).code
    if (code === 'CONFLICT') {
      // Already entitled (race) — behave as if it succeeded.
      router.push(`/courses/${props.courseId}`)
      return
    }
    errorMessage.value = code === 'NOT_FOUND'
      ? '课程不存在或已下架.'
      : '授权失败, 请稍后再试.'
  } finally {
    busy.value = false
  }
}

async function buy(): Promise<void> {
  busy.value = true
  errorMessage.value = ''
  try {
    await createCourseOrder(props.courseId)
    router.push('/me/orders')
  } catch (err: unknown) {
    const code = (err as { code?: string }).code
    if (code === 'CONFLICT') {
      // Already entitled (race) — bounce to course detail.
      router.push(`/courses/${props.courseId}`)
      return
    }
    errorMessage.value = '下单失败, 请稍后再试.'
  } finally {
    busy.value = false
  }
}
</script>

<style scoped>
.access-gate {
  display: contents;
}
.gate-card {
  border: 1px dashed var(--color-border, #d0d4dc);
  border-radius: 8px;
  padding: 12px 14px;
  background: var(--color-bg-soft, #f7f8fb);
}
.gate-title {
  font-weight: 600;
  margin: 0 0 4px 0;
}
.gate-detail {
  margin: 0 0 6px 0;
  color: var(--color-text-muted, #5b6472);
  font-size: 13px;
}
.gate-error {
  margin: 0 0 8px 0;
  color: #b42318;
  font-size: 13px;
}
.gate-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.btn {
  display: inline-flex;
  align-items: center;
  padding: 6px 12px;
  border-radius: 6px;
  border: 1px solid transparent;
  font: inherit;
  cursor: pointer;
  text-decoration: none;
}
.btn-primary {
  background: var(--color-primary, #2563eb);
  color: #fff;
}
.btn-primary[disabled] {
  opacity: 0.55;
  cursor: not-allowed;
}
.btn-ghost {
  background: transparent;
  border-color: var(--color-border, #d0d4dc);
  color: inherit;
}
</style>