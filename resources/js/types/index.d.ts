export interface User {
    id: number;
    name: string;
    email: string;
    role: 'super_admin' | 'coordinator' | 'witness';
    status: 'active' | 'inactive';
    email_verified_at?: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash: {
        success?: string;
    };
    currentEvent?: {
        id: number;
        name: string;
        year: number;
        status: string;
    } | null;
};
