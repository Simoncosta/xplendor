import Select from "react-select";
import { reactSelectTheme, reactSelectThemeSm } from "helpers/reactSelectStyles";

/**
 * Escolha única da Linha Editorial (react-select com o tema claro e escuro do Velzon).
 * Todos os campos de escolha do módulo usam este componente, em vez de <select>.
 */
export type XOption<V extends string | number = string> = { value: V; label: string; isDisabled?: boolean };

type Props<V extends string | number> = {
    id?: string;
    options: XOption<V>[];
    value: V | null | undefined;
    onChange: (value: V) => void;
    placeholder?: string;
    small?: boolean;
    disabled?: boolean;
    searchable?: boolean;
    ariaLabel?: string;
    width?: number | string;
};

export default function XSelect<V extends string | number>({ id, options, value, onChange, placeholder, small, disabled, searchable, ariaLabel, width }: Props<V>) {
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
