FROM python:3.12-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY tunnel_manager.py .

CMD ["python", "-m", "uvicorn", "tunnel_manager:app", "--host", "0.0.0.0", "--port", "8092"]
