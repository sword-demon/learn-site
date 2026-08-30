import axios from 'axios';
import {
  ApiErr,
  ApiResponse,
  CreateCheckinInput,
  LearnerCheckinDTO,
  LearnerCheckinListDTO,
  LearnerTodayCheckinDTO,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export { CreateCheckinInput, LearnerCheckinDTO, LearnerCheckinListDTO, LearnerTodayCheckinDTO };

function throwApi(error: unknown): never {
  if (axios.isAxiosError(error) && error.response?.data) {
    const parsed = ApiErr.safeParse(error.response.data);
    if (parsed.success) {
      throw Object.assign(new Error(parsed.data.error.message), {
        code: parsed.data.error.code,
      });
    }
  }
  throw error;
}

export async function fetchTodayCheckinStatus(): Promise<LearnerTodayCheckinDTO> {
  const { data } = await http.get('/checkins/today');
  const parsed = ApiResponse(LearnerTodayCheckinDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function createCheckin(planHtml: string): Promise<LearnerCheckinDTO> {
  const body = CreateCheckinInput.parse({ plan_html: planHtml });
  try {
    const { data } = await http.post('/checkins', body);
    const parsed = ApiResponse(LearnerCheckinDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.message), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (error) {
    throwApi(error);
  }
}

export async function listCheckins(page = 1, limit = 20): Promise<LearnerCheckinListDTO> {
  const { data } = await http.get('/checkins', { params: { page, limit } });
  const parsed = ApiResponse(LearnerCheckinListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function getCheckin(id: number): Promise<LearnerCheckinDTO> {
  const { data } = await http.get(`/checkins/${id}`);
  const parsed = ApiResponse(LearnerCheckinDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}
