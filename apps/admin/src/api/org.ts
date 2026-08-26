import http from './http'
import {
  ApiOk,
  CreateDepartmentInput,
  CreatePostInput,
  CreateRoleInput,
  CreateStaffInput,
  DepartmentDTO,
  DepartmentListDTO,
  OrgDeleteResultDTO,
  PermissionDTO,
  PermissionListDTO,
  PostDTO,
  PostListDTO,
  RoleDTO,
  RoleListDTO,
  SetStaffOverridesInput,
  StaffDetailDTO,
  StaffDTO,
  StaffKickResultDTO,
  StaffListDTO,
  StaffOverrideResultDTO,
  UpdateDepartmentInput,
  UpdatePostInput,
  UpdateRoleInput,
  UpdateStaffInput,
} from '@learn-site/contracts'

const DepartmentEnvelope = ApiOk(DepartmentDTO)
const DepartmentListEnvelope = ApiOk(DepartmentListDTO)
const PostEnvelope = ApiOk(PostDTO)
const PostListEnvelope = ApiOk(PostListDTO)
const RoleEnvelope = ApiOk(RoleDTO)
const RoleListEnvelope = ApiOk(RoleListDTO)
const PermissionListEnvelope = ApiOk(PermissionListDTO)
const StaffEnvelope = ApiOk(StaffDTO)
const StaffListEnvelope = ApiOk(StaffListDTO)
const StaffDetailEnvelope = ApiOk(StaffDetailDTO)
const DeleteEnvelope = ApiOk(OrgDeleteResultDTO)
const StaffOverrideEnvelope = ApiOk(StaffOverrideResultDTO)
const StaffKickEnvelope = ApiOk(StaffKickResultDTO)

/**
 * Admin org API wrappers (Phase 8 / T055–T057). Authorize forces the
 * matching permission code on each prefix; the API returns 403 when the
 * caller lacks it. Errors propagate via axios so views can branch on
 * the response body `{ error: { code, message } }`.
 *
 * Endpoint shapes live in packages/contracts/src/org.ts and the API
 * controllers under apps/api/app/controller/admin/.
 */

export async function listDepartments(): Promise<{ items: DepartmentDTO[] }> {
  const response = await http.get<unknown>('/departments')
  return DepartmentListEnvelope.parse(response.data).data
}

export async function createDepartment(
  input: CreateDepartmentInput,
): Promise<DepartmentDTO> {
  const response = await http.post<unknown>('/departments', CreateDepartmentInput.parse(input))
  return DepartmentEnvelope.parse(response.data).data
}

export async function updateDepartment(
  id: number,
  input: UpdateDepartmentInput,
): Promise<DepartmentDTO> {
  const response = await http.patch<unknown>(
    `/departments/${id}`,
    UpdateDepartmentInput.parse(input),
  )
  return DepartmentEnvelope.parse(response.data).data
}

export async function setDepartmentStatus(
  id: number,
  status: DepartmentDTO['status'],
): Promise<DepartmentDTO> {
  const response = await http.patch<unknown>(
    `/departments/${id}/status`,
    { status },
  )
  return DepartmentEnvelope.parse(response.data).data
}

export async function deleteDepartment(id: number): Promise<void> {
  const response = await http.delete<unknown>(`/departments/${id}`)
  DeleteEnvelope.parse(response.data)
}

// ─── Posts ────────────────────────────────────────────────────────────

export async function listPosts(params: {
  department_id?: number
  status?: PostDTO['status']
} = {}): Promise<{ items: PostDTO[] }> {
  const response = await http.get<unknown>('/posts', { params })
  return PostListEnvelope.parse(response.data).data
}

export async function createPost(input: CreatePostInput): Promise<PostDTO> {
  const response = await http.post<unknown>('/posts', CreatePostInput.parse(input))
  return PostEnvelope.parse(response.data).data
}

