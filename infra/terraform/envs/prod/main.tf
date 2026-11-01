terraform {
  required_version = ">= 1.6"

  required_providers {
    digitalocean = {
      source  = "digitalocean/digitalocean"
      version = "~> 2.0"
    }
    cloudflare = {
      source  = "cloudflare/cloudflare"
      version = "~> 4.0"
    }
  }

  # El estado guarda secretos: va cifrado en Spaces, nunca en el repositorio.
  backend "s3" {}
}

# Credenciales por entorno: DIGITALOCEAN_TOKEN, SPACES_ACCESS_KEY_ID,
# SPACES_SECRET_ACCESS_KEY y CLOUDFLARE_API_TOKEN.
provider "digitalocean" {}

provider "cloudflare" {}

variable "cloudflare_zone_id" {
  type = string
}

# El balanceador lo crea el Ingress: la zona se aplica en un segundo paso con su IP.
variable "origin_ip" {
  type      = string
  sensitive = true
  default   = null
}

# Los tamaños son el punto de partida. Sin una medición en staging no están validados.
module "platform" {
  source = "../../modules/platform"

  environment         = "prod"
  control_plane_ha    = true
  create_registry     = true
  web_node_size       = "c-8"
  web_nodes           = { min = 3, max = 10 }
  worker_node_size    = "m-4vcpu-32gb"
  worker_nodes        = { min = 2, max = 6 }
  mysql_size          = "db-s-8vcpu-32gb"
  mysql_nodes         = 2
  mysql_read_replicas = 2
  valkey_size         = "db-s-4vcpu-8gb"
  valkey_nodes        = 2
}

module "edge" {
  source = "../../modules/edge"
  count  = var.origin_ip == null ? 0 : 1

  zone_id         = var.cloudflare_zone_id
  api_host        = "api.logistica.pe"
  app_host        = "app.logistica.pe"
  origin_ip       = var.origin_ip
  frontend_origin = "logistics-prod-frontend.sfo3.digitaloceanspaces.com"
}
