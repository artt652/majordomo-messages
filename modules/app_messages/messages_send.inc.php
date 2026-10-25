<?php
/*
 * Сообщение на терминал (или несколько) — из сценариев и из настроек «Сообщений».
 *
 *   include_once(DIR_MODULES . 'app_messages/app_messages.class.php');
 *   messagesSend('Кухня', 'Ужин готов', array(
 *       'from'  => 'Гостиная',                  // от кого: терминал (имя или название) или любой текст
 *       'from_user' => 1,                       // от кого: пользователь MajorDoMo (ID) — вместо from
 *       'from_note' => 'из панели управления',  // пометка к отправителю: на устройстве и в переписке — «Admin (…)»
 *       'image' => 'cms/cached/snapshot.jpg',   // картинка: путь в веб-корне MajorDoMo или http-ссылка
 *       'sound' => 'cms/sounds/dingdong.mp3',   // звук перед сообщением: путь в веб-корне или http-ссылка
 *       'file'  => 'cms/cached/terminals/x.pdf', // файл для страницы «Сообщения» (скачать); на устройство — «Файл: имя»
 *       'file_name' => 'Счёт.pdf',              // имя файла для показа и скачивания
 *       'video' => 'cms/cached/terminals/x.mp4', // видео: на странице «Сообщения» — плеер, на устройстве —
 *                                               // окно видео, если у дополнения есть sendVideo() (pipup), иначе «Видео»
 *       'sound_duration' => 12.4,               // длительность звука / видео в секундах, если известна заранее
 *       'video_duration' => 30,                 // (иначе — из файла: messagesMediaDuration())
 *       'voice' => true,                        // озвучить текст генератором речи (по умолчанию да)
 *       'level' => 5,                           // уровень; по умолчанию — порог терминала-получателя
 *       'chat'  => 'room:3',                    // чат на странице «Сообщения»: all, room:ID, group:ID (пусто — личный)
 *       'chat_title' => 'Кухня',                // название чата — добавляется к отправителю в заголовке окна
 *       'forwarded' => 'Кухня',                 // пересланное сообщение: от кого оно было (на странице — «Переслано от …»)
 *       'shout' => true,                        // записать копию в общий чат MajorDoMo (shouts)
 *       'stream' => 'door',                     // камера: имя потока go2rtc (messages_go2rtc.inc.php) — устройства
 *                                               // показывают поток, в переписку — снимок; без видео — снимок с текстом
 *       'stream_duration' => 60,                // сколько секунд показывать поток (по умолчанию 60)
 *       'snapshot' => false,                    // без снимка потока (по умолчанию — снимок в сообщение)
 *       'history' => false,                     // только на устройства, без записи в переписку
 *       'link' => 'id',                         // временный доступ к камере (messagesCamLinkCreate): на странице —
 *                                               // карточка ссылки (открыть, закрыть, срок)
 *   ));
 *
 * Куда: имя, название или адрес терминала или пользователь 'user:ID' (только в переписку); можно массив.
 * Текст с озвучкой идёт как sayTo(): событие SAYTO, генераторы речи делают голос для получателя.
 * Без озвучки (voice => false) — только текст, сразу в очередь получателя.
 * Картинку и отправителя показывают дополнения с showMessage() (pipup: картинка в окне,
 * отправитель в заголовке), звук играют дополнения с playFile() (mediaplayer, pipup).
 * Остальные получают текст, как обычно.
 * В общий чат MajorDoMo (shouts) пишется только с 'shout' => true — с именем отправителя: «Кухня: текст».
 * Возвращает число терминалов, которым сообщение отправлено.
 */

