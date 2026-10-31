<?php
/*
 * Камеры на странице «Сообщения» — через модуль go2rtc (artt652/majordomo-go2rtc, константа GO2RTC_API).
 * Модуля нет или камеры в нём не настроены — чата «Камеры» нет, сообщения с камерой не отправляются.
 * Здесь — только обёртки над функциями модуля (их имена — от прежней версии «Сообщений»), с ответом «нет камер»,
 * если модуля нет.
 */

if (is_file(DIR_MODULES . 'go2rtc/go2rtc_lib.inc.php')) {
    include_once(DIR_MODULES . 'go2rtc/go2rtc_lib.inc.php');
}

// Камеры доступны: модуль go2rtc установлен и настроен
function messagesGo2rtcEnabled()
{
    return defined('GO2RTC_API') && function_exists('go2rtcEnabled') && go2rtcEnabled();
}

function messagesGo2rtcConfig()
{
    return messagesGo2rtcEnabled() ? go2rtcConfig()
        : array('url' => '', 'user' => '', 'password' => '', 'device_url' => '', 'webtorrent' => false, 'wait' => 6);
}

function messagesGo2rtcStreams()
{
    return messagesGo2rtcEnabled() ? go2rtcStreams() : array();
}

function messagesGo2rtcHas($name)
{
    return messagesGo2rtcEnabled() && go2rtcHas($name);
}

function messagesGo2rtcDeviceBase()
{
    return messagesGo2rtcEnabled() ? go2rtcDeviceBase() : '';
}

function messagesGo2rtcFrame($name, $width = 0)
{
    return messagesGo2rtcEnabled() ? go2rtcFrame($name, $width) : '';
}

function messagesGo2rtcDown($name)
{
    return messagesGo2rtcEnabled() && go2rtcDown($name);
}

// Снимок — в каталог вложений «Сообщений» (cms/cached/terminals): его чистит чистка переписки
function messagesGo2rtcSnapshot($name)
{
    return messagesGo2rtcEnabled() ? go2rtcSnapshot($name, 'cms/cached/terminals') : '';
}

function messagesGo2rtcShow($terminal_rec, $name, $duration = 60, $title = '', $level = 0)
{
    return messagesGo2rtcEnabled() && go2rtcShow($terminal_rec, $name, $duration, $title, $level);
}

function messagesGo2rtcWhepProxy($name)
{
    if (messagesGo2rtcEnabled()) {
        go2rtcWhepProxy($name);
    }
    header('HTTP/1.1 404 Not Found');
    exit;
}

function messagesGo2rtcShare($name, $minutes = 60)
{
    return messagesGo2rtcEnabled() ? go2rtcShare($name, $minutes) : array();
}

function messagesGo2rtcUnshare($name)
{
    return messagesGo2rtcEnabled() && go2rtcUnshare($name);
}

function messagesGo2rtcShares()
{
    return messagesGo2rtcEnabled() ? go2rtcShares() : array();
}

// Права на камеры (задаются в модуле go2rtc)
function messagesCameraAllowed($name, $terminal_name, $user_id, $is_admin)
{
    return messagesGo2rtcEnabled() && go2rtcAllowed($name, $terminal_name, $user_id, $is_admin);
}

function messagesCamerasFor($terminal_name, $user_id, $is_admin)
{
    return messagesGo2rtcEnabled() ? go2rtcCamerasFor($terminal_name, $user_id, $is_admin) : array();
}

// Временный доступ участникам чата и гостевые ссылки (хранит модуль go2rtc; «Сообщения» передают участников чата)
function messagesCamLinks()
{
    return messagesGo2rtcEnabled() ? go2rtcLinks() : array();
}

function messagesCamLinkGet($id)
{
    return messagesGo2rtcEnabled() ? go2rtcLinkGet($id) : array();
}

function messagesCamLinkCreate($cam, $kind, $minutes, $members, $by, $by_title)
{
    return messagesGo2rtcEnabled() ? go2rtcLinkCreate($cam, $kind, $minutes, $members, $by, $by_title) : '';
}

function messagesCamLinkClose($id)
{
    return messagesGo2rtcEnabled() && go2rtcLinkClose($id);
}

function messagesCamLinkCameras($me_keys)
{
    return messagesGo2rtcEnabled() ? go2rtcLinkCameras($me_keys) : array();
}

// Адрес гостевой страницы (модуль go2rtc: api.php/module/go2rtc/guest/<id>)
function messagesCamGuestUrl($id)
{
    return messagesGo2rtcEnabled() ? go2rtcGuestUrl($id) : '';
}
