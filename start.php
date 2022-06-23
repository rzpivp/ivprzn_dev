<?php

/*
сделать рассылку запомненных адресов
*/

ini_set('default_charset', 'utf-8');
set_time_limit(0);

  $cnfg = include dirname(__FILE__) . '/botconfig.php';

 require_once 'PollBot.php';

 $bot = new PollBot($cnfg, 'PollBotChat');
 $bot->init();
 while($bot->redis->get('botExitFlag')) {
   $bot->longpoll();
 }
echo 'выход по флагу';
exit(0);