function messagesSend($to, $message, $options = array())
{
    if (!is_array($options)) {
        $options = array();
    }
    $message = trim((string)$message);
    $image = isset($options['image']) ? trim((string)$options['image']) : '';
    $sound = isset($options['sound']) ? trim((string)$options['sound']) : '';
    $voice = !isset($options['voice']) || !empty($options['voice']);
    $file = isset($options['file']) ? trim((string)$options['file']) : '';
    $video = isset($options['video']) ? trim((string)$options['video']) : '';
    $file_name = isset($options['file_name']) ? trim((string)$options['file_name']) : '';
    if ($file != '' && $file_name == '') {
        $file_name = basename($file);
    }
    // камера go2rtc: имя потока из списка go2rtc; снимок — картинкой сообщения (если картинки нет)
    $stream = isset($options['stream']) ? trim((string)$options['stream']) : '';
    if ($stream != '' && !messagesGo2rtcHas($stream)) {
        DebMes('messagesSend: go2rtc stream not found: ' . $stream, 'app_messages');
        $stream = '';
    }
    $stream_duration = !empty($options['stream_duration']) ? (int)$options['stream_duration'] : 60;
    $link = (isset($options['link']) && preg_match('/^[0-9a-f]{32}$/', (string)$options['link'])) ? (string)$options['link'] : '';
    if ($stream != '' && $image == '' && (!isset($options['snapshot']) || $options['snapshot'])) {
        $image = messagesGo2rtcSnapshot($stream);
    }
    // history => false — только на устройства, в переписку не пишется
    $history = !isset($options['history']) || $options['history'];
    if ($message == '' && $image == '' && $sound == '' && $file == '' && $video == '' && $stream == '') {
        return 0;
    }
    // отправитель: название терминала, если это терминал, иначе как есть
    $sender = isset($options['from']) ? trim((string)$options['from']) : '';
    $from = array();
    if ($sender != '') {
        $from = messagesTerminal($sender);
        if (!empty($from['ID'])) {
            $sender = $from['TITLE'];
        }
    }
    // отправитель — пользователь MajorDoMo (from_user => ID): его имя вместо терминала
    $from_user = array();
    if (!empty($options['from_user'])) {
        $from_user = SQLSelectOne("SELECT ID, NAME, USERNAME FROM users WHERE ID=" . (int)$options['from_user']);
        if (!empty($from_user['ID'])) {
            $sender = ($from_user['NAME'] != '') ? $from_user['NAME'] : $from_user['USERNAME'];
            $from = array();
        }
    }
    // пометка к отправителю (страница из панели управления — «из панели управления»)
    $from_note = isset($options['from_note']) ? mb_substr(trim((string)$options['from_note']), 0, 64, 'UTF-8') : '';
    $chat = isset($options['chat']) ? preg_replace('/[^a-z0-9:_]/i', '', (string)$options['chat']) : '';
    // копия в общий чат MajorDoMo (shouts) — только по просьбе сценария (shout => true), одна запись на отправку,
    // с именем отправителя в тексте (чат показывает автором имя компьютера). Сообщения «Все терминалы» туда
    // не копируются: общий чат — отдельный чат страницы «Сообщения», копия дублировала бы переписку
    if (!empty($options['shout'])) {
        $text = $message;
        if ($text == '') {
            if ($video != '') {
                $text = messagesLang('MESSAGES_VIDEO');
            } elseif ($file != '') {
                $text = messagesLang('MESSAGES_FILE') . ': ' . $file_name;
            } elseif ($sound != '' && $image == '') {
                $text = messagesLang('MESSAGES_VOICE_MESSAGE');
            }
        }
        if ($sender != '') {
            $text = $sender . ($from_note != '' ? ' (' . $from_note . ')' : '') . ': ' . $text;
        }
        $shout = array(
            // таблица shouts в utf8 (3 байта): эмодзи и другие 4-байтовые символы она не принимает
            'MESSAGE' => preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $text),
            'IMAGE' => $image,
            'ADDED' => date('Y-m-d H:i:s'),
            'ROOM_ID' => 0,
            'MEMBER_ID' => 0,
            'SOURCE' => ($sender != '') ? 'terminal: ' . $sender : 'terminal',
        );
        if (isset($options['level']) && (int)$options['level'] > 0) {
            $shout['IMPORTANCE'] = (int)$options['level'];
        }
        SQLInsert('shouts', $shout);
    }
    $send_id = md5(uniqid('', true) . mt_rand());
    // заголовок окна на устройстве: отправитель с пометкой и чат
    $sender_title = ($sender != '' && $from_note != '') ? $sender . ' (' . $from_note . ')' : $sender;
    if ($sender != '' && !empty($options['chat_title'])) {
        $sender_title .= ' · ' . trim((string)$options['chat_title']);
    }
    $sent = 0;
    $recipients = array();
    // сначала все записи переписки (страницы сразу видят сообщение), потом доставка устройствам
    $deliveries = array();
    $from_name = (isset($from['NAME']) && !empty($from['ID'])) ? $from['NAME'] : $sender;
    foreach ((array)$to as $destination) {
        // пользователь MajorDoMo (user:ID): только в переписку — на страницы «Сообщения», где он вошёл
        if (is_string($destination) && preg_match('/^user:(\d+)$/', $destination, $m)) {
            $user = SQLSelectOne("SELECT ID, NAME, USERNAME FROM users WHERE ID=" . (int)$m[1]);
            if (empty($user['ID']) || !$history) {
                continue;
            }
            $rec = array(
                'FROM_NAME' => $from_name, 'TO_NAME' => ($user['NAME'] != '') ? $user['NAME'] : $user['USERNAME'],
                'FROM_ID' => !empty($from['ID']) ? (int)$from['ID'] : 0, 'TO_ID' => 0,
                'FROM_USER' => !empty($from_user['ID']) ? (int)$from_user['ID'] : 0, 'TO_USER' => (int)$user['ID'],
                'MESSAGE' => json_encode($message), 'IMAGE' => $image, 'SOUND' => $sound, 'FILE' => $file, 'VIDEO' => $video,
                'FILE_NAME' => $file_name,
                'FORWARDED' => isset($options['forwarded']) ? mb_substr(trim((string)$options['forwarded']), 0, 255, 'UTF-8') : '',
                'ADDED' => time(), 'CHAT' => $chat, 'SEND_ID' => $send_id, 'STREAM' => $stream, 'LINK' => $link, 'FROM_NOTE' => $from_note,
            );
            SQLInsert('messages_history', $rec);
            $recipients[] = 'user:' . (int)$user['ID'];
            $sent++;
            continue;
        }
        $terminal_rec = messagesTerminal($destination);
        if (empty($terminal_rec['ID'])) {
            DebMes('messagesSend: terminal not found: ' . $destination, 'app_messages');
            continue;
        }
        // переписка терминалов (страница «Сообщения»): сначала запись — её номер идёт с сообщением
        // до устройства, по нему ставится «доставлено». Текст хранится в JSON, чтобы не терять эмодзи
        $rec = array(
            'FROM_NAME' => $from_name,
            'TO_NAME' => $terminal_rec['NAME'],
            'FROM_ID' => !empty($from['ID']) ? (int)$from['ID'] : 0,
            'TO_ID' => (int)$terminal_rec['ID'],
            'FROM_USER' => !empty($from_user['ID']) ? (int)$from_user['ID'] : 0,
            'MESSAGE' => json_encode($message),
            'IMAGE' => $image,
            'SOUND' => $sound,
            'FILE' => $file,
            'VIDEO' => $video,
            'FILE_NAME' => $file_name,
            'FORWARDED' => isset($options['forwarded']) ? mb_substr(trim((string)$options['forwarded']), 0, 255, 'UTF-8') : '',
            'ADDED' => time(),
            'CHAT' => $chat,
            'SEND_ID' => $send_id,
            'STREAM' => $stream,
            'LINK' => $link,
            'FROM_NOTE' => $from_note,
        );
        $rec['ID'] = $history ? SQLInsert('messages_history', $rec) : 0;
        $message_id = (int)$rec['ID'];
        $recipients[] = $terminal_rec['NAME'];
        $sent++;
        $deliveries[] = array($terminal_rec, $message_id);
    }
    // открытые страницы «Сообщения» получают одно событие на отправку (кэш вебсокета хранит
    // только последнее событие с этим именем) и сразу обновляют переписку: сообщение уже записано,
    // доставка устройствам — дальше (она может быть долгой: устройство не отвечает)
    if (count($recipients)) {
        // журнал: одна строка на отправку — кто (терминал или пользователь, адрес браузера или сценарий), кому
        if (!empty($from_user['ID'])) {
            $who = 'user:' . (int)$from_user['ID'] . ' ' . $sender;
        } else {
            $who = (!empty($from['ID']) ? 'terminal ' . $from['NAME'] : ($sender != '' ? $sender : '-'));
        }
        $where = (PHP_SAPI != 'cli' && !empty($_SERVER['REMOTE_ADDR'])) ? 'IP ' . $_SERVER['REMOTE_ADDR'] : 'script';
        DebMes('messagesSend ' . substr($send_id, 0, 8) . " from $who ($where) to " . implode(', ', $recipients) . ': '
            . messagesLogSafe($message) . ($image != '' ? " (image: $image)" : '') . ($sound != '' ? " (sound: $sound)" : '')
            . ($video != '' ? " (video: $video)" : '') . ($file != '' ? " (file: $file)" : '') . ($stream != '' ? " (camera: $stream)" : ''), 'app_messages');
        messagesNotify(!empty($from_user['ID']) ? 'user:' . (int)$from_user['ID'] : $from_name, $recipients);
    }
    // after_record — страница «Сообщения» отвечает браузеру здесь, до доставки (messages_page.inc.php)
    if (isset($options['after_record']) && is_callable($options['after_record'])) {
        call_user_func($options['after_record'], $sent);
    }
    foreach ($deliveries as $delivery) {
        list($terminal_rec, $message_id) = $delivery;
        // терминал без «Может проговаривать» (например, только браузер со страницей «Сообщения»)
        // получает сообщение только в переписку — на его страницу, без доставки дополнению TTS
        $speaks = $terminal_rec['CANTTS'] && $terminal_rec['TTS_TYPE'] != '';
        $level = isset($options['level']) ? (int)$options['level'] : messagesMinLevel($terminal_rec);
        $tts = $speaks ? messagesAddon($terminal_rec) : null;
        // журнал: строка на получателя — адрес устройства и номер сообщения в переписке
        DebMes('messagesSend ' . substr($send_id, 0, 8) . ' to ' . $terminal_rec['TITLE'] . ' (' . messagesDeviceLabel($terminal_rec, $tts)
            . ($speaks ? ', ' . $terminal_rec['TTS_TYPE'] : '') . ", level $level) message #$message_id" . ($speaks ? '' : ' (page only)'), 'app_messages');
        // устройство выключено — не ждём его (messagesReachable): сообщение остаётся в переписке
        if (is_object($tts) && !messagesReachable($terminal_rec, $tts)) {
            DebMes('messagesSend ' . substr($send_id, 0, 8) . ' to ' . $terminal_rec['TITLE'] . ': not reachable, page only', 'app_messages');
            continue;
        }
        // звук перед сообщением
        if ($sound != '' && is_object($tts) && method_exists($tts, 'playFile')) {
            if (messagesUsesQueue($tts)) {
                terminalsQueueAdd($terminal_rec, 'sound', '', $level, $sound, '', $sender_title, messagesRef($message_id),
                    isset($options['sound_duration']) ? (float)$options['sound_duration'] : 0);
            } elseif ($tts->playFile($sound, $level, $sender_title, isset($options['sound_duration']) ? (float)$options['sound_duration'] : 0)) {
                messagesDelivered($message_id);
            }
        }
        // видео: окно видео на устройстве, если дополнение умеет (pipup: sendVideo по ссылке, со звуком).
        // Через очередь: ждёт конца текущего сообщения и держит терминал на время ролика (срочные прерывают)
        $video_shown = false;
        // (у дополнений с canShowVideo() — только если оно разрешает: mediaplayer — плеер с экраном и галочка)
        if ($speaks && $video != '' && is_object($tts) && method_exists($tts, 'sendVideo')
            && (!method_exists($tts, 'canShowVideo') || $tts->canShowVideo())) {
            $url = method_exists($tts, 'fileUrl') ? $tts->fileUrl($video) : $video;
            // длительность: от страницы «Сообщения» (браузер знает её при выборе файла), иначе из файла, иначе 60 с
            $seconds = !empty($options['video_duration']) ? (int)ceil((float)$options['video_duration']) : messagesFileSeconds($video);
            $duration = ($seconds > 0) ? min($seconds + 3, 600) : 60;
            $video_args = array($url, '', $sender_title, 'video', array('muted' => false, 'duration' => $duration));
            if ($url && messagesUsesQueue($tts)) {
                $video_shown = (bool)terminalsQueueCall($terminal_rec, 'sendVideo', $video_args,
                    array('level' => $level, 'busy' => $duration, 'refs' => messagesRef($message_id)));
            } else {
                $video_shown = $url && (bool)call_user_func_array(array($tts, 'sendVideo'), $video_args);
                if ($video_shown) {
                    messagesDelivered($message_id);
                }
            }
        }
        // камера: окно потока на устройстве; показано — снимок устройству не нужен (он в переписке),
        // не показано (устройство без видео) — снимок с текстом, как картинка
        $device_image = $image;
        if ($speaks && $stream != '' && messagesGo2rtcShow($terminal_rec, $stream, $stream_duration,
            ($sender_title != '' ? $sender_title . ' · ' : '') . messagesLang('MESSAGES_CAMERA_TITLE') . ': ' . $stream, $level)) {
            messagesDelivered($message_id);
            $device_image = '';
        }
        // только файл или видео, которое устройство не покажет: на устройство — текст без озвучки
        // («Файл: имя», «Видео»), сам файл — на странице «Сообщения»
        if ($speaks && $message == '' && $image == '' && $sound == '' && ($file != '' || ($video != '' && !$video_shown))) {
            $label = ($file != '') ? messagesLang('MESSAGES_FILE') . ': ' . $file_name : messagesLang('MESSAGES_VIDEO');
            messagesTerminalSay($terminal_rec, $label, $level, '', $sender_title, $message_id, true);
        }
        if ($speaks && ($message != '' || $device_image != '')) {
            if ($voice && $message != '') {
                // как sayTo(): терминалы и генераторы речи получают событие SAYTO
                processSubscriptionsSafe('SAYTO', array('level' => $level, 'message' => $message,
                    'destination' => $terminal_rec['NAME'], 'image' => $device_image, 'from' => $sender_title, 'ref' => messagesRef($message_id)));
                if (!messagesHasApi()) {
                    // прежние «Терминалы» не сообщают о доставке — «доставлено», когда сообщение отдано им
                    messagesDelivered($message_id);
                }
            } else {
                // без озвучки (текст, картинка): в очереди не задерживает голос
                messagesTerminalSay($terminal_rec, $message, $level, $device_image, $sender_title, $message_id, true);
            }
        }
    }
    return $sent;
}

