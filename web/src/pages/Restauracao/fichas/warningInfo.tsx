import { useState } from "react";
import { Button, Collapse } from "reactstrap";
import InfoTip from "Components/Common/InfoTip";

/**
 * Fatia 4 (B3) — de onde vem cada aviso das fichas técnicas (calculado pela XPLENDOR a partir de
 * quê), porque importa e o que fazer. Um InfoTip por aviso e uma legenda curta no topo do detalhe.
 */
type Info = { title: string; from: string; why: string; todo: string; group: string; info?: boolean };

export const WARNING_INFO: Record<string, Info> = {
    no_cost: {
        group: "Custo", title: "Sem custo",
        from: "A XPLENDOR não encontrou compras deste componente (faturas e faturas-recibo de fornecedor fechadas).",
        why: "Sem o custo de um componente, o custo da ficha fica parcial e o food cost aparece mais baixo do que é.",
        todo: "Confirme se o componente da ficha é o artigo que se compra; se não for, troque-o na ficha.",
    },
    old_cost: {
        group: "Custo", title: "Preço antigo",
        from: "Não há compras nos últimos 90 dias: usa-se o preço da última compra conhecida.",
        why: "O preço pode ter mudado desde essa compra.",
        todo: "Veja o preço atual com o fornecedor; o aviso desaparece com a próxima compra.",
    },
    outliers: {
        group: "Custo", title: "Compras fora do normal",
        from: "Compras com preço acima de 4 vezes ou abaixo de um quarto da mediana dos 90 dias ficaram de fora do custo.",
        why: "Costumam ser erros de unidade ou de quantidade na fatura, e distorcem a média.",
        todo: "Veja essas faturas: se a quantidade ou a unidade estiver errada, corrija-a na PingWin.",
    },
    pingwin_diff: {
        group: "Custo", title: "Diferença face à PingWin",
        from: "O custo XPLENDOR (das compras) difere mais de 20% do custo que a PingWin calcula para todos os armazéns.",
        why: "Uma das duas contas está a usar uma unidade, um preço ou um armazém diferente.",
        todo: "Veja as linhas com a maior diferença; muitas vezes é um fator de unidade por confirmar.",
    },
    zero_cost: {
        group: "Custo", title: "Custo 0", info: true,
        from: "O componente não tem compras pagas e a PingWin também lhe dá custo 0.",
        why: "É normal nos subprodutos (aparas, ossos, sobras), que não custam nada à receita.",
        todo: "Nada, se for um subproduto. Se não for, confirme se o componente certo está na ficha.",
    },
    similar: {
        group: "Componentes", title: "Compra-se um parecido",
        from: "Este componente não tem compras, mas há um artigo com o nome quase igual que se compra.",
        why: "A ficha pode estar a usar um artigo antigo em vez do que se compra hoje.",
        todo: "Se for o mesmo produto, troque o componente na ficha pelo que se compra.",
    },
    inactive: {
        group: "Componentes", title: "Componente inativo",
        from: "O estado do componente na PingWin é Inativo (ou o artigo foi anulado).",
        why: "Uma ficha com componentes inativos costuma estar desatualizada.",
        todo: "Troque-o pelo componente em uso, ou confirme que continua certo.",
    },
    unit_factor: {
        group: "Unidades", title: "Fator de unidade suspeito",
        from: "A unidade tem nome de embalagem (caixa, grade, barril…) mas vale 1, ou as compras são noutra família de unidades.",
        why: "O custo por unidade base pode estar multiplicado ou dividido pela embalagem.",
        todo: "Use \"Confirmar fator\" na linha (fica só na XPLENDOR).",
    },
    factor_unconfirmed: {
        group: "Unidades", title: "Fator por confirmar",
        from: "Há um fator sugerido que explica a diferença face à PingWin, e ainda não foi confirmado.",
        why: "Sem o fator certo, o custo deste componente está errado.",
        todo: "Use \"Confirmar fator\" na linha.",
    },
    yield_unconfirmed: {
        group: "Unidades", title: "Rendimento por confirmar",
        from: "O semi-produto não tem rendimento confirmado: o custo do lote conta-se por 1 unidade base.",
        why: "Se a ficha for de um lote (ex.: 30 L), o custo por unidade sai muito alto.",
        todo: "Abra o semi-produto e confirme quanto rende o lote.",
    },
    batch: {
        group: "Unidades", title: "Ficha de lote",
        from: "Os componentes somam muito mais do que 1 unidade base do semi-produto.",
        why: "A PingWin conta a ficha por 1 unidade base; nos pratos usa o custo de stock do semi-produto.",
        todo: "Confirme o rendimento do lote.",
    },
    same_day: {
        group: "Ficha", title: "Versões com a mesma data",
        from: "Há duas ou mais versões com a mesma data de início.",
        why: "Pela regra da XPLENDOR conta a criada depois, que pode não ser a pretendida.",
        todo: "Apague a versão a mais.",
    },
    empty_version: {
        group: "Ficha", title: "Versão sem linhas",
        from: "A versão em vigor não tem componentes.",
        why: "O artigo fica sem custo e a PingWin não desconta stock na venda.",
        todo: "Junte os componentes ou apague essa versão.",
    },
    store_without: {
        group: "Ficha", title: "Loja sem ficha em vigor",
        from: "A loja vendeu o artigo nos últimos 90 dias e nenhuma versão da ficha está ativa nela.",
        why: "Nessa loja não há custo nem desconto de stock da receita.",
        todo: "Ative a loja numa versão da ficha.",
    },
    cycle: {
        group: "Ficha", title: "Ciclo",
        from: "Um semi-produto usa-se a si próprio, direta ou indiretamente.",
        why: "O custo não se pode calcular.",
        todo: "Corrija a ficha do semi-produto.",
    },
    depth: {
        group: "Ficha", title: "Mais de 5 níveis",
        from: "A cadeia de semi-produtos tem mais de 5 níveis.",
        why: "A XPLENDOR não calcula para lá de 5 níveis.",
        todo: "Simplifique a cadeia de semi-produtos.",
    },
};

