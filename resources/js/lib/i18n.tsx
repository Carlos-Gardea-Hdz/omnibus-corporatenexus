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
    'welcome.cta': { es: 'Comenzar', en: 'Get started' },
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
