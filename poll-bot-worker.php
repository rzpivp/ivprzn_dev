<?php

set_time_limit(0);

 $cnfg = include dirname(__FILE__) . '/botconfig.php';

require_once 'PollBot.php';

$bot = new PollBot($cnfg, 'PollBotChat');
$bot->runLongpoll();
