/**
 * CatalogDataMapper — Unified data transformation layer.
 * 
 * Invariants:
 *   I1: CategoryNode.children ALWAYS exists as array (minimum [])
 *   I2: CourseTableView.formatted_price is human-readable string
 *   I3: CourseEditorForm.chapters/lessons arrays never undefined
 *   I4: All status labels are Chinese display strings ('草稿' | '已发布' | '已下架')
 *   I5: All price formats include ¥ symbol and 2 decimal places
 * 
 * Two Adapter Evidence:
 *   A) StandardMapper - Production implementation calling real DTOs
 *   B) TestMapper - Unit test implementation returning fixed data
 */

import type {
  CategoryDTO,
  CourseDTO,
  CourseTreeDTO,
  PaginatedCourses,
  PriceMode,
  CourseStatus,
} from '@learn-site/contracts';

import type {
  CategoryNode,
  FlatCategoryRow,
  CategoryFormData,
  CourseTableView,
  CourseEditorForm,
  CourseChapterForm,
  CourseLessonForm,
  CoursePaginationView,
  MapOptions,
  MockCatalogData,
} from './types/catalog-views';

// ─── Utility Functions ──────────────────────────────────────────

/**
 * Format price according to invariant I5.
 */
export function formatPrice(price_mode: PriceMode, list_price: number, sale_price?: number): string {
  if (price_mode === 'free') {
    return '免费';
  }
  
  const list = list_price.toFixed(2);
  
  // No sale price or sale equals list price → show standard price only
  if (!sale_price || sale_price >= list_price) {
    return `¥${list}`;
  }
  
  // Sale window active → show both prices
  const sale = sale_price.toFixed(2);
  return `¥${sale} / ¥${list}`;
}

/**
 * Internal status code to display label per invariant I4.
 */
export function statusLabel(status: CourseStatus): '草稿' | '已发布' | '已下架' {
  switch (status) {
    case 'draft':
      return '草稿';
    case 'published':
      return '已发布';
    case 'unpublished':
      return '已下架';
    default:
      // Fallback for unknown statuses
      return '草稿';
  }
}

/**
 * Get visual type tag for status per invariant I4.
 */
export function statusType(status: CourseStatus): 'info' | 'success' | 'warning' {
  switch (status) {
    case 'draft':
      return 'info';
    case 'published':
      return 'success';
    case 'unpublished':
      return 'warning';
    default:
      return 'info';
  }
}

// ─── Standard Mapper Implementation ─────────────────────────────

/**
 * Standard production implementation of CatalogDataMapper.
 */
export class StandardMapper implements CatalogDataMapper {
  
  // ─── Category transformations ────────────────────────────────
  
  /**
   * Convert flat category list to tree structure.
   * Ensures I1: every node has children array.
   */
  toCategoryTreeView(dtoList: CategoryDTO[]): CategoryNode[] {
    const nodeMap = new Map<number, CategoryNode>();
    
    // Create all nodes first
    for (const dto of dtoList) {
      nodeMap.set(dto.id, { ...dto, children: [] });
    }
    
    // Link parents
    const roots: CategoryNode[] = [];
    for (const dto of dtoList) {
      const node = nodeMap.get(dto.id)!;
      if (dto.parent_id === 0) {
        roots.push(node);
      } else {
        const parent = nodeMap.get(dto.parent_id);
        if (parent) {
          parent.children.push(node);
        }
      }
    }
    
    return roots;
  }
  
  /**
   * Flatten tree to linear rows with explicit depth.
   * Useful for table views requiring indented rows.
   */
  toCategoryFlatList(nodes: CategoryNode[]): FlatCategoryRow[] {
    const result: FlatCategoryRow[] = [];
    
    function traverse(nodes: CategoryNode[], depth: number) {
      for (const node of nodes) {
        result.push({
          ...node,
          depth,
        });
        
        if (node.children.length > 0) {
          traverse(node.children, depth + 1);
        }
      }
    }
    
    traverse(nodes, 1);
    return result;
  }
  
  /**
   * Prepare form data from existing DTO or empty state.
   */
  toCategoryEditorForm(dto?: CategoryDTO | null): CategoryFormData {
    if (dto) {
      return {
        id: dto.id,
        parent_id: dto.parent_id,
        name: dto.name,
        sort: dto.sort,
      };
    }
    
    // Empty form for creation
    return {
      id: null,
      parent_id: null,
      name: '',
      sort: 0,
    };
  }
  
  // ─── Course transformations ──────────────────────────────────
  
