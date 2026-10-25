<?php
/*
 * Связь «Сообщений» с модулем «Терминалы».
 *
 * Новые «Терминалы» (константа TERMINALS_API): очередь, картинка и отправитель в окне, метка записи (ref) и событие
 * TERMINAL_DELIVERED — «доставлено» ставится, когда устройство приняло сообщение. Прежние «Терминалы» (upstream) —
 * урезанно: только текст (terminalSay / SAYTO) и методы дополнений напрямую, «доставлено» — когда сообщение отдано им.
 * Функции «Терминалов» вызываются только здесь, с проверкой, что они есть.
 */

include_once(DIR_MODULES . 'terminals/terminals.class.php');

// Новые «Терминалы»: очередь, ref, события
function messagesHasApi()
{
    return defined('TERMINALS_API') && function_exists('terminalsQueueAdd');
}

// Метка записи переписки для «Терминалов» (ref): с именем модуля, чтобы событие TERMINAL_DELIVERED не путало
// записи разных модулей
function messagesRef($message_id)
{
    return ((int)$message_id > 0) ? 'app_messages:' . (int)$message_id : '';
}

// Номера записей переписки из поля refs события TERMINAL_DELIVERED (только свои метки)
function messagesRefIds($refs)
{
    $ids = array();
    foreach (explode(',', (string)$refs) as $ref) {
        if (preg_match('/^app_messages:(\d+)$/', trim($ref), $m)) {
            $ids[] = (int)$m[1];
        }
    }
    return $ids;
}

// Текст (и картинка, отправитель) на терминал; $silent — без озвучки. Прежние «Терминалы» берут только текст и уровень
function messagesTerminalSay($terminal_rec, $message, $level, $image = '', $sender = '', $message_id = 0, $silent = false)
{
    $terminals = new terminals();
    if (messagesHasApi()) {
        return $terminals->terminalSay($terminal_rec, $message, $level, $image, $sender, messagesRef($message_id), $silent);
    }
    $result = $terminals->terminalSay($terminal_rec, $message, $level);
    if ($result !== false) {
        messagesDelivered($message_id);
    }
    return $result;
}

// Терминал по имени, названию или адресу
function messagesTerminal($name)
{
    if (function_exists('terminalsFind')) {
        return terminalsFind($name);
    }
    $name = trim((string)$name);
    if ($name == '') {
        return array();
    }
    $list = getTerminalsByName($name, 1);
    if (!empty($list[0]['ID'])) {
        return $list[0];
    }
    $rec = SQLSelectOne("SELECT * FROM terminals WHERE TITLE='" . DBSafe($name) . "' LIMIT 1");
    if (!empty($rec['ID'])) {
        return $rec;
    }
    $list = getTerminalsByHost($name, 1);
    return !empty($list[0]['ID']) ? $list[0] : array();
}

// Порог уровня сообщений терминала: его поле или ThisComputer.minMsgLevel
function messagesMinLevel($terminal_rec)
{
    if (function_exists('terminalsMinLevel')) {
        return terminalsMinLevel($terminal_rec);
    }
    $min_level = (int)getGlobal('ThisComputer.minMsgLevel');
    if (!empty($terminal_rec['MIN_MSG_LEVEL'])) {
        $min_level = (int)processTitle($terminal_rec['MIN_MSG_LEVEL']);
    }
    return max(0, $min_level);
}

// Дополнение TTS терминала (или null)
function messagesAddon($terminal_rec)
{
    if (function_exists('terminalsQueueAddon')) {
        return terminalsQueueAddon($terminal_rec);
    }
    $type = isset($terminal_rec['TTS_TYPE']) ? preg_replace('/[^\w]/', '', (string)$terminal_rec['TTS_TYPE']) : '';
    $file = DIR_MODULES . 'terminals/tts/' . $type . '.addon.php';
    if ($type == '' || !is_file($file)) {
        return null;
    }
    include_once DIR_MODULES . 'terminals/tts_addon.class.php';
    include_once $file;
    return class_exists($type) ? new $type($terminal_rec) : null;
}

