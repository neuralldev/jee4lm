<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once dirname(__FILE__) . "/../../../../core/php/core.inc.php";

// Validate daemon API key
if (!jeedom::apiAccess(init('apikey'), 'jee4lm5')) {
    die();
}

log::add('jee4lm5', 'debug', 'callback incoming message');

// Connectivity test from daemon startup
if (init('test') != '') {
    log::add('jee4lm5', 'debug', 'callback ack');
    echo 'OK';
    die();
}

$result = json_decode(file_get_contents("php://input"), true);

if (!is_array($result)) {
    log::add('jee4lm5', 'error', 'callback: message is not a valid JSON array, got=' . print_r($result, true));
    die();
}

foreach ($result as $key => $value) {
    log::add('jee4lm5', 'debug', 'callback key=' . $key);
}

// Detect response — no eq id needed
if (isset($result['cmd']) && $result['cmd'] === 'detect') {
    log::add('jee4lm5', 'debug', 'callback: detect response received');
    jee4lm5::processdetect($result['things']);
    die();
}

// All other responses require an eq id
if (!isset($result['id'])) {
    log::add('jee4lm5', 'error', 'callback: id not set in message');
    die();
}

$eq = jee4lm5::byId($result['id']);
if ($eq === null || !is_object($eq)) {
    log::add('jee4lm5', 'warning', 'callback: eqlogic id=' . $result['id'] . ' not found');
    die();
}

log::add('jee4lm5', 'debug', 'callback: routing for eq=' . $result['id']);

if (isset($result['run'])) {
    $eq->setConfiguration('daemon', $result['run']);
    $eq->save();
    log::add('jee4lm5', 'debug', 'callback: daemon run flag=' . $result['run']);
} elseif (isset($result['settings'])) {
    jee4lm5::processthingSettings($eq, $result['settings']);
} elseif (isset($result['schedule'])) {
    jee4lm5::processthingSchedule($eq, $result['schedule']);
} elseif (isset($result['dash'])) {
    // Handles both one-shot 'dash' and polling loop 'dash_update' messages.
    // checkAndUpdateCmd() inside doRefreshDashboard emits cmd::update events
    // automatically when values change — Jeedom core notifies connected browsers.
    jee4lm5::doRefreshDashboard($eq, $result['dash']);
} else {
    log::add('jee4lm5', 'warning', 'callback: unhandled message keys=' . implode(',', array_keys($result)));
}

log::add('jee4lm5', 'debug', 'callback: done');