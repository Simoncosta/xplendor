FROM python:3.11-slim

WORKDIR /app

# poppler-utils: necessário ao pdf2image para rasterizar PDF-scan de faturas (OCR Fase A).
RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils \
    && rm -rf /var/lib/apt/lists/*

# Copy requirements from the real scraper directory (build context = repo root)
COPY scraper/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy placeholder adapter — overridden at runtime by the ./scraper volume mount
COPY docker/scraper/scraper.py .

CMD ["sleep", "infinity"]
