<?php
/**
 * «Сообщения» — приложение MajorDoMo: мессенджер терминалов и пользователей.
 *
 * Страница: /popup/app_messages.html (и /module/app_messages.html, /apps/app_messages.html), в списке приложений —
 * по файлу-признаку modules/app_messages/app. Настройки — в панели управления (вкладки «Настройки», «Терминалы»,
 * «Камеры (go2rtc)»).
 *
 * Доставка на устройства — через модуль «Терминалы» (messages_terminals.inc.php): с новыми «Терминалами» (TERMINALS_API) —
 * очередь, окно с картинкой и отправителем, «доставлено» по событию TERMINAL_DELIVERED; с прежними — урезанно.
 * Камеры go2rtc (messages_go2rtc.inc.php) — временно здесь, переедут в отдельный модуль.
 */

include_once(DIR_MODULES . 'app_messages/messages_terminals.inc.php');
include_once(DIR_MODULES . 'app_messages/messages_send.inc.php');
include_once(DIR_MODULES . 'app_messages/messages_auth.inc.php');
include_once(DIR_MODULES . 'app_messages/messages_go2rtc.inc.php');

// Прежнее имя функции отправки для сценариев (была в модуле «Терминалы»)
if (!function_exists('terminalsSend')) {
    function terminalsSend($to, $message, $options = array())
    {
        return messagesSend($to, $message, $options);
    }
}

class app_messages extends module
{
    function __construct()
    {
        $this->name = 'app_messages';
        $this->title = messagesLang('MESSAGES_TITLE');
        $this->module_category = '<#LANG_SECTION_APPLICATIONS#>';
        $this->checkInstalled();
    }

    // Параметры модуля для ссылок и форм панели управления (как у остальных модулей): без этого
    // parseLinks() не передаёт в pd состояние панели (action=app_messages), и форма настроек,
    // отправленная из панели, попадает на стартовую страницу — модуль не вызывается
    function saveParams($data = 1)
    {
        return parent::saveParams(array());
    }

    function run()
    {
        messagesLoadLanguage();
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        $out['ACTION'] = $this->action;
        $out['MODE'] = $this->mode;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
        $this->result = $p->result;
    }

    // Страница «Сообщения» (messages_page.inc.php)
    function usual(&$out)
    {
        include DIR_MODULES . 'app_messages/messages_page.inc.php';
    }

