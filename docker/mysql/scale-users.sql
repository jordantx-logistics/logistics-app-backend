-- Usuarios del stack de escala, creados en el primary y replicados. Son credenciales
-- locales de prueba.
CREATE USER IF NOT EXISTS 'repl'@'%' IDENTIFIED WITH mysql_native_password BY 'repl-local';
GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';
CREATE USER IF NOT EXISTS 'logistics_read'@'%' IDENTIFIED WITH mysql_native_password BY 'read-local';
GRANT SELECT ON logistics_app.* TO 'logistics_read'@'%';
CREATE USER IF NOT EXISTS 'proxysql_monitor'@'%' IDENTIFIED WITH mysql_native_password BY 'monitor-local';
GRANT REPLICATION CLIENT ON *.* TO 'proxysql_monitor'@'%';
ALTER USER 'logistics'@'%' IDENTIFIED WITH mysql_native_password BY 'secret';
FLUSH PRIVILEGES;
