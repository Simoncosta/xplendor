import React, { Fragment, ReactNode, useMemo, useState } from "react";
import { DataTable, DataTableColumn, DataTableProps, DataTableRowExpansionProps, DataTableSortStatus } from "mantine-datatable";
import { TextInput, Group, Button, Tooltip } from "@mantine/core";
import IconPencil from "./icon/icon-pencil";
import IconTrash from "./icon/icon-trash";
import IconSearch from "./icon/icon-search";
import UButton from "./u-button";
import { getTranslation } from '@/i18n';

export interface UDatatableRowAction<T> {
    icon: ReactNode;
    hint?: string;
    onClick: (row: T) => void;
    disabled?: boolean;
}

export interface UDatatableControls<T> {
    onEdit?: (row: T) => void;
    onDelete?: (row: T) => void;
    customActions?: UDatatableRowAction<T>[];
}

export const PAGE_SIZES = [15, 20, 30, 50, 100] as const;
export type TPageSize = typeof PAGE_SIZES[number];
export interface IToolbarButton {
    label?: string;
    onClick: (event: React.MouseEvent<HTMLButtonElement>) => void;
    color?: string;
    variant?: "filled" | "outline" | "light" | "subtle";
    icon?: ReactNode;
    tooltip?: string;
    isRounded?: boolean
}

export type UDatatableProps<T> = Omit<DataTableProps<T>, 'onRecordsPerPageChange'> & {
    toolbarButtons?: (IToolbarButton | JSX.Element)[];
    searchable?: boolean;
    search?: string;
    onSearchChange?: (query: string) => void;
    sortDirection?: 'asc' | 'desc';
    onRecordsPerPageChange: (pageSize: TPageSize) => void;
    controls?: UDatatableControls<T>;
    rowExpansion?: DataTableRowExpansionProps<T>;
}

/**
 * Componente de tabela genérico com pesquisa, paginação e barra de ferramentas.
 */
