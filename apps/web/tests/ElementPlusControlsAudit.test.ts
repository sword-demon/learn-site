import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const root = fileURLToPath(new URL('../src/', import.meta.url));
const forbidden = /<(button|input|select|textarea|dialog|details|summary)\b/g;

const controlGroups: Record<string, string[]> = {
  'shell and forms': [
    'layouts/LearnerLayout.vue',
    'views/auth/LoginRegisterView.vue',
    'components/PdfViewer.vue',
  ],
  'reviews and questions': [
    'views/catalog/ReviewTree.vue',
    'views/catalog/ReviewReplyBranch.vue',
    'views/learn/QuestionPanel.vue',
  ],
  'catalog and sharing': [
    'views/catalog/AccessGate.vue',
    'views/catalog/CategoryView.vue',
    'views/catalog/CourseDetailView.vue',
    'views/catalog/CourseOutline.vue',
    'views/catalog/ShareBar.vue',
    'views/checkout/CheckoutView.vue',
    'components/SharePosterDialog.vue',
  ],
  'discovery and maps': [
    'views/home/HomeView.vue',
    'views/home/CourseShelfCard.vue',
    'components/CourseEntryRow.vue',
    'views/maps/MapListView.vue',
    'views/maps/MapDetailView.vue',
  ],
  'learning and personal': ['views/learn/LessonView.vue', 'views/me/StudentCenterView.vue'],
};

function violations(relativePath: string): string[] {
  const source = readFileSync(`${root}${relativePath}`, 'utf8')
    .replace(/<script\b[\s\S]*?<\/script>/g, '')
    .replace(/<style\b[\s\S]*?<\/style>/g, '');

  return [...source.matchAll(forbidden)].map((match) => `${relativePath}: <${match[1]}>`);
}

describe('Element Plus control migration', () => {
  it.each(Object.entries(controlGroups))('%s has no native interactive controls', (_, files) => {
    expect(files.flatMap(violations)).toEqual([]);
  });
});
