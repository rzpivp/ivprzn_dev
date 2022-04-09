<?php

header('Content-Type: text/html; charset=utf-8');

$cnfg = include dirname(__FILE__) . '/botconfig.php';
/* токен бота, базовый URL для управления им и идентификатор чата бота с админом */
$bot_access_token = $cnfg['bot_access_token'];
$admin_chat_id = $cnfg['admin_chat_id'];
$bot_api = 'https://api.telegram.org/bot'.$bot_access_token;
$fl_mess = false;


//**************************************************************************
//запрашиваем из БД update_id - идентификатор последнего полученного апдейта
//**************************************************************************
$mysqli = new mysqli($cnfg['db_host'], $cnfg['db_user'], $cnfg['db_pass'], $cnfg['db_base']);
if ($mysqli->connect_errno) {
	exit();
}
$selParamName = 'update_id';
$sql = "SELECT param_val FROM {$cnfg['tlgrm_session']} WHERE `param_name`='{$selParamName}'";
if ( !($result=$mysqli->query($sql)) ) {
	sendMessage($admin_chat_id,'Не удалось выполнить запрос ('.$mysqli->errno.': '.$mysqli->error.') для чтения update_id');
	exit();
}
$row = $result->fetch_assoc();
$update_id = ($row) ? (int)$row['param_val'] : NULL;

$update_id++;

