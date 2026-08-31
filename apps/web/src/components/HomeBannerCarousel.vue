<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import type { BannerPublicDTO } from '@learn-site/contracts';

defineOptions({ name: 'HomeBannerCarousel' });

const props = defineProps<{
  banners: BannerPublicDTO[];
  headline?: string;
}>();
const router = useRouter();

const orderedBanners = computed(() =>
  [...props.banners].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id),
);

function navigate(link: string): void {
  if (link.startsWith('/')) {
    void router.push(link);
    return;
  }
  window.open(link, '_blank', 'noopener,noreferrer');
}

function showHeadline(index: number): boolean {
  return index === 0 && Boolean(props.headline?.trim());
}
</script>

<template>
  <section
    v-if="orderedBanners.length > 0"
    id="home-banner-carousel"
    data-testid="home-banner-carousel"
    class="home-banner-carousel"
    aria-label="首页轮播图"
  >
    <el-carousel
      :interval="5000"
      :arrow="orderedBanners.length > 1 ? 'hover' : 'never'"
      :indicator-position="orderedBanners.length > 1 ? 'outside' : 'none'"
      height="320px"
    >
      <el-carousel-item
        v-for="(banner, index) in orderedBanners"
        :key="banner.id"
        :data-banner-index="index"
      >
        <div class="banner-frame" :data-banner-id="banner.id">
          <button
            v-if="banner.link_url"
            type="button"
            class="banner-action"
            :aria-label="`打开轮播图 ${banner.id}`"
            @click="navigate(banner.link_url)"
          >
            <img :src="banner.image_url" alt="" class="banner-image" />
          </button>
          <img v-else :src="banner.image_url" alt="" class="banner-image" />
          <div v-if="showHeadline(index)" class="banner-copy" aria-hidden="true">
            <h1>{{ headline }}</h1>
          </div>
        </div>
      </el-carousel-item>
    </el-carousel>
  </section>
</template>

<style scoped>
.home-banner-carousel {
  width: 100%;
  margin: 0 0 40px;
}

.home-banner-carousel :deep(.el-carousel__container) {
  min-height: 320px;
  overflow: hidden;
  border: 1px solid var(--line-2);
  border-radius: 12px;
  background: var(--card-2);
}

.home-banner-carousel :deep(.el-carousel__indicators--outside) {
  margin-top: 12px;
}

.banner-frame {
  position: relative;
  width: 100%;
  height: 100%;
  overflow: hidden;
}

.banner-frame::after {
  position: absolute;
  inset: 0;
  content: '';
  pointer-events: none;
  background: linear-gradient(0deg, rgba(26, 27, 31, 0.4), transparent 55%);
}

.banner-action {
  display: block;
  width: 100%;
  height: 100%;
  padding: 0;
  border: 0;
  cursor: pointer;
  background: transparent;
}

.banner-image {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.banner-copy {
  position: absolute;
  z-index: 1;
  left: 24px;
  bottom: 24px;
  color: #fff;
  pointer-events: none;
}

.banner-copy h1 {
  margin: 0;
  font-family: var(--serif);
  font-size: 40px;
  line-height: 1.15;
  font-weight: 700;
  text-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}
</style>
