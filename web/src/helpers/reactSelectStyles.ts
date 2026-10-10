/**
 * Estilos partilhados para o react-select, ancorados nas variáveis de tema do
 * Velzon (--vz-*). Como o react-select injeta estilos inline (emotion) e ignora
 * o nosso CSS/SCSS, o controlo fechado (control/singleValue/input/placeholder)
 * ficava sempre claro mesmo em dark mode. Ao apontar cada slot para uma var de
 * tema, o componente segue automaticamente o data-bs-theme do <html> — claro em
 * light, escuro E legível em dark (fundo escuro + texto claro). Sem cores fixas.
 *
 * Tamanho (documents/design-system.md §7): o normal tem a altura, a letra e o
 * espaçamento de um .form-control do Velzon (37,8 px); o compacto (Sm) os de um
 * .form-control-sm / .btn-sm (27,3 px). Os valores vêm de config/saas/_variables.scss
 * ($font-size-base 0.825rem, $font-size-sm ×0.875, $input-padding-x 0.9rem / sm 0.5rem).
 *
 * Uso: <Select ... styles={reactSelectTheme} />
 * Params tipados como `any` de propósito: encaixa em qualquer Option/IsMulti sem
 * fricção de generics (evita colisões com @types/react-select antigos).
 */
export const reactSelectTheme = {
    control: (base: any, state: any) => ({
        ...base,
        minHeight: "calc(1.2375rem + 1rem + 2px)",
        fontSize: "0.825rem",
        borderRadius: "var(--vz-border-radius)",
        backgroundColor: "var(--vz-input-bg-custom)",
        borderColor: state.isFocused ? "var(--vz-primary)" : "var(--vz-input-border-custom)",
        boxShadow: state.isFocused ? "0 0 0 0.15rem rgba(64,81,137,0.25)" : "none",
        color: "var(--vz-body-color)",
        "&:hover": { borderColor: "var(--vz-primary)" },
    }),
    singleValue: (base: any) => ({ ...base, color: "var(--vz-body-color)" }),
    input: (base: any) => ({ ...base, color: "var(--vz-body-color)" }),
    placeholder: (base: any) => ({ ...base, color: "var(--vz-secondary-color)" }),
    // O texto começa onde começa o de um .form-control (0.9rem; o valor já tem 2px de margem).
    valueContainer: (base: any) => ({ ...base, color: "var(--vz-body-color)", padding: "2px calc(0.9rem - 2px)" }),
    // Menu flutuante: superfície de cartão/dropdown. Usa --vz-secondary-bg
    // (branco em claro, escuro em dark) porque é GLOBAL no :root — ao contrário
    // de --vz-card-bg, que só existe dentro de .card e ficava indefinido (menu
    // transparente) quando o select vive num modal fora de um cartão.
    menu: (base: any) => ({
        ...base,
        backgroundColor: "var(--vz-secondary-bg)",
        borderColor: "var(--vz-border-color)",
    }),
    menuPortal: (base: any) => ({ ...base, zIndex: 9999 }),
    option: (base: any, state: any) => ({
        ...base,
        backgroundColor: state.isSelected
            ? "var(--vz-primary)"
            : state.isFocused
                ? "var(--vz-tertiary-bg)"
                : "transparent",
        color: state.isSelected ? "#fff" : "var(--vz-body-color)",
        "&:active": { backgroundColor: "var(--vz-tertiary-bg)" },
    }),
    // Chips (isMulti): fundo subtil + texto legível em ambos os temas.
    multiValue: (base: any) => ({ ...base, backgroundColor: "var(--vz-primary-bg-subtle)" }),
    multiValueLabel: (base: any) => ({ ...base, color: "var(--vz-body-color)" }),
    multiValueRemove: (base: any) => ({
        ...base,
        color: "var(--vz-secondary-color)",
        "&:hover": { backgroundColor: "var(--vz-danger)", color: "#fff" },
    }),
    indicatorSeparator: (base: any) => ({ ...base, backgroundColor: "var(--vz-border-color)" }),
    // Sem padding vertical nas setas: a altura vem só do minHeight (a do .form-control).
    dropdownIndicator: (base: any) => ({ ...base, color: "var(--vz-secondary-color)", paddingTop: 0, paddingBottom: 0 }),
    clearIndicator: (base: any) => ({ ...base, color: "var(--vz-secondary-color)", paddingTop: 0, paddingBottom: 0 }),
};

/**
 * Variante compacta, com a altura de um .form-control-sm / .btn-sm (27,3 px): barras de
 * filtros e células de tabela, ao lado de botões pequenos. Mesmas cores de tema.
 */
export const reactSelectThemeSm = {
    ...reactSelectTheme,
    control: (base: any, state: any) => ({
        ...reactSelectTheme.control(base, state),
        minHeight: "calc(1.0828125rem + 0.5rem + 2px)",
        fontSize: "0.721875rem",
        borderRadius: "var(--vz-border-radius-sm)",
    }),
    valueContainer: (base: any) => ({ ...reactSelectTheme.valueContainer(base), padding: "0 calc(0.5rem - 2px)" }),
    input: (base: any) => ({ ...reactSelectTheme.input(base), margin: 0, padding: 0 }),
    multiValue: (base: any) => ({ ...reactSelectTheme.multiValue(base), margin: 1 }),
    dropdownIndicator: (base: any) => ({ ...reactSelectTheme.dropdownIndicator(base), padding: "0 4px", "& svg": { width: 16, height: 16 } }),
    clearIndicator: (base: any) => ({ ...reactSelectTheme.clearIndicator(base), padding: "0 4px", "& svg": { width: 16, height: 16 } }),
    option: (base: any, state: any) => ({ ...reactSelectTheme.option(base, state), fontSize: "0.721875rem" }),
};
