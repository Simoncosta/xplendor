import React from "react";
import { Link } from "react-router-dom";
import { Col, Row } from "reactstrap";
import InfoTip from "./InfoTip";

/**
 * O cabeçalho de TODAS as páginas (documents/design-system.md §1): o título (h4, o mesmo nível
 * em toda a app) com o (i) da explicação ao lado e os breadcrumbs à direita. Por baixo, só os
 * FILTROS que valem para a página inteira (período, loja). As ações da página ficam DENTRO dos
 * cartões (PageCard), no cabeçalho do quadro a que dizem respeito.
 */
export type Crumb = { label: string; to?: string };

type Props = {
    title: React.ReactNode;
    /** Os níveis acima da página (a página atual entra sozinha no fim). */
    breadcrumbs?: Crumb[];
    /** A explicação curta da página, num (i) ao lado do título (tooltip; toque no telemóvel). */
    info?: React.ReactNode;
    /** Filtros que valem para a página inteira (período, loja), por baixo do título, à direita. */
    filters?: React.ReactNode;
    /** Texto do último breadcrumb, quando o título não é texto simples. */
    crumbLabel?: string;
};

export default function PageHeader({ title, breadcrumbs = [], info, filters, crumbLabel }: Props) {
    const current = crumbLabel ?? (typeof title === "string" ? title : "");
    return (
        <>
            <Row>
                <Col xs={12}>
                    <div className="page-title-box d-sm-flex align-items-center justify-content-between">
                        {info ? (
                            <div className="d-flex align-items-center gap-1 mb-2 mb-sm-0" style={{ minWidth: 0 }}>
                                <h4 className="mb-0 text-truncate" data-testid="page-title">{title}</h4>
                                <InfoTip text={info} />
                            </div>
                        ) : (
                            <h4 className="mb-sm-0 text-truncate" data-testid="page-title">{title}</h4>
                        )}
                        {(breadcrumbs.length > 0 || current) && (
                            <div className="page-title-right">
                                <ol className="breadcrumb m-0">
                                    {breadcrumbs.map((c, i) => (
                                        <li key={i} className="breadcrumb-item">{c.to ? <Link to={c.to}>{c.label}</Link> : c.label}</li>
                                    ))}
                                    {current && <li className="breadcrumb-item active">{current}</li>}
                                </ol>
                            </div>
                        )}
                    </div>
                </Col>
            </Row>
            {filters && (
                <div className="xp-page-toolbar" data-testid="page-toolbar">
                    <div className="xp-page-toolbar-text" />
                    <div className="xp-page-toolbar-actions">
                        <div className="xp-page-filters" data-testid="page-filters">{filters}</div>
                    </div>
                </div>
            )}
        </>
    );
}
