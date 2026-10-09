import React, { useEffect, useMemo, useState } from "react";
import {
    ColumnDef,
    PaginationState,
    SortingState,
    flexRender,
    getCoreRowModel,
    getPaginationRowModel,
    getSortedRowModel,
    useReactTable,
} from "@tanstack/react-table";
import Pagination from "./Pagination";
import ColumnSelector from "./ColumnSelector";
import useTablePrefs from "hooks/useTablePrefs";
import { useIsMobile } from "hooks/useIsMobile";

/**
 * A tabela única da aplicação (documents/design-system.md §8), sobre o @tanstack/react-table v8
 * com o markup das Basic Tables do Velzon. Ordenação, paginação, pesquisa, colunas visíveis
 * ("Colunas", guardadas por pessoa e por tabela), carregamento (esqueleto), vazio, ações por
 * linha e cartões no telemóvel.
 *
 * Dois modos:
 *  · "client" (por omissão): a página entrega TODAS as linhas; a tabela ordena, pesquisa e pagina.
 *  · "server": a página entrega UMA página; a paginação e a ordenação são pedidas ao servidor
 *    (`server.onPageChange`, `server.onSortChange`). Sem `onSortChange`, nada se ordena.
 *
 * Uso:
 *   const cols = useDataColumns("restauracao.fornecedores", [...colunas]);
 *   <PageCard actions={<>{cols.selector}<Button …/></>}>
 *     <DataTable columns={cols} data={rows} rowKey={(r) => r.id} … />
 *   </PageCard>
 */

export type DTAlign = "start" | "center" | "end";

export type DTColumn<T> = {
    id: string;
    /** Título da coluna (também no "Colunas" e nos cartões do telemóvel). */
    header: string;
    /** Valor simples da linha: usado para ordenar, pesquisar e, sem `cell`, para mostrar. */
    value?: (row: T) => string | number | null | undefined;
    /** Célula rica (badges, ligações, cores). */
    cell?: (row: T) => React.ReactNode;
    /** Ordenável (por omissão: sim, quando há `value`). */
    sortable?: boolean;
    /** Nome do campo a pedir ao servidor (modo "server"). */
    sortKey?: string;
    align?: DTAlign;
    /** Visível por omissão (por omissão: sim). As outras ficam no "Colunas". */
    defaultVisible?: boolean;
    /** Pode ser escondida no "Colunas" (por omissão: sim). */
    hideable?: boolean;
    /** Coluna ainda sem dados: não se mostra e aparece desativada no "Colunas", com este motivo. */
    unavailable?: string | null;
    nowrap?: boolean;
    /** Classes do cabeçalho e das células. */
    className?: string;
    /** Classes por célula (por exemplo, âmbar quando falta um valor). */
    cellClassName?: (row: T) => string | undefined;
    /** Papel no cartão do telemóvel: título, linha secundária, ou fora. */
    mobile?: "title" | "subtitle" | "hide";
};

export type DataColumns<T> = {
    key: string;
    defs: DTColumn<T>[];
    isVisible: (id: string) => boolean;
    /** O botão "Colunas", para o cabeçalho do cartão (PageCard `actions`). */
    selector: React.ReactNode;
};

/** As colunas de uma tabela, com a escolha de cada pessoa (useTablePrefs) e o botão "Colunas". */
export function useDataColumns<T>(key: string, defs: DTColumn<T>[]): DataColumns<T> {
    const { prefs, setColumn, resetColumns } = useTablePrefs(key);
    const chosen = prefs.columns ?? {};
    const isVisible = (id: string) => {
        const d = defs.find((c) => c.id === id);
        if (!d) return false;
        if (d.unavailable) return false;
        if (d.hideable === false) return true;
        return chosen[id] ?? (d.defaultVisible ?? true);
    };
    const hideable = defs.filter((d) => d.hideable !== false);
    const selector = hideable.length > 0 ? (
        <ColumnSelector
            columns={hideable.map((d) => ({ id: d.id, label: d.header, visible: isVisible(d.id), disabledReason: d.unavailable ?? null }))}
            onChange={setColumn}
            onReset={resetColumns}
        />
    ) : null;
    return { key, defs, isVisible, selector };
}

type ServerProps = {
    page: number;
    lastPage: number;
    total: number;
    perPage: number;
    from: number;
    to: number;
    onPageChange: (page: number) => void;
    sort?: { id: string; desc: boolean } | null;
    onSortChange?: (sort: { id: string; key: string; desc: boolean } | null) => void;
};

