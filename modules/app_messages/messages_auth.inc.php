<?php
/*
 * Ключ доступа терминала к странице «Сообщения» и к её API.
 *
 * У каждого терминала свой ключ (таблица messages_tokens). Он создаётся сам — при открытии карточки
 * терминала и при установке/обновлении модуля — и всегда виден в карточке (ссылка и QR-код): хранится
 * как есть, карточку видит только тот, кто вошёл в панель управления. На устройстве ключ открывают
 * ссылкой …/popup/app_messages.html?token=КЛЮЧ: страница запоминает его в cookie и дальше узнаёт
 * терминал по нему. Ключ важнее всего: с ним адрес устройства, сессия и ?terminal= не смотрятся.
 * «Только по ключу» у терминала (REQUIRED): без ключа (по адресу, сессии, ?terminal=, выбору на странице)
 * этот терминал не открывается — кроме входа из панели управления. У новых терминалов включено (в том
 * числе у созданных ядром по ?terminal=), у терминалов, которые были до появления ключей, — выключено.
 *
 * Ключ пользователя MajorDoMo (USER_ID, TERMINAL_ID=0) — вход в «Сообщения» от его имени, без терминала.
 * Ключи видны в настройках «Сообщений» (панель управления), открываются той же ссылкой ?token=… и
 * запоминаются в своей cookie tmmsg_user: на одном устройстве могут быть и терминал, и пользователь.
 * На сайт MajorDoMo ключ пользователя не входит. Без ключа пользователь попадает в «Сообщения» по
 * входу на сайт, только если у него есть пароль: без пароля ядро входит им само (пользователь по
 * умолчанию, по адресу устройства) — такой вход ничего не доказывает.
 */

// Запись ключа терминала (или пустой массив)
function messagesTokenRow($terminal_id)
{
    $row = SQLSelectOne("SELECT * FROM messages_tokens WHERE TERMINAL_ID=" . (int)$terminal_id);
    return !empty($row['ID']) ? $row : array();
}

// Новый случайный ключ: 40 шестнадцатеричных знаков
function messagesTokenNew()
{
    return function_exists('random_bytes') ? bin2hex(random_bytes(20)) : sha1(uniqid('', true) . mt_rand() . microtime());
}

// Создать ключ заново (прежний сразу перестаёт работать). Возвращает новый ключ.
// $required — «Только по ключу» для терминала, у которого ключа ещё не было (новый терминал — да)
function messagesTokenCreate($terminal_id, $required = 1)
{
    $row = messagesTokenRow($terminal_id);
    if (empty($row['ID'])) {
        $row = array('TERMINAL_ID' => (int)$terminal_id, 'REQUIRED' => $required ? 1 : 0);
    }
    $row['TOKEN'] = messagesTokenNew();
    $row['CREATED'] = time();
    $row['LAST_USED'] = 0;
    if (!empty($row['ID'])) {
        SQLUpdate('messages_tokens', $row);
    } else {
        SQLInsert('messages_tokens', $row);
    }
    return $row['TOKEN'];
}

// Ключ терминала; нет — создать
function messagesTokenGet($terminal_id)
{
    $row = messagesTokenRow($terminal_id);
    if (!empty($row['TOKEN'])) {
        return $row['TOKEN'];
    }
    return messagesTokenCreate($terminal_id);
}

// «Только по ключу»
function messagesTokenSetRequired($terminal_id, $required)
{
    messagesTokenGet($terminal_id); // запись с ключом есть всегда
    SQLExec("UPDATE messages_tokens SET REQUIRED=" . ($required ? 1 : 0) . " WHERE TERMINAL_ID=" . (int)$terminal_id);
}

// Терминал без записи ключа — новый (создан после установки модуля, например ядром по ?terminal=): только по ключу
function messagesTokenRequired($terminal_id)
{
    $row = messagesTokenRow($terminal_id);
    return empty($row['ID']) || !empty($row['REQUIRED']);
}

// Ключи всем терминалам, у которых их нет (установка и обновление модуля)
// Первая установка (ключей ещё нет ни у кого): у прежних терминалов «Только по ключу» выключено, как было;
// при обновлении терминалы без ключа — появившиеся после установки — получают его включённым
function messagesTokenEnsureAll()
{
    $any = SQLSelectOne("SELECT ID FROM messages_tokens LIMIT 1");
    $required = !empty($any['ID']) ? 1 : 0;
    $list = SQLSelect("SELECT t.ID FROM terminals t LEFT JOIN messages_tokens k ON k.TERMINAL_ID=t.ID WHERE k.TOKEN IS NULL OR k.TOKEN=''");
    foreach ((array)$list as $rec) {
        messagesTokenCreate($rec['ID'], $required);
    }
}