// Событие вебсокета TERMINAL_MESSAGE для страниц «Сообщения»: FROM — кто, TO — список, кого касается
function messagesNotify($from, $to)
{
    if (function_exists('postToWebSocketQueue')) {
        postToWebSocketQueue('TERMINAL_MESSAGE', json_encode(array('FROM' => (string)$from, 'TO' => array_values(array_unique((array)$to)),
            'TIME' => time())), 'PostEvent');
    }
}

// Терминал переименован: «Сообщения» хранят его имя в составе своих групп, в ключах аватарок
// (term:ИМЯ) и в переписке (для показа; узнаётся она по ID) — переносим на новое имя
function messagesTerminalRenamed($old, $new, $id)
{
    foreach (SQLSelect("SELECT * FROM messages_groups") as $group) {
        $members = json_decode((string)$group['MEMBERS'], true);
        if (is_array($members) && in_array($old, $members, true)) {
            foreach ($members as $i => $name) {
                if ($name === $old) {
                    $members[$i] = $new;
                }
            }
            $group['MEMBERS'] = json_encode(array_values(array_unique($members)));
            SQLUpdate('messages_groups', $group);
        }
    }
    SQLExec("DELETE FROM messages_colors WHERE CHAT_KEY='" . DBSafe('term:' . $new) . "'");
    SQLExec("UPDATE messages_colors SET CHAT_KEY='" . DBSafe('term:' . $new) . "' WHERE CHAT_KEY='" . DBSafe('term:' . $old) . "'");
    SQLExec("UPDATE messages_history SET TO_NAME='" . DBSafe($new) . "' WHERE TO_ID=" . (int)$id);
    SQLExec("UPDATE messages_history SET FROM_NAME='" . DBSafe($new) . "' WHERE FROM_ID=" . (int)$id);
}

