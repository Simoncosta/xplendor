import { ICar } from "@/components/cars/models/cars.model";

//Reuso da interface ICar
export interface ICarEditorProps {
    /** objeto inicial (para editar). Se undefined -> formulário vazio (novo) */
    record?: Partial<ICar>;

    /** função chamada quando usuário confirmar salvar.
      * Recebe o objeto completo (valores do form).
      */
    onSave?: (payload: ICar, onCancel: (cancel: boolean) => void) => void;

    /** função chamada quando cancelar. Envia true para indicar cancelamento. */
    onCancel?: (cancel: boolean) => void;

    /** desabilitar inputs (ex.: view mode) */
    disabled?: boolean;
}