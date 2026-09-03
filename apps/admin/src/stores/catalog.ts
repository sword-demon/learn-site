/**
 * Pinia store for catalog data caching and state management.
 * 
 * Provides reactive caching for mapped data across components.
 */

import { defineStore } from 'pinia';
import { ref, shallowReactive } from 'vue';
import type { CatalogDataMapper } from '@/api/catalog-mapper';
import type {
  CategoryNode,
  CourseTableView,
} from '@/api/types/catalog-views';

interface CatalogCacheItem<T> {
  data: T;
  timestamp: number;
}

export const useCatalogStore = defineStore('catalog', () => {
  // State
  const cache = shallowReactive<Map<string, CatalogCacheItem<unknown>>>(new Map());
  const mapper = ref<CatalogDataMapper | null>(null);
  
  // Actions
  function setMapper(m: CatalogDataMapper): void {
    mapper.value = m;
  }
  
  /**
   * Get or compute cached mapped data.
   */
  function getOrMap<T>(key: string, computeFn: () => T): T {
    const cached = cache.get(key);
    if (cached) {
      return cached.data as T;
    }
    
    const result = computeFn();
    cache.set(key, { data: result, timestamp: Date.now() });
    return result;
  }
  
  /**
   * Cache category tree mapping result.
   */
  function cacheAndMapCategoryTree(dtoList: unknown[]): CategoryNode[] {
    const key = 'categoryTree';
    
    return getOrMap(key, () => {
      if (!mapper.value) {
        throw new Error('Mapper not initialized in store');
      }
      // Assume DTOs conform to CategoryDTO[] interface
      return mapper.value.toCategoryTreeView(dtolist as any);
    });
  }
  
  /**
   * Invalidate specific cache entry.
   */
  function invalidateCache(key: string): void {
    cache.delete(key);
  }
  
  /**
   * Clear all cache.
   */
  function clearAllCache(): void {
    cache.clear();
  }
  
  return {
    setMapper,
    cacheAndMapCategoryTree,
    invalidateCache,
    clearAllCache,
  };
});

declare module 'vue' {
  interface ComponentCustomProperties {
    $catalogStore: ReturnType<typeof useCatalogStore>;
  }
}