// Идут ли сообщения терминала через очередь «Терминалов»
function messagesUsesQueue($tts)
{
    return function_exists('terminalsQueueUsed') && function_exists('terminalsQueueCall') && terminalsQueueUsed($tts);
}

// Отвечает ли устройство (новые «Терминалы»; прежние — считаем, что да)
function messagesReachable($terminal_rec, $tts)
{
    return function_exists('terminalsReachable') ? terminalsReachable($terminal_rec, $tts) : true;
}

// Адрес устройства для журнала
function messagesDeviceLabel($terminal_rec, $tts)
{
    if (function_exists('terminalsDeviceLabel')) {
        return terminalsDeviceLabel($terminal_rec, $tts);
    }
    return (isset($terminal_rec['HOST']) && trim((string)$terminal_rec['HOST']) != '') ? trim((string)$terminal_rec['HOST']) : '-';
}

// Текст для журнала без паролей и ключей в ссылках
function messagesLogSafe($text)
{
    if (function_exists('terminalsLogSafe')) {
        return terminalsLogSafe($text);
    }
    $text = preg_replace('~(\b[a-z][a-z0-9+.-]*://[^/:@\s"\']*):[^/@\s"\']*@~i', '$1:***@', (string)$text);
    return preg_replace('/([?&#](?:token|sig|key|password|pass|pwd|id)=)[^&\s"\']+/i', '$1***', $text);
}

// Длительность звука или видео, с (дробная); 0 — не узнали. Новые «Терминалы» читают заголовок файла
function messagesMediaDuration($path)
{
    if (!function_exists('terminalsMediaDuration') && is_file(DIR_MODULES . 'terminals/terminals_media.inc.php')) {
        include_once DIR_MODULES . 'terminals/terminals_media.inc.php';
    }
    return function_exists('terminalsMediaDuration') ? (float)terminalsMediaDuration($path) : 0;
}

// Длительность файла в целых секундах (путь от веб-корня или от корня диска); 0 — не узнали
function messagesFileSeconds($file)
{
    if (function_exists('terminalsQueueFileSeconds')) {
        return terminalsQueueFileSeconds($file);
    }
    $file = (string)$file;
    $local = is_file($file) ? $file : (($file != '' && is_file(ROOT . ltrim($file, '/'))) ? ROOT . ltrim($file, '/') : '');
    return ($local != '') ? (int)ceil(messagesMediaDuration($local)) : 0;
}

// Поля и значения настроек дополнений (новые «Терминалы»; прежние — нет)
function messagesAddonFields($addon, $kind)
{
    return function_exists('terminalsAddonFields') ? terminalsAddonFields($addon, $kind) : array();
}

function messagesAddonSettings($terminal_rec, $kind, $addon)
{
    return function_exists('terminalsAddonSettings') ? terminalsAddonSettings($terminal_rec, $kind, $addon) : array();
}

function messagesSettingsColumns()
{
    return function_exists('terminalsSettingsColumns') ? terminalsSettingsColumns() : array();
}

function messagesFieldsForForm($fields)
{
    return function_exists('terminalsFieldsForForm') ? terminalsFieldsForForm($fields) : array();
}

// Переводы модуля: languages/app_messages_<язык>.php и app_messages_default.php (английский)
function messagesLoadLanguage()
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    if (defined('SETTINGS_SITE_LANGUAGE') && SETTINGS_SITE_LANGUAGE != '' && file_exists(ROOT . 'languages/app_messages_' . SETTINGS_SITE_LANGUAGE . '.php')) {
        include_once(ROOT . 'languages/app_messages_' . SETTINGS_SITE_LANGUAGE . '.php');
    }
    if (file_exists(ROOT . 'languages/app_messages_default.php')) {
        include_once(ROOT . 'languages/app_messages_default.php');
    }
}

function messagesLang($key)
{
    messagesLoadLanguage();
    return defined('LANG_' . $key) ? constant('LANG_' . $key) : $key;
}

// --- Сведения о терминалах для страницы ---

