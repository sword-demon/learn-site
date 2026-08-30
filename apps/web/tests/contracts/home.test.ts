import { describe, expect, it } from 'vitest';
import { HomePayload } from '@learn-site/contracts';

describe('HomePayload schema', () => {
  it('accepts recommended_maps field', () => {
    const result = HomePayload.parse({
      categories: [],
      site_intro: {
        title: 't',
        subtitle: '',
        body_html: '',
        contact_email: '',
        updated_at: null,
      },
      recent_courses: [],
      banners: [],
      recommended_maps: [],
    });
    expect(result.recommended_maps).toEqual([]);
  });
});
