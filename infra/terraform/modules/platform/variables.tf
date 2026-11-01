variable "environment" {
  type = string
}

variable "region" {
  type    = string
  default = "sfo3"
}

variable "kubernetes_version_prefix" {
  type    = string
  default = "1."
}

variable "control_plane_ha" {
  type    = bool
  default = false
}

variable "web_node_size" {
  type = string
}

variable "web_nodes" {
  type = object({ min = number, max = number })
}

variable "worker_node_size" {
  type = string
}

variable "worker_nodes" {
  type = object({ min = number, max = number })
}

variable "mysql_size" {
  type = string
}

variable "mysql_nodes" {
  type = number
}

variable "mysql_read_replicas" {
  type = number
}

variable "valkey_size" {
  type = string
}

# 2 o más: el servicio mantiene un nodo en espera y hace failover con el mismo endpoint.
variable "valkey_nodes" {
  type = number
}

# El registro es de la cuenta, no del entorno: solo lo crea un entorno.
variable "create_registry" {
  type    = bool
  default = false
}
