// Seguidores (Perfil da Marca). Espelha GET /companies/{id}/followers?days=N.
import type { SocialStatus } from "./socialConnection.model";

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
    /** Leitura automática por rede (ligação das redes sociais). */
    automation?: Record<FollowerPlatform, FollowerAutomation>;
}

/** Registo manual: escondido enquanto a leitura automática funciona; o porquê quando aparece numa rede ligada. */
type ManualEntry = { manual_allowed: boolean; manual_reason: string | null };

export type FollowerAutomation = ManualEntry & (
    | { connected: false }
    | {
          connected: true;
          connection_status: SocialStatus;
          account: string | null;
          last_read_at: string | null;
          last_error_at: string | null;
          last_error_kind: string | null;
      });

