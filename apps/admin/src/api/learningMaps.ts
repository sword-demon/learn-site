import http from './http'
import type {
  AddCourseToStageInput,
  AdminMapDetailDTO,
  AdminMapListDTO,
  CreateMapInput,
  CreateStageInput,
  MapStatus,
  UpdateMapInput,
  UpdateStageInput,
} from '@learn-site/contracts'

/**
 * Admin learning-map management API (Phase 13 / US6 — T075).
 * Endpoints live in apps/api/app/controller/admin/LearningMapController.php
 * and are guarded by Authorize with `map.view` / `map.manage`.
 */

export interface AdminListMapParams {
  department_id?: number
  status?: MapStatus
  page?: number
  limit?: number
}

export async function listMaps(params: AdminListMapParams = {}): Promise<AdminMapListDTO> {
  const { data } = await http.get<AdminMapListDTO>('/learning-maps', { params })
  return data
}

export async function getMap(id: number): Promise<AdminMapDetailDTO> {
  const { data } = await http.get<AdminMapDetailDTO>(`/learning-maps/${id}`)
  return data
}

export async function createMap(input: CreateMapInput): Promise<AdminMapDetailDTO> {
  const { data } = await http.post<AdminMapDetailDTO>('/learning-maps', input)
  return data
}

export async function updateMap(id: number, input: UpdateMapInput): Promise<AdminMapDetailDTO> {
  const { data } = await http.patch<AdminMapDetailDTO>(`/learning-maps/${id}`, input)
  return data
}

export async function deleteMap(id: number): Promise<void> {
  await http.delete(`/learning-maps/${id}`)
}

export async function publishMap(id: number): Promise<AdminMapDetailDTO> {
  const { data } = await http.post<AdminMapDetailDTO>(`/learning-maps/${id}/publish`)
  return data
}

export async function unpublishMap(id: number): Promise<AdminMapDetailDTO> {
  const { data } = await http.post<AdminMapDetailDTO>(`/learning-maps/${id}/unpublish`)
  return data
}

export async function addStage(
  mapId: number,
  input: CreateStageInput,
): Promise<AdminMapDetailDTO['stages'][number]> {
  const { data } = await http.post<AdminMapDetailDTO['stages'][number]>(
    `/learning-maps/${mapId}/stages`,
    input,
  )
  return data
}

export async function updateStage(
  mapId: number,
  stageId: number,
  input: UpdateStageInput,
): Promise<AdminMapDetailDTO['stages'][number]> {
  const { data } = await http.patch<AdminMapDetailDTO['stages'][number]>(
    `/learning-maps/${mapId}/stages/${stageId}`,
    input,
  )
  return data
}

export async function deleteStage(mapId: number, stageId: number): Promise<void> {
  await http.delete(`/learning-maps/${mapId}/stages/${stageId}`)
}

export async function addCourseToStage(
  mapId: number,
  stageId: number,
  input: AddCourseToStageInput,
): Promise<{ id: number; stage_id: number; course_id: number; sort_order: number }> {
  const { data } = await http.post<{ id: number; stage_id: number; course_id: number; sort_order: number }>(
    `/learning-maps/${mapId}/stages/${stageId}/courses`,
    input,
  )
  return data
}

export async function removeCourseFromStage(
  mapId: number,
  stageId: number,
  courseId: number,
): Promise<void> {
  await http.delete(`/learning-maps/${mapId}/stages/${stageId}/courses/${courseId}`)
}
