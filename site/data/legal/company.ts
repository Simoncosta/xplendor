/**
 * Dados da entidade responsável, usados nas páginas legais (PT e EN) e no rodapé.
 * Fonte única: server/config/legal-company.json, partilhado com o servidor (PDF dos
 * orçamentos). Corrigir lá chega aos dois. O site é compilado no repositório, onde
 * a pasta server/ existe.
 */
import legal from "../../../server/config/legal-company.json";

export const COMPANY = {
  legalName: legal.legalName,
  brandOwnerName: legal.brandOwnerName,
  brand: legal.brand,
  nif: legal.nif,
  address: legal.address,
  // Email provisório até existir o email da empresa (também usado como mailto no rodapé).
  email: legal.email,
  phone: legal.phone,
};
