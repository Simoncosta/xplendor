/**
 * Marca assinada de "browser da equipa XPLENDOR" (vem no login do root). Fica no
 * localStorage, partilhado entre separadores, para que a página pública de um orçamento
 * aberta neste browser não conte como abertura do cliente. Apagada quando o root termina
 * a sessão. Não dá acesso a nada.
 */
export const TEAM_MARKER_KEY = "xpl_team_marker";

export function storeTeamMarker(marker: string | null | undefined): void {
    if (!marker) return;
    try { localStorage.setItem(TEAM_MARKER_KEY, marker); } catch { /* armazenamento indisponível */ }
}

export function readTeamMarker(): string | null {
    try { return localStorage.getItem(TEAM_MARKER_KEY); } catch { return null; }
}

export function clearTeamMarker(): void {
    try { localStorage.removeItem(TEAM_MARKER_KEY); } catch { /* armazenamento indisponível */ }
}