// Срок хранения переписки страницы «Сообщения», дней (настройка приложения «Сообщения»): по умолчанию 30, 0 — без срока
function messagesHistoryDays($config)
{
    if (!isset($config['MESSAGES_HISTORY_DAYS']) || $config['MESSAGES_HISTORY_DAYS'] === '') {
        return 30;
    }
    return max(0, (int)$config['MESSAGES_HISTORY_DAYS']);
}

// Чистка переписки (раз в сутки, MINUTELY): сообщения старше срока хранения, сообщения удалённых групп,
// терминалов и пользователей-получателей, ключи удалённых пользователей; файлы вложений (cms/cached/terminals) — когда на них не осталось сообщений
// (пересланное ссылается на тот же файл), и загруженные, но не отправленные — старше суток
function messagesCleanup($days)
{
    $where = array();
    if ($days > 0) {
        $where[] = "ADDED<" . (time() - (int)$days * 86400);
    }
    $where[] = "(CHAT LIKE 'group:%' AND CAST(SUBSTRING(CHAT, 7) AS UNSIGNED) NOT IN (SELECT ID FROM messages_groups))";
    $where[] = "(TO_ID>0 AND TO_ID NOT IN (SELECT ID FROM terminals))";
    $where[] = "(TO_USER>0 AND TO_USER NOT IN (SELECT ID FROM users))";
    // скрытые обеими сторонами («Очистить историю»); отправитель-не терминал и не пользователь (сценарий) скрыть не может
    $where[] = "(HIDE_TO=1 AND (HIDE_FROM=1 OR (FROM_ID=0 AND FROM_USER=0)))";
    $old = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM messages_history WHERE " . implode(' OR ', $where));
    if (!empty($old['TOTAL'])) {
        SQLExec("DELETE FROM messages_history WHERE " . implode(' OR ', $where));
        DebMes('Messages cleanup: removed ' . (int)$old['TOTAL'] . ' message(s)', 'app_messages');
    }
    // ключи удалённых пользователей
    SQLExec("DELETE FROM messages_tokens WHERE TERMINAL_ID=0 AND USER_ID>0 AND USER_ID NOT IN (SELECT ID FROM users)");
    $dir = ROOT . 'cms/cached/terminals';
    if (!is_dir($dir)) {
        return;
    }
    $used = array();
    foreach (SQLSelect("SELECT IMAGE, SOUND, FILE, VIDEO FROM messages_history WHERE IMAGE LIKE 'cms/cached/terminals/%'"
        . " OR SOUND LIKE 'cms/cached/terminals/%' OR FILE LIKE 'cms/cached/terminals/%' OR VIDEO LIKE 'cms/cached/terminals/%'") as $row) {
        foreach ($row as $path) {
            if ($path != '') {
                $used[basename((string)$path)] = true;
                // превью видео (кадр от браузера отправителя) — рядом с видео: «имя.webm.jpg»
                $used[basename((string)$path) . '.jpg'] = true;
            }
        }
    }
    $removed = 0;
    foreach ((array)@scandir($dir) as $file) {
        $path = $dir . '/' . $file;
        if ($file == '.' || $file == '..' || !is_file($path) || isset($used[$file]) || filemtime($path) > time() - 86400) {
            continue;
        }
        if (@unlink($path)) {
            $removed++;
        }
    }
    if ($removed) {
        DebMes("Messages cleanup: removed $removed file(s)", 'app_messages');
    }
}

