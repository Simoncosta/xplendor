"""
XPLENDOR — Auditoria v2 (PASSO 0): escrita das UNIDADES DE UM ARTIGO no PingWin.

Cada chamada faz UMA alteração, sempre pela mesma ordem:
  1. LER o artigo (read_product): o código (para a guarda), a unidade base e a de compra;
  2. GUARDA: o código tem de estar na lista autorizada ("0" = nenhum; vazio = todos);
  3. GRAVAR uma só coisa:
     · create        → reutilizar ou criar a unidade global certa (grelha das unidades: OPEN → NEW →
                       GET,INFO → EDIT,SAVE da lista exata → GET,INFO), sempre filha da unidade base;
     · edit_factor   → o fator (parent_qnt) de uma unidade DO ARTIGO (EDIT,SAVE da grelha);
     · void          → anular uma unidade DO ARTIGO (deleted = 1);
     · set_purchase  → a unidade de compra por defeito (MERGE do artigo, só default_purchase_unit_id);
  4. RELER o artigo e a grelha, e dizer o estado final.

Nunca:
  · muda a unidade base do artigo (recusa qualquer pedido com base_unit_id);
  · anula nem altera uma unidade GLOBAL (product_id vazio), que é de todos os artigos, nem as que a
    XPLENDOR criou (o PingWin guarda-as todas como globais): no artigo, a antiga deixa de se usar;
  · cria uma unidade que já existe (mesma base e quantidade: reutiliza-a) nem um nome repetido;
  · usa o unit_value do PingWin como verdade (o fator escreve-se, não se lê para custos).
"""
from __future__ import annotations

from typing import Any, Callable, Dict, List

OPS = ("create", "edit_factor", "void", "set_purchase")


class UnitWriteRefused(RuntimeError):
    """A escrita foi recusada ANTES de tocar no PingWin (guarda, unidade global, base)."""


def _guard(code: str, allowed_codes: List[str]) -> None:
    allowed = [str(a).strip() for a in (allowed_codes or []) if str(a).strip() != ""]
    if allowed == ["0"]:
        raise UnitWriteRefused("Escrita de unidades desligada neste ambiente (PINGWIN_UNIT_WRITE_ALLOWLIST=0).")
    if allowed and code not in allowed:
        raise UnitWriteRefused(f"Escrita recusada: o artigo {code} não está na lista autorizada ({', '.join(allowed)}).")


def _snapshot(product: Dict[str, Any], units: List[Dict[str, Any]], product_id: str) -> Dict[str, Any]:
    """O estado do artigo que interessa à releitura: unidades por defeito e as unidades dele."""
    main = product.get("maindataset") or {}
    own = [u for u in units if str(u.get("product_id") or "") == product_id]
    return {
        "code": str(main.get("code") or ""),
        "description": main.get("description"),
        "base_unit_id": str(main.get("base_unit_id") or ""),
        "default_purchase_unit_id": str(main.get("default_purchase_unit_id") or ""),
        "default_stock_unit_id": str(main.get("default_stock_unit_id") or ""),
        "default_sale_unit_id": str(main.get("default_sale_unit_id") or ""),
        "own_units": [{"id": str(u.get("id")), "description": u.get("description"), "parent_id": str(u.get("parent_id") or ""),
                       "parent_qnt": u.get("parent_qnt"), "deleted": bool(u.get("deleted"))} for u in own],
    }


def _unit(units: List[Dict[str, Any]], unit_id: str) -> Dict[str, Any] | None:
    return next((u for u in units if str(u.get("id")) == str(unit_id)), None)


