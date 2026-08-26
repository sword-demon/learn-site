import { z } from 'zod';

export const DepartmentStatus = z.enum(['enabled', 'disabled']);
export type DepartmentStatus = z.infer<typeof DepartmentStatus>;

export const DepartmentDTO = z.object({
  id: z.number().int(),
  parent_id: z.number().int(),
  name: z.string(),
  path: z.string(),
  depth: z.number().int(),
  sort: z.number().int(),
  status: DepartmentStatus,
  created_at: z.string(),
  updated_at: z.string(),
});
export type DepartmentDTO = z.infer<typeof DepartmentDTO>;

export const CreateDepartmentInput = z.object({
  parent_id: z.number().int().min(0),
  name: z.string().min(1).max(64),
  sort: z.number().int().min(0).max(999),
  status: DepartmentStatus.optional(),
});
export type CreateDepartmentInput = z.infer<typeof CreateDepartmentInput>;

export const UpdateDepartmentInput = z.object({
  name: z.string().min(1).max(64).optional(),
  sort: z.number().int().min(0).max(999).optional(),
});
export type UpdateDepartmentInput = z.infer<typeof UpdateDepartmentInput>;

export const PostStatus = z.enum(['enabled', 'disabled']);
export type PostStatus = z.infer<typeof PostStatus>;

export const PostDTO = z.object({
  id: z.number().int(),
  department_id: z.number().int(),
  department_name: z.string(),
  name: z.string(),
  status: PostStatus,
  role_ids: z.array(z.number().int().positive()),
  created_at: z.string(),
  updated_at: z.string(),
});
export type PostDTO = z.infer<typeof PostDTO>;

export const CreatePostInput = z.object({
  department_id: z.number().int().positive(),
  name: z.string().min(1).max(64),
  status: PostStatus.optional(),
  role_ids: z.array(z.number().int().positive()).optional(),
});
export type CreatePostInput = z.infer<typeof CreatePostInput>;

export const UpdatePostInput = z.object({
  name: z.string().min(1).max(64).optional(),
  status: PostStatus.optional(),
  role_ids: z.array(z.number().int().positive()).optional(),
});
export type UpdatePostInput = z.infer<typeof UpdatePostInput>;

export const DataScope = z.enum([
  'all',
  'dept_and_children',
  'specified_depts',
  'dept',
  'self',
]);
export type DataScope = z.infer<typeof DataScope>;

export const RoleStatus = z.enum(['enabled', 'disabled']);
export type RoleStatus = z.infer<typeof RoleStatus>;

export const RoleDTO = z.object({
  id: z.number().int(),
  name: z.string(),
  code: z.string(),
  data_scope: DataScope,
  status: RoleStatus,
  permission_ids: z.array(z.number().int()),
  scope_department_ids: z.array(z.number().int()),
  created_at: z.string(),
  updated_at: z.string(),
});
export type RoleDTO = z.infer<typeof RoleDTO>;

export const CreateRoleInput = z.object({
  name: z.string().min(1).max(64),
  code: z
    .string()
    .min(2)
    .max(64)
    .regex(/^[a-z][a-z0-9_.-]{1,63}$/),
  data_scope: DataScope,
  permission_ids: z.array(z.number().int()).optional(),
  scope_department_ids: z.array(z.number().int()).optional(),
});
export type CreateRoleInput = z.infer<typeof CreateRoleInput>;

export const UpdateRoleInput = z.object({
  name: z.string().min(1).max(64).optional(),
  data_scope: DataScope.optional(),
  permission_ids: z.array(z.number().int()).optional(),
  scope_department_ids: z.array(z.number().int()).optional(),
});
export type UpdateRoleInput = z.infer<typeof UpdateRoleInput>;

export const PermissionDTO = z.object({
  id: z.number().int(),
  code: z.string(),
  module: z.string(),
  description: z.string(),
});
export type PermissionDTO = z.infer<typeof PermissionDTO>;

export const StaffAccountStatus = z.enum(['active', 'disabled']);
export type StaffAccountStatus = z.infer<typeof StaffAccountStatus>;

export const StaffOverride = z.object({
  effect: z.enum(['grant', 'deny']),
  code: z.string(),
  permission_id: z.number().int(),
});
export type StaffOverride = z.infer<typeof StaffOverride>;

export const StaffDTO = z.object({
  account_id: z.number().int(),
  login: z.string(),
  display_name: z.string(),
  is_super_admin: z.boolean(),
  department_id: z.number().int().nullable(),
  department_name: z.string(),
  department_status: z.string(),
  account_status: StaffAccountStatus,
  must_change_password: z.boolean(),
  last_login_at: z.string(),
  created_at: z.string(),
  updated_at: z.string(),
});
export type StaffDTO = z.infer<typeof StaffDTO>;

export const StaffDetailDTO = z.object({
  staff: StaffDTO,
  roles: z.array(z.number().int()),
  posts: z.array(z.number().int()),
  overrides: z.array(StaffOverride),
});
export type StaffDetailDTO = z.infer<typeof StaffDetailDTO>;

export const CreateStaffInput = z
  .object({
    login: z
      .string()
      .min(3)
      .max(64)
      .refine((v) => !/^1[3-9]\d{9}$/.test(v), 'INVALID_LOGIN'),
    password: z.string().min(8).max(72),
    display_name: z.string().min(1).max(64),
    is_super_admin: z.boolean().optional(),
    department_id: z.number().int().positive().nullable().optional(),
    role_ids: z.array(z.number().int().positive()).optional(),
    post_ids: z.array(z.number().int().positive()).optional(),
  })
  .superRefine((value, context) => {
    if (value.is_super_admin !== true && value.department_id == null) {
      context.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['department_id'],
        message: 'STAFF_DEPARTMENT_REQUIRED',
      });
    }
  });
export type CreateStaffInput = z.infer<typeof CreateStaffInput>;

export const UpdateStaffInput = z
  .object({
    display_name: z.string().min(1).max(64).optional(),
    is_super_admin: z.boolean().optional(),
    department_id: z.number().int().positive().nullable().optional(),
    role_ids: z.array(z.number().int().positive()).optional(),
    post_ids: z.array(z.number().int().positive()).optional(),
    reset_password: z.boolean().optional(),
    new_password: z.string().min(8).max(72).optional(),
  })
  .superRefine((value, context) => {
    if (value.reset_password === true && value.new_password === undefined) {
      context.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['new_password'],
        message: 'PASSWORD_REQUIRED',
      });
    }
  });
export type UpdateStaffInput = z.infer<typeof UpdateStaffInput>;

export const SetStaffOverridesInput = z.object({
  entries: z.array(
    z.object({
      code: z.string(),
      effect: z.enum(['grant', 'deny']),
    }),
  ),
});
export type SetStaffOverridesInput = z.infer<typeof SetStaffOverridesInput>;

export const DepartmentListDTO = z.object({ items: z.array(DepartmentDTO) });
export const PostListDTO = z.object({ items: z.array(PostDTO) });
export const RoleListDTO = z.object({ items: z.array(RoleDTO) });
export const PermissionListDTO = z.object({ items: z.array(PermissionDTO) });
export const StaffListDTO = z.object({ items: z.array(StaffDTO) });
export const OrgDeleteResultDTO = z.object({ deleted: z.literal(true) });
export const StaffOverrideResultDTO = z.object({ overrides: z.array(StaffOverride) });
export const StaffKickResultDTO = z.object({ revoked: z.number().int().min(0) });
