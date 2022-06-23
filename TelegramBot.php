<?php

abstract class TelegramBotCore {

  protected $host;
  protected $port;
  public $apiUrl;
  public $apiFileUrl;

  protected $cnfg;

  public    $botId;
  public    $botUsername;
  protected $botToken;

  protected $handle;
  protected $inited = false;

  protected $lpDelay = 1;
  protected $netDelay = 1;

  protected $updatesOffset = false;
  protected $updatesLimit = 30;
  protected $updatesTimeout = 10;

  protected $netTimeout = 10;
  protected $netConnectTimeout = 5;

  public function __construct($cnfg, $options = array()) {
    $options += array(
      'host' => 'api.telegram.org',
      'port' => 443,
    );

    $this->cnfg = $cnfg;
    $this->host = $host = $options['host'];
    $this->port = $port = $options['port'];
    $this->botToken = $cnfg['bot_access_token'];

    $proto_part = ($port == 443 ? 'https' : 'http');
    $port_part = ($port == 443 || $port == 80) ? '' : ':'.$port;

    $this->apiUrl = "{$proto_part}://{$host}{$port_part}/bot{$this->botToken}";
    $this->apiFileUrl = "{$proto_part}://{$host}{$port_part}/file/bot{$this->botToken}";
  }

  public function init() {
    if ($this->inited) {
      return true;
    }

    $this->handle = curl_init();

    $response = $this->request('getMe');
    if (!$response['ok']) {
      throw new Exception("Can't connect to server");
    }

    $bot = $response['result'];
    $this->botId = $bot['id'];
    $this->botUsername = $bot['username'];

    $this->inited = true;
    return true;
  }

  public function runLongpoll() {
    $this->init();

    $this->longpoll();
  }

  public function setWebhook($url) {
    $this->init();
    $result = $this->request('setWebhook', array('url' => $url));
    return $result['ok'];
  }

  public function removeWebhook() {
    $this->init();
    $result = $this->request('setWebhook', array('url' => ''));
    return $result['ok'];
  }

  public function request($method, $params = array(), $options = array()) {
    $options += array(
      'http_method' => 'GET',
      'timeout' => $this->netTimeout,
    );
    $params_arr = array();
    foreach ($params as $key => &$val) {
      if (!is_numeric($val) && !is_string($val)) {
        $val = json_encode($val);
      }
      $params_arr[] = urlencode($key).'='.urlencode($val);
    }
    $query_string = implode('&', $params_arr);

    $url = $this->apiUrl.'/'.$method;

    if ($options['http_method'] === 'POST') {
      curl_setopt($this->handle, CURLOPT_SAFE_UPLOAD, false);
      curl_setopt($this->handle, CURLOPT_POST, true);
      curl_setopt($this->handle, CURLOPT_POSTFIELDS, $query_string);
    } else {
      $url .= ($query_string ? '?'.$query_string : '');
      curl_setopt($this->handle, CURLOPT_HTTPGET, true);
    }

    $connect_timeout = $this->netConnectTimeout;
    $timeout = $options['timeout'] ?: $this->netTimeout;

    curl_setopt($this->handle, CURLOPT_URL, $url);
    curl_setopt($this->handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->handle, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
    curl_setopt($this->handle, CURLOPT_TIMEOUT, $timeout);

    $response_str = curl_exec($this->handle);
    $errno = curl_errno($this->handle);
    $http_code = intval(curl_getinfo($this->handle, CURLINFO_HTTP_CODE));

    if ($http_code == 401) {
      throw new Exception('Invalid access token provided');
    } else if ($http_code >= 500 || $errno) {
      sleep($this->netDelay);
      if ($this->netDelay < 30) {
        $this->netDelay *= 2;
      }
    }

    $response = json_decode($response_str, true);

    return $response;
  }
  public function requestOBJ($method, $params = array()) {
    $curl = curl_init($this->apiUrl.'/'.$method);
  	curl_setopt($curl, CURLOPT_HEADER, false);
  	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
  	curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
  	$fileSendStatus = curl_exec($curl);
  	curl_close($curl);
  	return $fileSendStatus;
  }

  public function longpoll() {
    $params = array(
      'limit' => $this->updatesLimit,
      'timeout' => $this->updatesTimeout,
    );
    if ($this->updatesOffset) {
      $params['offset'] = $this->updatesOffset;
    }
    $options = array(
      'timeout' => $this->netConnectTimeout + $this->updatesTimeout + 2,
    );
    $response = $this->request('getUpdates', $params, $options);
    if ($response['ok']) {
      $updates = $response['result'];
      if (is_array($updates)) {
        foreach ($updates as $update) {
          $this->updatesOffset = $update['update_id'] + 1;
          $method = 'db' . $this->cnfg['db_active'] . 'UpdateOffset';
          $this->$method($update['update_id']);
          $this->onUpdateReceived($update);
        }
      }
    }
//    $this->longpoll();
  }

  protected function dbSQLUpdateOffset($offsetId) {
    $this->mysqli->query("UPDATE IGNORE {$this->cnfg['sessionBot']} SET `param_val`='{$offsetId}'  WHERE `param_name`='update_id'");
  }

  protected function dbRedisUpdateOffset($offsetId) {
    $this->redis->set('tlgr_update_id', $offsetId);
  }

  abstract public function onUpdateReceived($update);

}

class TelegramBot extends TelegramBotCore {