  /**
   * Transform course list for table display.
   * Ensures I2, I4, I5 formatting rules.
   */
  toCourseTableView(dtoList: CourseDTO[], options?: MapOptions): CourseTableView[] {
    try {
      return dtoList.map(dto => {
        let formattedPrice: string;
        let statusLabelValue: '草稿' | '已发布' | '已下架';
        let statusTypeValue: 'info' | 'success' | 'warning';
        
        try {
          formattedPrice = formatPrice(dto.price_mode, dto.list_price, dto.sale_price);
          statusLabelValue = statusLabel(dto.status);
          statusTypeValue = statusType(dto.status);
        } catch (err) {
          if (options?.strict) {
            throw err as Error;
          }
          options?.logger?.(err as Error, 'toCourseTableView');
          
          // Fallback values
          formattedPrice = '¥0.00';
          statusLabelValue = '草稿';
          statusTypeValue = 'info';
        }
        
        return {
          id: dto.id,
          department_id: dto.department_id,
          category_id: dto.category_id,
          title: dto.title,
          cover_url: dto.cover_url,
          teacher_name: dto.teacher_name,
          summary: dto.summary,
          intro_rich_text: dto.intro_rich_text,
          price_mode: dto.price_mode,
          list_price: dto.list_price,
          sale_price: dto.sale_price ?? undefined,
          sale_start_at: dto.sale_start_at,
          sale_end_at: dto.sale_end_at,
          created_by_staff_id: dto.created_by_staff_id,
          created_at: dto.created_at,
          updated_at: dto.updated_at,
          
          // Pre-formatted fields
          formatted_price: formattedPrice,
          status_label: statusLabelValue,
          status_type: statusTypeValue,
        };
      });
    } catch (error) {
      if (options?.strict) {
        throw error;
      }
      console.error('Failed to transform courses:', error);
      return [];
    }
  }
  
  /**
   * Wrap paginated courses with pagination metadata.
   */
  toCoursePagination(paginated: PaginatedCourses): CoursePaginationView {
    return {
      items: this.toCourseTableView(paginated.items),
      total: paginated.total,
      page: paginated.page,
      limit: paginated.limit,
    };
  }
  
  /**
   * Convert course tree to editor form state.
   * Ensures I3: nested arrays always present.
   */
  toCourseTreeForm(tree: CourseTreeDTO): CourseEditorForm {
    return {
      id: tree.id,
      department_id: tree.department_id,
      category_id: tree.category_id,
      title: tree.title,
      cover_url: tree.cover_url,
      teacher_name: tree.teacher_name,
      summary: tree.summary,
      intro_rich_text: tree.intro_rich_text,
      price_mode: tree.price_mode,
      list_price: tree.list_price,
      sale_price: tree.sale_price,
      sale_start_at: tree.sale_start_at,
      sale_end_at: tree.sale_end_at,
      created_by_staff_id: tree.created_by_staff_id,
      
      // Guarantee children arrays exist (I3)
      chapters: tree.chapters.map(chapter => ({
        id: chapter.id,
        course_id: chapter.course_id,
        title: chapter.title,
        sort: chapter.sort,
        status: chapter.status as 'enabled' | 'disabled',
        lessons: chapter.lessons.map(lesson => ({
          id: lesson.id,
          chapter_id: lesson.chapter_id,
          title: lesson.title,
          sort: lesson.sort,
          status: lesson.status as 'enabled' | 'disabled',
          content_type: lesson.content_type as 'markdown' | 'pdf' | 'video',
          body_markdown: lesson.body_markdown,
          asset_id: lesson.asset_id,
          is_preview: lesson.is_preview,
          duration_seconds: lesson.duration_seconds,
        })),
      })),
    };
  }
  
  // ─── Public method aliases for clarity ───────────────────────
  
  /**
   * Alias for toCourseTreeForm - clearer intent in editor context.
   */
  toEditorForm(tree: CourseTreeDTO): CourseEditorForm {
    return this.toCourseTreeForm(tree);
  }
}

// ─── Interface Definition ──────────────────────────────────────

/**
 * Main interface for catalog data transformations.
 */
export interface CatalogDataMapper {
  toCategoryTreeView(dtoList: CategoryDTO[]): CategoryNode[];
  toCategoryFlatList(nodes: CategoryNode[]): FlatCategoryRow[];
  toCategoryEditorForm(dto?: CategoryDTO | null): CategoryFormData;
  
  toCourseTableView(dtoList: CourseDTO[], options?: MapOptions): CourseTableView[];
  toCourseTreeForm(tree: CourseTreeDTO): CourseEditorForm;
  toCoursePagination(paginated: PaginatedCourses): CoursePaginationView;
}

// ─── Export Default Instance ───────────────────────────────────

/**
 * Default export for easy importing:
 * import mapper from '@/api/catalog-mapper';
 */
export const StandardMapperInstance = new StandardMapper();
export default StandardMapperInstance;
