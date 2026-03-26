#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NAMESPACE="postgresdemo"
SQL_FILE="${SCRIPT_DIR}/pagila.sql"

if [ ! -f "$SQL_FILE" ]; then
  echo "ERROR: ${SQL_FILE} not found."
  exit 1
fi

echo "==> Applying Kubernetes resources..."
kubectl apply -f "${SCRIPT_DIR}/postgres-demo.yaml"

echo "==> Waiting for PostgreSQL pod to be ready..."
kubectl wait --for=condition=Available deployment/postgres \
  -n "$NAMESPACE" --timeout=120s

POD=$(kubectl get pod -n "$NAMESPACE" -l app=postgres \
  -o jsonpath='{.items[0].metadata.name}')

echo "==> Waiting for PostgreSQL to accept connections..."
until kubectl exec -n "$NAMESPACE" "$POD" -- \
  pg_isready -U postgresdemo -d pagila -q 2>/dev/null; do
  sleep 2
done

echo "==> Loading Pagila schema and data (this may take a moment)..."
kubectl exec -i -n "$NAMESPACE" "$POD" -- \
  psql -U postgresdemo -d pagila -q < "$SQL_FILE"

echo ""
echo "=== Done ==="
echo ""
echo "Connection string (from within the cluster):"
echo "  postgresql://postgresdemo:postgresdemo@postgres.${NAMESPACE}.svc.cluster.local:5432/pagila"
echo ""
echo "Cleanup:"
echo "  kubectl delete namespace ${NAMESPACE}"