    // Панель управления: настройки, терминалы (ключи, отправка), камеры go2rtc
    function admin(&$out)
    {
        $this->getConfig();
        $h = function ($text) {
            return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
        };
        // вкладка, которую открыть после сохранения (у каждой вкладки своя форма)
        $out['TAB'] = (gr('tab') == 'terminals') ? 'tab_terminals' : '';
        if (gr('go2rtc_save') || gr('access_save')) {
            $out['TAB'] = 'tab_cameras';
        }
        if (gr('ws_save')) {
            $this->config['MESSAGES_WS_EXTERNAL'] = messagesWsUrl(gr('ws_external'));
            $this->config['MESSAGES_HISTORY_DAYS'] = (string)max(0, min(3650, (int)gr('history_days')));
            $this->saveConfig();
            $out['SAVED'] = 1;
        }
        if (gr('go2rtc_save')) {
            foreach (array('GO2RTC_URL', 'GO2RTC_USER', 'GO2RTC_PASSWORD', 'GO2RTC_DEVICE_URL', 'GO2RTC_RTSP_USER', 'GO2RTC_RTSP_PASSWORD') as $key) {
                $this->config[$key] = trim((string)gr(strtolower($key)));
            }
            foreach (array('GO2RTC_URL', 'GO2RTC_DEVICE_URL') as $key) {
                if ($this->config[$key] != '' && !preg_match('#^https?://#i', $this->config[$key])) {
                    $this->config[$key] = 'http://' . $this->config[$key];
                }
            }
            $this->config['GO2RTC_RTSP_PORT'] = gr('go2rtc_rtsp_port', 'int') ? (string)gr('go2rtc_rtsp_port', 'int') : '';
            $this->config['GO2RTC_WEBTORRENT'] = gr('go2rtc_webtorrent', 'int') ? '1' : '0';
            $this->config['GO2RTC_WT_MINUTES'] = gr('go2rtc_wt_minutes', 'int') ? (string)max(1, min(1440, gr('go2rtc_wt_minutes', 'int'))) : '';
            $this->config['GO2RTC_WAIT'] = gr('go2rtc_wait', 'int') ? (string)max(2, min(25, gr('go2rtc_wait', 'int'))) : '';
            $this->saveConfig();
            $out['GO2RTC_SAVED'] = 1;
        }
        // права на камеры: камера => все / терминалы / пользователи (без записи — только администратору)
        if (gr('access_save')) {
            $posted = gr('access');
            $posted = is_array($posted) ? $posted : array();
            $access = array();
            foreach (messagesGo2rtcStreams() as $name) {
                $key = md5($name);
                $rule = isset($posted[$key]) && is_array($posted[$key]) ? $posted[$key] : array();
                $item = array(
                    'all' => !empty($rule['all']) ? 1 : 0,
                    'terminals' => array_values(array_filter(isset($rule['terminals']) ? (array)$rule['terminals'] : array(), 'is_string')),
                    'users' => array_values(array_unique(array_map('intval', isset($rule['users']) ? (array)$rule['users'] : array()))),
                );
                if ($item['all'] || count($item['terminals']) || count($item['users'])) {
                    $access[$name] = $item;
                }
            }
            messagesCameraAccessSave($access);
            $this->getConfig();
            $out['ACCESS_SAVED'] = 1;
        }
        $user_id = gr('user_key_create', 'int');
        if ($user_id && SQLSelectOne("SELECT ID FROM users WHERE ID=" . (int)$user_id)) {
            messagesUserTokenCreate($user_id);
            $out['KEY_RECREATED'] = 1;
        }

        // терминал: ключ доступа к странице, «Только по ключу», отправка сообщения, экран устройства
        $terminal_id = gr('terminal_id', 'int');
        $terminal = $terminal_id ? SQLSelectOne("SELECT * FROM terminals WHERE ID=" . (int)$terminal_id) : array();
        if (!empty($terminal['ID'])) {
            $this->adminTerminal($terminal, $out, $h);
            return;
        }

        $out['PAGE_URL'] = ROOTHTML . 'popup/app_messages.html';
        $out['WS_EXTERNAL'] = $h(isset($this->config['MESSAGES_WS_EXTERNAL']) ? $this->config['MESSAGES_WS_EXTERNAL'] : '');
        $out['HISTORY_DAYS'] = messagesHistoryDays($this->config);
        $out['OLD_TERMINALS'] = messagesHasApi() ? 0 : 1;

        // терминалы: ключ каждому (создаётся при первом показе), «Только по ключу»
        $out['TERMINAL_LIST'] = array();
        foreach (SQLSelect("SELECT ID, NAME, TITLE FROM terminals ORDER BY TITLE") as $t) {
            $row = messagesTokenRow($t['ID']);
            if (empty($row['TOKEN'])) {
                messagesTokenGet($t['ID']);
                $row = messagesTokenRow($t['ID']);
            }
            $out['TERMINAL_LIST'][] = array(
                'ID' => (int)$t['ID'],
                'NAME' => $h($t['NAME']),
                'TITLE' => $h($t['TITLE']),
                'REQUIRED' => !empty($row['REQUIRED']) ? 1 : 0,
                'LAST_USED' => !empty($row['LAST_USED']) ? date('d.m.Y H:i', (int)$row['LAST_USED']) : '',
            );
        }

        // камеры go2rtc: настройки и состояние (сколько потоков видно)
        foreach (array('GO2RTC_URL', 'GO2RTC_USER', 'GO2RTC_PASSWORD', 'GO2RTC_DEVICE_URL', 'GO2RTC_RTSP_PORT', 'GO2RTC_RTSP_USER',
            'GO2RTC_RTSP_PASSWORD', 'GO2RTC_WT_MINUTES', 'GO2RTC_WAIT') as $key) {
            $out[$key] = $h(isset($this->config[$key]) ? $this->config[$key] : '');
        }
        $out['GO2RTC_WEBTORRENT'] = !empty($this->config['GO2RTC_WEBTORRENT']) ? 1 : 0;
        $out['CAMERAS'] = array();
        if (messagesGo2rtcEnabled()) {
            $streams = messagesGo2rtcStreams();
            $out['GO2RTC_STATUS'] = $h(sprintf(messagesLang('MESSAGES_GO2RTC_STREAMS'), count($streams)));
            $out['GO2RTC_DEVICE_AUTO'] = $h(messagesGo2rtcDeviceBase());
            $access = messagesCameraAccess();
            $all_terms = SQLSelect("SELECT NAME, TITLE FROM terminals ORDER BY TITLE");
            $all_users = SQLSelect("SELECT ID, NAME, USERNAME FROM users ORDER BY NAME");
            foreach ($streams as $name) {
                $rule = isset($access[$name]) ? $access[$name] : array();
                $camera = array('NAME' => $h($name), 'KEY' => md5($name), 'ALL' => !empty($rule['all']) ? 1 : 0,
                    'TERMINALS' => array(), 'USERS' => array());
                foreach ($all_terms as $t) {
                    $camera['TERMINALS'][] = array('KEY' => $camera['KEY'], 'NAME' => $h($t['NAME']), 'TITLE' => $h($t['TITLE']),
                        'ON' => (!empty($rule['terminals']) && in_array($t['NAME'], (array)$rule['terminals'], true)) ? 1 : 0);
                }
                foreach ($all_users as $u) {
                    $camera['USERS'][] = array('KEY' => $camera['KEY'], 'ID' => (int)$u['ID'], 'TITLE' => $h($u['NAME'] != '' ? $u['NAME'] : $u['USERNAME']),
                        'ON' => (!empty($rule['users']) && in_array((int)$u['ID'], array_map('intval', (array)$rule['users']), true)) ? 1 : 0);
                }
                $out['CAMERAS'][] = $camera;
            }
        }
        $out['HAS_CAMERAS'] = count($out['CAMERAS']) ? 1 : 0;

        // ключ каждому пользователю (создаётся при первом показе)
        $out['USERS'] = array();
        foreach (SQLSelect("SELECT * FROM users ORDER BY NAME") as $user) {
            $token = messagesUserTokenGet($user['ID']);
            $row = messagesUserTokenRow($user['ID']);
            $out['USERS'][] = array(
                'ID' => (int)$user['ID'],
                'TITLE' => $h($user['NAME'] != '' ? $user['NAME'] : $user['USERNAME']),
                'USERNAME' => $h($user['USERNAME']),
                'HAS_PASSWORD' => messagesUserHasPassword($user) ? 1 : 0,
                'TOKEN' => $token,
                'CREATED' => !empty($row['CREATED']) ? date('d.m.Y H:i', (int)$row['CREATED']) : '',
                'LAST_USED' => !empty($row['LAST_USED']) ? date('d.m.Y H:i', (int)$row['LAST_USED']) : '',
            );
        }
    }

