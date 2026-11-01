-- Lo aplica `scripts/scale-up.sh` cuando los dos nodos están sanos.
CHANGE REPLICATION SOURCE TO
  SOURCE_HOST='mysql',
  SOURCE_USER='repl',
  SOURCE_PASSWORD='repl-local',
  SOURCE_AUTO_POSITION=1;
START REPLICA;
