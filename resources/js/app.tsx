import { createInertiaApp } from '@inertiajs/react';
import type { ResolvedComponent } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'EXSO';

// Pages of the core and of every module (Modules/<Module>/resources/js/pages).
// A page name such as "products/index" is looked up in all of them.
const pages = import.meta.glob([
    './pages/**/*.tsx',
    '../../Modules/*/resources/js/pages/**/*.tsx',
]);

async function resolvePage(name: string): Promise<ResolvedComponent> {
    const key = Object.keys(pages).find(
        (path) =>
            path === `./pages/${name}.tsx` ||
            path.endsWith(`/resources/js/pages/${name}.tsx`),
    );

    if (!key) {
        throw new Error(`Page not found: ${name}`);
    }

    const module = (await pages[key]()) as { default: ResolvedComponent };

    return module.default;
}

void createInertiaApp({
    resolve: resolvePage,
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