    // Терминал в настройках: ключ и ссылка (QR), «Только по ключу», сообщение на терминал, экран устройства
    // (было вкладками в карточке терминала)
    function adminTerminal($terminal, &$out, $h)
    {
        $id = (int)$terminal['ID'];
        $mode = (string)gr('terminal_mode');
        if ($mode == 'send') {
            $options = array(
                'from' => gr('send_from'),
                'image' => gr('send_image'),
                'sound' => gr('send_sound'),
                'voice' => gr('send_voice', 'int'),
            );
            if (gr('send_level') !== '') {
                $options['level'] = gr('send_level', 'int');
            }
            $out['SENT'] = messagesSend($terminal['NAME'], gr('send_message'), $options) ? 1 : 0;
            $out['SEND_FAILED'] = $out['SENT'] ? 0 : 1;
        } elseif ($mode == 'token_create') {
            messagesTokenCreate($id);
            $out['TOKEN_RECREATED'] = 1;
        } elseif ($mode == 'token_required') {
            messagesTokenSetRequired($id, gr('token_required', 'int'));
            $out['TOKEN_SAVED'] = 1;
        }
        $out['TERMINAL_ID'] = $id;
        $out['TERMINAL_TITLE'] = $h($terminal['TITLE']);
        $out['TOKEN'] = messagesTokenGet($id);
        $row = messagesTokenRow($id);
        $out['TOKEN_CREATED'] = !empty($row['CREATED']) ? date('d.m.Y H:i', (int)$row['CREATED']) : '';
        $out['TOKEN_LAST_USED'] = !empty($row['LAST_USED']) ? date('d.m.Y H:i', (int)$row['LAST_USED']) : '';
        $out['TOKEN_PAGE'] = ROOTHTML . 'popup/app_messages.html';
        $out['TOKEN_REQUIRED'] = !empty($row['REQUIRED']) ? 1 : 0;

        // экран устройства — если дополнение TTS умеет (screenState() / screen(): pipup)
        $tts = ($terminal['CANTTS'] && $terminal['TTS_TYPE'] != '') ? messagesAddon($terminal) : null;
        if (is_object($tts) && method_exists($tts, 'screenState') && method_exists($tts, 'screen')) {
            if ($mode == 'screen') {
                $ok = $tts->screen(gr('screen_state'));
                $out[$ok ? 'SCREEN_DONE' : 'SCREEN_FAILED'] = 1;
            }
            $screen = $tts->screenState(true);
            $out['SCREEN_SHOW'] = 1;
            $out['SCREEN_TEXT'] = $h(messagesScreenText($screen));
            $out['SCREEN_KNOWN'] = is_array($screen) ? 1 : 0;
            $out['SCREEN_CAN_SLEEP'] = (is_array($screen) && !empty($screen['can_sleep'])) ? 1 : 0;
        }

        $out['SEND_TERMINALS'] = array();
        foreach (SQLSelect("SELECT NAME, TITLE FROM terminals WHERE ID!=$id ORDER BY TITLE") as $t) {
            $out['SEND_TERMINALS'][] = array('NAME' => $h($t['NAME']), 'TITLE' => $h($t['TITLE']));
        }
        $out['SEND_SOUNDS'] = array();
        foreach ((array)@scandir(ROOT . 'cms/sounds') as $file) {
            if (preg_match('/\.(mp3|wav|ogg)$/i', $file)) {
                $out['SEND_SOUNDS'][] = array('FILE' => $h('cms/sounds/' . $file), 'TITLE' => $h($file));
            }
        }
    }

