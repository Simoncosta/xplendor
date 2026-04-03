FROM python:3.11-slim

WORKDIR /app

# Copy requirements from the real scraper directory (build context = repo root)
COPY scraper/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy placeholder adapter — overridden at runtime by the ./scraper volume mount
COPY docker/scraper/scraper.py .

CMD ["sleep", "infinity"]
