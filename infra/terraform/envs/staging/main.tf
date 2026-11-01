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

module "platform" {
  source = "../../modules/platform"

  environment         = "staging"
  web_node_size       = "c-4"
  web_nodes           = { min = 1, max = 2 }
  worker_node_size    = "m-2vcpu-16gb"
  worker_nodes        = { min = 1, max = 2 }
  mysql_size          = "db-s-2vcpu-4gb"
  mysql_nodes         = 1
  mysql_read_replicas = 1
  valkey_size         = "db-s-1vcpu-2gb"
  valkey_nodes        = 1
}

module "edge" {
  source = "../../modules/edge"
  count  = var.origin_ip == null ? 0 : 1

  zone_id         = var.cloudflare_zone_id
  api_host        = "api.staging.logistica.pe"
  app_host        = "app.staging.logistica.pe"
  origin_ip       = var.origin_ip
  frontend_origin = "logistics-staging-frontend.sfo3.digitaloceanspaces.com"
}
