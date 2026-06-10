"""Shim de retro-compatibilidade (MS2.b, 2026-06-10).

A lógica do Standvirtual moveu-se para `sources/standvirtual.py` (e a interface
comum para `sources/base.py`). Este ficheiro existe apenas para que imports
antigos continuem a funcionar:

    from scraper import StandvirtualScraper    # → sources.standvirtual
    from scraper import RawListing             # → sources.base

Não adicionar lógica nova aqui — vai para o adapter respectivo.
"""
from sources.base import RawListing
from sources.standvirtual import StandvirtualAdapter, StandvirtualScraper

__all__ = ["RawListing", "StandvirtualAdapter", "StandvirtualScraper"]
