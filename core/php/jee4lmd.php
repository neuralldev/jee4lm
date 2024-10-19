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

if (!jeedom::apiAccess(init('apikey'), 'jee4lm')) {
	echo 'Clef API non valide, vous n\'etes pas autorisé à effectuer cette action';
	die();
}

log::add('jee4lm', 'debug', 'callback incoming message');

if (init('test') != '') {
	log::add('jee4lm', 'debug', 'callback ack');
	echo 'OK';
	die();
}
$result = json_decode(file_get_contents("php://input"), true);
if (!is_array($result)) {
	log::add('jee4lm', 'debug', 'callback incoming message not an array ='.$result);
	die();
}
if (!isset($result['id'])) {
	log::add('jee4lm', 'debug', 'callback id not set');
	die();
}
$eq = eqLogic::byId($result['id']);
if ($eq==null) {
	log::add('jee4lm', 'debug', 'callback eqlogic not found');
	die();
}
log::add('jee4lm', 'debug', 'refreshing...');

jee4lm::RefreshAllInformation($eq, 2);