  /*
  */
  protected $chatClass;
  protected $chatInstances = array();

  public function __construct($cnfg, $chat_class, $options = array()) {
    parent::__construct($cnfg, $options);

    $this->cnfg = $cnfg;

    $instance = new $chat_class($this, 0);
    if (!($instance instanceof TelegramBotChat)) {
      throw new Exception('ChatClass must be extends TelegramBotChat');
    }
    $this->chatClass = $chat_class;
  }

  public function onUpdateReceived($update) {
    if ($update['message']) {
      $message = $update['message'];
      $chat_id = intval($message['chat']['id']);
//print_r($message);
      if ($chat_id) {
        $chat = $this->getChatInstance($chat_id);

        if (!empty($message['photo'])) { return $chat->apiMessageObj('photo', $message); }
        if (!empty($message['document'])) { return $chat->apiMessageObj('document', $message); }
        if (!empty($message['audio'])) { return $chat->apiMessageObj('audio', $message); }
        if (!empty($message['video'])) { return $chat->apiMessageObj('video', $message); }

      if (isset($message['group_chat_created'])) {
          $chat->bot_added_to_chat($message);
        } else if (isset($message['new_chat_participant'])) {
          if ($message['new_chat_participant']['id'] == $this->botId) {
            $chat->bot_added_to_chat($message);
          }
        } else if (isset($message['left_chat_participant'])) {
          if ($message['left_chat_participant']['id'] == $this->botId) {
            $chat->bot_kicked_from_chat($message);
          }
        } else {
          $text = trim($message['text']);
          $username = strtolower('@'.$this->botUsername);
          $username_len = strlen($username);
          if (strtolower(substr($text, 0, $username_len)) == $username) {
            $text = trim(substr($text, $username_len));
          }
          if (preg_match('/^(?:\/([a-z0-9_]+)(@[a-zа-я0-9_]+)?(?:\s+(.*))?)$/is', $text, $matches)) {
            $command = $matches[1];
            $command_owner = (isset($matches[2])) ? strtolower($matches[2]) : '';
            $command_params = (isset($matches[3])) ? $matches[3] : '';
            if (!$command_owner || $command_owner == $username) {
              $method = 'command_'.$command;
              if (method_exists($chat, $method)) {
                $chat->$method($command_params, $message);
              } else {
                $chat->some_command($command, $command_params, $message);
              }
            }
          } else {
            $chat->message($text, $message);
          }
        }
      }
    }
  }

  protected function getChatInstance($chat_id) {
    if (!isset($this->chatInstances[$chat_id])) {
      $instance = new $this->chatClass($this, $chat_id);
      $this->chatInstances[$chat_id] = $instance;
      $instance->init();
    }
    return $this->chatInstances[$chat_id];
  }

}



abstract class TelegramBotChat {

