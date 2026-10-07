import React from "react";
import { Link } from "react-router-dom";
import { Col, Row } from "reactstrap";

/**
 * O cabeçalho de TODAS as páginas (documents/design-system.md): o título (h4, o mesmo nível
 * em toda a app) com os breadcrumbs à direita e, por baixo, a barra de ações: a descrição
 * à esquerda e as ações à direita (no máximo UMA ação principal, as secundárias em
 * contorno, as raras no menu "..."). Referência: a página "Regras de formato".
 */
export type Crumb = { label: string; to?: string };

type Props = {
    title: React.ReactNode;
    /** Os níveis acima da página (a página atual entra sozinha no fim). */
    breadcrumbs?: Crumb[];
    /** Texto curto que explica a página (fica à esquerda das ações). */
    description?: React.ReactNode;
    /** Secundárias primeiro, depois o menu "...", e a principal no fim (à direita). */
    actions?: React.ReactNode;
    /** Texto do último breadcrumb, quando o título não é texto simples. */
    crumbLabel?: string;
};

export default function PageHeader({ title, breadcrumbs = [], description, actions, crumbLabel }: Props) {
    const current = crumbLabel ?? (typeof title === "string" ? title : "");
    return (
        <>
            <Row>
                <Col xs={12}>
                    <div className="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 className="mb-sm-0 text-truncate" data-testid="page-title">{title}</h4>
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
            {(description || actions) && (
                <div className="xp-page-toolbar" data-testid="page-toolbar">
                    <div className="xp-page-toolbar-text">{description}</div>
                    {actions && <div className="xp-page-toolbar-actions">{actions}</div>}
                </div>
            )}
        </>
    );
}