def write_article_unit(client: Any, product_id: str, op: str, data: Dict[str, Any], allowed_codes: List[str],
                       owned_unit_ids: List[str] | None = None,  # ignorado desde a v2.1 (nunca se mexe em globais)
                       progress: Callable[..., None] | None = None) -> Dict[str, Any]:
    """Uma alteração às unidades de um artigo, com leitura antes e releitura depois."""
    say = progress or (lambda **kw: None)
    product_id = str(product_id or "")
    if op not in OPS:
        raise UnitWriteRefused(f"Operação desconhecida: {op}.")
    if not product_id:
        raise UnitWriteRefused("Falta o id do artigo.")
    if "base_unit_id" in (data or {}):
        raise UnitWriteRefused("A XPLENDOR não muda a unidade base de um artigo.")

    say(step="ler", status="running")
    product = client.read_product(product_id)
    if not product.get("found"):
        raise UnitWriteRefused(f"O artigo {product_id} não existe no PingWin.")
    units = client.fetch_units()
    before = _snapshot(product, units, product_id)
    _guard(before["code"], allowed_codes)
    say(step="ler", status="ok")

    say(step="gravar", status="running")
    written: Dict[str, Any] = {}
    if op == "create":
        description = str(data.get("description") or "").strip()
        factor = data.get("parent_qnt")
        if not description or factor in (None, "") or float(factor) <= 0:
            raise UnitWriteRefused("Falta o nome ou o fator da unidade nova.")
        # Sempre filha da unidade base do artigo: o fator é "quantas unidades base leva".
        # Regra 1 (Auditoria v2.1): as unidades são GLOBAIS. Antes de criar, procura na grelha em direto
        # uma unidade ativa com a mesma base e a mesma quantidade: se existir, reutiliza-a (não grava).
        same = _reusable(units, before["base_unit_id"], float(factor))
        if same is not None:
            say(step="gravar", status="ok")
            return _finish(client, product_id, op, data, before, {"id": str(same.get("id")), "reused": True},
                           progress=say, reused=same)
        if _name_taken(units, description):
            raise UnitWriteRefused(f"Já existe uma unidade chamada «{description}» com outra quantidade: a XPLENDOR não cria duplicados.")
        row = {"description": description, "shortname": str(data.get("shortname") or description)[:10],
               "parent_id": before["base_unit_id"], "parent_qnt": str(factor)}
        written = client.create_unit(row)
    else:
        unit_id = str(data.get("unit_id") or "")
        if not unit_id:
            raise UnitWriteRefused("Falta a unidade.")
        if op in ("edit_factor", "void"):
            target = _unit(units, unit_id)
            if target is None:
                raise UnitWriteRefused(f"A unidade {unit_id} não existe no PingWin.")
            # Regra 2 (Auditoria v2.1): nunca se anula nem se altera uma unidade global (de todos os artigos),
            # nem a que a XPLENDOR criou: no artigo, a antiga deixa de se usar.
            if str(target.get("product_id") or "") == "":
                raise UnitWriteRefused("Essa unidade é global (de todos os artigos): a XPLENDOR nunca a anula nem a altera. "
                                       "Ponha a unidade certa como unidade de compra e deixe de usar esta.")
            if str(target.get("product_id") or "") != product_id:
                raise UnitWriteRefused("Essa unidade é de outro artigo.")
            if op == "edit_factor" and not data.get("try_anyway"):
                # PASSO 0 (18/10, TESTE A): o PingWin recusa ("Quantidade não pode ser alterada."), mesmo
                # numa unidade acabada de criar. Corrige-se com uma unidade nova + compra + anular.
                raise UnitWriteRefused("O PingWin não deixa mudar o fator de uma unidade: crie uma unidade nova com o fator certo, "
                                       "ponha-a como unidade de compra e anule a antiga.")
            if op == "edit_factor":
                factor = data.get("parent_qnt")
                if factor in (None, "") or float(factor) <= 0:
                    raise UnitWriteRefused("O fator tem de ser maior que zero.")
                # Só o parent_qnt: o unit_value é o PingWin que o deriva.
                written = client.save_unit({"id": unit_id, "parent_qnt": str(factor), "deleted": 0})
            else:
                if before["default_purchase_unit_id"] == unit_id or before["default_stock_unit_id"] == unit_id:
                    raise UnitWriteRefused("Essa unidade está em uso como unidade por defeito do artigo: mude-a antes de a anular.")
                written = client.save_unit({"id": unit_id, "deleted": 1})
        else:  # set_purchase
            if not _unit(units, unit_id) and unit_id != before["base_unit_id"]:
                raise UnitWriteRefused(f"A unidade {unit_id} não existe no PingWin.")
            res = client.update_product(product_id, {"default_purchase_unit_id": unit_id})
            written = {"confirm": res.get("confirm")}
    say(step="gravar", status="ok")
    return _finish(client, product_id, op, data, before, written, progress=say)


def _finish(client: Any, product_id: str, op: str, data: Dict[str, Any], before: Dict[str, Any], written: Dict[str, Any],
            progress: Callable[..., None], reused: Dict[str, Any] | None = None) -> Dict[str, Any]:
    """A releitura: o artigo e a grelha outra vez, e o estado final."""
    progress(step="confirmar", status="running")
    after_product = client.read_product(product_id)
    after_units = client.fetch_units()
    after = _snapshot(after_product, after_units, product_id)
    if reused is not None:
        ok, why = True, f"Reutilizada a unidade «{reused.get('description')}» (já existia com esta quantidade)."
    else:
        ok, why = _verify(op, product_id, data, before, after, after_units, written)
    progress(step="confirmar", status="ok" if ok else "mismatch")
    return {"ok": ok, "op": op, "product_id": product_id, "before": before, "after": after, "written": written,
            "reused": reused is not None, "unit": _unit(after_units, str(written.get("id") or data.get("unit_id") or "")), "note": why}


def _qty(u: Dict[str, Any]) -> float:
    try:
        return float(u.get("parent_qnt") or u.get("unit_value") or 0)
    except (TypeError, ValueError):
        return 0.0


def _reusable(units: List[Dict[str, Any]], base_id: str, factor: float) -> Dict[str, Any] | None:
    """Uma unidade global ativa, filha da mesma base, com a mesma quantidade."""
    for u in units:
        if (not u.get("deleted") and str(u.get("product_id") or "") == "" and str(u.get("parent_id") or "") == str(base_id)
                and abs(_qty(u) - factor) <= 1e-6 * max(1.0, factor)):
            return u
    return None


def _name_taken(units: List[Dict[str, Any]], name: str) -> bool:
    key = " ".join(name.lower().split())
    return any(" ".join(str(u.get("description") or "").lower().split()) == key for u in units)


def _verify(op: str, product_id: str, data: Dict[str, Any], before: Dict[str, Any], after: Dict[str, Any],
            units: List[Dict[str, Any]], written: Dict[str, Any]) -> tuple[bool, str]:
    """A releitura tem de mostrar a alteração, e só ela (a unidade base nunca muda)."""
    if after["base_unit_id"] != before["base_unit_id"]:
        return False, "A unidade base mudou: não devia."
    if op == "create":
        u = _unit(units, str(written.get("id") or ""))
        if u is None:
            return False, "A unidade nova não aparece na releitura."
        return True, "Criada (unidade global, de todos os artigos)."
    if op == "edit_factor":
        u = _unit(units, str(data.get("unit_id")))
        got = float((u or {}).get("parent_qnt") or (u or {}).get("unit_value") or 0)
        return (u is not None and abs(got - float(data["parent_qnt"])) < 1e-6), f"Fator relido: {got:g}."
    if op == "void":
        u = _unit(units, str(data.get("unit_id")))
        return (u is not None and bool(u.get("deleted"))), "Anulada." if u and u.get("deleted") else "Continua ativa."
    got = after["default_purchase_unit_id"]
    return got == str(data.get("unit_id")), f"Unidade de compra relida: {got}."