  protected $core;
  protected $chatId;
  protected $isGroup;
  protected $cnfg;

  public function __construct($core, $chat_id) {
    if (!($core instanceof TelegramBot)) {
      throw new Exception('$core must be TelegramBot instance');
    }
    $this->core = $core;
    $this->chatId = $chat_id;
    $this->isGroup = $chat_id < 0;
    $this->cnfg = $GLOBALS['cnfg'];
  }

  public function init() {}

  public function bot_added_to_chat($message) {}
  public function bot_kicked_from_chat($message) {}
//public function command_commandname($params, $message) {}
  public function some_command($command, $params, $message) {}
  public function message($text, $message) {}

  protected function apiSendMessage($text, $params = array()) {
    $params += array(
      'chat_id' => $this->cnfg['move_no_tlgrchat'],
      'text' => $text,
    );
    return $this->core->request('sendMessage', $params);
  }

  protected function apiSendObj($method, $params, $text='') {
    $ret =  $this->core->requestOBJ($method, $params);
    if($text) {
      $ret = $this->apiSendMessage($text);
    }
    return $ret;
  }

/*
*   Функция сбора и пересылки файлов в сонфиг['move_no_tlgrchat'] как
*   инфа сохраняется в БД Redis или MySQL, как определено в сонфиг['db_active']
*/
  public function apiMessageObj($method, $message) {
//    print_r($message);
    switch($method) {
      case('audio'):
      case('video'):
      case('document'):
        $obj = $message[$method];
        break;
      default:
        $obj = array_pop($message[$method]);
    }
    $res = $this->core->requestOBJ(
      'getFile',
      [ 'file_id' => $obj['file_id'] ],
    );
    $res = json_decode($res, true);
    $ret = false;
    if ($res['ok']) {
      $src = $this->core->apiFileUrl . '/' . $res['result']['file_path'];
      $dest = __DIR__.'/upload/' . $method . '/' . time() . '-' . basename($src);
      $ret = copy($src, $dest);
      if ($ret) {
        $caption = (isset($message['caption'])) ? htmlspecialchars($message['caption']) : '';
        $params = [
          'chat_id' => $this->cnfg['move_no_tlgrchat'],
          $method   => curl_file_create($dest),
          'caption' => $caption,
        ];
        $this->apiSendObj('send'.ucfirst($method), $params, $text='Спасибо. Информация сохранена');

        $dbData = [
          'chat_id'      => $this->chatId,
          'first_name'   => $message['from']['first_name'],
          'last_name'    => $message['from']['last_name'],
          'username'     => $message['from']['username'],
          'obj_type'     => $method,
          'obj_name'     => $dest,
//          'date_mess'    => time(), // default DB
          'text'         => $caption,
          'status'       => 1,
        ];
        $method = 'write' . $this->cnfg['db_active'] . 'Data';
        $this->$method($dbData);
      }
    }
    return $ret;
  }

  protected function writeSQLData($data) {
    $sql = 'SET NAMES utf8;';
    $sql .= "INSERT IGNORE INTO {$this->cnfg['user_chatBot']} (`chat_id`,`status`) VALUES('{$data['chat_id']}',{true});";
    $fields = $values = '';
    foreach($data as $field=>$value) {
      $fields .= "`" . $field . "`,";
      $values .= "'" . $value . "',";
    }
    $fields = rtrim($fields, ",");
    $values = rtrim($values, ",");
    $sql .= "INSERT INTO {$this->cnfg['msgDataBot']} ({$fields}) VALUES({$values});";
    $sql .= "UPDATE {$this->cnfg['tlgrm_logs']} SET `fl_mess`=false;";
    $this->core->mysqli->multi_query($sql);
  }

  protected function writeRedisData($data) {
    $key = 'msg:' . $data['chat_id'] . ':' . time();
    $this->core->redis->set($key, json_encode($data), ['nx', 'ex'=>3600*24*10]);
//    print_r($this->core->redis->keys('*'));
  }

} //TelegramBotChat
