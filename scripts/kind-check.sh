#!/usr/bin/env bash
# Comprueba los manifiestos en un clúster kind real: arranque sin root, rolling update y drain
# sin peticiones perdidas, autoescalado y vuelta a la base. Consume mucha CPU y memoria, y
# corre en CI (job `kubernetes`). Necesita kind, kubectl y la imagen de producción construida.
set -euo pipefail

ns=logistics-kind
cluster=logistics-check
failures=0

ok() { echo "OK    $1"; }
fail() { echo "FALLO $1"; failures=$((failures + 1)); }
k() { kubectl -n "$ns" "$@"; }

cat <<'EOF' | kind create cluster --name "$cluster" --wait 120s --config=-
kind: Cluster
apiVersion: kind.x-k8s.io/v1alpha4
nodes:
  - role: control-plane
  - role: worker
  - role: worker
EOF

kind load docker-image logistics-app-backend:production --name "$cluster"

kubectl apply -f https://github.com/kubernetes-sigs/metrics-server/releases/download/v0.7.2/components.yaml
kubectl -n kube-system patch deployment metrics-server --type=json \
  -p '[{"op":"add","path":"/spec/template/spec/containers/0/args/-","value":"--kubelet-insecure-tls"}]'
kubectl -n kube-system rollout status deployment/metrics-server --timeout=180s

kubectl kustomize k8s/overlays/kind | kubectl apply -f -

k wait --for=condition=complete --timeout=300s job/db-migrate

for workload in proxysql web horizon-high horizon-reports outbox; do
  k rollout status "deployment/$workload" --timeout=300s
done
ok "todas las cargas arrancan y quedan listas"

test "$(k exec deploy/web -- id -u)" = 10001 && ok "web corre sin root" || fail "web corre sin root"

# Producción aplica `restricted`. Solo pueden incumplirlo los sustitutos de prueba.
violations=$(kubectl label --dry-run=server --overwrite namespace "$ns" \
  pod-security.kubernetes.io/enforce=restricted 2>&1 | grep -E 'Warning: .*"' | grep -vE '"(mysql|redis)-' || true)
if [ -z "$violations" ]; then ok "los pods cumplen restricted"; else echo "$violations"; fail "los pods cumplen restricted"; fi

# Sonda continua contra el Service: cuenta peticiones y fallos mientras se opera el clúster.
k run prober --image=curlimages/curl:8.11.1 --restart=Never --command -- sh -c '
  total=0; failed=0
  while [ ! -f /tmp/stop ]; do
    code=$(curl -s -o /dev/null -m 3 -w "%{http_code}" http://web:8000/up || echo 000)
    total=$((total + 1))
    if [ "$code" != 200 ]; then failed=$((failed + 1)); echo "fallo $code"; fi
    sleep 0.2
  done
  echo "total=$total failed=$failed"
  sleep 300'
k wait --for=condition=ready --timeout=120s pod/prober

k rollout restart deployment/web
k rollout status deployment/web --timeout=300s
ok "rolling update completado"

# El drain respeta el PDB: nunca quedan menos de 2 pods de web.
node=$(k get pod -l app=web -o jsonpath='{.items[0].spec.nodeName}')
kubectl drain "$node" --ignore-daemonsets --delete-emptydir-data --timeout=300s --pod-selector='app=web'
kubectl uncordon "$node"
ok "drain de un nodo respetando el PDB"

k exec prober -- touch /tmp/stop
sleep 4
summary=$(k logs prober | tail -1)
echo "$summary"
if echo "$summary" | grep -q 'failed=0'; then ok "sin peticiones perdidas durante el rollout y el drain"; else fail "sin peticiones perdidas durante el rollout y el drain"; fi
k delete pod prober --now

# Autoescalado: carga en `web`, escala por encima de la base y vuelve a ella.
base=$(k get hpa web -o jsonpath='{.spec.minReplicas}')
k create deployment load --image=curlimages/curl:8.11.1 --replicas=4 -- sh -c 'while true; do curl -s -o /dev/null http://web:8000/up; done'

scaled=0
for _ in $(seq 1 40); do
  replicas=$(k get deployment web -o jsonpath='{.status.replicas}')
  if [ "$replicas" -gt "$base" ]; then scaled=$replicas; break; fi
  sleep 15
done
k get hpa web
if [ "$scaled" -gt 0 ]; then ok "HPA escala con carga ($scaled réplicas)"; else fail "HPA escala con carga"; fi

k delete deployment load --now

restored=0
for _ in $(seq 1 60); do
  replicas=$(k get deployment web -o jsonpath='{.spec.replicas}')
  if [ "$replicas" -eq "$base" ]; then restored=1; break; fi
  sleep 15
done
if [ "$restored" -eq 1 ]; then ok "HPA vuelve a la base ($base réplicas)"; else fail "HPA vuelve a la base"; fi

# El scheduler termina: es un Job de minuto, no un proceso permanente.
k create job scheduler-check --from=cronjob/scheduler
if k wait --for=condition=complete --timeout=180s job/scheduler-check; then ok "el CronJob del scheduler termina"; else k logs job/scheduler-check | tail -20; fail "el CronJob del scheduler termina"; fi

# Un SIGTERM cierra el daemon del outbox dentro de su plazo de gracia, y la otra réplica sigue.
pod=$(k get pod -l app=outbox -o jsonpath='{.items[0].metadata.name}')
started=$(date +%s)
k delete pod "$pod" --wait=true
elapsed=$(($(date +%s) - started))
if [ "$elapsed" -lt 30 ]; then ok "el daemon del outbox para con SIGTERM (${elapsed} s)"; else fail "el daemon del outbox para con SIGTERM (${elapsed} s)"; fi

k rollout status deployment/outbox --timeout=120s

if [ "$failures" -ne 0 ]; then
  k get pods -o wide
  echo "$failures comprobaciones fallaron."
  exit 1
fi

echo "Todo correcto."
