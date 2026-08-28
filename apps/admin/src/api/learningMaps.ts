import http from './http'
import { z } from 'zod'
import { uploadCover, type UploadCoverInput, type UploadCoverResult } from './covers'
import type {
  AddCourseToStageInput,
  AdminMapDetailDTO,
  AdminMapListDTO,
  CreateMapInput,
  CreateStageInput,
  MapStageDTO,
  MapStatus,
  UpdateMapInput,
  UpdateStageInput,
} from '@learn-site/contracts'
import {
  AdminMapDetailDTO as AdminMapDetailSchema,
  AdminMapListDTO as AdminMapListSchema,
  ApiOk,
  MapStageDTO as MapStageSchema,
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

const AdminMapListEnvelope = ApiOk(AdminMapListSchema)
const AdminMapDetailEnvelope = ApiOk(AdminMapDetailSchema)
const MapStageEnvelope = ApiOk(MapStageSchema)
const DeleteMapEnvelope = ApiOk(z.object({ id: z.number().int().positive() }))
const DeleteStageEnvelope = ApiOk(z.object({
  id: z.number().int().positive(),
  stage_id: z.number().int().positive(),
}))
const CourseStepEnvelope = ApiOk(z.object({
  id: z.number().int().positive(),
  stage_id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  sort_order: z.number().int().nonnegative(),
}))
const RemoveCourseEnvelope = ApiOk(z.object({
  stage_id: z.number().int().positive(),
  course_id: z.number().int().positive(),
}))

export async function listMaps(params: AdminListMapParams = {}): Promise<AdminMapListDTO> {
  const response = await http.get<unknown>('/learning-maps', { params })
  return AdminMapListEnvelope.parse(response.data).data
}

export async function getMap(id: number): Promise<AdminMapDetailDTO> {
  const response = await http.get<unknown>(`/learning-maps/${id}`)
  return AdminMapDetailEnvelope.parse(response.data).data
}

export async function uploadMapCover(input: UploadCoverInput): Promise<UploadCoverResult> {
  return uploadCover('/map-covers', input)
}

export async function createMap(input: CreateMapInput): Promise<AdminMapDetailDTO> {
  const response = await http.post<unknown>('/learning-maps', input)
  return AdminMapDetailEnvelope.parse(response.data).data
}

export async function updateMap(id: number, input: UpdateMapInput): Promise<AdminMapDetailDTO> {
  const response = await http.patch<unknown>(`/learning-maps/${id}`, input)
  return AdminMapDetailEnvelope.parse(response.data).data
}

export async function deleteMap(id: number): Promise<void> {
  const response = await http.delete<unknown>(`/learning-maps/${id}`)
  DeleteMapEnvelope.parse(response.data)
}

export async function publishMap(id: number): Promise<AdminMapDetailDTO> {
  const response = await http.post<unknown>(`/learning-maps/${id}/publish`)
  return AdminMapDetailEnvelope.parse(response.data).data
}

export async function unpublishMap(id: number): Promise<AdminMapDetailDTO> {
  const response = await http.post<unknown>(`/learning-maps/${id}/unpublish`)
  return AdminMapDetailEnvelope.parse(response.data).data
}

export async function addStage(
  mapId: number,
  input: CreateStageInput,
): Promise<MapStageDTO> {
  const response = await http.post<unknown>(
    `/learning-maps/${mapId}/stages`,
    input,
  )
  return MapStageEnvelope.parse(response.data).data
}

export async function updateStage(
  mapId: number,
  stageId: number,
  input: UpdateStageInput,
): Promise<MapStageDTO> {
  const response = await http.patch<unknown>(
    `/learning-maps/${mapId}/stages/${stageId}`,
    input,
  )
  return MapStageEnvelope.parse(response.data).data
}

export async function deleteStage(mapId: number, stageId: number): Promise<void> {
  const response = await http.delete<unknown>(`/learning-maps/${mapId}/stages/${stageId}`)
  DeleteStageEnvelope.parse(response.data)
}

export async function addCourseToStage(
  mapId: number,
  stageId: number,
  input: AddCourseToStageInput,
): Promise<{ id: number; stage_id: number; course_id: number; sort_order: number }> {
  const response = await http.post<unknown>(
    `/learning-maps/${mapId}/stages/${stageId}/courses`,
    input,
  )
  return CourseStepEnvelope.parse(response.data).data
}

export async function removeCourseFromStage(
  mapId: number,
  stageId: number,
  courseId: number,
): Promise<void> {
  const response = await http.delete<unknown>(
    `/learning-maps/${mapId}/stages/${stageId}/courses/${courseId}`,
  )
  RemoveCourseEnvelope.parse(response.data)
}
