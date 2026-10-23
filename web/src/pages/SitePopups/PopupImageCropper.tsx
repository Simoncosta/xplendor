import { useState } from "react";
import Cropper, { Area } from "react-easy-crop";
import { Input, Label } from "reactstrap";

/**
 * O recorte da imagem do aviso, com as medidas reais do site da Quebom (spike de 23/10):
 *  · computador: 458 × 344 px, área 4:3 fixa (object-fit cover, centrada);
 *  · telemóvel: 375 × 180 e 430 × 180 px, que cortam a imagem 4:3 em cima e em baixo: só fica a
 *    faixa central, entre 22% e 78% da altura;
 *  · o botão de fechar no canto superior direito e a pílula "Termina a…" no inferior esquerdo.
 * Recomendado: 960 × 720 px (4:3), até 200 KB. O recorte vai para o servidor em frações da imagem
 * original; o servidor recorta, redimensiona para 960 × 720 e comprime até 200 KB.
 */
export const ASPECT = 4 / 3;
export const RECOMMENDED = { width: 960, height: 720, kb: 200 };
/** A faixa que o telemóvel mostra (em fração da altura). */
export const SAFE_BAND = { top: 0.22, bottom: 0.78 };
const CROP_BOX = { width: 320, height: 240 };

export type CropFraction = { x: number; y: number; width: number; height: number };
export type CropState = { fraction: CropFraction; pixels: { width: number; height: number }; natural: { width: number; height: number } };

/** O recorte em percentagens (do react-easy-crop) passado a frações (0 a 1). */
export const toFraction = (a: Area): CropFraction => {
    const r = (v: number) => Math.round(Math.max(0, Math.min(100, v)) * 10) / 1000;
    return { x: r(a.x), y: r(a.y), width: r(a.width), height: r(a.height) };
};

/** O fundo que mostra só a área recortada numa caixa 4:3 (sem canvas). */
export const cropBackground = (src: string, f: CropFraction) => ({
    backgroundImage: `url("${src}")`,
    backgroundSize: `${100 / f.width}% ${100 / f.height}%`,
    backgroundPosition: `${f.width >= 1 ? 0 : (f.x / (1 - f.width)) * 100}% ${f.height >= 1 ? 0 : (f.y / (1 - f.height)) * 100}%`,
    backgroundRepeat: "no-repeat",
});

/** As marcas por cima do recorte: a faixa segura do telemóvel e os dois cantos a deixar livres. */
export function SafeAreaOverlay() {
    const shade = "rgba(0,0,0,0.35)";
    return (
        <div aria-hidden data-testid="popup-safe-overlay" style={{ position: "absolute", inset: 0, pointerEvents: "none" }}>
            <div style={{ position: "absolute", left: 0, right: 0, top: 0, height: `${SAFE_BAND.top * 100}%`, background: shade }} />
            <div style={{ position: "absolute", left: 0, right: 0, bottom: 0, height: `${(1 - SAFE_BAND.bottom) * 100}%`, background: shade }} />
            <div style={{ position: "absolute", left: 0, right: 0, top: `${SAFE_BAND.top * 100}%`, height: `${(SAFE_BAND.bottom - SAFE_BAND.top) * 100}%`, border: "1px dashed #fff" }} />
            <div title="Botão de fechar" style={{ position: "absolute", top: 4, right: 4, width: "9%", aspectRatio: "1", borderRadius: "50%", border: "2px solid #ffc107" }} />
            <div title="Pílula “Termina a…”" style={{ position: "absolute", bottom: 4, left: 4, width: "36%", height: "9%", borderRadius: 12, border: "2px solid #ffc107" }} />
        </div>
    );
}

export default function PopupImageCropper({ src, onChange }: { src: string; onChange: (c: CropState) => void }) {
    const [crop, setCrop] = useState({ x: 0, y: 0 });
    const [zoom, setZoom] = useState(1);
    const [natural, setNatural] = useState<{ width: number; height: number } | null>(null);

    return (
        <div data-testid="popup-cropper">
            <div className="position-relative bg-dark rounded" style={{ height: CROP_BOX.height + 40 }}>
                <Cropper image={src} crop={crop} zoom={zoom} aspect={ASPECT} cropSize={CROP_BOX} showGrid={false} objectFit="cover"
                    onCropChange={setCrop} onZoomChange={setZoom}
                    onMediaLoaded={(m) => setNatural({ width: m.naturalWidth, height: m.naturalHeight })}
                    onCropComplete={(area, px) => natural && onChange({ fraction: toFraction(area), pixels: { width: Math.round(px.width), height: Math.round(px.height) }, natural })} />
                {/* A área do recorte está sempre ao centro, com o tamanho fixo de CROP_BOX. */}
                <div style={{ position: "absolute", left: "50%", top: "50%", width: CROP_BOX.width, height: CROP_BOX.height, transform: "translate(-50%, -50%)", pointerEvents: "none" }}>
                    <SafeAreaOverlay />
                </div>
            </div>
            <Label for="pop-zoom" className="form-label fs-12 mt-2 mb-0">Aproximar</Label>
            <Input id="pop-zoom" type="range" min={1} max={3} step={0.05} value={zoom} onChange={(e) => setZoom(Number(e.target.value))} />
            <small className="text-muted d-block">A faixa entre as linhas tracejadas é o que o telemóvel mostra; deixe livres os cantos marcados (o botão de fechar e a pílula “Termina a…”).</small>
        </div>
    );
}

/** Os píxeis da imagem e do recorte, com o aviso abaixo do recomendado. */
export function CropPixels({ state }: { state: CropState | null }) {
    if (!state) return null;
    const small = state.pixels.width < RECOMMENDED.width || state.pixels.height < RECOMMENDED.height;
    return (
        <div className="fs-13 mt-2" data-testid="popup-pixels">
            Imagem original: {state.natural.width} × {state.natural.height} px · recorte: {state.pixels.width} × {state.pixels.height} px
            {small && <div className="text-warning" data-testid="popup-pixels-warning"><i className="ri-error-warning-line me-1" />O recorte tem menos de {RECOMMENDED.width} × {RECOMMENDED.height} px: no site vai parecer desfocado. Escolha uma imagem maior ou aproxime menos.</div>}
        </div>
    );
}

/** A pré-visualização lado a lado: computador (458 × 344) e telemóvel (375 × 180 e 430 × 180). */
export function PopupDevicePreviews({ src, fraction }: { src: string; fraction: CropFraction | null }) {
    const f = fraction ?? { x: 0, y: 0, width: 1, height: 1 };
    const img = cropBackground(src, f);
    const mobile = (w: number) => (
        <div key={w} className="text-center">
            <div className="border rounded overflow-hidden position-relative mx-auto" style={{ width: w / 2.5, height: 180 / 2.5 }} data-testid={`preview-mobile-${w}`}>
                <div style={{ position: "absolute", left: 0, right: 0, top: "50%", transform: "translateY(-50%)", aspectRatio: "4 / 3", ...img }} />
            </div>
            <span className="text-muted fs-12">Telemóvel {w} × 180</span>
        </div>
    );
    return (
        <div className="d-flex flex-wrap align-items-end gap-3" data-testid="popup-device-previews">
            <div className="text-center">
                <div className="border rounded" style={{ width: 458 / 2.5, aspectRatio: "4 / 3", ...img }} data-testid="preview-desktop" />
                <span className="text-muted fs-12">Computador 458 × 344</span>
            </div>
            {[375, 430].map(mobile)}
        </div>
    );
}
