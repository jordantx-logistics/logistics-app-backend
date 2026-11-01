variable "zone_id" {
  type = string
}

variable "api_host" {
  type = string
}

variable "app_host" {
  type = string
}

# IP del balanceador de DigitalOcean. Solo existe en el estado de Terraform y en Cloudflare:
# ningún registro público apunta a ella sin proxy.
variable "origin_ip" {
  type      = string
  sensitive = true
}

variable "frontend_origin" {
  type = string
}

# Un almacén con muchos PDA sale por una sola IP: el valor de la spec (600) se revisa con
# el tráfico real antes de pasar a producción.
variable "api_requests_per_minute" {
  type    = number
  default = 600
}