$getUpdates_url = $bot_api.'/getUpdates?offset='.$update_id.'&limit=10';	// формируем адрес для запроса апдейтов (максимум 10 штук)
$answer_source = execRequest($getUpdates_url);		// запрашиваем последние апдейты (получаем массив апдейтов в виде json)
$answer = json_decode($answer_source, TRUE);		// распарсиваем в ассоциативный массив
if($answer){				// если всё нормально получено и распарсено

//**************************************************************************
//вычисляем количество прилетевших апдейтов
//**************************************************************************
	$number_of_updates = 0;			// начальное значение ноль
	$number_of_updates = count($answer['result']);	// количество элементов массива result
	if($number_of_updates==0){		// если новых апдейтов нет
		exit();						// выходим
	}
//**************************************************************************
//перебираем все объекты update (которые также представляют из себя массивы) и
//обрабатываем каждый из них так же, как для случая с вебхуками
//**************************************************************************
	foreach($answer['result'] as $update_value){
		print_r($update_value);
		$mysqli->query("UPDATE IGNORE {$cnfg['tlgrm_session']} SET `param_val`='{$update_value['update_id']}'  WHERE `param_name`='{$selParamName}'");
		if(isset($update_value['message']['from']['id'])){	// если в сообщении есть идентификатор юзера - обрабатываем это сообщение

			//*********************			код обработки апдейта		*****************************

			$chat_id = $update_value['message']['chat']['id']; // выделяем идентификатор чата
		  $message = $update_value['message']['text'];       // выделяем сообщение

		  $user_id = $update_value['message']['from']['id'];  // выделяем идентификатор юзера
		  $fname = $update_value['message']['chat']['first_name']; // выделяем имя собеседника
		  $lname = $update_value['message']['chat']['last_name'];  // выделяем фамилию собеседника
		  $uname = $update_value['message']['chat']['username'];   // выделяем ник собеседника
		  // обрабатываем принятое сообщение для защиты и удобства
		  $message = trim($message);                         // удаляем пробелы
		  $message = htmlspecialchars($message, ENT_QUOTES); // преобразуем спецсимволы (&, ", ', <, >) в html-сущности

			$status = true;
			$result=$mysqli->query("SELECT * FROM {$cnfg['tlgrm_logs']} WHERE `user_id`='{$user_id}'");
			if( ($row = $result->fetch_assoc()) ) {
//				print_r($row);
				$status = $row['status'];
				$fl_mess = $row['fl_mess'];
				$result=$mysqli->query("UPDATE {$cnfg['tlgrm_logs']} SET `lastvisit`='{date('Y-m-d h:i:s')}'");
			}else{
				$result=$mysqli->query("INSERT IGNORE INTO {$cnfg['tlgrm_logs']} (`user_id`,`first_name`,`last_name`,`nick_name`) VALUES('{$user_id}','{$fname}','{$lname}','{$uname}')");
			}

			// Прислали фото.
			if (!empty($update_value['message']['photo'])) {
				$photo = array_pop($update_value['message']['photo']);
				$res = sendTelegram(
					'getFile',
					array(
						'file_id' => $photo['file_id']
					)
				);
				$res = json_decode($res, true);
				if ($res['ok']) {
					$src = 'https://api.telegram.org/file/bot' . $bot_access_token . '/' . $res['result']['file_path'];
					$dest = __DIR__.'/upload/photo/' . time() . '-' . basename($src);

					$rr = copy($src, $dest);
//					$errors= error_get_last();
//			    echo "COPY ERROR: ".$errors['type'];
//			    echo "<br />\n".$errors['message'];
//					echo 'kkkk'.$rr.'hhhhhh'.$dest.'***'.$src;
					if ($rr) {
						sendTelegram(
							'sendMessage',
							array(
								'chat_id' => $update_value['message']['chat']['id'],
								'text' => 'Фото сохранено'
							)
						);
					}
				}
				$typeMess = 'photo';
				$value = $dest;
				$sql = 'SET NAMES utf8;';
				$caption = htmlspecialchars($update_value['message']['caption']);
				$sql .= "INSERT IGNORE INTO {$cnfg['tlgrmlogsdata']} (`user_id`,`type_mess`,`value`, `caption`) VALUES('{$user_id}','{$typeMess}','{$value}','{$caption}');";
				$sql .= "UPDATE {$cnfg['tlgrm_logs']} SET `fl_mess`=false;";
				$mysqli->multi_query($sql);
				continue;
//				$mysqli->close();
//				exit();
			}

			// Прислали файл.
			if (!empty($update_value['message']['document'])) {
				$res = sendTelegram(
					'getFile',
					array(
						'file_id' => $update_value['message']['document']['file_id']
					)
				);

				$res = json_decode($res, true);
				if ($res['ok']) {
					$src = 'https://api.telegram.org/file/bot' . $bot_access_token . '/' . $res['result']['file_path'];
					$dest = __DIR__ . '/upload/document/' . time() . '-' . $update_value['message']['document']['file_name'];

					if (copy($src, $dest)) {
						sendTelegram(
							'sendMessage',
							array(
								'chat_id' => $update_value['message']['chat']['id'],
								'text' => 'Файл сохранён'
							)
						);
					}
				}
				$typeMess = 'document';
				$value = $dest;
				$sql = 'SET NAMES utf8;';
				$caption = htmlspecialchars($update_value['message']['caption']);
				$sql .= "INSERT IGNORE INTO {$cnfg['tlgrmlogsdata']} (`user_id`,`type_mess`,`value`, `caption`) VALUES('{$user_id}','{$typeMess}','{$value}','{$caption}');";
				$sql .= "UPDATE {$cnfg['tlgrm_logs']} SET `fl_mess`=false;";
				$mysqli->multi_query($sql);
				continue;
//				$mysqli->close();
//				exit();
			}
			// начинаем парсить полученное сообщение
		  $command = '';          // команды нет
		  $user_chat_id = '';     // адресат не определён
		  $user_text = 'Валяйте ваше сообщение';        // текст от юзера пустой
		  $admin_text = 'них себе';       // текст сообщения от админа тоже пустой

		  $message_length = strlen($message);   // определяем длину сообщения
		  if($message_length!=0){               // если сообщение не нулевое
	      $fs_pos = strpos($message,' ');   // определяем позицию первого пробела
	      if($fs_pos === false){            // если пробелов нет,
	          $command = $message;          //  то это целиком команда, без текста
	      }else{                             // если пробелы есть,
          // выделяем команду и текст
          $command = substr($message,0,$fs_pos);
          $user_text = substr($message,$fs_pos+1,$message_length-$fs_pos-1);

          $user_text_length = strlen($user_text);    // определяем длину выделенного текста
          // если команда от админа и после неё есть текст - продолжаем парсить
				  if(($chat_id == $admin_chat_id) && (($command === '/send') || ($command === '/ban') || ($command === '/unban')) && ($user_text_length!=0)){
		          // определяем позицию второго пробела
		        $ss_pos = strpos($user_text,' ');
		        if($ss_pos === false){                 // если второго пробела нет
		            $user_chat_id = $user_text;        // то это целиком id чата назначения,
		            $user_text = '';                   // а user_text - пустой
						}else{
				        // если пробелы есть выделяем id чата назначения и текст
		            $user_chat_id = substr($user_text,0,$ss_pos);
		            $admin_text = substr($user_text,$ss_pos+1,$user_text_length-$ss_pos-1);
		        }
					}
				}
		  }

		  // после того, как всё распарсили, - начинаем проверять и выполнять
		  switch($command){
		      case('/start'):
		      case('/help'):
		          sendMessage($chat_id,'Здравствуйте! 😌 Я знаю такие команды:
/start
/help - вывести список поддерживаемых команд
/send <message> - послать <message> админу
/getphoto - фото дня');
		          // если это команда от админа, дописываем что можно только ему
		          if($chat_id == $admin_chat_id){
		              sendMessage($chat_id,'Поскольку вы админ, то можно ещё вот это:
/send <chat_id> <message> - послать <i>message</i> в указанный чат
/ban <user_id> - забанить пользователя с указанным user_id
/unban <user_id> - разбанить пользователя с указанным user_id');
		          }
		      break;
		      case('/send'):    // отсылаем админу id чата юзера и его сообщение
		          if($chat_id == $admin_chat_id){
		              // посылаем текст по назначению (в указанный user_chat)
		              sendMessage($user_chat_id, $admin_text);
		          }
		          else{
								$result=$mysqli->query("UPDATE {$cnfg['tlgrm_logs']} SET `fl_mess`=true");
		            sendMessage($admin_chat_id,$fname.': '.$user_text);
		          }
		      break;
					case('/getphoto'):
						$fName = './upload/photo/devushka'.rand(1,9).'.jpg';
						$postContent = ['chat_id' => $chat_id, 'photo'		=> curl_file_create($fName),];
						sendTelegram('sendPhoto', $postContent);
						$postContent = ['chat_id' => $chat_id, 'text' => 'Вот така гарна дивчина!'];
						sendTelegram('sendMessage', $postContent);
					break;
		      // команда /whoami добавлена чтобы админ мог узнать и записать
		      // id своего чата с ботом, после этого её можно стереть
//		      case('/whoami'):
//		          sendMessage($chat_id,$chat_id);    // отсылаем юзеру id его чата с ботом
//		      break;
		      case('/ban'):
		          if($chat_id == $admin_chat_id){             // если это команда от админа
		              if($user_chat_id != $admin_chat_id){    // если админ не пытается забанить сам себя
											$mysqli->query("UPDATE IGNORE {$cnfg['tlgrm_logs']} SET `status`=false WHERE `user_id`='{$user_chat_id}'");
		                  sendMessage($admin_chat_id,'Запрос на добавление в бан пользователя c user_id = '.$user_chat_id.' выполнен');
		              }
		              else{                                   // если всё же админ пытается забанить сам себя
		                  sendMessage($admin_chat_id,'Никто не имеет права банить админа, даже сам админ!');
		              }
		          }
		          else{
		              sendMessage($chat_id,'неизвестная команда'); // если команда не от админа, то её как бы нет
		          }
		      break;
		      case('/unban'):
		          if($chat_id == $admin_chat_id){             // если это команда от админа
								$mysqli->query("UPDATE IGNORE {$cnfg['tlgrm_logs']} SET `status`=false WHERE `user_id`='{$user_chat_id}'");
		            sendMessage($admin_chat_id,'Запрос на отмену бана пользователя c user_id = '.$user_chat_id.' выполнен');
		          }
		          else{
		              sendMessage($chat_id,'неизвестная команда'); // если команда не от админа, то её как бы нет
		          }
		      break;
		      default:
						if(mb_substr($message,0,1)!=='/'){
							$typeMess = 'txt_message';
							$value = $message;
							$sql = 'SET NAMES utf8;';
							$sql .= "INSERT IGNORE INTO {$cnfg['tlgrmlogsdata']} (`user_id`,`type_mess`,`value`) VALUES('{$user_id}','{$typeMess}','{$value}');";
							$sql .= "UPDATE {$cnfg['tlgrm_logs']} SET `fl_mess`=false;";
							$mysqli->multi_query($sql);
							sendMessage($chat_id,$fname.'! Будет непременно доставлено с вечерним голубем');
						}else{
							sendMessage($chat_id,'неизвестная команда');
						}
		      break;
		  }

		}
	}
}

$mysqli->close();

/* Функция отправки сообщения в чат с использованием метода sendMessage*/
function sendMessage($var_chat_id,$var_message){
    file_get_contents($GLOBALS['bot_api'].'/sendMessage?chat_id='.$var_chat_id.'&text='.urlencode($var_message));
}

function execRequest($telegram_req_url){
	$telegram_ch = curl_init();
	curl_setopt($telegram_ch, CURLOPT_URL, $telegram_req_url);
	curl_setopt($telegram_ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($telegram_ch, CURLOPT_HTTPGET, true);		// необязательно
	curl_setopt($telegram_ch, CURLOPT_SSL_VERIFYPEER, false);	// отменяем проверку сертификатов
	curl_setopt($telegram_ch, CURLOPT_SSL_VERIFYHOST, false);	// (это для тестов, ну а что делать)
	curl_setopt($telegram_ch, CURLOPT_MAXREDIRS, 10);		// необязательно
	curl_setopt($telegram_ch, CURLOPT_CONNECTTIMEOUT, 5);		// необязательно (таймаут попытки подключения)
	curl_setopt($telegram_ch, CURLOPT_TIMEOUT, 20);			// необязательно (таймаут выполнения запроса)
	$telegram_ch_result = curl_exec($telegram_ch);
	curl_close($curl);
	return $telegram_ch_result;
}

function sendTelegram($method, $content) {
	$curl = curl_init($GLOBALS['bot_api'].'/'.$method);
	curl_setopt($curl, CURLOPT_HEADER, false);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
	$fileSendStatus = curl_exec($curl);
	curl_close($curl);
	return $fileSendStatus;
}
/*
 // Отправка файла.
 if (mb_stripos($text, 'файл') !== false) {
	 sendTelegram(
		 'sendDocument',
		 array(
			 'chat_id' => $data['message']['chat']['id'],
			 'document' => curl_file_create(__DIR__ . '/example.xls')
		 )
	 );

	 exit();
 }
*/
/*
help-помощь
send-отправить сообщение админу
getphoto-фото дня
*/