// Статус терминала для страницы «Сообщения»: online — активность не старше 3 минут
// (страница «Сообщения», shoutbox, command.php, ping каждый час); reachable — устройство на связи
// по данным ядра (IS_ONLINE); last — время последней активности для показа
function messagesTerminalStatus($terminal_rec)
{
    $last = !empty($terminal_rec['LATEST_ACTIVITY']) ? strtotime($terminal_rec['LATEST_ACTIVITY']) : 0;
    if ($last <= 0) {
        $last = 0;
    }
    return array(
        'online' => ($last && time() - $last <= 180) ? 1 : 0,
        'reachable' => !empty($terminal_rec['IS_ONLINE']) ? 1 : 0,
        'last' => $last ? (date('Y-m-d', $last) == date('Y-m-d') ? date('H:i', $last) : date('d.m H:i', $last)) : '',
    );
}

// Значения полей дополнения для показа: подписи из описания полей, пароли скрыты, пустое — «по умолчанию»
function messagesSettingsForView($fields, $values)
{
    $result = array();
    foreach (messagesFieldsForForm($fields) as $field) {
        $value = isset($values[$field['NAME']]) ? (string)$values[$field['NAME']] : '';
        if ($field['TYPE'] == 'password') {
            $value = ($value != '') ? '••••••' : '';
        } elseif ($field['TYPE'] == 'checkbox') {
            $value = ($value == '1') ? messagesLang('MESSAGES_YES') : messagesLang('MESSAGES_NO');
        } elseif (($field['TYPE'] == 'select' || $field['TYPE'] == 'combo') && $value != '') {
            foreach ($field['OPTIONS'] as $option) {
                if ($option[0] === $value) {
                    $value = $option[1];
                }
            }
        }
        if ($value == '') {
            $value = ($field['DEFAULT'] != '') ? $field['DEFAULT'] : messagesLang('MESSAGES_BY_DEFAULT');
        }
        $result[] = array('title' => strip_tags($field['TITLE']), 'value' => $value);
    }
    return $result;
}

// Состояние экрана словами (pipup: screenState()): «включён», «заставка», «выключен», «нет связи»
function messagesScreenText($screen)
{
    if (!is_array($screen)) {
        return messagesLang('MESSAGES_SCREEN_UNKNOWN');
    }
    if ($screen['visible']) {
        return messagesLang('MESSAGES_SCREEN_ON');
    }
    return messagesLang($screen['dreaming'] ? 'MESSAGES_SCREEN_DREAM' : 'MESSAGES_SCREEN_OFF');
}

