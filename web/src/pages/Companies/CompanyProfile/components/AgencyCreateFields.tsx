import { useEffect, useState } from "react";
import { useFormikContext } from "formik";
import { Col, Input, Label, Row } from "reactstrap";
import { getAdminAgencies, getEditorialSectors } from "helpers/laravel_helper";
import { getHomeCompanyId } from "helpers/workingCompany";
import XSelect from "pages/Editorial/XSelect";

/**
 * Criar empresa (só o root): o ramo (decide os módulos à nascença), "Esta empresa é uma
 * agência" e "Gerida por". Uma empresa gerida pode nascer sem NIPC e sem utilizadores.
 */
/** part: só o ramo ("sector"), só a gestão por agências ("agency"), ou ambos (por omissão). */
export default function AgencyCreateFields({ part }: { part?: "sector" | "agency" } = {}) {
    const { values, setFieldValue } = useFormikContext<any>();
    const [agencies, setAgencies] = useState<{ value: number; label: string }[]>([]);
    const [sectors, setSectors] = useState<{ value: number; label: string }[]>([]);

    useEffect(() => {
        getAdminAgencies().then((r: any) => setAgencies((r?.data?.agencies ?? []).map((a: any) => ({ value: a.id, label: a.name })))).catch(() => setAgencies([]));
        getEditorialSectors(getHomeCompanyId()).then((r: any) => setSectors((r?.data?.sectors ?? []).map((s: any) => ({ value: s.id, label: s.name })))).catch(() => setSectors([]));
    }, []);

    const managedBy = Number(values.managed_by_company_id || 0);

    return (
        <Row className="mb-3" data-testid="agency-create-fields">
            <div className="mb-2 border-bottom pb-2">
                <h5 className="card-title">{part === "sector" ? "Ramo" : part === "agency" ? "Gestão por agências" : "Ramo e gestão por agências"}</h5>
            </div>
            {part !== "agency" && <Col lg={6} className="mb-2">
                <Label for="create-sector" className="mb-1">Ramo</Label>
                <XSelect id="create-sector" options={[{ value: 0, label: "Sem ramo (módulos base)" }, ...sectors]}
                    value={Number(values.content_sector_id || 0)} onChange={(v) => setFieldValue("content_sector_id", v || null)} />
                <div className="form-text">Decide os módulos ligados à nascença (ajustáveis depois em Módulos).</div>
            </Col>}
            {part !== "sector" && <><Col lg={6} className="mb-2">
                <Label for="create-managed-by" className="mb-1">Gerida por</Label>
                <XSelect id="create-managed-by" options={[{ value: 0, label: "Sem agência gestora" }, ...agencies]} searchable
                    value={managedBy} disabled={!!values.is_agency}
                    onChange={(v) => setFieldValue("managed_by_company_id", v || null)} />
                {managedBy > 0 && <div className="form-text">Sem NIPC nem utilizador de acesso: a agência trabalha com a própria conta.</div>}
                {!!values.is_agency && <div className="form-text">Uma agência não é gerida por outra agência.</div>}
            </Col>
            <Col lg={12}>
                <div className="form-check form-switch">
                    <Input type="switch" className="form-check-input" id="create-is-agency" checked={!!values.is_agency} disabled={managedBy > 0}
                        onChange={(e) => setFieldValue("is_agency", e.target.checked)} />
                    <Label className="form-check-label" for="create-is-agency">Esta empresa é uma agência</Label>
                </div>
                {managedBy > 0 && <div className="form-text">Uma empresa gerida por uma agência não pode ser agência.</div>}
            </Col></>}
        </Row>
    );
}