// Терминал по ключу (или пустой массив); отмечает, когда ключ использовался (не чаще раза в минуту)
function messagesTokenFind($token)
{
    $token = strtolower(trim((string)$token));
    if (!preg_match('/^[0-9a-f]{32,64}$/', $token)) {
        return array();
    }
    $row = SQLSelectOne("SELECT * FROM messages_tokens WHERE TOKEN='" . DBSafe($token) . "'");
    if (empty($row['ID'])) {
        return array();
    }
    $terminal_rec = getTerminalByID((int)$row['TERMINAL_ID']);
    if (empty($terminal_rec['ID'])) {
        return array();
    }
    if ((int)$row['LAST_USED'] < time() - 60) {
        SQLExec("UPDATE messages_tokens SET LAST_USED=" . time() . " WHERE ID=" . (int)$row['ID']);
    }
    return $terminal_rec;
}

// Ключ в cookie устройства: HttpOnly (скрипты страницы его не видят), надолго, на весь сайт.
// tmmsg_key — ключ терминала, tmmsg_user — ключ пользователя
function messagesTokenCookie($token, $name = 'tmmsg_key')
{
    $expire = ($token != '') ? time() + 10 * 365 * 24 * 3600 : time() - 3600;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off';
    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $token, array('expires' => $expire, 'path' => '/', 'httponly' => true, 'secure' => $secure, 'samesite' => 'Lax'));
    } else {
        setcookie($name, $token, $expire, '/; samesite=Lax', '', $secure, true);
    }
}

// Запись ключа пользователя (или пустой массив)
function messagesUserTokenRow($user_id)
{
    $row = SQLSelectOne("SELECT * FROM messages_tokens WHERE TERMINAL_ID=0 AND USER_ID=" . (int)$user_id);
    return (!empty($row['ID']) && (int)$user_id > 0) ? $row : array();
}

// Создать ключ пользователя заново (прежний сразу перестаёт работать). Возвращает новый ключ
function messagesUserTokenCreate($user_id)
{
    $row = messagesUserTokenRow($user_id);
    if (empty($row['ID'])) {
        $row = array('TERMINAL_ID' => 0, 'USER_ID' => (int)$user_id);
    }
    $row['TOKEN'] = messagesTokenNew();
    $row['CREATED'] = time();
    $row['LAST_USED'] = 0;
    if (!empty($row['ID'])) {
        SQLUpdate('messages_tokens', $row);
    } else {
        SQLInsert('messages_tokens', $row);
    }
    return $row['TOKEN'];
}

// Ключ пользователя; нет — создать
function messagesUserTokenGet($user_id)
{
    $row = messagesUserTokenRow($user_id);
    return !empty($row['TOKEN']) ? $row['TOKEN'] : messagesUserTokenCreate($user_id);
}

// Пользователь по ключу (или пустой массив); отмечает, когда ключ использовался (не чаще раза в минуту)
function messagesUserTokenFind($token)
{
    $token = strtolower(trim((string)$token));
    if (!preg_match('/^[0-9a-f]{32,64}$/', $token)) {
        return array();
    }
    $row = SQLSelectOne("SELECT * FROM messages_tokens WHERE TERMINAL_ID=0 AND USER_ID>0 AND TOKEN='" . DBSafe($token) . "'");
    if (empty($row['ID'])) {
        return array();
    }
    $user = SQLSelectOne("SELECT * FROM users WHERE ID=" . (int)$row['USER_ID']);
    if (empty($user['ID'])) {
        return array();
    }
    if ((int)$row['LAST_USED'] < time() - 60) {
        SQLExec("UPDATE messages_tokens SET LAST_USED=" . time() . " WHERE ID=" . (int)$row['ID']);
    }
    return $user;
}

// У пользователя есть пароль (как проверяет ядро: пустой пароль — пустая строка или sha512 от пустой строки)
function messagesUserHasPassword($user)
{
    $password = isset($user['PASSWORD']) ? (string)$user['PASSWORD'] : '';
    return $password != '' && $password != hash('sha512', '');
}
