output "cluster_id" {
  value = digitalocean_kubernetes_cluster.main.id
}

output "mysql_primary_host" {
  value     = digitalocean_database_cluster.mysql.private_host
  sensitive = true
}

output "mysql_port" {
  value = digitalocean_database_cluster.mysql.port
}

output "mysql_replica_hosts" {
  value     = digitalocean_database_replica.mysql[*].private_host
  sensitive = true
}

output "valkey_host" {
  value     = digitalocean_database_cluster.valkey.private_host
  sensitive = true
}

output "valkey_port" {
  value = digitalocean_database_cluster.valkey.port
}
