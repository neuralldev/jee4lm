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

require_once __DIR__ . '/../../../core/php/core.inc.php';

function jee4lm_install() {
  $cron = cron::byClassAndFunction('jee4lm', 'pull');
  if (!is_object($cron)) {
      $cron = new cron();
      $cron->setClass('jee4heat');
      $cron->setFunction('pull');
      $cron->setEnable(1);
      $cron->setDeamon(0);
      $cron->setSchedule('* 1 * * *');
      $cron->save();
  }
}

function jee4lm_update() {
  $cron = cron::byClassAndFunction('jee4lm', 'pull');
  if (!is_object($cron)) {
      $cron = new cron();
      $cron->setClass('jee4lm');
      $cron->setFunction('pull');
      $cron->setEnable(1);
      $cron->setDeamon(0);
      $cron->setSchedule('* 1 * * *');
      $cron->save();
  }
  $cron->stop();
}

function jee4lm_remove() {
  $cron = cron::byClassAndFunction('jee4lm', 'pull');
  if (is_object($cron)) {
      $cron->remove();
  }
}
