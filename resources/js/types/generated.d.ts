declare namespace App.Domain.Tenancy.Data {
export type CreateTenantData = {
name: string;
subdomain: string;
ownerEmail: string;
plan: App.Domain.Tenancy.Enums.TenantPlan;
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
export type TenantStatus = 'pending' | 'active' | 'suspended' | 'archived';
}
