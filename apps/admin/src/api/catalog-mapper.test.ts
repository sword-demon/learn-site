/**
 * Test double implementation of CatalogDataMapper.
 * 
 * Returns fixed mock data without making HTTP calls.
 * Used for unit testing mapping logic in isolation.
 */

import type {
  CategoryDTO,
  CourseDTO,
  CourseTreeDTO,
  PaginatedCourses,
} from '@learn-site/contracts';

import type {
  CategoryNode,
  FlatCategoryRow,
  CategoryFormData,
  CourseTableView,
  CourseEditorForm,
  CoursePaginationView,
  MapOptions,
  MockCatalogData,
  CatalogDataMapper,
} from './types/catalog-views';

import { StandardMapper } from './catalog-mapper';

/**
 * Test-only extension of CatalogDataMapper interface.
 */
export interface TestableCatalogDataMapper extends CatalogDataMapper {
  setMockData(mock: MockCatalogData): void;
}

/**
 * Test double mapper that returns pre-defined mock data.
 */
export class TestMapper implements CatalogDataMapper, TestableCatalogDataMapper {
  private mockData: MockCatalogData | null = null;
  
  constructor() {}
  
  /**
   * Set fixed mock data for unit tests.
   * This is the ONLY method on TestMapper, not part of base interface.
   */
  setMockData(mock: MockCatalogData): void {
    this.mockData = mock;
  }
  
  // ─── Category transformations ────────────────────────────────
  
  toCategoryTreeView(dtoList: CategoryDTO[]): CategoryNode[] {
    if (this.mockData) {
      return this._buildTree(this.mockData.categories);
    }
    
    // Fallback to standard implementation
    const mapper = new StandardMapper();
    return mapper.toCategoryTreeView(dtoList);
  }
  
  toCategoryFlatList(nodes: CategoryNode[]): FlatCategoryRow[] {
    if (this.mockData && nodes.length === 0) {
      // Return flattened mock tree
      const tree = this._buildTree(this.mockData.categories);
      return this._flattenTree(tree);
    }
    
    const mapper = new StandardMapper();
    return mapper.toCategoryFlatList(nodes);
  }
  
  toCategoryEditorForm(dto?: CategoryDTO | null): CategoryFormData {
    if (this.mockData && !dto) {
      // Return empty form when creating new
      return {
        id: null,
        parent_id: null,
        name: '',
        sort: 0,
      };
    }
    
    const mapper = new StandardMapper();
    return mapper.toCategoryEditorForm(dto);
  }
  
  // ─── Course transformations ──────────────────────────────────
  
  toCourseTableView(dtoList: CourseDTO[], options?: MapOptions): CourseTableView[] {
    if (this.mockData) {
      // Convert mock categories to course views
      return this.mockData.courses.map(course => ({
        ...course,
        formatted_price: '¥99.00',
        status_label: '草稿' as const,
        status_type: 'info' as const,
      }));
    }
    
    const mapper = new StandardMapper();
    return mapper.toCourseTableView(dtoList, options);
  }
  
  toCourseTreeForm(tree: CourseTreeDTO): CourseEditorForm {
    // Build tree form from mock courses
    if (this.mockData?.featuredCourse) {
      const mockTree = this.mockData.featuredCourse;
      return {
        id: mockTree.id,
        department_id: mockTree.department_id,
        category_id: mockTree.category_id,
        title: mockTree.title,
        cover_url: mockTree.cover_url,
        teacher_name: mockTree.teacher_name,
        summary: mockTree.summary,
        intro_rich_text: mockTree.intro_rich_text,
        price_mode: mockTree.price_mode,
        list_price: mockTree.list_price,
        sale_price: mockTree.sale_price,
        sale_start_at: mockTree.sale_start_at,
        sale_end_at: mockTree.sale_end_at,
        created_by_staff_id: mockTree.created_by_staff_id,
        chapters: mockTree.chapters.map(chapter => ({
          id: chapter.id,
          course_id: chapter.course_id,
          title: chapter.title,
          sort: chapter.sort,
          status: 'enabled' as const,
          lessons: chapter.lessons.map(lesson => ({
            id: lesson.id,
            chapter_id: lesson.chapter_id,
            title: lesson.title,
            sort: lesson.sort,
            status: 'enabled' as const,
            content_type: 'markdown' as const,
            body_markdown: null,
            asset_id: null,
            is_preview: false,
            duration_seconds: 0,
          })),
        })),
      };
    }
    
    const mapper = new StandardMapper();
    return mapper.toCourseTreeForm(tree);
  }
  
  toCoursePagination(paginated: PaginatedCourses): CoursePaginationView {
    if (this.mockData) {
      return {
        items: this.mockData.courses.map(course => ({
          ...course,
          formatted_price: '¥99.00',
          status_label: '草稿' as const,
          status_type: 'info' as const,
        })),
        total: paginated.total,
        page: paginated.page,
        limit: paginated.limit,
      };
    }
    
    const mapper = new StandardMapper();
    return mapper.toCoursePagination(paginated);
  }
  
  // ─── Private helpers ─────────────────────────────────────────
  
  private _buildTree(categories: CategoryDTO[]): CategoryNode[] {
    const nodeMap = new Map<number, CategoryNode>();
    
    for (const dto of categories) {
      nodeMap.set(dto.id, { ...dto, children: [] });
    }
    
    const roots: CategoryNode[] = [];
    for (const dto of categories) {
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
  
  private _flattenTree(nodes: CategoryNode[]): FlatCategoryRow[] {
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
}
