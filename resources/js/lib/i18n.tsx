import { createContext, useCallback, useContext, useMemo, useState } from 'react';
import type { ReactNode } from 'react';

/**
 * Lightweight bilingual (ES/EN) copy layer. All user-facing strings flow
 * through `t()` so the UI is fully bilingual per project standards. Default
 * locale is provided by the server (app locale) and can be toggled client-side.
 */

export type Locale = 'es' | 'en';

type Dictionary = Record<string, { es: string; en: string }>;

const dictionary: Dictionary = {
    'app.name': { es: 'CorporateNexus', en: 'CorporateNexus' },
    'app.tagline': {
        es: 'El hub corporativo multi-tenant',
        en: 'The multi-tenant corporate hub',
    },
    'nav.dashboard': { es: 'Panel', en: 'Dashboard' },
    'nav.register': { es: 'Crear organización', en: 'Create organization' },
    'theme.toggle': { es: 'Cambiar tema', en: 'Toggle theme' },
    'lang.toggle': { es: 'Cambiar idioma', en: 'Toggle language' },
    'register.title': { es: 'Crea tu organización', en: 'Create your organization' },
    'register.name': { es: 'Nombre de la organización', en: 'Organization name' },
    'register.subdomain': { es: 'Subdominio', en: 'Subdomain' },
    'register.ownerEmail': { es: 'Correo del propietario', en: 'Owner email' },
    'register.submit': { es: 'Crear organización', en: 'Create organization' },
    'register.submitting': { es: 'Creando…', en: 'Creating…' },
    'register.plan': { es: 'Plan', en: 'Plan' },
    'register.plan.seats': { es: 'asientos', en: 'seats' },
    'register.plan.unlimited': { es: 'ilimitados', en: 'unlimited' },
    'register.plan.free': { es: 'Gratis', en: 'Free' },
    'provisioning.title': {
        es: 'Preparando tu organización',
        en: 'Setting up your organization',
    },
    'provisioning.pending': { es: 'Aprovisionando…', en: 'Provisioning…' },
    'provisioning.active': { es: '¡Listo!', en: 'Ready!' },
    'provisioning.open': { es: 'Abrir tu espacio', en: 'Open your workspace' },
    'provisioning.wait': {
        es: 'Esto puede tardar un momento.',
        en: 'This may take a moment.',
    },
    'landing.title': { es: 'Inicio', en: 'Home' },
    'landing.welcome': { es: 'Bienvenido a', en: 'Welcome to' },
    'landing.notes_count': {
        es: 'notas en este espacio',
        en: 'notes in this workspace',
    },
    'dashboard.title': { es: 'Panel', en: 'Dashboard' },
    'dashboard.welcome': { es: 'Bienvenido a', en: 'Welcome to' },
    'dashboard.plan': { es: 'Plan', en: 'Plan' },
    'dashboard.status': { es: 'Estado', en: 'Status' },
    'dashboard.members': { es: 'Miembros', en: 'Members' },
    'dashboard.seats': { es: 'Asientos', en: 'Seats' },
    'dashboard.you': { es: 'Tu sesión', en: 'Your session' },
    'dashboard.manage_members': { es: 'Gestionar miembros', en: 'Manage members' },
    'welcome.cta': { es: 'Comenzar', en: 'Get started' },

    // --- Auth (mirrors lang/{es,en}/auth.php) ---
    'auth.failed': {
        es: 'Estas credenciales no coinciden con nuestros registros.',
        en: 'These credentials do not match our records.',
    },
    'auth.login.title': { es: 'Inicia sesión', en: 'Sign in' },
    'auth.login.email': { es: 'Correo electrónico', en: 'Email' },
    'auth.login.password': { es: 'Contraseña', en: 'Password' },
    'auth.login.remember': { es: 'Recordarme', en: 'Remember me' },
    'auth.login.submit': { es: 'Entrar', en: 'Sign in' },
    'auth.login.submitting': { es: 'Entrando…', en: 'Signing in…' },
    'auth.logout': { es: 'Cerrar sesión', en: 'Sign out' },

    // --- Members (mirrors lang/{es,en}/members.php) ---
    'members.role.owner': { es: 'Propietario', en: 'Owner' },
    'members.role.admin': { es: 'Administrador', en: 'Admin' },
    'members.role.member': { es: 'Miembro', en: 'Member' },
    'members.title': { es: 'Miembros', en: 'Members' },
    'members.subtitle': {
        es: 'Gestiona quién pertenece a tu organización.',
        en: 'Manage who belongs to your organization.',
    },
    'members.invite': { es: 'Invitar miembro', en: 'Invite member' },
    'members.invite.name': { es: 'Nombre', en: 'Name' },
    'members.invite.email': { es: 'Correo electrónico', en: 'Email' },
    'members.invite.role': { es: 'Rol', en: 'Role' },
    'members.invite.submit': { es: 'Enviar invitación', en: 'Send invitation' },
    'members.invite.submitting': { es: 'Invitando…', en: 'Inviting…' },
    'members.update_role': { es: 'Actualizar rol', en: 'Update role' },
    'members.remove': { es: 'Eliminar', en: 'Remove' },
    'members.remove.confirm': {
        es: '¿Eliminar a este miembro? Esta acción no se puede deshacer.',
        en: 'Remove this member? This cannot be undone.',
    },
    'members.created': { es: 'Miembro invitado.', en: 'Member invited.' },
    'members.updated': { es: 'Rol actualizado.', en: 'Role updated.' },
    'members.removed': { es: 'Miembro eliminado.', en: 'Member removed.' },
    'members.you_badge': { es: 'Tú', en: 'You' },
    'members.empty': {
        es: 'Aún no hay otros miembros.',
        en: 'No other members yet.',
    },
    'members.table.name': { es: 'Nombre', en: 'Name' },
    'members.table.email': { es: 'Correo', en: 'Email' },
    'members.table.role': { es: 'Rol', en: 'Role' },
    'members.table.actions': { es: 'Acciones', en: 'Actions' },
    'members.temp_password.title': {
        es: 'Credencial de un solo uso',
        en: 'One-time credential',
    },
    'members.temp_password.hint': {
        es: 'Comparte esta contraseña temporal de forma segura. No volverá a mostrarse.',
        en: 'Share this temporary password securely. It will not be shown again.',
    },
    'members.temp_password.copy': { es: 'Copiar', en: 'Copy' },
    'members.temp_password.copied': { es: 'Copiado', en: 'Copied' },
    'members.seat.unlimited': { es: 'Asientos ilimitados', en: 'Unlimited seats' },
    'members.seat.used': { es: 'usados', en: 'used' },
    'members.seat.remaining': { es: 'disponibles', en: 'remaining' },
    'members.error.seat_limit': {
        es: 'Has alcanzado el límite de asientos de tu plan.',
        en: 'You have reached your plan seat limit.',
    },
    'members.error.owner_protected': {
        es: 'El propietario no puede ser degradado de esta forma.',
        en: 'The owner cannot be demoted this way.',
    },
    'members.error.cannot_remove_owner': {
        es: 'No puedes eliminar al único propietario.',
        en: 'You cannot remove the sole owner.',
    },
    'members.error.cannot_remove_self': {
        es: 'No puedes eliminarte a ti mismo.',
        en: 'You cannot remove yourself.',
    },
    'members.error.role_not_assignable': {
        es: 'No tienes permiso para asignar ese rol.',
        en: 'You are not allowed to assign that role.',
    },

    // --- Provisioning owner credential (extends Central/Provisioning) ---
    'provisioning.owner_credential.title': {
        es: 'Credencial del propietario',
        en: 'Owner credential',
    },
    'provisioning.owner_credential.hint': {
        es: 'Guarda esta contraseña temporal del propietario. Solo se muestra una vez.',
        en: 'Save this temporary owner password. It is shown only once.',
    },

    // --- Platform admin console (Slice 003 — mirrors lang/{es,en}/platform.php) ---
    'platform.console': { es: 'Consola de plataforma', en: 'Platform console' },
    'platform.login.title': {
        es: 'Acceso de administrador de plataforma',
        en: 'Platform admin sign in',
    },
    'platform.login.subtitle': {
        es: 'Solo para operadores de la plataforma.',
        en: 'For platform operators only.',
    },
    'platform.login.email': { es: 'Correo electrónico', en: 'Email' },
    'platform.login.password': { es: 'Contraseña', en: 'Password' },
    'platform.login.submit': { es: 'Entrar', en: 'Sign in' },
    'platform.login.submitting': { es: 'Entrando…', en: 'Signing in…' },
    'platform.logout': { es: 'Cerrar sesión', en: 'Sign out' },

    'platform.dashboard.title': { es: 'Organizaciones', en: 'Tenants' },
    'platform.dashboard.subtitle': {
        es: 'Todas las organizaciones registradas en la plataforma.',
        en: 'Every organization registered on the platform.',
    },
    'platform.dashboard.filters.status': { es: 'Estado', en: 'Status' },
    'platform.dashboard.filters.plan': { es: 'Plan', en: 'Plan' },
    'platform.dashboard.filters.all': { es: 'Todos', en: 'All' },
    'platform.dashboard.filters.apply': { es: 'Filtrar', en: 'Filter' },
    'platform.dashboard.filters.clear': { es: 'Limpiar', en: 'Clear' },
    'platform.dashboard.empty': {
        es: 'Ninguna organización coincide con estos filtros.',
        en: 'No tenants match these filters.',
    },
    'platform.dashboard.total': { es: 'Total', en: 'Total' },
    'platform.dashboard.table.name': { es: 'Organización', en: 'Organization' },
    'platform.dashboard.table.subdomain': { es: 'Subdominio', en: 'Subdomain' },
    'platform.dashboard.table.status': { es: 'Estado', en: 'Status' },
    'platform.dashboard.table.plan': { es: 'Plan', en: 'Plan' },
    'platform.dashboard.table.owner': { es: 'Propietario', en: 'Owner' },
    'platform.dashboard.table.created': { es: 'Creada', en: 'Created' },
    'platform.dashboard.view': { es: 'Ver', en: 'View' },
    'platform.pagination.previous': { es: 'Anterior', en: 'Previous' },
    'platform.pagination.next': { es: 'Siguiente', en: 'Next' },
    'platform.pagination.page': { es: 'Página', en: 'Page' },
    'platform.pagination.of': { es: 'de', en: 'of' },

    'platform.tenant.detail_title': {
        es: 'Detalle de la organización',
        en: 'Tenant detail',
    },
    'platform.tenant.back': { es: 'Volver a organizaciones', en: 'Back to tenants' },
    'platform.tenant.owner_email': { es: 'Correo del propietario', en: 'Owner email' },
    'platform.tenant.created_at': { es: 'Creada', en: 'Created' },
    'platform.tenant.status': { es: 'Estado', en: 'Status' },
    'platform.tenant.plan': { es: 'Plan', en: 'Plan' },
    'platform.tenant.seat_limit': { es: 'Límite de asientos', en: 'Seat limit' },
    'platform.tenant.seat_limit.unlimited': { es: 'Ilimitados', en: 'Unlimited' },
    'platform.tenant.price': { es: 'Precio', en: 'Price' },
    'platform.tenant.price.per_month': { es: '/mes', en: '/mo' },
    'platform.tenant.features': { es: 'Funciones', en: 'Features' },
    'platform.tenant.feature.active': { es: 'Activa', en: 'Active' },
    'platform.tenant.feature.inactive': { es: 'Inactiva', en: 'Inactive' },
    'platform.tenant.over_limit': {
        es: 'Sobre el límite de asientos',
        en: 'Over seat limit',
    },
    'platform.tenant.seats_over': {
        es: 'asientos por encima del plan',
        en: 'seats over the plan',
    },

    'platform.actions.title': { es: 'Acciones', en: 'Actions' },
    'platform.actions.suspend': { es: 'Suspender', en: 'Suspend' },
    'platform.actions.suspending': { es: 'Suspendiendo…', en: 'Suspending…' },
    'platform.actions.suspend.confirm': {
        es: '¿Suspender esta organización? Dejará de servir hasta reactivarla.',
        en: 'Suspend this tenant? It will stop serving until reactivated.',
    },
    'platform.actions.reactivate': { es: 'Reactivar', en: 'Reactivate' },
    'platform.actions.reactivating': { es: 'Reactivando…', en: 'Reactivating…' },
    'platform.actions.change_plan': { es: 'Cambiar plan', en: 'Change plan' },
    'platform.actions.changing_plan': { es: 'Cambiando…', en: 'Changing…' },
    'platform.actions.change_plan.label': {
        es: 'Nuevo plan',
        en: 'New plan',
    },
    'platform.actions.none': {
        es: 'No hay acciones disponibles para esta organización.',
        en: 'No actions are available for this tenant.',
    },
};

type I18nContextValue = {
    locale: Locale;
    setLocale: (locale: Locale) => void;
    t: (key: keyof typeof dictionary | string) => string;
};

const I18nContext = createContext<I18nContextValue | null>(null);

function normalize(locale: string): Locale {
    return locale.toLowerCase().startsWith('es') ? 'es' : 'en';
}

export function I18nProvider({ locale, children }: { locale: string; children: ReactNode }) {
    const [current, setCurrent] = useState<Locale>(normalize(locale));

    const t = useCallback(
        (key: string): string => {
            const entry = dictionary[key];
            return entry ? entry[current] : key;
        },
        [current],
    );

    const value = useMemo<I18nContextValue>(
        () => ({ locale: current, setLocale: setCurrent, t }),
        [current, t],
    );

    return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nContextValue {
    const ctx = useContext(I18nContext);
    if (!ctx) {
        throw new Error('useI18n must be used within an I18nProvider');
    }
    return ctx;
}
