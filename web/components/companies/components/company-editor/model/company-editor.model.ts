import { ICompany } from "@/components/companies/models/companies.model";

//Reuso da interface ICompany
export interface ICompanyEditorProps {
    /** objeto inicial (para editar). Se undefined -> formulário vazio (novo) */
    record?: Partial<ICompany>;

    /** função chamada quando usuário confirmar salvar.
      * Recebe o objeto completo (valores do form).
      */
    onSave?: (payload: ICompany, onCancel: (cancel: boolean) => void) => void;

    /** função chamada quando cancelar. Envia true para indicar cancelamento. */
    onCancel?: (cancel: boolean) => void;

    /** desabilitar inputs (ex.: view mode) */
    disabled?: boolean;
}