    function processSubscription($event, $details = '')
    {
        $details = is_array($details) ? $details : array();
        if ($event == 'TERMINAL_DELIVERED') {
            // «Терминалы» отдали сообщение устройству: свои метки (app_messages:ID) — «доставлено»
            $ids = messagesRefIds(isset($details['refs']) ? $details['refs'] : '');
            if (count($ids)) {
                messagesDelivered($ids);
            }
        } elseif ($event == 'TERMINAL_RENAMED') {
            if (!empty($details['old']) && !empty($details['new'])) {
                messagesTerminalRenamed((string)$details['old'], (string)$details['new'], isset($details['terminal_id']) ? (int)$details['terminal_id'] : 0);
            }
        } elseif ($event == 'TERMINAL_DELETED') {
            if (!empty($details['terminal_id'])) {
                SQLExec("DELETE FROM messages_tokens WHERE TERMINAL_ID=" . (int)$details['terminal_id']);
            }
        } elseif ($event == 'MINUTELY') {
            // ссылки внешнего просмотра камер (WebTorrent), срок которых вышел
            messagesGo2rtcSharesCleanup();
            // переписка: чистка раз в сутки
            $this->getConfig();
            if (!isset($this->config['CLEANUP_DAY']) || $this->config['CLEANUP_DAY'] != date('Y-m-d')) {
                $this->config['CLEANUP_DAY'] = date('Y-m-d');
                $this->saveConfig();
                messagesCleanup(messagesHistoryDays($this->config));
            }
        }
    }