export function UDatatable<T extends Record<string, any>>(
    props: UDatatableProps<T>
) {
    const {
        records,
        columns,
        page = 1,
        totalRecords,
        fetching = false,
        toolbarButtons = [],
        searchable = true,
        search = "",
        noRecordsText = "Nenhum registro encontrado",
        onPageChange,
        onRecordsPerPageChange,
        onSearchChange,
        onRowClick,
        sortDirection = 'asc',
        controls,
        ...rest
    } = props;

    const [sortStatus, setSortStatus] = useState<DataTableSortStatus>({
        columnAccessor: "", // string vazia indica nenhuma coluna selecionada
        direction: 'asc',    // nenhuma direção
    });
    const [recordsPerPage, setRecordsPerPage] = useState<TPageSize>(PAGE_SIZES[0]);

    // Ordenação dos dados
    const sortedData = useMemo(() => {
        if (!sortStatus?.columnAccessor || !sortStatus.direction) return records;

        const col = columns.find(c => c.accessor === sortStatus.columnAccessor);

        if (!col || !col.sortable) return records; // só ordena se a coluna for sortable

        return [...(records || [])].sort((a, b) => {
            const aValue = a[sortStatus.columnAccessor];
            const bValue = b[sortStatus.columnAccessor];

            if (aValue < bValue) return sortStatus.direction === sortDirection ? -1 : 1;
            if (aValue > bValue) return sortStatus.direction === sortDirection ? 1 : -1;
            return 0;
        });
    }, [records, sortStatus, columns]);

    //Função para verificar se os botões do toolbar são jsx.Element
    function isJsxElementArray(
        arr: any
    ): arr is JSX.Element[] {
        return Array.isArray(arr) && arr.every((el) => React.isValidElement(el));
    }

    //Função para verificar se os botões do toolbar serão criado usando o Mantine
    function isToolbarButtonArray(
        arr: any
    ): arr is IToolbarButton[] {
        return (
            Array.isArray(arr) &&
            arr.every(
                (el) =>
                    typeof el === "object" &&
                    "onClick" in el
            )
        );
    }

    function renderButtonCustom(btn: IToolbarButton, index: number) {
        const hasLabel = Boolean(btn.label);
        const buttonEl = (
            <Button
                key={index}
                color={btn.color || "blue"}
                variant={btn.variant || "filled"}
                onClick={btn.onClick}
                leftIcon={hasLabel ? btn.icon : undefined}
                size={hasLabel ? "sm" : "xs"}
                px={hasLabel ? "sm" : 8}
                radius={hasLabel ? "md" : "sm"}
                style={{
                    height: hasLabel ? 34 : 28,
                    display: "flex",
                    alignItems: "center",
                    justifyContent: "center",
                    minWidth: hasLabel ? undefined : 32,
                }}
            >
                {hasLabel ? btn.label : btn.icon}
            </Button>
        );

        return btn.tooltip ? (
            <Tooltip key={index} label={btn.tooltip || ""} withArrow transition="fade">
                <div style={{ display: "inline-flex" }}>{buttonEl}</div>
            </Tooltip>
        ) : (
            buttonEl
        );
    }

    function renderJsxElement(item: JSX.Element, i: number) {
        return <Fragment key={i}>{item}</Fragment>;
    }

    const actionColumn: DataTableColumn<T> | null = controls
        ? {
            accessor: "__actions",
            title: "Ações",
            textAlignment: "center",
            width: 100,
            render: (row: T) => (
                <div className="flex gap-2 justify-center">
                    {controls.onEdit && (
                        <UButton
                            isIconOnly={true}
                            type='button'
                            icon={<IconPencil className="w-4 h-4" />}
                            title={'Editar'}
                            className="text-blue-600 hover:text-blue-800"
                            onClick={(e) => {
                                e.stopPropagation();
                                controls.onEdit!(row);
                            }}>
                        </UButton>
                    )}

                    {controls.onDelete && (
                        <UButton
                            isIconOnly={true}
                            type='button'
                            icon={<IconTrash className="w-4 h-4" />}
                            title={'Remover'}
                            className="text-red-600 hover:text-red-800"
                            onClick={(e) => {
                                e.stopPropagation();
                                controls.onDelete!(row);
                            }}>
                        </UButton>
                    )
                    }

                    {
                        controls.customActions?.map((action, index) => (
                            <UButton
                                isIconOnly={true}
                                key={index}
                                type='button'
                                icon={action.icon}
                                title={action.hint}
                                disabled={action.disabled}
                                className={`text-blue-600 hover:text-blue-800 ${action.disabled ? 'hover:cursor-not-allowed' : 'hover:cursor-pointer'}`}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    action.onClick(row);
                                }}>
                            </UButton>
                        ))
                    }
                </div >
            )
        }
        : null;

    const finalColumns = actionColumn
        ? [...columns, actionColumn]
        : columns;

    return (
        <div className={`flex flex-col space-y-3 ${props.className}`} style={{ width: "100%", height: "100%" }}>

            <Group position="apart" w="100%">
                {/* Lado esquerdo */}
                <Group spacing="xs">
                    {searchable && (
                        <TextInput
                            placeholder="Pesquisar..."
                            icon={<IconSearch />}
                            value={search}
                            onChange={(e) => onSearchChange?.(e.currentTarget.value)}
                            sx={{ width: 260 }}
                        />
                    )}
                </Group>

                {/* Lado direito */}
                <Group spacing="xs">
                    {toolbarButtons
                        .map((item: (IToolbarButton | JSX.Element), i: number) => {
                            if (isJsxElementArray([item])) {
                                return renderJsxElement(item as JSX.Element, i)
                            }
                            if (isToolbarButtonArray([item])) return renderButtonCustom(item as IToolbarButton, i);
                            return null;
                        })}
                </Group>
            </Group>


            {/* 📊 Tabela */}
            <div style={{ flex: 1, minHeight: 0 }}>
                <DataTable<T>
                    columns={finalColumns}
                    records={sortedData}
                    onRowClick={onRowClick}
                    noRecordsText={noRecordsText}
                    fetching={fetching}
                    striped={true}
                    highlightOnHover={true}
                    withBorder={true}
                    borderRadius="lg"
                    withColumnBorders
                    totalRecords={totalRecords}
                    recordsPerPage={recordsPerPage}
                    page={page}
                    onPageChange={typeof onPageChange === 'function' ? onPageChange : (() => { })}
                    paginationText={({ from, to, totalRecords }) =>
                        `${from}–${to} de ${totalRecords}`
                    }
                    recordsPerPageOptions={[...PAGE_SIZES]}
                    onRecordsPerPageChange={(recordsPerPage) => {
                        if (typeof onRecordsPerPageChange === "function") {
                            setRecordsPerPage(recordsPerPage as TPageSize);
                            onRecordsPerPageChange(recordsPerPage as TPageSize);
                        }
                    }}
                    minHeight={0}
                    style={{ width: "100%", height: "100%" }}
                    sortStatus={sortStatus ?? undefined}
                    onSortStatusChange={setSortStatus}
                    rowExpansion={props.rowExpansion}
                />
            </div>
        </div>
    );
}
