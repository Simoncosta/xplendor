import time
import logging
from typing import Optional

import requests

from config import config
from normalizer import NormalizedSnapshot

logger = logging.getLogger(__name__)


class LaravelSender:

    def __init__(self):
        self.base_url = config.laravel_api_url.rstrip("/")
        self.endpoint = config.laravel_endpoint
        self.headers = {
            "Authorization": f"Bearer {config.laravel_api_token}",
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-Scraper-Source": "standvirtual",
        }
        self.batch_size = config.batch_size
        self._pending: list[NormalizedSnapshot] = []
        self._total_sent = 0
        self._total_failed = 0

    # ------------------------------------------------------------------
    # Interface pública
    # ------------------------------------------------------------------

    def add(self, snapshot: NormalizedSnapshot):
        """Adiciona ao buffer e envia quando atingir batch_size."""
        self._pending.append(snapshot)
        if len(self._pending) >= self.batch_size:
            self.flush()

    def flush(self):
        """Envia tudo o que está no buffer imediatamente."""
        if not self._pending:
            return

        batch = self._pending[:]
        self._pending.clear()

        success = self._send_batch(batch)
        if success:
            self._total_sent += len(batch)
            logger.info(f"Batch enviado: {len(batch)} snapshots. Total: {self._total_sent}")
        else:
            self._total_failed += len(batch)
            logger.error(f"Falhou batch de {len(batch)} snapshots. Total falhas: {self._total_failed}")

    def stats(self) -> dict:
        return {
            "total_sent": self._total_sent,
            "total_failed": self._total_failed,
            "pending": len(self._pending),
        }

    # ------------------------------------------------------------------
    # Envio HTTP
    # ------------------------------------------------------------------

    def _send_batch(self, snapshots: list[NormalizedSnapshot], attempt: int = 0) -> bool:
        url = f"{self.base_url}{self.endpoint}"
        payload = {
            "snapshots": [s.to_dict() for s in snapshots],
        }

        try:
            response = requests.post(
                url,
                json=payload,
                headers=self.headers,
                timeout=30,
            )
            response.raise_for_status()
            return True

        except requests.exceptions.HTTPError as e:
            status = e.response.status_code if e.response is not None else None
            body = e.response.text[:1000] if e.response is not None else ""
            logger.error(f"HTTP {status}: {body}")

            if status == 422:
                # Erro de validação — não vale a pena retentár
                logger.error(f"Validação falhou (422): {body}")
                return False
            elif status == 401:
                logger.error("Token inválido ou sem autorização (401). Verificar LARAVEL_API_TOKEN.")
                return False
            elif status == 429:
                wait = (attempt + 1) * 10
                logger.warning(f"Rate limited pelo Laravel (429). Aguardar {wait}s...")
                time.sleep(wait)
            else:
                logger.error(f"HTTP {status}: {body}")

            if attempt < 2:
                time.sleep(5)
                return self._send_batch(snapshots, attempt + 1)
            return False

        except requests.exceptions.ConnectionError:
            logger.error(f"Não foi possível ligar ao Laravel em {url}. Verificar LARAVEL_API_URL.")
            if attempt < 2:
                time.sleep(10)
                return self._send_batch(snapshots, attempt + 1)
            return False

        except requests.exceptions.RequestException as e:
            logger.error(f"Erro de rede ao enviar batch: {e}")
            return False
