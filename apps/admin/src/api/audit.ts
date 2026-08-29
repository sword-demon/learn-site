import http from './http';
import {
  ApiOk,
  ModerationLogListDTO,
  type ModerationAction,
  type ModerationObjectType,
} from '@learn-site/contracts';

const ModerationLogListEnvelope = ApiOk(ModerationLogListDTO);

export interface ModerationLogListParams {
  object_type?: ModerationObjectType;
  action?: ModerationAction;
  staff_id?: number;
  page?: number;
  limit?: number;
}

export interface ModeratedContentRef {
  object_type: ModerationObjectType;
  object_id: number;
}

export async function listModerationLogs(
  params: ModerationLogListParams = {},
): Promise<ModerationLogListDTO> {
  const response = await http.get<unknown>('/moderation-logs', { params });
  return ModerationLogListEnvelope.parse(response.data).data;
}

export async function restoreModeratedContent(target: ModeratedContentRef): Promise<void> {
  const path =
    target.object_type === 'review'
      ? `/reviews/${target.object_id}/restore`
      : `/review-replies/${target.object_id}/restore`;
  await http.post(path);
}
