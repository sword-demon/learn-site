import { ApiResponse, LearnerNextActionDTO } from '@learn-site/contracts';
import { http } from '@/api/http';

export async function fetchNextAction(): Promise<LearnerNextActionDTO> {
  const { data } = await http.get('/me/next-action');
  const parsed = ApiResponse(LearnerNextActionDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}