export async function updatePost(
  id: number,
  input: UpdatePostInput,
): Promise<PostDTO> {
  const response = await http.patch<unknown>(`/posts/${id}`, UpdatePostInput.parse(input))
  return PostEnvelope.parse(response.data).data
}

export async function deletePost(id: number): Promise<void> {
  const response = await http.delete<unknown>(`/posts/${id}`)
  DeleteEnvelope.parse(response.data)
}

// ─── Roles ────────────────────────────────────────────────────────────

export async function listRoles(): Promise<{ items: RoleDTO[] }> {
  const response = await http.get<unknown>('/roles')
  return RoleListEnvelope.parse(response.data).data
}

export async function createRole(input: CreateRoleInput): Promise<RoleDTO> {
  const response = await http.post<unknown>('/roles', CreateRoleInput.parse(input))
  return RoleEnvelope.parse(response.data).data
}

export async function updateRole(
  id: number,
  input: UpdateRoleInput,
): Promise<RoleDTO> {
  const response = await http.patch<unknown>(`/roles/${id}`, UpdateRoleInput.parse(input))
  return RoleEnvelope.parse(response.data).data
}

export async function setRoleStatus(
  id: number,
  status: RoleDTO['status'],
): Promise<RoleDTO> {
  const response = await http.patch<unknown>(
    `/roles/${id}/status`,
    { status },
  )
  return RoleEnvelope.parse(response.data).data
}

export async function deleteRole(id: number): Promise<void> {
  const response = await http.delete<unknown>(`/roles/${id}`)
  DeleteEnvelope.parse(response.data)
}

export async function listPermissions(): Promise<{ items: PermissionDTO[] }> {
  const response = await http.get<unknown>('/permissions')
  return PermissionListEnvelope.parse(response.data).data
}

// ─── Staff ────────────────────────────────────────────────────────────

export interface ListStaffParams {
  status?: StaffDTO['account_status']
}

export async function listStaff(
  params: ListStaffParams = {},
): Promise<{ items: StaffDTO[] }> {
  const response = await http.get<unknown>('/staff', { params })
  return StaffListEnvelope.parse(response.data).data
}

export async function getStaff(id: number): Promise<StaffDetailDTO> {
  const response = await http.get<unknown>(`/staff/${id}`)
  return StaffDetailEnvelope.parse(response.data).data
}

export async function createStaff(input: CreateStaffInput): Promise<StaffDTO> {
  const response = await http.post<unknown>('/staff', CreateStaffInput.parse(input))
  return StaffEnvelope.parse(response.data).data
}

export async function updateStaff(
  id: number,
  input: UpdateStaffInput,
): Promise<StaffDTO> {
  const response = await http.patch<unknown>(`/staff/${id}`, UpdateStaffInput.parse(input))
  return StaffEnvelope.parse(response.data).data
}

export async function setStaffStatus(
  id: number,
  status: StaffDTO['account_status'],
): Promise<StaffDTO> {
  const response = await http.patch<unknown>(
    `/staff/${id}/status`,
    { status },
  )
  return StaffEnvelope.parse(response.data).data
}

export async function deleteStaff(id: number): Promise<void> {
  const response = await http.delete<unknown>(`/staff/${id}`)
  DeleteEnvelope.parse(response.data)
}

export async function setStaffOverrides(
  id: number,
  input: SetStaffOverridesInput,
): Promise<{ overrides: StaffDetailDTO['overrides'] }> {
  const response = await http.put<unknown>(
    `/staff/${id}/overrides`,
    SetStaffOverridesInput.parse(input),
  )
  return StaffOverrideEnvelope.parse(response.data).data
}

export async function kickStaff(
  id: number,
  familyId?: string,
): Promise<{ revoked: number }> {
  const response = await http.post<unknown>(
    `/staff/${id}/kick`,
    familyId ? { family_id: familyId } : {},
  )
  return StaffKickEnvelope.parse(response.data).data
}
