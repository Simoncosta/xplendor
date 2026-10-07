/**
 * Tema escolhido (claro ou escuro), guardado no browser para persistir depois de atualizar a
 * página. O public/index.html aplica-o antes de a app carregar (sem piscar em claro). O acesso
 * ao localStorage pode falhar (modo privado, bloqueado): nesse caso vale o claro.
 */
export const THEME_KEY = "xp-theme";
export type ThemeMode = "light" | "dark";

export function readStoredTheme(): ThemeMode | null {
    try {
        const v = window.localStorage.getItem(THEME_KEY);
        return v === "dark" || v === "light" ? v : null;
    } catch {
        return null;
    }
}

export function storeTheme(mode: string): void {
    if (mode !== "dark" && mode !== "light") return;
    try {
        window.localStorage.setItem(THEME_KEY, mode);
    } catch {
        // sem armazenamento: o tema vale só nesta visita
    }
}
