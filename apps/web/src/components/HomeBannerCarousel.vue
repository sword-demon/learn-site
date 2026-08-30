<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import type { BannerPublicDTO } from '@learn-site/contracts';

defineOptions({ name: 'HomeBannerCarousel' });

const props = defineProps<{ banners: BannerPublicDTO[] }>();
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
      indicator-position="outside"
      height="clamp(180px, 28vw, 360px)"
    >
      <el-carousel-item v-for="banner in orderedBanners" :key="banner.id">
        <div class="banner-frame" :data-banner-id="banner.id">
          <button
            v-if="banner.link_url"
            type="button"
            class="banner-action"
            :aria-label="`打开轮播图 ${banner.id}`"
            @click="navigate(banner.link_url)"
          >
            <el-image :src="banner.image_url" fit="cover" alt="首页轮播图" class="banner-image" />
          </button>
          <el-image
            v-else
            :src="banner.image_url"
            fit="cover"
            alt="首页轮播图"
            class="banner-image"
          />
        </div>
      </el-carousel-item>
    </el-carousel>
  </section>
</template>

<style scoped>
.home-banner-carousel {
  width: 100%;
  margin: 0 0 24px;
}

.home-banner-carousel :deep(.el-carousel__container) {
  min-height: 180px;
  overflow: hidden;
  border: 1px solid var(--line);
  border-radius: var(--r);
  background: var(--card-2);
}

.banner-frame,
.banner-action,
.banner-image {
  width: 100%;
  height: 100%;
}

.banner-action {
  display: block;
  padding: 0;
  border: 0;
  cursor: pointer;
  background: transparent;
}

.banner-image {
  display: block;
}
</style>
