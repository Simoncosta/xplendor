// Seguidores (Perfil da Marca). Espelha GET /companies/{id}/followers?days=N.

export type FollowerPlatform = "instagram" | "facebook";
export type FollowerSource = "manual" | "api" | "business_discovery";

export interface FollowerPoint {
    date: string;
    /** null = dia sem registo (fica como falha no gráfico). */
    count: number | null;
    source: FollowerSource | null;
}

export interface FollowerPlatformData {
    current: { count: number; date: string; source: FollowerSource } | null;
    series: FollowerPoint[];
}

export interface FollowersOverview {
    from: string;
    to: string;
    today: string;
    platforms: Record<FollowerPlatform, FollowerPlatformData>;
    can_record: boolean;
}
