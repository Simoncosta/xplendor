/**
 * "Rever o português": o verificador encontrou, mesmo depois de pedir de novo à IA, marcas do
 * português do Brasil ou travessões no texto gerado. O texto pode ser usado, mas convém rever.
 */
export default function PtReviewNotice({ issues, className = "" }: { issues?: string[] | null; className?: string }) {
    if (!issues || issues.length === 0) return null;

    return (
        <div className={`alert alert-warning fs-13 py-2 ${className}`} role="status" data-testid="pt-review">
            <i className="ri-translate-2 me-1" />
            <strong>Rever o português.</strong> O texto gerado ainda tem marcas que não são de português de Portugal: {issues.join(", ")}.
        </div>
    );
}
