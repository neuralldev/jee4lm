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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
  include_file('desktop', '404', 'php');
  die();
}
?>

<form class="form-horizontal">
  <fieldset>
    <div>
      <legend><i class="fas fa-info"></i> {{Plugin}}</legend>
      <div class="form-group">
        <label class="col-md-4 control-label">{{Version}}
          <sup><i class="fas fa-question-circle tooltips" title="{{Version du plugin à indiquer sur Community}}"></i></sup>
        </label>
        <div class="col-md-1">
          <input class="configKey form-control" data-l1key="pluginVersion" readonly />
        </div>
      </div>
      <legend><i class="fas fa-tasks"></i> {{Mises à jour Automatiques}} :</legend>
      <div class="form-group">
        <label class="col-md-4 control-label">{{Equipement Local (1 min)}}
          <sup><i class="fas fa-question-circle tooltips" title="{{Activer ou Désactiver les MàJ auto (toutes les minutes) de l'équipement local}}"></i></sup>
        </label>
        <div class="col-md-4">
          <input type="checkbox" class="configKey form-control" data-l1key="configPullL" />
        </div>
      </div>  
    </div>
  </fieldset>
</form>