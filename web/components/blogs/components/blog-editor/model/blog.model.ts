import { IBlogs } from "@/components/blogs/models/blog.model";

//Reuso da interface IUser
export interface IBlogEditorProps {
  /** objeto inicial (para editar). Se undefined -> formulário vazio (novo) */
  record?: Partial<IBlogs>;

  /** função chamada quando usuário confirmar salvar.
    * Recebe o objeto completo (valores do form).
    */
  onSave?: (payload: IBlogs, onCancel: (cancel: boolean) => void) => void;

  /** função chamada quando cancelar. Envia true para indicar cancelamento. */
  onCancel?: (cancel: boolean) => void;

  /** desabilitar inputs (ex.: view mode) */
  disabled?: boolean;
}