type Props<T> = {
    columns: DataColumns<T>;
    data: T[];
    rowKey: (row: T) => string | number;
    mode?: "client" | "server";
    loading?: boolean;
    /** Texto e ação opcional quando não há linhas. */
    empty?: { message: React.ReactNode; action?: React.ReactNode };
    /** Ações por linha (design-system §4: uma ou duas visíveis, o resto no "..."). */
    rowActions?: (row: T) => React.ReactNode;
    rowClassName?: (row: T) => string | undefined;
    onRowClick?: (row: T) => void;
    /** Pesquisa (modo "client": filtra aqui; modo "server": a página envia-a à API). */
    search?: string;
    /** Linhas por página no modo "client" (por omissão 25). */
    pageSize?: number;
    initialSort?: { id: string; desc?: boolean };
    server?: ServerProps;
    /** Cartão próprio no telemóvel (por omissão: título + pares rótulo/valor). */
    mobileCard?: (row: T, actions: React.ReactNode) => React.ReactNode;
    /** Nome da tabela para leitores de ecrã. */
    caption?: string;
    "data-testid"?: string;
};

const norm = (v: unknown) =>
    String(v ?? "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();

/** Compara valores para ordenar: números como números, texto pela ordem portuguesa; vazios no fim. */
function compare(a: unknown, b: unknown): number {
    if (typeof a === "number" && typeof b === "number") return a - b;
    return String(a).localeCompare(String(b), "pt", { numeric: true, sensitivity: "base" });
}

const alignClass = (a?: DTAlign) => (a === "end" ? "text-end" : a === "center" ? "text-center" : "");

export default function DataTable<T>({
    columns, data, rowKey, mode = "client", loading, empty, rowActions, rowClassName, onRowClick,
    search = "", pageSize = 25, initialSort, server, mobileCard, caption, ...rest
}: Props<T>) {
    const isMobile = useIsMobile();
    const isServer = mode === "server";
    const visibleDefs = columns.defs.filter((d) => columns.isVisible(d.id));

    // Pesquisa no cliente: em todas as colunas com valor (mesmo as escondidas).
    const rows = useMemo(() => {
        if (isServer || !search.trim()) return data;
        const q = norm(search.trim());
        return data.filter((r) => columns.defs.some((d) => d.value && norm(d.value(r)).includes(q)));
    }, [data, search, isServer, columns.defs]);

    const [sorting, setSorting] = useState<SortingState>(initialSort ? [{ id: initialSort.id, desc: !!initialSort.desc }] : []);
    const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize });

    // Pesquisar volta à primeira página; menos linhas não deixa a página fora do fim.
    useEffect(() => { setPagination((p) => ({ ...p, pageIndex: 0 })); }, [search]);
    useEffect(() => {
        const last = Math.max(0, Math.ceil(rows.length / pagination.pageSize) - 1);
        if (!isServer && pagination.pageIndex > last) setPagination((p) => ({ ...p, pageIndex: last }));
    }, [rows.length, pagination.pageSize, pagination.pageIndex, isServer]);

    const serverSorting: SortingState = server?.sort ? [{ id: server.sort.id, desc: server.sort.desc }] : [];

    const colDefs: ColumnDef<T, any>[] = visibleDefs.map((d) => ({
        id: d.id,
        accessorFn: d.value ? (r: T) => d.value!(r) ?? undefined : undefined,
        header: d.header,
        enableSorting: isServer ? !!(d.sortKey && server?.onSortChange) : (d.sortable ?? !!d.value),
        sortUndefined: "last" as const,
        sortDescFirst: false, // o primeiro clique ordena sempre do menor para o maior
        sortingFn: (a, b, id) => compare(a.getValue(id), b.getValue(id)),
        cell: (info) => d.cell ? d.cell(info.row.original) : (d.value ? (d.value(info.row.original) ?? "—") : null),
        meta: d,
    }));

    const table = useReactTable({
        data: rows,
        columns: colDefs,
        getRowId: (r) => String(rowKey(r)),
        state: { sorting: isServer ? serverSorting : sorting, pagination },
        onSortingChange: (updater) => {
            const next = typeof updater === "function" ? updater(isServer ? serverSorting : sorting) : updater;
            if (isServer) {
                const s = next[0];
                const def = s ? columns.defs.find((d) => d.id === s.id) : undefined;
                server?.onSortChange?.(s && def?.sortKey ? { id: s.id, key: def.sortKey, desc: s.desc } : null);
            } else {
                setSorting(next);
                setPagination((p) => ({ ...p, pageIndex: 0 }));
            }
        },
        onPaginationChange: setPagination,
        manualSorting: isServer,
        // Atualizar os dados (por exemplo, o polling) não volta à página 1.
        autoResetPageIndex: false,
        manualPagination: isServer,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: isServer ? undefined : getSortedRowModel(),
        getPaginationRowModel: isServer ? undefined : getPaginationRowModel(),
        enableSortingRemoval: true,
    });

    const pageRows = table.getRowModel().rows;
    const colCount = visibleDefs.length + (rowActions ? 1 : 0);
    const showSkeleton = !!loading && data.length === 0;
    const showEmpty = !loading && pageRows.length === 0;
    const emptyMessage = search.trim() && rows.length === 0 && data.length > 0
        ? <>Nenhum resultado para “{search.trim()}”.</>
        : empty?.message ?? "Sem registos.";

    // Paginação (sempre dentro do cartão): a do servidor, ou a calculada aqui.
    const pager = (() => {
        if (isServer && server) {
            if (server.total <= 0) return null;
            return { current: server.page, last: server.lastPage, total: server.total, perPage: server.perPage, from: server.from, to: server.to, go: server.onPageChange };
        }
        const total = rows.length;
        if (total <= pagination.pageSize) return total > 0 ? { current: 1, last: 1, total, perPage: pagination.pageSize, from: 1, to: total, go: () => undefined } : null;
        const current = pagination.pageIndex + 1;
        const from = pagination.pageIndex * pagination.pageSize + 1;
        return {
            current, last: Math.ceil(total / pagination.pageSize), total, perPage: pagination.pageSize,
            from, to: Math.min(total, from + pagination.pageSize - 1),
            go: (p: number) => setPagination((s) => ({ ...s, pageIndex: p - 1 })),
        };
    })();

    // Uma só página: só a contagem (sem botões de páginas que não servem para nada).
    const footer = pager && (
        <div className="xp-dt-footer xp-no-print" data-testid="dt-footer">
            {pager.last <= 1
                ? <span className="text-muted fs-13">{pager.total} {pager.total === 1 ? "resultado" : "resultados"}</span>
                : <Pagination currentPage={pager.current} lastPage={pager.last} total={pager.total} perPage={pager.perPage}
                    from={pager.from} to={pager.to} onPageChange={pager.go} className="mb-0" />}
        </div>
    );

    const emptyBlock = (
        <div className="xp-dt-empty" data-testid="dt-empty">
            <div>{emptyMessage}</div>
            {empty?.action && !search.trim() && <div className="mt-2">{empty.action}</div>}
        </div>
    );

    // ── Telemóvel: cartões ───────────────────────────────────────────────
    if (isMobile) {
        const titleDef = visibleDefs.find((d) => d.mobile === "title") ?? visibleDefs[0];
        const subDefs = visibleDefs.filter((d) => d !== titleDef && d.mobile === "subtitle");
        const pairDefs = visibleDefs.filter((d) => d !== titleDef && d.mobile !== "subtitle" && d.mobile !== "hide");
        const render = (d: DTColumn<T>, r: T) => (d.cell ? d.cell(r) : d.value ? (d.value(r) ?? "—") : null);
        return (
            <div className="xp-dt xp-dt-mobile" data-testid={rest["data-testid"] ?? "datatable"} aria-busy={!!loading}>
                <div className="p-3 d-flex flex-column gap-2" style={loading && data.length > 0 ? { opacity: 0.55 } : undefined}>
                    {showSkeleton ? Array.from({ length: 4 }).map((_, i) => (
                        <div key={i} className="xp-dt-card placeholder-glow" data-testid="dt-skeleton">
                            <span className="placeholder col-7 mb-2" /><span className="placeholder col-4" />
                        </div>
                    )) : showEmpty ? emptyBlock : pageRows.map((row) => {
                        const r = row.original;
                        const actions = rowActions ? rowActions(r) : null;
                        if (mobileCard) return <React.Fragment key={row.id}>{mobileCard(r, actions)}</React.Fragment>;
                        return (
                            <div key={row.id} className={`xp-dt-card ${rowClassName?.(r) ?? ""}`} data-testid="dt-row"
                                role={onRowClick ? "button" : undefined} onClick={onRowClick ? () => onRowClick(r) : undefined}>
                                {titleDef && <div className="fw-semibold text-body text-truncate">{render(titleDef, r)}</div>}
                                {subDefs.map((d) => <div key={d.id} className="text-muted fs-12">{render(d, r)}</div>)}
                                {pairDefs.length > 0 && (
                                    <dl className="xp-dt-pairs mb-0 mt-2">
                                        {pairDefs.map((d) => (
                                            <React.Fragment key={d.id}>
                                                <dt>{d.header}</dt>
                                                <dd className={d.cellClassName?.(r)}>{render(d, r)}</dd>
                                            </React.Fragment>
                                        ))}
                                    </dl>
                                )}
                                {actions && <div className="d-flex justify-content-end gap-1 mt-2" onClick={(e) => e.stopPropagation()}>{actions}</div>}
                            </div>
                        );
                    })}
                </div>
                {footer}
            </div>
        );
    }

    // ── Computador: tabela ───────────────────────────────────────────────
    return (
        <div className="xp-dt" data-testid={rest["data-testid"] ?? "datatable"}>
            <div className="table-responsive">
                <table className="table table-bordered table-hover align-middle mb-0" aria-busy={!!loading} aria-label={caption}>
                    <thead className="table-light text-muted">
                        {table.getHeaderGroups().map((hg) => (
                            <tr key={hg.id}>
                                {hg.headers.map((h) => {
                                    const d = h.column.columnDef.meta as DTColumn<T>;
                                    const sorted = h.column.getIsSorted();
                                    const canSort = h.column.getCanSort();
                                    return (
                                        <th key={h.id} className={`${alignClass(d.align)} ${d.nowrap ? "text-nowrap" : ""} ${d.className ?? ""}`}
                                            aria-sort={sorted === "asc" ? "ascending" : sorted === "desc" ? "descending" : canSort ? "none" : undefined}
                                            data-testid={`th-${d.id}`}>
                                            {canSort ? (
                                                <button type="button" className={`xp-dt-sort ${sorted ? "on" : ""}`} onClick={h.column.getToggleSortingHandler()}
                                                    title={sorted === "asc" ? "Ordenado do menor para o maior" : sorted === "desc" ? "Ordenado do maior para o menor" : "Ordenar"}>
                                                    {flexRender(h.column.columnDef.header, h.getContext())}
                                                    <i className={sorted === "asc" ? "ri-arrow-up-line" : sorted === "desc" ? "ri-arrow-down-line" : "ri-arrow-up-down-line"} aria-hidden="true" />
                                                </button>
                                            ) : flexRender(h.column.columnDef.header, h.getContext())}
                                        </th>
                                    );
                                })}
                                {rowActions && <th className="text-end xp-no-print" style={{ width: 1 }}>Ações</th>}
                            </tr>
                        ))}
                    </thead>
                    <tbody style={loading && data.length > 0 ? { opacity: 0.55 } : undefined}>
                        {showSkeleton ? Array.from({ length: 5 }).map((_, i) => (
                            <tr key={i} className="placeholder-glow" data-testid="dt-skeleton">
                                {Array.from({ length: colCount }).map((__, j) => (
                                    <td key={j}><span className={`placeholder ${j === 0 ? "col-8" : "col-6"}`} /></td>
                                ))}
                            </tr>
                        )) : showEmpty ? (
                            <tr><td colSpan={colCount} className="p-0">{emptyBlock}</td></tr>
                        ) : pageRows.map((row) => {
                            const r = row.original;
                            return (
                                <tr key={row.id} className={rowClassName?.(r)} data-testid="dt-row"
                                    style={onRowClick ? { cursor: "pointer" } : undefined}
                                    onClick={onRowClick ? () => onRowClick(r) : undefined}>
                                    {row.getVisibleCells().map((cell) => {
                                        const d = cell.column.columnDef.meta as DTColumn<T>;
                                        return (
                                            <td key={cell.id} className={`${alignClass(d.align)} ${d.nowrap ? "text-nowrap" : ""} ${d.className ?? ""} ${d.cellClassName?.(r) ?? ""}`}>
                                                {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                            </td>
                                        );
                                    })}
                                    {rowActions && (
                                        <td className="text-end text-nowrap xp-no-print" onClick={(e) => e.stopPropagation()}>
                                            <div className="d-inline-flex gap-1 align-items-center">{rowActions(r)}</div>
                                        </td>
                                    )}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            {footer}
        </div>
    );
}
