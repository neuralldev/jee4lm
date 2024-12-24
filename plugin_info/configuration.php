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
    <legend>{{Configuration connexion cloud La Marzocco}}</legend>
    <div class="form-group">
      <label class="col-sm-3 control-label">{{Connexion}}</label>
      <div class="col-sm-3">
        <a class="btn btn-default" id="bt_loginToLMCloud">{{Se connecter}}</a>
      </div>
    </div>
    <div class="form-group">
      <label class="col-lg-3 control-label">{{Détection}}</label>
      <div class="col-lg-4">
        <a class="btn btn-default" id="bt_syncWithLMCloud"><i class="fas fa-sync"></i> {{Détecter mes équipements}}</a>
      </div>
    </div>
    <div class="form-group">
        <label class="col-sm-3 control-label">{{Port clef bluetooth}}</label>
        <div class="col-sm-3">
            <select class="configKey form-control" data-l1key="port">
                <option value="none">{{Aucun}}</option>
                <?php
                  foreach (jeedom::getBluetoothMapping() as $name => $value) {
                    echo '<option value="' . $name . '">' . $name . ' (' . $value . ')</option>';
                  }
                ?>
           </select>
       </div>
   </div>
   <div class="form-group">
      <label class="col-lg-3 control-label">{{mDNS test}}</label>
      <div class="col-lg-4">
        <a class="btn btn-default" id="bt_tcpdetect"><i class="fas fa-sync"></i> {{Détecter les IP locales}}</a>
      </div>
    </div>

  </fieldset>
</form>

<script>
document.getElementById('bt_loginToLMCloud').addEventListener('click', function () {
  jeeDialog.dialog({
    id: 'jee_LMModal',
    title: '{{Connexion de Jeedom au Cloud La Marzocco}}',
    width: '85vw',
    height: '51vw',
    top: '8vh',
    contentUrl: 'index.php?v=d&modal=login&plugin=jee4lm'
  });
});

document.getElementById('bt_syncWithLMCloud').addEventListener('click', function () {
  domUtils.showLoading();
  fetch('plugins/jee4lm/core/ajax/jee4lm.ajax.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ action: 'sync' })
  })
  .then(response => response.json())
  .then(data => {
    if (data.state !== 'ok') {
      domUtils.hideLoading();
      jeedomUtils.showAlert({ message: data.result, level: 'danger' });
      return;
    }
    domUtils.hideLoading();
    jeedomUtils.showAlert({ message: '{{Détection réussie}}', level: 'success' });
  })
  .catch(error => handleAjaxError(null, null, error));
});

document.getElementById('bt_tcpdetect').addEventListener('click', function () {
  domUtils.ajax({
    type: "POST",
    url: "plugins/jee4lm/core/ajax/jee4lm.ajax.php",
    data: {
        action: "tcpdetect"
    },
    dataType: 'json',
    global: false,
    error: function(error) {
        jeedomUtils.showAlert({ message: error.message, level: 'danger' });
    },
    success: function(data) {
      jeedomUtils.showAlert({ message: '{{Détection réussie, regardez les logs}}', level: 'success' });
    }
  });
});

</script>
