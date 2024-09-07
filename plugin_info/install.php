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

function jee4lm_install()
{

/*  $cronLocal = cron::byClassAndFunction('jee4lm', 'pull');
  if (!is_object($cronLocal)) {
    $cronLocal = new cron();
    $cronLocal->setClass('jee4lm');
    $cronLocal->setFunction('pull');
    $cronLocal->setEnable(1);
    $cronLocal->setDeamon(0);
    $cronLocal->setSchedule('* * * * *');
    $cronLocal->setTimeout(1);
    $cronLocal->save();
  }

  if (config::byKey('configPull', 'jee4lm') == '')
    config::save('configPull', '1', 'jee4lm');
*/
}

function jee4lm_update()
{
/*
  $cronLocal = cron::byClassAndFunction('jee4lm', 'pull');
  if (!is_object($cronLocal)) {
    $cronLocal = new cron();
    $cronLocal->setClass('jee4lm');
    $cronLocal->setFunction('pull');
    $cronLocal->setEnable(1);
    $cronLocal->setDeamon(0);
    $cronLocal->setSchedule('* * * * *');
    $cronLocal->setTimeout(1);
    $cronLocal->save();
  }

  if (config::byKey('configPull', 'jee4lm') == '')
    config::save('configPull', '1', 'jee4lm');

*/
}

function jee4lm_remove()
{
/*
  $cron = cron::byClassAndFunction('jee4lm', 'pull');
  if (is_object($cron))
    $cron->remove();
  config::remove('configPull', 'jee4lm');
*/
}