// Сообщения доставлены (дополнение устройства приняло их или страница получателя загрузила):
// $ids — номер или список через запятую. Отправители узнают об этом через вебсокет
function messagesDelivered($ids)
{
    $ids = implode(',', array_filter(array_map('intval', is_array($ids) ? $ids : explode(',', (string)$ids))));
    if ($ids == '') {
        return;
    }
    $rows = SQLSelect("SELECT DISTINCT m.FROM_NAME, m.TO_NAME, f.NAME AS FROM_NOW, t.NAME AS TO_NOW FROM messages_history m"
        . " LEFT JOIN terminals f ON f.ID=m.FROM_ID LEFT JOIN terminals t ON t.ID=m.TO_ID WHERE m.ID IN ($ids) AND m.DELIVERED=0");
    if (!count($rows)) {
        return;
    }
    SQLExec("UPDATE messages_history SET DELIVERED=" . time() . " WHERE ID IN ($ids) AND DELIVERED=0");
    $senders = array();
    foreach ($rows as $row) {
        $senders[] = ($row['FROM_NOW'] != '') ? $row['FROM_NOW'] : $row['FROM_NAME'];
    }
    messagesNotify(($rows[0]['TO_NOW'] != '') ? $rows[0]['TO_NOW'] : $rows[0]['TO_NAME'], $senders);
}
