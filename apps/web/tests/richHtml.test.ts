// @vitest-environment happy-dom

import { describe, expect, it } from 'vitest';
import { hasRichHtml } from '@/utils/richHtml';

describe('hasRichHtml', () => {
  it('returns false for empty or whitespace-only HTML', () => {
    expect(hasRichHtml('')).toBe(false);
    expect(hasRichHtml('<p><br></p>')).toBe(false);
    expect(hasRichHtml('<p>&nbsp;</p>')).toBe(false);
  });

  it('returns true when sanitized HTML contains visible text', () => {
    expect(hasRichHtml('<p>课程介绍</p>')).toBe(true);
    expect(hasRichHtml('<pre><code>func main() {}</code></pre>')).toBe(true);
  });
});
