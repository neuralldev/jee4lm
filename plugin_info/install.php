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

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
function jee4lm5_install()
{
}

function jee4lm5_update()
{
  // Clear the BATTERY generic type set on scalebattery by earlier versions:
  // HomeKit attaches it to the machine accessory and reports a low battery
  // whenever the scale is off.
  foreach (eqLogic::byType('jee4lm5') as $eq) {
    $cmd = $eq->getCmd('info', 'scalebattery');
    if (is_object($cmd) && $cmd->getGeneric_type() == 'BATTERY') {
      $cmd->setGeneric_type(null);
      $cmd->save();
      log::add('jee4lm5', 'info', 'update: type générique BATTERY retiré de scalebattery (eq ' . $eq->getId() . ')');
    }
  }
}

function jee4lm5_remove()
{
}