    function install($parent_name = '')
    {
        foreach (array('MINUTELY', 'TERMINAL_DELIVERED', 'TERMINAL_RENAMED', 'TERMINAL_DELETED') as $event) {
            subscribeToEvent($this->name, $event);
        }
        parent::install($parent_name);
        // настройки из прежних «Терминалов» — когда запись модуля уже есть (конфигурация хранится в ней)
        $this->migrateSettings();
    }

    function uninstall()
    {
        foreach (array('MINUTELY', 'TERMINAL_DELIVERED', 'TERMINAL_RENAMED', 'TERMINAL_DELETED') as $event) {
            unsubscribeFromEvent($this->name, $event);
        }
        SQLDropTable('messages_history');
        SQLDropTable('messages_groups');
        SQLDropTable('messages_colors');
        SQLDropTable('messages_tokens');
        parent::uninstall();
    }

    function dbInstall($data = '')
    {
        $data = <<<EOD
 messages_history: ID int(10) unsigned NOT NULL auto_increment
 messages_history: FROM_NAME varchar(255) NOT NULL DEFAULT ''
 messages_history: TO_NAME varchar(255) NOT NULL DEFAULT ''
 messages_history: MESSAGE text
 messages_history: IMAGE varchar(255) NOT NULL DEFAULT ''
 messages_history: SOUND varchar(255) NOT NULL DEFAULT ''
 messages_history: ADDED int(10) NOT NULL DEFAULT '0'
 messages_history: VIDEO varchar(255) NOT NULL DEFAULT ''
 messages_history: FILE varchar(255) NOT NULL DEFAULT ''
 messages_history: FILE_NAME varchar(255) NOT NULL DEFAULT ''
 messages_history: FORWARDED varchar(255) NOT NULL DEFAULT ''
 messages_history: CHAT varchar(50) NOT NULL DEFAULT ''
 messages_history: SEND_ID varchar(32) NOT NULL DEFAULT ''
 messages_history: STREAM varchar(100) NOT NULL DEFAULT ''
 messages_history: LINK varchar(32) NOT NULL DEFAULT ''
 messages_history: DELIVERED int(10) NOT NULL DEFAULT '0'
 messages_history: READ_AT int(10) NOT NULL DEFAULT '0'
 messages_history: FROM_ID int(10) NOT NULL DEFAULT '0'
 messages_history: TO_ID int(10) NOT NULL DEFAULT '0'
 messages_history: FROM_USER int(10) NOT NULL DEFAULT '0'
 messages_history: TO_USER int(10) NOT NULL DEFAULT '0'
 messages_history: HIDE_FROM int(3) NOT NULL DEFAULT '0'
 messages_history: HIDE_TO int(3) NOT NULL DEFAULT '0'
 messages_history: INDEX (TO_NAME)
 messages_history: INDEX (TO_ID)
 messages_history: INDEX (FROM_ID)
 messages_history: INDEX (TO_USER)

 messages_tokens: ID int(10) unsigned NOT NULL auto_increment
 messages_tokens: TERMINAL_ID int(10) NOT NULL DEFAULT '0'
 messages_tokens: USER_ID int(10) NOT NULL DEFAULT '0'
 messages_tokens: TOKEN varchar(64) NOT NULL DEFAULT ''
 messages_tokens: REQUIRED int(3) NOT NULL DEFAULT '0'
 messages_tokens: CREATED int(10) NOT NULL DEFAULT '0'
 messages_tokens: LAST_USED int(10) NOT NULL DEFAULT '0'
 messages_tokens: INDEX (TERMINAL_ID)
 messages_tokens: INDEX (USER_ID)
 messages_tokens: INDEX (TOKEN)

 messages_colors: ID int(10) unsigned NOT NULL auto_increment
 messages_colors: CHAT_KEY varchar(100) NOT NULL DEFAULT ''
 messages_colors: COLOR varchar(10) NOT NULL DEFAULT ''
 messages_colors: EMOJI varchar(100) NOT NULL DEFAULT ''

 messages_groups: ID int(10) unsigned NOT NULL auto_increment
 messages_groups: TITLE varchar(255) NOT NULL DEFAULT ''
 messages_groups: MEMBERS text
 messages_groups: NOTE text
 messages_groups: ADDED int(10) NOT NULL DEFAULT '0'
EOD;
        parent::dbInstall($data);
        $this->migrateTables();
        // ключ доступа к странице — каждому терминалу
        messagesTokenEnsureAll();
        // переписка узнаёт терминалы по ID (переименование не теряет историю): прежним записям — ID по имени
        SQLExec("UPDATE messages_history m JOIN terminals t ON t.NAME=m.TO_NAME SET m.TO_ID=t.ID WHERE m.TO_ID=0 AND m.TO_USER=0");
        SQLExec("UPDATE messages_history m JOIN terminals t ON t.NAME=m.FROM_NAME SET m.FROM_ID=t.ID WHERE m.FROM_ID=0 AND m.FROM_USER=0");
    }

