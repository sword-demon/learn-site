import { ApiResponse, SiteIntro, SiteProfileUpdateInput } from '@learn-site/contracts';
import { http } from '@/api/http';

export { SiteIntro, SiteProfileUpdateInput };

export async function fetchSiteProfile(): Promise<SiteIntro> {
  const { data } = await http.get('/site');
  const parsed = ApiResponse(SiteIntro).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function updateSiteProfile(input: SiteProfileUpdateInput): Promise<SiteIntro> {
  const validInput = SiteProfileUpdateInput.parse(input);
  const { data } = await http.patch('/site', validInput);
  const parsed = ApiResponse(SiteIntro).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}
