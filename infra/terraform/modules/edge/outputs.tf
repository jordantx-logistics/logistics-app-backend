# Valor de la anotación `service.beta.kubernetes.io/do-loadbalancer-allow-rules` del Service
# de ingress-nginx. Es lo que oculta el origen: el balanceador lo crea Kubernetes.
output "load_balancer_allow_rules" {
  value = join(",", [for cidr in data.cloudflare_ip_ranges.current.ipv4_cidr_blocks : "cidr:${cidr}"])
}
