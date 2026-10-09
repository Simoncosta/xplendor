import Select from "react-select";
import { reactSelectTheme, reactSelectThemeSm } from "helpers/reactSelectStyles";

/**
 * As escolhas de toda a aplicação (documents/design-system.md §7): react-select com o tema
 * claro e escuro do Velzon, nunca um <select> nativo. O menu abre no document.body (não fica
 * cortado em modais nem em tabelas) e há pesquisa quando há mais de 8 opções.
 *  · XSelect: escolha única.
 *  · XMultiSelect: escolha múltipla (chips), com o mesmo tema.
 */
export type XOption<V extends string | number = string> = { value: V; label: string; isDisabled?: boolean };

type BaseProps<V extends string | number> = {
    id?: string;
    options: XOption<V>[];
    placeholder?: string;
    small?: boolean;
    disabled?: boolean;
    searchable?: boolean;
    ariaLabel?: string;
    width?: number | string;
};

type SingleProps<V extends string | number> = BaseProps<V> & {
    value: V | null | undefined;
    onChange: (value: V) => void;
};

export default function XSelect<V extends string | number>({ id, options, value, onChange, placeholder, small, disabled, searchable, ariaLabel, width }: SingleProps<V>) {
    return (
        <div style={width ? { width } : undefined}>
            <Select
                inputId={id}
                aria-label={ariaLabel}
                styles={small ? reactSelectThemeSm : reactSelectTheme}
                menuPortalTarget={document.body}
                options={options}
                value={options.find((o) => o.value === value) ?? null}
                onChange={(o: any) => o && onChange(o.value)}
                placeholder={placeholder ?? "Escolher…"}
                isDisabled={disabled}
                isSearchable={searchable ?? options.length > 8}
                noOptionsMessage={() => "Sem opções"}
            />
        </div>
    );
}

type MultiProps<V extends string | number> = BaseProps<V> & {
    value: V[];
    onChange: (values: V[]) => void;
    /** Fecha o menu a cada escolha (por omissão fica aberto para escolher várias). */
    closeOnSelect?: boolean;
};

export function XMultiSelect<V extends string | number>({ id, options, value, onChange, placeholder, small, disabled, searchable, ariaLabel, width, closeOnSelect = false }: MultiProps<V>) {
    return (
        <div style={width ? { width } : undefined}>
            <Select
                isMulti
                inputId={id}
                aria-label={ariaLabel}
                styles={small ? reactSelectThemeSm : reactSelectTheme}
                menuPortalTarget={document.body}
                options={options}
                value={options.filter((o) => value.includes(o.value))}
                onChange={(list: any) => onChange(((list ?? []) as XOption<V>[]).map((o) => o.value))}
                placeholder={placeholder ?? "Escolher…"}
                isDisabled={disabled}
                isSearchable={searchable ?? options.length > 8}
                closeMenuOnSelect={closeOnSelect}
                noOptionsMessage={() => "Sem opções"}
            />
        </div>
    );
}
