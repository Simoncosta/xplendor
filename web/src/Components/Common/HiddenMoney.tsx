/**
 * Um valor em euros que esta pessoa não pode ver (sem a permissão de ver as Finanças): um número
 * FICTÍCIO desfocado. O backend não envia o valor real, por isso nada fica legível nas
 * ferramentas do browser.
 */
export const FINANCIAL_NOTE = "Sem acesso aos valores financeiros";

const FAKE = ["1 240 €", "860 €", "2 310 €", "490 €", "1 075 €", "3 420 €"];

export default function HiddenMoney({ seed = 0, text }: { seed?: number; text?: string }) {
    return (
        <span
            className="d-inline-block"
            style={{ filter: "blur(5px)", userSelect: "none", pointerEvents: "none" }}
            aria-label={FINANCIAL_NOTE}
            title={FINANCIAL_NOTE}
            data-testid="hidden-money"
        >
            <span aria-hidden>{text ?? FAKE[Math.abs(seed) % FAKE.length]}</span>
        </span>
    );
}

/** A frase que acompanha os valores desfocados. */
export function FinancialNote({ className = "" }: { className?: string }) {
    return (
        <p className={`text-muted fs-12 mb-0 ${className}`} data-testid="financial-note">
            <i className="ri-lock-line me-1" aria-hidden />{FINANCIAL_NOTE}
        </p>
    );
}
