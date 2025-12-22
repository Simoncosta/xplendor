import { IUsers } from "@/components/users/models/user.model";

//Reuso da interface IUser
export interface IUserEditorProps {
  /** objeto inicial (para editar). Se undefined -> formulário vazio (novo) */
  record?: Partial<IUsers>;

  /** função chamada quando usuário confirmar salvar.
    * Recebe o objeto completo (valores do form).
    */
  onSave?: (payload: IUsers, onCancel: (cancel: boolean) => void) => void;

  /** função chamada quando cancelar. Envia true para indicar cancelamento. */
  onCancel?: (cancel: boolean) => void;

  /** desabilitar inputs (ex.: view mode) */
  disabled?: boolean;
}