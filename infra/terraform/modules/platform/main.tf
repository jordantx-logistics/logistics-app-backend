# Clúster, bases de datos y almacenamiento de un entorno (ADR-0007).

terraform {
  required_providers {
    digitalocean = {
      source  = "digitalocean/digitalocean"
      version = "~> 2.0"
    }
  }
}

resource "digitalocean_vpc" "main" {
  name   = "logistics-${var.environment}"
  region = var.region
}

data "digitalocean_kubernetes_versions" "current" {
  version_prefix = var.kubernetes_version_prefix
}

resource "digitalocean_kubernetes_cluster" "main" {
  name         = "logistics-${var.environment}"
  region       = var.region
  version      = data.digitalocean_kubernetes_versions.current.latest_version
  vpc_uuid     = digitalocean_vpc.main.id
  auto_upgrade = false
  ha           = var.control_plane_ha

  node_pool {
    name       = "web"
    size       = var.web_node_size
    auto_scale = true
    min_nodes  = var.web_nodes.min
    max_nodes  = var.web_nodes.max
    labels     = { pool = "web" }
  }
}

resource "digitalocean_kubernetes_node_pool" "workers" {
  cluster_id = digitalocean_kubernetes_cluster.main.id
  name       = "workers"
  size       = var.worker_node_size
  auto_scale = true
  min_nodes  = var.worker_nodes.min
  max_nodes  = var.worker_nodes.max
  labels     = { pool = "workers" }
}

resource "digitalocean_database_cluster" "mysql" {
  name                 = "logistics-${var.environment}-mysql"
  engine               = "mysql"
  version              = "8"
  size                 = var.mysql_size
  region               = var.region
  node_count           = var.mysql_nodes
  private_network_uuid = digitalocean_vpc.main.id
}

resource "digitalocean_database_replica" "mysql" {
  count      = var.mysql_read_replicas
  cluster_id = digitalocean_database_cluster.mysql.id
  name       = "logistics-${var.environment}-replica-${count.index + 1}"
  size       = var.mysql_size
  region     = var.region
}

resource "digitalocean_database_db" "app" {
  cluster_id = digitalocean_database_cluster.mysql.id
  name       = "logistics_app"
}

# ProxySQL autentica contra MySQL con contraseña nativa.
resource "digitalocean_database_user" "app" {
  cluster_id        = digitalocean_database_cluster.mysql.id
  name              = "logistics"
  mysql_auth_plugin = "mysql_native_password"
}

resource "digitalocean_database_user" "read" {
  cluster_id        = digitalocean_database_cluster.mysql.id
  name              = "logistics_read"
  mysql_auth_plugin = "mysql_native_password"
}

resource "digitalocean_database_firewall" "mysql" {
  cluster_id = digitalocean_database_cluster.mysql.id

  rule {
    type  = "k8s"
    value = digitalocean_kubernetes_cluster.main.id
  }
}

# DigitalOcean retiró Managed Redis: su servicio compatible es Managed Valkey, y la app
# se conecta igual, con el protocolo de Redis.
resource "digitalocean_database_cluster" "valkey" {
  name                 = "logistics-${var.environment}-valkey"
  engine               = "valkey"
  version              = "8"
  size                 = var.valkey_size
  region               = var.region
  node_count           = var.valkey_nodes
  private_network_uuid = digitalocean_vpc.main.id
}

resource "digitalocean_database_firewall" "valkey" {
  cluster_id = digitalocean_database_cluster.valkey.id

  rule {
    type  = "k8s"
    value = digitalocean_kubernetes_cluster.main.id
  }
}

resource "digitalocean_spaces_bucket" "app" {
  name   = "logistics-${var.environment}-app"
  region = var.region
  acl    = "private"
}

resource "digitalocean_spaces_bucket" "frontend" {
  name   = "logistics-${var.environment}-frontend"
  region = var.region
  acl    = "public-read"
}

resource "digitalocean_container_registry" "main" {
  count                  = var.create_registry ? 1 : 0
  name                   = "logistics"
  subscription_tier_slug = "basic"
  region                 = var.region
}
