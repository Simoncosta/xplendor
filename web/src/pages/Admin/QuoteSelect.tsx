import Select from "react-select";
import { reactSelectTheme, reactSelectThemeSm } from "helpers/reactSelectStyles";

/**
 * Seletor do módulo de orçamentos: o react-select da app com o tema de claro e escuro
 * (reactSelectTheme). O menu abre num portal no body, por cima de tudo, para não ficar
 * cortado dentro das linhas, dos cartões nem dos modais.
 */
export type QuoteSelectOption<T extends string | number> = { value: T; label: string };

type Props<T extends string | number> = {
    options: QuoteSelectOption<T>[];
    value: T | null | undefined;
    onChange: (value: T | null) => void;
    inputId?: string;
    ariaLabel?: string;
    placeholder?: string;
    isDisabled?: boolean;
    isClearable?: boolean;
    isSearchable?: boolean;
    small?: boolean;
};

export default function QuoteSelect<T extends string | number>({
    options, value, onChange, inputId, ariaLabel, placeholder, isDisabled, isClearable, isSearchable = false, small,
}: Props<T>) {
    const selected = options.find((o) => o.value === value) ?? null;

    return (
        <Select
            inputId={inputId}
            aria-label={ariaLabel}
            styles={small ? reactSelectThemeSm : reactSelectTheme}
            classNamePrefix="react-select"
            menuPortalTarget={document.body}
            menuPlacement="auto"
            options={options}
            value={selected}
            onChange={(o: any) => onChange(o ? (o.value as T) : null)}
            isDisabled={isDisabled}
            isClearable={isClearable}
            isSearchable={isSearchable}
            placeholder={placeholder ?? "Escolher"}
            noOptionsMessage={() => "Sem resultados"}
        />
    );
}
