import { Alert } from "reactstrap";
import { Link } from "react-router-dom";
import { ArticleUnitChange } from "common/models/unitGuide.model";

/**
 * A unidade base ou de compra mudou num artigo com histórico de compras: as linhas antigas ficam na
 * unidade anterior (as quantidades e os preços por unidade não se comparam diretamente). No artigo
 * (Cadastros, com a ligação ao artigo no Guião: guideTo) e no Guião de unidades.
 */
export default function UnitChangesAlert({ changes, guideTo }: { changes: ArticleUnitChange[]; guideTo?: string }) {
    if (changes.length === 0) return null;
    return (
        <Alert color="warning" className="py-2 fs-13" data-testid="article-unit-changes">
            <i className="ri-error-warning-line me-1" /><strong>A unidade deste artigo mudou.</strong>{" "}
            {changes.map((c) => c.warning).join(" ")} Ao comparar compras antigas com novas, tenha em conta a unidade de cada linha.
            {guideTo && <> <Link to={guideTo} data-testid="unit-changes-guide"><i className="ri-ruler-line me-1" />Rever no Guião de unidades</Link></>}
        </Alert>
    );
}
