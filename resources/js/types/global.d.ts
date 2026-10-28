import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            store: { name: string };
            auth: Auth;
            branchContext: {
                current: number | null;
                available: { id: number; name: string }[];
            };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