// Возможности и текущие настройки терминала (только для просмотра) — карточка на странице «Сообщения»
function messagesTerminalInfo($terminal_rec)
{
    $info = array(
        'name' => $terminal_rec['NAME'],
        'title' => $terminal_rec['TITLE'],
        'host' => (string)$terminal_rec['HOST'],
        'room' => '',
        'min_level' => (string)$terminal_rec['MIN_MSG_LEVEL'],
        'status' => messagesTerminalStatus($terminal_rec),
        'tts' => null,
        'player' => null,
    );
    if (!empty($terminal_rec['LOCATION_ID'])) {
        $room = SQLSelectOne("SELECT TITLE FROM locations WHERE ID=" . (int)$terminal_rec['LOCATION_ID']);
        $info['room'] = isset($room['TITLE']) ? $room['TITLE'] : '';
    }
    // озвучка (TTS): что умеет дополнение — по его необязательным методам
    if ($terminal_rec['CANTTS'] && $terminal_rec['TTS_TYPE'] != '') {
        $type = preg_replace('/[^\w]/', '', $terminal_rec['TTS_TYPE']);
        $tts = array('type' => $type, 'title' => $type, 'capabilities' => array(), 'settings' => array());
        $file = DIR_MODULES . 'terminals/tts/' . $type . '.addon.php';
        // одноимённое дополнение плеера (majordroid) — тот же класс, не подключаем
        if (is_file($file) && !file_exists(DIR_MODULES . 'app_player/addons/' . $type . '.addon.php')) {
            include_once DIR_MODULES . 'terminals/tts_addon.class.php';
            include_once $file;
            if (class_exists($type)) {
                $addon = new $type($terminal_rec);
                if (isset($addon->title) && is_string($addon->title) && $addon->title != '') {
                    $tts['title'] = $addon->title;
                }
                $caps = array('say' => 'MESSAGES_CAP_SAY', 'showMessage' => 'MESSAGES_CAP_IMAGE', 'playFile' => 'MESSAGES_CAP_SOUND',
                    'sendVideo' => 'MESSAGES_CAP_VIDEO', 'ask' => 'MESSAGES_CAP_ASK', 'useQueue' => 'MESSAGES_CAP_QUEUE',
                    'restoreState' => 'MESSAGES_CAP_RESTORE', 'screenState' => 'MESSAGES_CAP_SCREEN');
                foreach ($caps as $method => $key) {
                    // pipup: saveState/restoreState — экран после серии, а не плеер
                    if ($method == 'restoreState' && method_exists($addon, 'screenState')) {
                        continue;
                    }
                    // видео — если дополнение его сейчас показывает (mediaplayer: плеер с экраном и галочка)
                    if ($method == 'sendVideo' && method_exists($addon, 'canShowVideo') && !$addon->canShowVideo()) {
                        continue;
                    }
                    if (method_exists($addon, $method) && ($method != 'useQueue' || $addon->useQueue())) {
                        $tts['capabilities'][] = messagesLang($key);
                    }
                }
                // экран устройства (pipup): включён, заставка, выключен
                if (method_exists($addon, 'screenState')) {
                    $info['screen'] = messagesScreenText($addon->screenState());
                }
                $tts['settings'] = messagesSettingsForView(messagesAddonFields($addon, 'tts'), messagesAddonSettings($terminal_rec, 'tts', $type));
            }
        }
        $info['tts'] = $tts;
    }
    // плеер: возможности — публичные методы дополнения app_player (как в карточке терминала)
    if ($terminal_rec['CANPLAY'] && $terminal_rec['PLAYER_TYPE'] != '') {
        $type = preg_replace('/[^\w]/', '', $terminal_rec['PLAYER_TYPE']);
        $player = array('type' => $type, 'title' => $type, 'capabilities' => array(), 'settings' => array());
        $file = DIR_MODULES . 'app_player/addons/' . $type . '.addon.php';
        if (is_file($file) && is_file(DIR_MODULES . 'app_player/addons.php')) {
            include_once DIR_MODULES . 'app_player/addons.php';
            include_once $file;
            if (class_exists($type) && is_subclass_of($type, 'app_player_addon', true)) {
                $addon = new $type(null);
                if (isset($addon->title) && $addon->title != '') {
                    $player['title'] = strip_tags($addon->title);
                }
                $reflection = new ReflectionClass($addon);
                foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $name = $method->getName();
                    if ($method->getDeclaringClass()->getName() == $reflection->getName() && substr($name, 0, 2) != '__'
                        && !in_array($name, array('destroy', 'command', 'settingsFields'))) {
                        $player['capabilities'][] = $name;
                    }
                }
                $values = messagesAddonSettings($terminal_rec, 'player', $type);
                if (!count($values)) {
                    // настройки ещё не в JSON — прежние столбцы
                    foreach (messagesSettingsColumns() as $column) {
                        $values[$column] = isset($terminal_rec[$column]) ? $terminal_rec[$column] : '';
                    }
                }
                $player['settings'] = messagesSettingsForView(messagesAddonFields($addon, 'player'), $values);
            }
        }
        $info['player'] = $player;
    }
    return $info;
}

// Адрес вебсокета для внешнего доступа к странице «Сообщения»: ws://… или wss://… (полный адрес)
// либо путь от адреса страницы (/majordomo — через тот же хост и порт, например за обратным прокси); иначе ''
function messagesWsUrl($value)
{
    $value = trim((string)$value);
    if (preg_match('~^wss?://[^\s"\'<>]+$~i', $value) || preg_match('~^/[^\s"\'<>]*$~', $value)) {
        return $value;
    }
    return '';
}
