/**
 * Estilos partilhados para o react-select, ancorados nas variáveis de tema do
 * Velzon (--vz-*). Como o react-select injeta estilos inline (emotion) e ignora
 * o nosso CSS/SCSS, o controlo fechado (control/singleValue/input/placeholder)
 * ficava sempre claro mesmo em dark mode. Ao apontar cada slot para uma var de
 * tema, o componente segue automaticamente o data-bs-theme do <html> — claro em
 * light, escuro E legível em dark (fundo escuro + texto claro). Sem cores fixas.
 *
 * Uso: <Select ... styles={reactSelectTheme} />
 * Params tipados como `any` de propósito: encaixa em qualquer Option/IsMulti sem
 * fricção de generics (evita colisões com @types/react-select antigos).
 */
export const reactSelectTheme = {
    control: (base: any, state: any) => ({
        ...base,
        backgroundColor: "var(--vz-input-bg-custom)",
        borderColor: state.isFocused ? "var(--vz-primary)" : "var(--vz-input-border-custom)",
        boxShadow: state.isFocused ? "0 0 0 0.15rem rgba(64,81,137,0.25)" : "none",
        color: "var(--vz-body-color)",
        "&:hover": { borderColor: "var(--vz-primary)" },
    }),
    singleValue: (base: any) => ({ ...base, color: "var(--vz-body-color)" }),
    input: (base: any) => ({ ...base, color: "var(--vz-body-color)" }),
    placeholder: (base: any) => ({ ...base, color: "var(--vz-secondary-color)" }),
    valueContainer: (base: any) => ({ ...base, color: "var(--vz-body-color)" }),
    // Menu flutuante: fundo de cartão + borda de tema.
    menu: (base: any) => ({
        ...base,
        backgroundColor: "var(--vz-card-bg)",
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
    dropdownIndicator: (base: any) => ({ ...base, color: "var(--vz-secondary-color)" }),
    clearIndicator: (base: any) => ({ ...base, color: "var(--vz-secondary-color)" }),
};
