// Helpers de deteção de PWA (partilhados pela Landing e pela página "Instalar app").

/** true se a app está a correr instalada (modo standalone), Android ou iOS. */
export const isStandalone = (): boolean => {
    if (typeof window === "undefined") return false;
    const mql = window.matchMedia && window.matchMedia("(display-mode: standalone)");
    // iOS Safari expõe navigator.standalone em vez de display-mode.
    const iosStandalone = (window.navigator as any).standalone === true;
    return Boolean((mql && mql.matches) || iosStandalone);
};

/** Deteta iPhone/iPad/iPod (Safari não permite prompt de instalação programático). */
export const isIOS = (): boolean => {
    if (typeof navigator === "undefined") return false;
    const ua = navigator.userAgent || "";
    const iOSDevice = /iPad|iPhone|iPod/.test(ua);
    // iPadOS 13+ apresenta-se como Mac; deteta pelo touch.
    const iPadOS = navigator.platform === "MacIntel" && (navigator as any).maxTouchPoints > 1;
    return iOSDevice || iPadOS;
};

/** Deteta Android. */
export const isAndroid = (): boolean => {
    if (typeof navigator === "undefined") return false;
    return /Android/.test(navigator.userAgent || "");
};