    // Однократный перенос из полной версии «Терминалов» (ветка main majordomo-terminals): таблицы terminals_messages,
    // terminals_groups, terminals_colors, terminals_tokens — только если своя таблица пуста; прежние таблицы не удаляются
    // (удалить вручную после проверки)
    function migrateTables()
    {
        $tables = array('terminals_messages' => 'messages_history', 'terminals_groups' => 'messages_groups',
            'terminals_colors' => 'messages_colors', 'terminals_tokens' => 'messages_tokens');
        foreach ($tables as $old => $new) {
            if (!SQLSelectOne("SHOW TABLES LIKE '$old'")) {
                continue;
            }
            $has = SQLSelectOne("SELECT ID FROM `$new` LIMIT 1");
            if (!empty($has['ID'])) {
                continue;
            }
            $new_columns = array();
            foreach (SQLSelect("SHOW COLUMNS FROM `$new`") as $column) {
                $new_columns[] = $column['Field'];
            }
            $columns = array();
            foreach (SQLSelect("SHOW COLUMNS FROM `$old`") as $column) {
                if (in_array($column['Field'], $new_columns, true)) {
                    $columns[] = '`' . $column['Field'] . '`';
                }
            }
            if (count($columns)) {
                $list = implode(', ', $columns);
                SQLExec("INSERT INTO `$new` ($list) SELECT $list FROM `$old`");
                $count = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM `$new`");
                DebMes("Migrated $old -> $new: " . (int)$count['TOTAL'] . ' row(s)', 'app_messages');
            }
        }
    }

    // Настройки «Сообщений» и камер из конфигурации модуля terminals — то, чего ещё нет в своей (один раз)
    function migrateSettings()
    {
        $this->getConfig();
        if (empty($this->config['MIGRATED_FROM_TERMINALS'])) {
            $rec = SQLSelectOne("SELECT DATA FROM project_modules WHERE NAME='terminals'");
            $old = !empty($rec['DATA']) ? @unserialize($rec['DATA']) : array();
            $old = is_array($old) ? $old : array();
            $moved = array();
            foreach ($old as $key => $value) {
                if (preg_match('/^(MESSAGES_|GO2RTC_|CAMERA_ACCESS$)/', (string)$key) && !isset($this->config[$key])) {
                    $this->config[$key] = $value;
                    $moved[] = $key;
                }
            }
            $this->config['MIGRATED_FROM_TERMINALS'] = 1;
            $this->saveConfig();
            if (count($moved)) {
                DebMes('Migrated settings from terminals: ' . implode(', ', $moved), 'app_messages');
            }
        }
    }
}
