<?php

$REDIS_HOST = '127.0.0.1';
$REDIS_PORT = 6379;

$redis = new Redis();
$redis_connected = $redis->connect($REDIS_HOST, $REDIS_PORT);
if ($redis_connected) {
  $redis->set('botExitFlag', 0);
}else{
//  echo '******' . $redis->set('botExitFlag', 0) . '******';
  throw new Exception("Redis not connected");
}
