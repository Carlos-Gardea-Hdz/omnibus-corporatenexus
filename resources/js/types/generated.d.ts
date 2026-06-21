declare namespace App.Domain.Membership.Data {
export type InviteMemberData = {
name: string;
email: string;
role: App.Domain.Membership.Enums.MemberRole;
};
export type LoginData = {
email: string;
password: string;
remember: boolean;
};
export type MemberData = {
id: number;
name: string;
email: string;
role: App.Domain.Membership.Enums.MemberRole;
};
export type UpdateMemberRoleData = {
role: App.Domain.Membership.Enums.MemberRole;
};
}
declare namespace App.Domain.Membership.Enums {
export type MemberRole = 'owner' | 'admin' | 'member';
}
declare namespace App.Domain.Tenancy.Data {
export type CreateTenantData = {
name: string;
subdomain: string;
ownerEmail: string;
plan: App.Domain.Tenancy.Enums.TenantPlan;
};
export type PlanOptionData = {
value: string;
label: string;
price_cents: number;
seat_limit: number;
};
export type TenantData = {
id: string;
name: string;
status: App.Domain.Tenancy.Enums.TenantStatus;
plan: App.Domain.Tenancy.Enums.TenantPlan;
};
}
declare namespace App.Domain.Tenancy.Enums {
export type TenantFeature = 'advanced-analytics' | 'sso-saml' | 'audit-log-export' | 'beta-workspace-ui';
export type TenantPlan = 'free' | 'team' | 'business' | 'enterprise';
export type TenantStatus = 'pending' | 'active' | 'failed' | 'suspended' | 'archived';
}