export function WarningTip({ type }: { type: string }) {
    const w = WARNING_INFO[type];
    if (!w) return null;
    return (
        <InfoTip
            label={`Sobre o aviso: ${w.title}`}
            placement="top"
            text={<><strong>{w.title}.</strong> {w.from}<br /><strong>Porque importa:</strong> {w.why}<br /><strong>O que fazer:</strong> {w.todo}</>}
        />
    );
}

/** A legenda curta dos tipos de aviso, por grupo (no topo do detalhe). */
export function WarningLegend() {
    const [open, setOpen] = useState(false);
    const groups = Object.entries(WARNING_INFO).reduce<Record<string, [string, Info][]>>((acc, [k, w]) => {
        (acc[w.group] ??= []).push([k, w]);
        return acc;
    }, {});
    return (
        <div className="mb-3" data-testid="warning-legend">
            <Button type="button" size="sm" color="link" className="p-0 fs-13" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
                <i className={`${open ? "ri-arrow-up-s-line" : "ri-arrow-down-s-line"} me-1`} aria-hidden />Legenda dos avisos
            </Button>
            <Collapse isOpen={open}>
                <div className="d-flex flex-wrap gap-4 mt-2 fs-12">
                    {Object.entries(groups).map(([g, items]) => (
                        <div key={g} style={{ minWidth: 200 }}>
                            <div className="fw-semibold text-uppercase text-muted fs-11 mb-1">{g}</div>
                            <ul className="list-unstyled mb-0">
                                {items.map(([k, w]) => (
                                    <li key={k} className="mb-1">
                                        <i className={`${w.info ? "ri-information-line text-info" : "ri-alert-line text-warning"} me-1`} aria-hidden />
                                        {w.title} <WarningTip type={k} />
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            </Collapse>
        </div>
    );
}
