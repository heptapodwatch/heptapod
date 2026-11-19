export type User = {
    id: number;
    name: string;
    email: string;
    locale: 'en' | 'de';
    /** The zone in use: the user's own, else the instance's. */
    timezone: string;
    /** The user's own choice; null follows the instance. */
    timezone_override: string | null;
    roles: { name: string }[];
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
};

export type Auth = {
    user: User | null;
    canManageMembers: boolean;
    canManageChannels: boolean;
    canManageInstance: boolean;
    canViewSystemHealth: boolean;
    canViewUpdates: boolean;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string | null;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
