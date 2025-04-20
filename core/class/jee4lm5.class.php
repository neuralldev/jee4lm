<?php

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
//require_once dirname(__FILE__) . '/mdns.class.php';

const
  PLUGINNAME = 'jee4lm5',
  LMMODELCODE = ['LINEAMINI'],
  LMCLOUD = 'https://lion.lamarzocco.io/api/customer-app/',
 
  JEEDOM_DAEMON_PORT = '50044',
  JEEDOM_DAEMON_HOST = '192.168.1.113',
  TOKEN_TIME_TO_REFRESH = 4 * 60 * 60,  # 4 hours
  PENDING_COMMAND_TIMEOUT = 10;

  //LMBT_ADVERTISING = "_marzocco._tcp.local";

/* source api from HA
https://github.com/zweckj/pylamarzocco/tree/v5
*/

/**
 * jee4lm5 est la classe qui couvre les fonctions relatives au pilotage de la Linea Mini
 */
class jee4lm5 extends eqLogic
{

  /**
   * build path to rest api to local machine or remote web site depending on prensence of ip address
   * @param mixed $_serial
   * @param mixed $_ip
   * @return mixed
   */
  public static function getPath($_serial) {
    return LMCLOUD. 'things/' . $_serial;
  }

  /**
   * sends a request to the REST API formatting request for GET or POST as expected by La Marzocco
   * data is used only for POST and must be URL encoded / formatted as a string parm1=val1&parm2=val2...
   * an optional header can be sent as well, especially to set the OAuth2 token in the Bearer field
   * @param mixed $_path
   * @param mixed $_data
   * @param mixed $_type
   * @param mixed $_header
   * @param mixed $_serial
   * @return mixed
   */
  public static function request($_path, $_data = null, $_type = 'GET', $_header = null)
  {
    // Utiliser cURL ou une autre méthode pour appeler l'API de La Marzocco
   log::add(__CLASS__, 'debug', 'request query url='.$_path." with data=".$_data." and type=".$_type);
   
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $_path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $_header == null ? ["Content-Type: application/json"] : $_header);
    switch ($_type) {
      case "POST":
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $_data);
        break;
      case "PUT":
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($_data));
        break;
      default:
        break;
    }
    $response = curl_exec($ch);
    if (!$response) {
      log::add(__CLASS__, 'debug', 'request error, cannot fetch info');
      $error_msg = curl_error($ch);
      $err_no = curl_errno($ch);
      log::add(__CLASS__, 'debug', "request error no=$err_no message=$error_msg");
      if ($err_no != 0) {// connection problem 
        curl_close($ch);
        return null;
      }
    } 
    curl_close($ch);
    return json_decode($response, true);
  }

  /**
   * Login is the login API to get the token based on the credential from the Web/App 
   * if the login succeeds, it sets the fields with both the access_token and the refresh token for renewal
   * in the appropriate plugin global variables
   * @param mixed $_username
   * @param mixed $_password
   * @return mixed
   */
  public static function login($_username, $_password)
  {

    log::add(__CLASS__, 'debug', 'login start');
    if ($_username == '' || $_password == '') {
      log::add(__CLASS__, 'debug', 'login empty username or password');
      return '';
    }
    // login to LM cloud attempt to get the token 
    $data = self::request(
      LMCLOUD."auth/signin",
      '{"username": "'.$_username.'", "password": "'.$_password.'"}',
      'POST'
    );
    log::add(__CLASS__, 'debug', 'login ' . json_encode($data, true));
    cache::delete(PLUGINNAME.'::accessToken'); // for any login attempt, reset cache with token, as it will change
    config::save('refreshToken', '', PLUGINNAME);
    config::save('accessToken', '', PLUGINNAME);
    if ($data['accessToken'] != '') {
      config::save('refreshToken', $data['refreshToken'], PLUGINNAME);
      config::save('accessToken', $data['accessToken'], PLUGINNAME);
      config::save('userId', $_username, PLUGINNAME);
      config::save('userPwd', $_password, PLUGINNAME);
      cache::set(''.PLUGINNAME.'::access_token', $data['accessToken'], TOKEN_TIME_TO_REFRESH);
      log::add(__CLASS__, 'debug', 'login valid');
      return $data['accessToken'];
    }
    return '';
  }

  /**
   * Refresh the token by checking if it is expired, then asks for its renewal if necessary.
   * the new token is stored in the cache with the expiricy set as well to 300
   * @return mixed
   */
  public static function refreshToken()
  {
    $refresh = config::byKey('refreshToken', PLUGINNAME);
    $username = config::byKey('userId', PLUGINNAME);
    $password = config::byKey('userPwd', PLUGINNAME);
    config::save('refreshToken', '', PLUGINNAME);
    config::save('accessToken', '', PLUGINNAME);
    // try to detect the machines only if token succeeded
    log::add(__CLASS__, 'debug', 'refresh token=' . $refresh);

    if ($refresh == '') {
      log::add(__CLASS__, 'debug', 'refresh token empty, do login');
      self::login($username, $password);
      $refresh = config::byKey('refreshToken', PLUGINNAME);
      return $refresh;
    }
    $data = self::request(
      LMCLOUD."auth/refreshtoken",
        '{"username": "'.$username.'", "refreshToken": "'.$refresh.'"}',
      'POST'
    );
    log::add(__CLASS__, 'debug', 'tokenrequest returned =' . json_encode($data, true));
    cache::delete(''.PLUGINNAME.'::access_token');
    if ($data['access_token'] != '') {
      cache::set(''.PLUGINNAME.'::access_token', $data['access_token'], 300);
      config::save('refreshToken', $data['refresh_token'], PLUGINNAME);
      config::save('accessToken', $data['access_token'], PLUGINNAME);
      return $data['access_token'];
    }
    return '';
  }

  /**
   * getToken retrieve the current token stored in the cache. of the value has expired it calls
   * the refresh routine to renew it 
   * @param $_force boolean to force the token refresh
   * @return mixed
   */
  public static function getToken($_force=false)
  {
    $mc = cache::byKey(''.PLUGINNAME.'::access_token');
    $access_token = $mc->getValue();
 //   if (config::byKey('accessToken', PLUGINNAME) == '') // no login performed yet
 //     return '';
    if ($access_token == '' || $access_token == null || $_force) 
      $access_token = self::refreshToken();
    return $access_token;
  }

  public static function executeCommand($_serial, $_command, $_data='') {
    log::add(__CLASS__, 'debug', 'execute command serial='.$_serial.' command='.$_command.' data='.$_data);
    $token = self::getToken();
    if ($_command!='') {
        $data = self::request(
          jee4lm5::getpath($_serial).'/command/'.$_command,
            $_data, 
            'POST',
            ["Authorization: Bearer $token", "Content-Type: application/json"]);
        log::add(__CLASS__, 'debug', 'execute command response='.json_encode($data, true));
        return jee4lm5::waitCommmandExecution($_serial, $data[0]);
    }
    else 
      log::add(__CLASS__, 'debug', 'execute command cancelled, command empty');
  return '';    
  }


 public static  function waitCommmandExecution($_serial, $_data) {
    log::add(__CLASS__, 'debug', 'waitCommmandExecution serial='.$_serial. 'data='.json_encode($_data, true));
//    if ($_data==null) return true;
    $id = $_data['id'];
    if ($_data['status'] == 'Pending') {
      log::add(__CLASS__, 'debug', 'waitCommmandExecution command waiting for '.$id.' current status='.$_data['status']);
      $start = time();
      while (time() - $start < PENDING_COMMAND_TIMEOUT) {
        $data = self::request(jee4lm5::getpath($_serial).'/dashboard/');
        if ($data['runningCommand'] == null) {
          log::add(__CLASS__, 'debug', "waitCommmandExecution command $id no running command");
          return true;
        }
        if ($data['runningCommands'][$id]['status'] != 'Pending') {
          log::add(__CLASS__, 'debug', 'waitCommmandExecution command '.$id.' status='.$data['status']);
          return $data;
        }
        sleep(2);
      }
      log::add(__CLASS__, 'debug', "waitCommmandExecution command $id timeout");
      return false;
    }
    return true;
  }

  /**
   * la fonction CRON permet de mettre à jour les paramètres principaux toutes les minutes 
   * @return void
   */
  public static function cron()
  {
    log::add(__CLASS__, 'debug', 'cron start');

    // suspension des tests pendant une tranche horaire où la machine à café ne sera jamais utilisée.
    // cette section devra évoluer pour saisie de la tranche dans le plugin

    $heureActuelle = date('H');
    $minuteActuelle = date('i');

    // Tester si l'heure est entre 22h et 6h
    if ($heureActuelle >= 20 || $heureActuelle < 6) {
          log::add(__CLASS__, 'debug', 'cron exit out of hours ('.$heureActuelle.')');
      return;
    } else {
      log::add(__CLASS__, 'debug', 'cron in hours ('.$heureActuelle.')');
    }

    foreach (eqLogic::byType(__CLASS__, true) as $jee4lm) {
      // for each serial found, check the machine state
      if ($jee4lm->getConfiguration('serialNumber') != '') {
        $id=$jee4lm->getId();
        $m = cmd::byEqLogicIdAndLogicalId($id, 'machinemode');
        log::add(__CLASS__, 'debug', "cron eqID=$id state=".$m->execCmd());
        if (!self::RefreshLMDashboard($jee4lm, "cron")) // translate registers to jeedom values,           
          log::add(__CLASS__, 'debug', 'cron error on read/getconfiguration');
          if ($minuteActuelle % 5 == 0) { // every 10 minutes max
            $jee4lm->getThingSettings();
            $jee4lm->getThingSchedule(); 
          } 
      } else
        log::add(__CLASS__, 'debug', 'equipment has no serial number, cron skiped');
   } //foreach
  }

  /**
   * does nothing, here for backwards compatibiliy
   * @param mixed $_options
   * @return void
   */
  public static function pull($_options = null)
  {
  }

  /**
   * fonction nécessaire à jeedom pour nettoyer les commandes dans la fonction de remplacement 
   * @return array<mixed|string>[]
   */
  public static function deadCmd()
  {

    log::add(__CLASS__, 'debug', 'deadcmd start');
    $return = array();
    foreach (eqLogic::byType(__CLASS__) as $eql) {
      foreach ($eql->getCmd() as $cmd) {
        preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('serialNumber', ''), $matches);
        foreach ($matches[1] as $cmd_id) {
          if (!cmd::byId(str_replace('#', '', $cmd_id))) {
            $return[] = array('detail' => __(__CLASS__, __FILE__) . ' ' . $eql->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('Modèle', __FILE__), 'who' => "#" . $cmd_id . "#");
          }
        }
        preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('refreshToken', ''), $matches);
        foreach ($matches[1] as $cmd_id) {
          if (!cmd::byId(str_replace('#', '', $cmd_id))) {
            $return[] = array('detail' => __(__CLASS__, __FILE__) . ' ' . $eql->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('Modèle', __FILE__), 'who' => '#' . $cmd_id . '#');
          }
        }
        preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('accessToken', ''), $matches);
        foreach ($matches[1] as $cmd_id) {
          if (!cmd::byId(str_replace('#', '', $cmd_id))) {
            $return[] = array('detail' => __(__CLASS__, __FILE__) . ' ' . $eql->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('Modèle', __FILE__), 'who' => '#' . $cmd_id . '#');
          }
        }
        preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('userId', ''), $matches);
        foreach ($matches[1] as $cmd_id) {
          if (!cmd::byId(str_replace('#', '', $cmd_id))) {
            $return[] = array('detail' => __(__CLASS__, __FILE__) . ' ' . $eql->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('Modèle', __FILE__), 'who' => '#' . $cmd_id . '#');
          }
        }
        preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('userPwd', ''), $matches);
        foreach ($matches[1] as $cmd_id) {
          if (!cmd::byId(str_replace('#', '', $cmd_id))) {
            $return[] = array('detail' => __(__CLASS__, __FILE__) . ' ' . $eql->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('Modèle', __FILE__), 'who' => '#' . $cmd_id . '#');
          }
        }
      }
    }
    log::add(__CLASS__, 'debug', 'deadcmd end');
    return $return;
  }

  /**
   * used to set visible state (0=invisible/1=visible) of a jeedom equipment by logicalID
   * @param mixed $_logicalId
   * @param mixed $_state
   * @return bool
   */
  private function toggleVisible($_logicalId, $_state)
  {
    $Command = $this->getCmd(null, $_logicalId);
    if (is_object($Command)) {
      log::add(__CLASS__, 'debug', 'toggle visible state of ' . $_logicalId . " to " . $_state);
      $Command->setIsVisible($_state);
      $Command->save();
      return true;
    }
    return false;
  }
  /**
   * Refresh function from Jeedom to refresh all values
   * @return void
   */
  public function refresh()
  {
    foreach ($this->getCmd() as $cmd) {
      //      $s = print_r($cmd, 1);
//      log::add(__CLASS__, 'debug', 'refresh  cmd: ' . $s);
      $cmd->execute();
    }
  }

  /**
   * not used
   * @return void
   */
  public function preSave(): void
  {
   
  }

  /**
   * Reads and refresh all the values of an equipment previously created by detection routine
   * the function takes only the target equipment to refresh as argument
   * @param eqLogic $_eq
   * @param mixed $_poll 0 = regular call, 1 = switch on/off, 2 = called from callback, 3 = cron
   * @return bool
   */
  public static function RefreshLMDashboard($_eq, $_poll = '')
  {
//    log::add(__CLASS__, 'debug', 'refresh all information');
    $serial = $_eq->getConfiguration('serialNumber');
    $id = $_eq->getId();
    $uid = uniqid();

    $actual_state = $_eq->getCmd(null, 'machinemode')->execCmd();
    log::add(__CLASS__, 'debug', 'refresh all information id='.$id.' uid='.$uid.' dr='.$actual_state.' source='.$_poll);
    $ret = $_eq->getThingDashboardInformation(); // refresh
    $new_state = $_eq->getCmd(null, 'machinemode')->execCmd();
    switch (true) {
      case $new_state == 1:
        // going from off to on, run daemon
        if (self::deamon_info()['state'] == 'ok') {
          self::deamon_send(['id' => $id, 'lm' => 'check']); sleep(0.5);
          if($_eq->getConfiguration('daemon') != 1) {
            log::add(__CLASS__, 'debug', 'daemon start as machine is now on');
            self::deamon_send(['id' => $id, 'lm' => 'poll']);
          } else
            log::add(__CLASS__, 'debug', 'daemon already running');
        }
        break;
      case $new_state == 0:
        // going from on to off, stop daemon
        if (self::deamon_info()['state'] == 'ok') {
          if ($_eq->getConfiguration('daemon') != 0) {
            log::add(__CLASS__, 'debug', 'daemon stop as machine is now off');
            self::deamon_send(['id' => $id, 'lm' => 'stop']);
            $_eq->setConfiguration('daemon', 0);
            $_eq->save();
          }
        }
        break;
    }
    return $ret;
  }

  /**
   * Reads and create/refresh all the values from the internet web site of an equipment previously created by detection routine
   * the function takes only the target equipment to refresh as argument
   * @param eqLogic $_eq
   * @return bool
   */
  public static function CreateThing($_eq)
  {
    log::add(__CLASS__, 'debug', 'create configuration');
    $serial = $_eq->getConfiguration('serialNumber');
    $slug = $_eq->getConfiguration('type');
    $token = self::getToken();
    $data = self::request(LMCLOUD . 'things/' . $serial . '/dashboard', null, 'GET', ["Authorization: Bearer $token"]);
    log::add(__CLASS__, 'debug', 'config=' . json_encode($data, true));
    if ($data != '') {
      foreach ($data['widgets'] as $w) {
        switch ($w["code"]) {
          case "CMBrewByWeightDoses":
            log::add(__CLASS__, 'debug', 'brewbyweight');
            $_eq->AddCommand("BBW Présent", 'isbbw', 'info', 'binary', null, null, null, 0);
            $_eq->AddCommand("Balance connectée", 'isscaleconnected', 'info', 'binary', PLUGINNAME . "::bbw", null, null, 0);
            $_eq->AddCommand("BBW Etat", 'bbwmode', 'info', 'string', null, null, null, 0);
            $_eq->AddCommand("Continu", 'bbwfree', 'info', 'binary', PLUGINNAME . "::bbw nodose", null, null, 0, 'default', 'default', 'default', 'default', null, 0, false, null, null, null, 0);
            $_eq->AddCommand("Dose 1", 'bbwdoseA', 'info', 'numeric', PLUGINNAME . "::bbw dose inactive", "g", 0);
            $_eq->AddCommand("Dose 2", 'bbwdoseB', 'info', 'numeric', PLUGINNAME . "::bbw dose inactive", "g", 0);
            $_eq->AddAction("jee4lm_bbwA", "BBW Dose 1", "button", "", 1);
            $_eq->AddAction("jee4lm_bbwB", "BBW Dose 2", "button", "", 1);
            $_eq->AddAction("jee4lm_doseA_slider", "Régler Dose 1", "button", "", 1, "slider", 5, 100, 0.5);
            $_eq->AddAction("jee4lm_doseB_slider", "Régler Dose 2", "button", "", 1, "slider", 5, 100, 0.5);
            $_eq->linksetpoint("jee4lm_doseA_slider", "bbwdoseA");
            $_eq->linksetpoint("jee4lm_doseB_slider", "bbwdoseB");
            break;
          case "ThingScale":
            log::add(__CLASS__, 'debug', 'scale');
            $_eq->setConfiguration("scalename", $w["output"]["name"]);
            $_eq->AddCommand("Batterie Balance", 'scalebattery', 'info', 'numeric', null, "%", 'tile', 1, null, null, 'default', 'default', '0', '100');
            break;
          case "CMCoffeeBoiler":
            log::add(__CLASS__, 'debug', 'coffee');
            $_eq->AddCommand("Cafetière activée", 'coffeeenabled', 'info', 'binary', null, null, 'THERMOSTAT_STATE', 0);
            $_eq->AddCommand("Cafetière temperature cible", 'coffeetarget', 'info', 'numeric', null, '°C', 'THERMOSTAT_SETPOINT', 0);
            $_eq->AddCommand("Cafetière temperature actuelle", 'coffeecurrent', 'info', 'numeric', null, '°C', 'THERMOSTAT_TEMPERATURE', 0);
            $_eq->AddCommand("Chaudière café", 'displaycoffee', 'info', 'string', null, null, null, 1);
            log::add(__CLASS__, 'debug', 'coffee commands done');
            $_eq->AddAction("jee4lm_coffee_slider", "Régler consigne café", "button", "THERMOSTAT_SET_SETPOINT", 1, "slider", $w["output"]["targetTemperatureMin"], $w["output"]["targetTemperatureMax"], $w["output"]["targetTemperatureStep"]);
            break;
          case "CMSteamBoilerTemperature":
            log::add(__CLASS__, 'debug', 'steam');
            $_eq->AddCommand("Vapeur activée", 'steamenabled', 'info', 'binary', PLUGINNAME . "::steam", null, 'THERMOSTAT_STATE', 0);
            $_eq->AddCommand("Vapeur temperature cible", 'steamtarget', 'info', 'numeric', null, '°C', 'THERMOSTAT_SETPOINT', 0);
            $_eq->AddCommand("Vapeur température actuelle", 'steamcurrent', 'info', 'numeric', null, '°C', 'THERMOSTAT_TEMPERATURE', 0);
            $_eq->AddCommand("Chaudière Vapeur", 'displaysteam', 'info', 'string', null, null, null, 1);
            $_eq->AddAction("jee4lm_steam_slider", "Régler consigne vapeur", "button", "THERMOSTAT_SET_SETPOINT", 1, "slider", $w["output"]["targetTemperatureMin"], $w["output"]["targetTemperatureMax"], $w["output"]["targetTemperatureStep"]);
            break;
          case "CMPreExtraction":
            log::add(__CLASS__, 'debug', 'preinfusion');
            $_eq->AddAction("jee4lm_prewet_slider", "Régler consigne mouillage", "button", "THERMOSTAT_SET_SETPOINT", 1, "slider", $w["output"]["times"]["In"]["secondsMin"]["PreBrewing"], $w["output"]["times"]["In"]["secondsMax"]["PreBrewing"], $w["output"]["times"]["In"]["secondsStep"]["PreBrewing"]);
            $_eq->AddAction("jee4lm_prewet_time_slider", "Régler consigne pause mouillage", "button", "THERMOSTAT_SET_SETPOINT", 1, "slider", $w["output"]["times"]["Out"]["secondsMin"]["PreBrewing"], $w["output"]["times"]["Out"]["secondsMax"]["PreBrewing"], $w["output"]["times"]["Out"]["secondsStep"]["PreBrewing"]);
            $_eq->AddCommand("Préinfusion", 'preinfusionmode', 'info', 'binary', null, null, null, 1);
            $_eq->AddCommand("Prétrempage", 'prewet', 'info', 'binary', null, null, null, 1);
            $_eq->AddCommand("Prétrempage durée", 'prewettime', 'info', 'numeric', null, 's', 'THERMOSTAT_SETPOINT', 0);
            $_eq->AddCommand("Prétrempage pause", 'prewetholdtime', 'info', 'numeric', null, 's', 'THERMOSTAT_SETPOINT', 0);
            break;
        }
      } // foreach
      $_eq->AddCommand("Sur réseau d'eau", 'plumbedin', 'info', 'binary', null, null, null, 1);
      $_eq->AddCommand("Etat Backflush", 'backflush', 'info', 'binary', null, null, null, 1);
      $_eq->AddCommand("Dernier Backflush", 'last_backflush', 'info', 'string', PLUGINNAME . "::backflush", null, null, 0);
      $_eq->AddCommand("Réservoir plein", 'tankStatus', 'info', 'binary', PLUGINNAME . "::tankStatus", null, null, 1, 'default', 'default', 'default', 'default', null, 0, false, 0, null, null, 0);
      $_eq->AddCommand("Etat", 'machinemode', 'info', 'binary', PLUGINNAME . "::main", null, 'THERMOSTAT_STATE', 0);
      $_eq->AddCommand("Version Firmware", 'fwversion', 'info', 'string', null, null, null, 1);
      $_eq->AddCommand("Version Gateway", 'gwversion', 'info', 'string', null, null, null, 1);
      $_eq->AddCommand("Mode", 'hbmode', 'info', 'string', null, null, "THERMOSTAT_MODE", 0);
      $_eq->AddCommand("SmartWakeup", 'smartwakeup', 'info', 'binary',null, null, "ENERGY_STATE", 0);
      $_eq->AddCommand("SmartWakeup durée", 'smartwakeupstandbyminutes', 'info', 'numeric',null, null, null, 0);
      $_eq->AddCommand("SmartWakeup depuis", 'smartwakeupstandbyafter', 'info', 'string',PLUGINNAME . "::smartstandby", null, null, 1);

      /*
             $this->checkAndUpdateCmd('smartwakeup', 1);
        $this->checkAndUpdateCmd('smartwakeupstandbyminutes', $arr1["smartStandByMinutes"]);
        $this->checkAndUpdateCmd('smartwakeupstandbyafter', $arr1["smartStandByAfter"]);
 */

      $_eq->AddAction("jee4lm_test", "TEST", "", "button", 0);
      $_eq->AddAction("jee4lm_on", "heat", PLUGINNAME . "::main on off", "THERMOSTAT_MODE", 1);
      $_eq->AddAction("jee4lm_off", "off", PLUGINNAME . "::main on off", "THERMOSTAT_MODE", 1);
      $_eq->AddAction("jee4lm_auto", "Auto", PLUGINNAME . "::main on off", "THERMOSTAT_MODE", 0);
      $_eq->AddAction("jee4lm_steam_on", "Vapeur ON", PLUGINNAME . "::steam on off", "", 1);
      $_eq->AddAction("jee4lm_steam_off", "Vapeur OFF", PLUGINNAME . "::steam on off", "", 1);
      $_eq->AddAction("refresh", __('Rafraichir', __FILE__));
      $_eq->AddAction("start_backflush", "Démarrer backflush", PLUGINNAME . "::backflush on off");
      $_eq->AddAction("jee4lm_smartwakeup_on", "Réveil on","binarySwitch", "ENERGY_ON", 1);
      $_eq->AddAction("jee4lm_smartwakeup_off", "Réveil off", "binarySwitch", "ENERGY_OFF", 1);
      $_eq->AddAction("jee4lm_smartwakeupstandbyminutes_slider", "Régler durée", "button", null, 1, "slider", 0, 240, 10);
      $_eq->AddAction("jee4lm_smartwakeup_after_lastbrew", "Dernier café");
      $_eq->AddAction("jee4lm_smartwakeup_after_poweron", "Allumage");
      // add machine slug to display machine by type
      $_eq->AddCommand("Machine", 'machine', 'info', 'string', PLUGINNAME . "::machine", null, null, 1);
      $_eq->save();
      $_eq->linksetpoint("jee4lm_coffee_slider", "coffeetarget");
      $_eq->linksetpoint("jee4lm_steam_slider", "steamtarget");
      $_eq->linksetpoint("jee4lm_prewet_slider", "prewettime");
      $_eq->linksetpoint("jee4lm_prewet_time_slider", "preWetHoldTime");
      $_eq->linksetpoint("jee4lm_smartwakeupstandbyminutes_slider", "smartwakeupstandbyminutes");
      $_eq->linksetpoint("jee4lm_on", "machinemode");
      $_eq->linksetpoint("jee4lm_off", "machinemode");
      $_eq->linksetpoint("jee4lm_steam_on", "steamenabled");
      $_eq->linksetpoint("jee4lm_steam_off", "steamenabled");
      $_eq->linksetpoint("jee4lm_smartwakeup_on", "smartwakeup");
      $_eq->linksetpoint("jee4lm_smartwakeup_off", "smartwakeup");
    } //if

    return true;
  }


  /**
   * AddCommand function adds/update an information on an existing command inside an equipment
   * it allows to initialize a lot of optional paramters to display the command properly
   * @param mixed $_Name
   * @param mixed $_logicalId
   * @param mixed $_Type
   * @param mixed $_SubType
   * @param mixed $_Template
   * @param mixed $_unite
   * @param mixed $_generic_type
   * @param mixed $_IsVisible
   * @param mixed $_icon
   * @param mixed $_forceLineB
   * @param mixed $_valuemin
   * @param mixed $_valuemax
   * @param mixed $_order
   * @param mixed $_IsHistorized
   * @param mixed $_repeatevent
   * @param mixed $_iconname
   * @param mixed $_calculValueOffset
   * @param mixed $_historizeRound
   * @param mixed $_noiconname
   * @param mixed $_warning
   * @param mixed $_danger
   * @param mixed $_invert
   * @return mixed
   */
  
  public function AddCommand(
    $_Name,
    $_logicalId,
    $_Type = 'info',
    $_SubType = 'binary',
    $_Template = null,
    $_unite = null,
    $_generic_type = null,
    $_IsVisible = 1,
    $_icon = 'default',
    $_forceLineB = 'default',
    $_valuemin = 'default',
    $_valuemax = 'default',
    $_order = null,
    $_IsHistorized = 0,
    $_repeatevent = false,
    $_iconname = 0,
    $_calculValueOffset = null,
    $_historizeRound = null,
    $_noiconname = 0,
    $_warning = null,
    $_danger = null,
    $_invert = 0
  ) {
    $createCmd = true;
    log::add(__CLASS__, 'debug', 'add command ' . $_Name . ' logicalId=' . $_logicalId . ' type=' . $_Type . ' subtype=' . $_SubType);
    $Command = $this->getCmd('info', $_logicalId);
    if (!is_object($Command)) { // check if info is already defined, if yes avoid duplicating
      $Command = cmd::byEqLogicIdCmdName($this->getId(), $_logicalId);
      if (is_object($Command)) $createCmd = false;
       } else $createCmd=false;

    if ($createCmd) {
      // basic settings
      $Command = new jee4lm5Cmd();
      // $Command->setId(null);
      $Command->setLogicalId($_logicalId);
      $Command->setEqLogic_id($this->getId());
      $Command->setName($_Name);
      $Command->setType($_Type);
      $Command->setSubType($_SubType);
      $Command->save();
      log::add(__CLASS__, 'debug', 'add command create object ' . $_Name);
    }

    $Command->setIsVisible($_IsVisible);
    log::add(__CLASS__, 'debug', 'add command set visible ' . $_Name);
    if ($_IsHistorized != null)      $Command->setIsHistorized(strval($_IsHistorized));
    log::add(__CLASS__, 'debug', 'add command set historized ' . $_IsHistorized);
    if ($_Template != null) {
      $Command->setTemplate('dashboard', $_Template);
      $Command->setTemplate('mobile', $_Template);
      log::add(__CLASS__, 'debug', 'add command set template ' . $_Template);
    }
    if ($_unite != null && $_SubType == 'numeric')      $Command->setUnite($_unite);
    log::add(__CLASS__, 'debug', 'add command set unite ' . $_unite);
    if ($_icon != 'default')  $Command->setdisplay('icon', '<i class="' . $_icon . '"></i>');
    log::add(__CLASS__, 'debug', 'add command set icon ' . $_Name);
      if ($_forceLineB != 'default')      $Command->setdisplay('forceReturnLineBefore', 1);
    log::add(__CLASS__, 'debug', 'add command set forceLineB ' . $_icon);
    if ($_iconname != 'default')      $Command->setdisplay('showIconAndNamedashboard', 1);
    log::add(__CLASS__, 'debug', 'add command set iconname ' . $_Name);
    if ($_noiconname != null) {
      $Command->setdisplay('showIconAndNamedashboard', $_noiconname);
      $Command->setdisplay('showNameOndashboard', $_noiconname);
    }
    log::add(__CLASS__, 'debug', 'add command set noiconname ' . $_Name);
    if ($_calculValueOffset != null)      $Command->setConfiguration('calculValueOffset', $_calculValueOffset);
    log::add(__CLASS__, 'debug', 'add command set calculValueOffset ' . $_calculValueOffset);
    if ($_historizeRound != null)      $Command->setConfiguration('historizeRound', $_historizeRound);
    log::add(__CLASS__, 'debug', 'add command set historizeRound ' . $_historizeRound);
    if ($_generic_type != null)      $Command->setGeneric_type($_generic_type);
    log::add(__CLASS__, 'debug', 'add command set generic_type ' . $_generic_type);
    if ($_repeatevent == true && $_Type == 'info')      $Command->setConfiguration('repeatEventManagement', 'never');
    log::add(__CLASS__, 'debug', 'add command set repeatevent ' . $_Name);
    if ($_valuemin != 'default')      $Command->setConfiguration('minValue', $_valuemin);
    log::add(__CLASS__, 'debug', 'add command set valuemin ' . $_valuemin);
    if ($_valuemax != 'default')      $Command->setConfiguration('maxValue', $_valuemax);
    log::add(__CLASS__, 'debug', 'add command set valuemax ' . $_valuemax);
    if ($_warning != null)      $Command->setDisplay("warningif", $_warning);
    log::add(__CLASS__, 'debug', 'add command set warning ' . $_warning);
    if ($_order != null)      $Command->setOrder($_order);
    log::add(__CLASS__, 'debug', 'add command set order ' . $_order);
    if ($_danger != null)      $Command->setDisplay("dangerif", $_danger);
    log::add(__CLASS__, 'debug', 'add command set danger ' . $_danger);
    if ($_invert != null)      $Command->setDisplay('invertBinary', $_invert);
    log::add(__CLASS__, 'debug', 'add command set invert ' . $_invert);
    $Command->save();
    log::add(__CLASS__, 'debug', 'command saved');
    
    // log::add(__CLASS__, 'debug', ' addcommand end');
    return $Command;
  }

  /**
   * AddAction allows to add/update an action to an equipment using optional parameters
   * @param mixed $_actionName
   * @param mixed $_actionTitle
   * @param mixed $_template
   * @param mixed $_generic_type
   * @param mixed $_visible
   * @param mixed $_SubType
   * @param mixed $_min
   * @param mixed $_max
   * @param mixed $_step
   * @return void
   */
  public function AddAction($_actionName, $_actionTitle, $_template = null, $_generic_type = null, $_visible = 1, $_SubType = 'other', $_min = null, $_max = null, $_step = null)
  {
    log::add(__CLASS__, 'debug', ' add action ' . $_actionName);
    $createCmd = true;
    $command = $this->getCmd('action', $_actionName);
    if (!is_object($command)) { // check if action is already defined, if yes avoid duplicating
      $command = cmd::byEqLogicIdCmdName($this->getId(), $_actionTitle);
      if (is_object($command)) $createCmd = false;
    } else $createCmd = false;

    if ($createCmd)  // only if action is not yet defined
      {
        $command = new jee4lm5Cmd();
        $command->setLogicalId($_actionName);
        $command->setName($_actionTitle);
        $command->setType('action');
        $command->setSubType($_SubType);
        $command->setEqLogic_id($this->getId());
        $command->save();
      }
    $command->setIsVisible($_visible);
    if ($_template != null) {
      $command->setTemplate('dashboard', $_template);
      $command->setTemplate('mobile', $_template);
    }
    if ($_generic_type != null) $command->setGeneric_type($_generic_type);
    if ($_min != null) $command->setConfiguration('minValue', $_min);
    if ($_max != null) $command->setConfiguration('maxValue', $_max);
    if ($_step != null) $command->setDisplay('parameters', ['step' => $_step]);
    $command->save();
  }

  /**
   * this function is required for a slider to work.
   * it sets the target information value field to a slider command
   * $slider holds the logicalID of the slider
   * $setpointlogicalID holds the target info command
   * @param mixed $_slider
   * @param mixed $_setpointlogicalID
   * @return void
   */
  public function linksetpoint($_slider, $_setpointlogicalID)
  {
    $set_setpoint = $_slider!=null?cmd::byEqLogicIdAndLogicalId($this->getId(), $_slider):null;
    $setpoint = $_setpointlogicalID!=null?cmd::byEqLogicIdAndLogicalId($this->getId(), $_setpointlogicalID):null;
    if ($set_setpoint == null || $setpoint == null)
      log::add(__CLASS__, 'debug', "setpoint : command not found");
    else {
      // log::add(__CLASS__, 'debug', "setpoint : command found!");
      $set_setpoint->setValue($setpoint->getId());
      $set_setpoint->save();
      // log::add(__CLASS__, 'debug', "setpoint ID  stored");
    }
  }

  /**
   * this function is used to set the Boiler value on the LM machine according to the slider
   * it is called when the user change the value of the slider on the desktop with the chosen value
   * note that type is used to set the coffee or steam boiler target
   * @param mixed $_options
   * @param mixed $_logicalID
   * @param mixed $_type
   * @return void
   */
  public function set_setpoint($_options, $_logicalID, $_type)
  {
     log::add(__CLASS__, 'debug', 'set setpoint start');
    $v = $_options["slider"];
    log::add(__CLASS__, 'debug', 'slider value='.$v.' for '.' logicalID='.$_logicalID.' type='.$_type);
    //find setpoint value and store it on stove as it after slider move
    if ($v > 0)
      switch($_type) {
        case "BbwDose":
          // set dose for Brew by Weight doses A and B
          $this->CoffeeMachineBrewByWeightSettingDoses($v, $_logicalID);
          break;
        case "CoffeeBoiler":
          $this->CoffeeMachineSettingCoffeeBoilerTargetTemperature($v);
          break;
        case "SteamBoiler":
          // set coffee boiler temperature targer (does not work on steam boiler of linea mini)
          $this->CoffeeMachineSettingSteamBoilerTargetTemperature($v);
          break;
        case "PrewetIn":
          // read actual value for the other slider as both have to be sent together
          $d = cmd::byEqLogicIdAndLogicalId($this->getId(), 'prewetholdtime')->execCmd();
          $this->CoffeeMachinePreBrewingChangeTimes($v,$d);
          break;
        case "PrewetOut":
          // read actual value for the other slider as both have to be sent together
          $d = cmd::byEqLogicIdAndLogicalId($this->getId(), 'prewettime')->execCmd();
          $this->CoffeeMachinePreBrewingChangeTimes($d, $v);
          break;
        }
  }

  

  /**
   * Switch machine ON/OFF accoding to a boolean value
   * @param mixed $_toggle
   * @return void
   */
  public function CoffeeMachineChangeMode($_toggle)
  {
    log::add(__CLASS__, 'debug', 'set coffee boiler '.$_toggle ? 'ON' : 'OFF');
    $serial = $this->getConfiguration('serialNumber');
    $data = ["mode" => $_toggle ? "BrewingMode" : "StandBy"];
    self::executeCommand($serial, "CoffeeMachineChangeMode",json_encode($data));
    $this->checkAndUpdateCmd('hbmode', $_toggle ? 'heat' : 'off');
  }

  /**
   * Switch Steam ON/OFF according to a boolean value
   * @param mixed $_toggle
   * @return void
   */
  public function CoffeeMachineSettingSteamBoilerEnabled($_toggle)
  {
    log::add(__CLASS__, 'debug', 'switch steam boiler '. $_toggle ? 'ON' : 'OFF');
    $serial = $this->getConfiguration('serialNumber');
    $data = ["boilerIndex" => 1, "enabled" => $_toggle ? "BrewingMode" : "StandBy"];
    self::executeCommand($serial, "CoffeeMachineSettingSteamBoilerEnabled", json_encode($data));
  }


  /**
   * set the LM boiler target temperature for coffee or steam boiler according to $type value
   * @param mixed $_value value in celsius
   * @param mixed $_identifier by default this is coffee boiler temperature 
   * @return void
   */
  public function CoffeeMachineSettingCoffeeBoilerTargetTemperature($_value)
  {
    log::add(__CLASS__, 'debug', 'set coffee boiler target temperature to '.$_value);
    $serial = $this->getConfiguration('serialNumber');
    $data = ["boilerIndex" => 1, "targetTemperature" => $_value]; // coffee boiler
    self::executeCommand($serial, "CoffeeMachineSettingCoffeeBoilerTargetTemperature", json_encode($data)); 
  }  

    /**
   * set the LM boiler target temperature for coffee or steam boiler according to $type value
   * @param mixed $_value value in celsius
   * @param mixed $_identifier by default this is coffee boiler temperature 
   * @return void
   */
  public function CoffeeMachineSettingSteamBoilerTargetTemperature($_value)
  {
    log::add(__CLASS__, 'debug', 'set steam boiler target temperature to '.$_value);
    $serial = $this->getConfiguration('serialNumber');
    $data = ["boilerIndex" => 1, "targetLevel" => $_value]; // coffee boiler
    self::executeCommand($serial, "CoffeeMachineSettingSteamBoilerTargetTemperature", json_encode($data)); 
  }  


  /**
   * This API allow to select if the LM is plumbed In or not. If not, the by default if preinfusion
   * is enabled it is Prebrew that is performed with the parameters set (time/hold). If enabled
   * then a preinfusion using the water pressure line is used (in general 1 to 3 bars). 
   * the samle (time/hold) parameters apply. 
   * Do not activate this feature if no plumbed in line is installed!
   * @param mixed $_toggle true or false
   * @return void
   */
  public function CoffeeMachinePreBrewingChangeMode($_toggle)
  {
    log::add(__CLASS__, 'debug', 'enable/disable plumbed in ');
    $serial = $this->getConfiguration('serialNumber');
    $data= ["mode" => $_toggle ? "PreInfusion" : "PreBrewing"];
    self::executeCommand($serial, "CoffeeMachinePreBrewingChangeMode", json_encode($data));
  }

  /**
   * Set the Dose to use with Group on Brew By Weight on Linea Mini. On Mini, 
   * Dose 1 and 2 hold the two possible values offered by BBW. 
   * this API is not used on Micra.
   * @param mixed $weight
   * @param mixed $dose
   * @return void
   */
  public function CoffeeMachineBrewByWeightSettingDoses($_weight, $_dose)
  {
    log::add(__CLASS__, 'debug', "select active Dose");
    // fetch actual doses 
    $dose1= 0+$_dose=="Dose1" ? $_weight : cmd::byEqLogicIdAndLogicalId($this->getId(), 'bbwdoseA')->execCmd();
    $dose2= 0+$_dose=="Dose2" ? $_weight : cmd::byEqLogicIdAndLogicalId($this->getId(), 'bbwdoseB')->execCmd();

    $serial = $this->getConfiguration('serialNumber');
    $data=  ["doses" => ["Dose1" => $dose1, "Dose2" => $dose2]];
    self::executeCommand($serial, "CoffeeMachineBrewByWeightSettingDoses",json_encode($data));
  } 

  /**
   * Summary of CoffeeMachineBrewByWeightChangeMode
   * @param mixed $_dose
   * @return void
   */
  public function CoffeeMachineBrewByWeightChangeMode($_dose)
  {
    log::add(__CLASS__, 'debug', "set bbw mode to $_dose");
    $serial = $this->getConfiguration('serialNumber');
    $data=  ["mode" => $_dose];
    self::executeCommand($serial, "CoffeeMachineBrewByWeightChangeMode",json_encode($data));
  }  

  /**
   * Summary of CoffeeMachinePreBrewingChangeTimes
   * @param int $_time 
   * @param int $_hold
   * @return void
   */
  public function CoffeeMachinePreBrewingChangeTimes($_time, $_hold) {
    log::add(__CLASS__, 'debug', "set preinfusion start t=$_time h=$_hold");
    $serial = $this->getConfiguration('serialNumber');
    $data=  ["In" => ["seconds" => $_time], "Out" => ["seconds" => $_hold]];
    self::executeCommand($serial, "CoffeeMachinePreBrewingChangeTimes",json_encode($data));
  }

  /**
   * Start the Backflush. I recommend using the app for this purpose, it is much more convenient
   * as it monitors the backflush and this is not.
   * @return void
   */
  public function CoffeeMachineBackFlushStartCleaning()
  {
    log::add(__CLASS__, 'debug', 'backflush start');
    $serial = $this->getConfiguration('serialNumber');
    $data = ["enabled" => true];
    self::executeCommand($serial, "CoffeeMachineBackFlushStartCleaning", json_encode($data));
  }


  /**
   * set the auto-standby mode of the machine.
   * @param bool $_enable   true or false
   * @param int $_miutes    number of minutes before standby
   * @param mixed $_after   event after what time starts LAST_BREW = "LastBrewing", POWER_ON = "PowerOn";
   * @return void
   */
  public function CoffeeMachineSettingSmartStandBy($_enable, $_minutes, $_after)
  {
    $serial = $this->getConfiguration('serialNumber');
    $data = ["enabled" => $_enable, "minutes" => $_minutes, "after"=> $_after];
    self::executeCommand($serial, "CoffeeMachineSettingSmartStandBy", json_encode($data));
  }

  /**
   * Detect is the function used by the plugin configuration button to detect and create the equipments.
   * this function shall be used only when new equipments are available. it is not necessary to ru it at regular.
   * @return bool
   */
  public static function detect()
  {
    log::add(__CLASS__, 'debug', '[detect] start');
    $token = self::getToken();
    // try to detect the machines only if token succeeded
    if ($token == '') {
      log::add(__CLASS__, 'debug', '[detect] login not done or token empty, exit');
      return false;
    }
    $data = self::request(LMCLOUD."things", null, 'GET', ["Authorization: Bearer $token"]);
    log::add(__CLASS__, 'debug', 'detect=' . json_encode($data, true));
    if ($data == '')
      return false;
    foreach ($data as $machines) {
      log::add(__CLASS__, 'debug', 'detect found ' . ($uuid = $machines['coffeeStation']['id']) . " " . $machines['name'] . '(' . $machines['modelcode'] . ') SN=' . $machines['serialNumber']);
      log::add(__CLASS__, 'debug', 'type=' . $machines['type']);
      if ($machines['type'] == 'CoffeeMachine') {
        $d = new DateTime;
        $d->createFromFormat('U.u', $machines['connectionDate']);
        //log::add(__CLASS__, 'debug', 'detect paired on ' . $d->format("d/m/y"));
        // now check if machine is already created as an eqlogic
        $eqLogic = eqLogic::byLogicalId($uuid, PLUGINNAME);
        if (!is_object($eqLogic)) {
          $eqLogic = new jee4lm5();
          $eqLogic->setEqType_name(PLUGINNAME);
          $eqLogic->setIsEnable(1);
          $eqLogic->setName($machines['name']);
          $eqLogic->setCategory('heating', 1);
          $eqLogic->setIsVisible(1);
          log::add(__CLASS__, 'debug', 'create eqlogif for uuid '.$uuid);
        } else
          log::add(__CLASS__, 'debug', $uuid.' uuid already exists, update only');
        $eqLogic->setConfiguration('type', $machines['type']);
        if ($d instanceof DateTime) {
            $eqLogic->setConfiguration('pairingDate', $d->format("d/m/Y H:i:s.v"));
        } else {
            log::add(__CLASS__, 'error', 'Invalid date format for pairingDate');
        }
        $eqLogic->setConfiguration('modelName', $machines['modelName']);
        $eqLogic->setConfiguration('modelCode', $machines['modelCode']);
        $eqLogic->setLogicalId($uuid);
        $eqLogic->setConfiguration('imageUrl', $machines['imageUrl']);
        // now store BBW information
        foreach($machines['coffeeStation']['accessories'] as $accessory) {
          if ($accessory['type'] == 'ScaleAcaiaLunar') {
            $eqLogic->setConfiguration('bbw', true);
            $eqLogic->setConfiguration('scaleName', $accessory['name']);
          }
        }
        // now get configuration of machine
        $eqLogic->setConfiguration('serialNumber', $machines['serialNumber']);
        $eqLogic->save();
        // create commands before setting display
        jee4lm5::CreateThing($eqLogic);
        // set display
        $display_map = [
          'scalebattery' => [1, 3],
          'machine' => [1, 2],
          'isscaleconnected' => [1, 3],
          'bbwdoseA' => [4, 1],
          'bbwdoseB' => [4, 2],
          'bbwfree' => [4, 3],
          'bbwmode' => [4, 3],
          'coffeeenabled' => [1, 1],
          'isbbw' => [1, 3],
          'coffeecurrent' => [3, 1],
          'coffeetarget' => [3, 1],
          'start_backflush' => [2, 1],
          'machinemode' => [1, 1],
          'backflush' => [1, 1],
          'last_backflush' => [1, 1],
          'jee4lm_off' => [2, 2],
          'jee4lm_on' => [2, 2],
          'groupDoseMode' => [1, 1],
          'preinfusionmode' => [5, 1],
          'groupDoseType' => [1, 1],
          'prewet' => [5, 1],
          'plumbedin' => [5, 2],
          'prewettime' => [5, 3],
          'prewetholdtime' => [5, 3],
          'jee4lm_doseA_slider' => [6, 3],
          'jee4lm_doseB_slider' => [6, 3],
          'jee4lm_coffee_slider' => [6, 1],
          'jee4lm_prewet_slider' => [6, 2],
          'jee4lm_prewet_time_slider' => [6, 2],
          'jee4lm_steam_slider' => [6,1],
          'tankStatus' => [1, 1],
          'jee4lm_steam_off' => [2, 3],
          'jee4lm_steam_on' => [2, 3],
          'steamcurrent' => [3,3],
          'steamtarget' => [3,3],
          'steamenabled' => [1, 1],
          'fwversion' => [7, 1],
          'gwversion' => [7, 3],
          'groupDoseMax' => [1, 1],
          'displaycoffee' => [3, 1],
          'displaysteam' => [3, 3],
          'jee4lm_bbwA' => [7, 2],
          'jee4lm_bbwB' => [7, 2],
          'smartwakeup' => [8, 1],
          'jee4lm_smartwakeup_on' => [8, 1],
          'jee4lm_smartwakeup_off' => [8, 1],
          'smartwakeupstandbyafter' => [8, 1],
          'smartwakeupstandbyafter_lastbrew' => [8, 2],
          'smartwakeupstandbyafter_poweron' => [8, 2],
          'smartwakeupstandbyminutes' => [8, 1],
          'smartwakeupstandbyminutes_slider' => [8, 1]
        ];

        $displayStuff = [
          "layout::dashboard::table::parameters" =>
            [
              "center" => "0",
              "styletable" => "background-image: url(/plugins/".PLUGINNAME."/core/config/img/bg_model_2.png);background-repeat: no-repeat; background-size: 100% 36%;",
              "styletd" => "",
              "style::td::1::1" => "font-size:larger;",
              "text::td::1::1" => "<br>Réservoir à eau<br>",
              "text::td::1::3" => "<br>Balance connectée<br>",
              "style::td::1::2" => "background-image: url(".$machines['imageUrl'].");background-repeat: no-repeat; background-size: 100% 100%;",
              //              "text::td::3::1"=>"Chaudière à café",
//              "text::td::3::3"=>"Chaudière à vapeur",
              "style::td::3::1" => "font-size:1.5em;height:3em;vertical-align:top;",
              "style::td::3::2" => "font-size:1.5em;height:3em;vertical-align:top;",
              "style::td::3::3" => "font-size:1.5em;height:3em;vertical-align:top;",
              "style::td::4::1" => "height:4em;vertical-align:middle;",
              "style::td::4::2" => "height:4em;vertical-align:middle;",
              "style::td::4::3" => "height:4em;vertical-align:middle;",
              "style::td::5::1" => "border-top:solid;border-bottom:solid;",
              "style::td::5::2" => "border-top:solid;border-bottom:solid;",
              "style::td::5::3" => "border-top:solid;border-bottom:solid;",
              "style::td::6::1" => "border-top:solid;border-bottom:solid;",
              "style::td::6::2" => "border-top:solid;border-bottom:solid;",
              "style::td::6::3" => "border-top:solid;border-bottom:solid;"
            ],
          "layout::dashboard" => "table",
          'layout::dashboard::table::nbLine' => '8',
          'layout::dashboard::table::nbColumn' => '3'
        ];

        foreach ($display_map as $key => $map) {
          $r = cmd::byEqLogicIdAndLogicalId($eqLogic->getId(), $key);
          //log::add(__CLASS__, 'debug', 'search '.$key. " in eqlogic ".$eqLogic->getId(). ($r ==null?' pas de retour':json_encode($r)));

          if ($r != null) {
            $displayStuff["layout::dashboard::table::cmd::" . $r->getId() . "::line"] = $map[0];
            $displayStuff["layout::dashboard::table::cmd::" . $r->getId() . "::column"] = $map[1];
            //log::add(__CLASS__, 'debug', 'add '.$key."=".$r->getId());
          }
        }

        foreach ($displayStuff as $key => $value)
          $eqLogic->setDisplay($key, $value);

        $eqLogic->save();
        log::add(__CLASS__, 'debug', 'eqlogic saved');
        // read information for the first time
        jee4lm5::RefreshLMDashboard($eqLogic, "detect");
      }
      log::add(__CLASS__, 'debug', 'loop to next machine');
    }
    log::add(__CLASS__, 'debug', 'end parsing');
    return true;
  }

  // add logic to monitor BBW presence based on declaration in the app
  public function searchForBBW()
  {
    return $this->getConfiguration('bbw');
  }

  /**
   * updateDisplay is used to update the display of a command
   */
  public function updateDisplay($_cmd, $_key, $_value) {
//    log::add(__CLASS__, 'debug', 'update display for '.$_cmd.'='.$_value);
    $cmd = $this->getCmd(null, $_cmd);
    if ($cmd != null) {
      $cmd->setDisplay($_key, $_value);
      $cmd->save();
    }
  }

  public function getThingSchedule() {
    $serial = $this->getConfiguration('serialNumber');
    $token = self::getToken();
    $arr1 = self::request($this->getPath($serial) . '/scheduling', '', 'GET', ["Authorization: Bearer $token"]);
    log::add(__CLASS__, 'debug', 'getinformation got feedback from schedule '.json_encode($arr1));
    if ($arr1 != null)
      if ($arr1["smartWakeUpSleepSupported"] == true) {
        log::add(__CLASS__, 'debug', 'getinformation smartWakeUpSleepSupported='.$arr1["smartWakeUpSleepSupported"]);
        $this->checkAndUpdateCmd('smartwakeup', 1);
        $this->checkAndUpdateCmd('smartwakeupstandbyminutes', $arr1["smartWakeUpSleep"]["smartStandByMinutes"]);
        $this->checkAndUpdateCmd('smartwakeupstandbyafter', $arr1["smartWakeUpSleep"]["smartStandByAfter"]);
      } else {
        log::add(__CLASS__, 'debug', 'getinformation smartWakeUpSleepSupported='.$arr1["smartWakeUpSleepSupported"]);
        // if not supported, set the command to 0
        $this->checkAndUpdateCmd('smartwakeup', 0);
        $this->checkAndUpdateCmd('smartwakeupstandbyminutes', 0);
        $this->checkAndUpdateCmd('smartwakeupstandbyafter', "");
      }   
  }

  
  public function getThingSettings()
  {
    $serial = $this->getConfiguration('serialNumber');
    $token = self::getToken();
    $arr1 = self::request($this->getPath($serial) . '/settings', '', 'GET', ["Authorization: Bearer $token"]);
    log::add(__CLASS__, 'debug', 'getinformation got feedback from settings '.json_encode($arr1));
    if ($arr1 != null) {
      $this->checkAndUpdateCmd('plumbedin',$arr1['isPlumbedIn']?1:0);
//        log::add(__CLASS__, 'debug', 'getinformation plumbed in=' . $arr1['isPlumbedIn']?1:0);
      foreach($arr1['actualFirmwares'] as $fw) {
//        log::add(__CLASS__, 'debug', 'getinformation firmware type=' . $fw['type'] . " version=" . $fw['buildVersion']);
        switch($fw['type']) {
          case 'Gateway':
            $this->checkAndUpdateCmd('gwversion',$fw['buildVersion']);
            break;
          case 'Machine':
            $this->checkAndUpdateCmd('fwversion',$fw['buildVersion']);
            break;
        }
      }
    }
  }
  /**
   * Refreshes the main counters and not all the information, this is mostly used when there is no
   * local ip defined and the machine is turned on. it mainly fetches the boiler temperature growth and on/off state
   * @return bool
   */
  public function getThingDashboardInformation()
  {
    log::add(__CLASS__, 'debug', 'getinformation start');
    $serial = $this->getConfiguration('serialNumber');
    $token = self::getToken();
    $arr = self::request($this->getPath($serial) . '/dashboard', '', 'GET', ["Authorization: Bearer $token"]);
    log::add(__CLASS__, 'debug', 'getinformation got feedback from dashboard '.json_encode($arr));
    if ($arr != null) {
      if ($arr["error"] == "Unauthorized") { // if credential is not set, try to login or abort
        $username = config::byKey('userId', PLUGINNAME);
        $password = config::byKey('userPwd', PLUGINNAME);    
        if (!$this->login($username, $password)) return false;
      } else
      // lire le constenu json équivalent à lineamin_dashboard.json
      foreach($arr['widgets'] as $w) { 
//        log::add(__CLASS__, 'debug', 'getinformation iteration on ' . json_encode($w));
        switch ($w["code"]) {
          case "CMMachineStatus":
            $cmdOn = $this->getCmd(null, 'jee4lm_on');
            $cmdOff = $this->getCmd(null, 'jee4lm_off');
            switch($w['output']['status']) {
              case "PoweredOn":
                $this->checkAndUpdateCmd('machinemode', 1);
                $this->checkAndUpdateCmd('hbmode', 'heat');
                $cmdOn->setIsVisible(0);
                $cmdOff->setIsVisible(1);
                break;
              case "BrewingMode":
                $this->checkAndUpdateCmd('hbmode', 'heat');
                $this->checkAndUpdateCmd('machinemode', 1);
                $cmdOn->setIsVisible(0);
                $cmdOff->setIsVisible(1);
                break;
              case "StandBy":
                $this->checkAndUpdateCmd('bbwfree',1);
                $this->checkAndUpdateCmd('machinemode', 0);
                $this->checkAndUpdateCmd('hbmode', 'off');
                $cmdOn->setIsVisible(1);
                $cmdOff->setIsVisible(0);
                break;
              default:
              log::add(__CLASS__, 'debug', 'getinformation machine status unknown');
                $this->checkAndUpdateCmd('machinemode', 0);
                $cmdOn->setIsVisible(1);
                $cmdOff->setIsVisible(0);
            }
            $cmdOn->save();
            $cmdOff->save();
            // now update display of temperature readdiness
            break;
          case "CMCoffeeBoiler":
            $this->checkAndUpdateCmd('coffeetarget',$w['output']['targetTemperature']);
            switch($w['output']['status']) {
              case "HeatingUp":
                $this->checkAndUpdateCmd('coffeecurrent',$w['output']['temperature']);
                $this->checkAndUpdateCmd('coffeeenabled',1);
                $d = $w['output']['readyStartTime'];
                $currentTimestamp = time();
                $differenceInMinutes = round((($d / 1000 - $currentTimestamp) / 60) * 2) / 2;
                $differenceInSeconds = 0;
                log::add(__CLASS__, 'debug', 'getinformation coffee ready in '.$differenceInMinutes.' minutes'. 'difference in seconds ='.$differenceInSeconds);
                if ($differenceInMinutes==0 && $differenceInSeconds==0) {
                  $displayDifference = '<span style="color:green"><br\>Prêt < 30s </span>';
                } else {
                  if($differenceInMinutes <= 1.5) {
                    $differenceInMinutes = 0;
                    $differenceInSeconds = $differenceInMinutes * 60;
                  }
                  $displayDifference = '<span style="color:green"><br\>Prêt dans '.($differenceInSeconds > 0 ? $differenceInSeconds.'s' : $differenceInMinutes.'min').'</span>';
                }
                $this->checkAndUpdateCmd('displaycoffee',$displayDifference);
                break;
              case "Ready":
                $this->checkAndUpdateCmd('coffeecurrent',$w['output']['temperature']);
                $this->checkAndUpdateCmd('coffeeenabled',1);
                $this->checkAndUpdateCmd('displaycoffee','<span style="color:green">Prêt</span>');
                break;
              default:
                $this->checkAndUpdateCmd('coffeecurrent',0);
                $this->checkAndUpdateCmd('coffeeenabled',0);
                $this->checkAndUpdateCmd('displaycoffee','<span style="color:red">Off</span>');
            }
            break;
          case "CMSteamBoilerTemperature":
        //   log::add(__CLASS__, 'debug', 'getinformation steam boiler temp=' . $w['output']['targetTemperature']);
            $this->checkAndUpdateCmd('steamstatus',$w['output']['status'] == 'On'?1:0);
            $this->checkAndUpdateCmd('steamtarget',$w['output']['targetTemperature']);
            $this->checkAndUpdateCmd('displaysteam',$w['output']['status'] == 'Off' ? 'OFF' : "<span style='color:green'>ON</span>");
            break;
          case "CMNoWater":
        //    log::add(__CLASS__, 'debug', 'getinformation tank status=' . $w['output']['allarm']);
            $this->checkAndUpdateCmd('tankStatus',$$w['output']['allarm']?1:0);
            break;
          case "CMBackFlush":
           //   log::add(__CLASS__, 'debug', 'getinformation backflush status=' . $w['output']['status']);
            $this->checkAndUpdateCmd('backflush',$w['output']['status'] == 'On' ? 1 : 0);
            $this->checkAndUpdateCmd('last_backflush',$w['output']['lastCleaningStartTime'] == 'On' ? 1 : 0);
            break;
          case "CMBrewByWeightDoses":
            //  log::add(__CLASS__, 'debug', 'getinformation bbw dose A=' . $w['output']['doses']['Dose1']['dose']. " bbw dose B=".$w['output']['doses']['Dose2']['dose']. " scale connected=".$w['output']['scaleConnected']);
            $this->checkAndUpdateCmd('bbwmode',$w['output']['mode']);
            $this->checkAndUpdateCmd('bbwdoseA',$w['output']['doses']['Dose1']['dose']);
            $this->checkAndUpdateCmd('bbwdoseB',$w['output']['doses']['Dose2']['dose']);
            $this->checkAndUpdateCmd('bbwfree',$w['output']['mode']=="Continuous");
            $this->updatedisplay('bbwdoseA', 'template', PLUGINNAME."::bbw dose".$w['output']['mode']=="Dose1"?"":" inactive");
            $this->updatedisplay('bbwdoseB', 'template', PLUGINNAME."::bbw dose".$w['output']['mode']=="Dose2"?"":" inactive");
            break; 
          case "CMPreExtraction":
            $this->checkAndUpdateCmd('prewettime',$w['output']['times']['In']['seconds']);
            $this->checkAndUpdateCmd('prewetholdtime',$w['output']['times']['Out']['seconds']);
            $this->checkAndUpdateCmd('preinfusionmode',$w['output']['mode']=="Preinfusion");
            $this->checkAndUpdateCmd('prewet',$w['output']['mode']=="PreBrewing");
            break;
          case "ThingScale":
            //log::add(__CLASS__, 'debug', 'getinformation scale battery=' . $w['output']['batteryLevel']);
            $this->checkAndUpdateCmd('isscaleconnected',$w['output']['connected']?1:0);
            if($w['output']['connected'] && $w['output']['batteryLevel']>0) // fetch battery only if scale is connected and battery is not null or zero else display last value
              $this->checkAndUpdateCmd('scalebattery',$w['output']['batteryLevel']);
            break;
        }
      } //for each
      
      return true;
    } //if
    return false;
  }

  /**
   * Required by jeedom plugin architecture, not used 
   * @return void
   */
  public function getjee4lm()
  {
    //    log::add(__CLASS__, 'debug', "getjee4lm");
    //$this->checkAndUpdateCmd(__CLASS__, "");
  }

  /**
   * Jeedom specific function to define inline widgets from the plugin
   * @return array[]
   */


  public static function templateWidget()
  {
    $r = ['action' => array('string' => array()), 'info' => array('string' => array())];

    $r['info']['numeric']['batterie'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# ==0',                 'state_light' => '<span style="font-size: 24px;color:red">-</span>', 'state_dark' => '<span style="font-size: 24px;color:red">-</span>'),
        array('operation' => '#value# > 0 && #value# <=10', 'state_light' => '<span style="font-size: 20px;color:red">#value#%</span>', 'state_dark' => '<span style="font-size: 20px;color:red">#value#%</span>'),
        array('operation' => '#value# > 10 && #value# <=70','state_light' => '<span style="font-size: 20px;color:orange">#value#%</span>', 'state_dark' => '<span style="font-size: 20px;color:orange">#value#%</span>'),
        array('operation' => '#value# > 70',                'state_light' => '<span style="font-size: 20px;color:green">#value#%</span>', 'state_dark' => '<span style="font-size: 20px;color:green">#value#%</span>')
      )
    );
    $r['info']['numeric']['temperature'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# == 0', 'state_light' => '<span style="font-size: 24px;color:gray">#value#</span><span style="font-size: 20px;color:black"> °C</span>', 'state_dark' => '<span style="font-size: 24px;color:gray">#value#</span><span style="font-size: 20px;color:white"> °C</span>'),
        array('operation' => '#value# >= 0', 'state_light' => '<span style="font-size: 20px;color:gray">#value#</span>', 'state_dark' => '<span style="font-size: 20px;color:lightgray">#value#</span>')
      )
    );
    $r['info']['numeric']['bbw dose'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# == 0', 'state_light' => 'N/A', 'state_dark' => 'N/A'),
        array(
          'operation' => '#value# >= 0',
          'state_light' => '<span style="display:inline-block;line-height:0px;border-radius:50%;font-size: 10px;background-color: gray;color:white;border-width:thick;border-color:red; border-style: solid;"><span style="display: inline-block;float:left;width:32px; padding-top: 50%;padding-bottom: 50%;margin-left: 8px; margin-right: 8px;">#value#g</span></span>',
          'state_dark' => '<span style="display:inline-block;line-height:0px;border-radius:50%;font-size: 12px;background-color: gray;color:white;border-width:thick;border-color:red; border-style: solid;"><span style="display: inline-block; float:left;width:32px;padding-top: 50%;padding-bottom: 50%;margin-left: 8px; margin-right: 8px;">#value#g</span></span>'
        )
      )
    );
    $r['info']['numeric']['bbw dose inactive'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# == 0', 'state_light' => 'N/A', 'state_dark' => 'N/A'),
        array(
          'operation' => '#value# >= 0',
          'state_light' => '<span style="display:inline-block;line-height:0px;border-radius:50%;font-size: 10px;background-color: gray;color:lightgray;border-width:thick;border-color:rgb(var(--panel-bg-color); border-style: solid;"><span style="display: inline-block; float:left;width:32px;padding-top: 50%;padding-bottom: 50%;margin-left: 8px; margin-right: 8px;">#value#g</span></span>',
          'state_dark' => '<span style="display:inline-block;line-height:0px;border-radius:50%;font-size: 12px;background-color: gray;color:lightgray;border-width:thick;border-color:lightgray; border-style: solid;"><span style="display: inline-block; float:left;width:32px;padding-top: 50%;padding-bottom: 50%;margin-left: 8px; margin-right: 8px;">#value#g</span></span>'
        )
      )
    );
    $r['info']['binary']['bbw nodose'] = array(
      'template' => 'tmplicon',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_icon_on_#' => "<span style='display:inline-block;line-height:0px;border-radius:50%;font-size: 8px;background-color: gray;color:white;border-width:thick;border-color:red; border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/nodose_on.png' width='58px' height='57px' ></span></span>",
        '#_icon_off_#' => "<span style='display:inline-block;line-height:0px;border-radius:50%;font-size: 8px;background-color: gray;color:white;border-width:thick;border-color:lightgray; border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/nodose_off.png' width='58px' height='57px' ></span></span>",
        "#_time_widget_#" => "0"
      )
    );
    $r['action']['other']['main on off'] = array(
      'template' => 'tmplimg',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_img_light_on_#' => "<span style='display: inline-block;margin-top:40px;line-height:0px;border-radius:50%;font-size: 8px;background-color: white;color:white;border-width:thick;border-color:white; border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/main_on.png' width='100px' height='100px' ></span></span>",
        '#_img_dark_on_#' => "<span style='display: inline-block;margin-top:40px;line-height:0px;border-radius:50%;font-size: 8px;background-color: white;color:white;border-width:thick;border-color:rgb(25,25,25); border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/".PLUGINNAME."core/config/img/main_on.png' width='100px' height='100px' ></span></span>",
        '#_img_light_off_#' => "<span style='display: inline-block;margin-top:40px;line-height:0px;border-radius:50%;font-size: 8px;background-color: white;color:white;border-width:thick;border-color:white; border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/main_off.png' width='100px' height='100px' ></span></span>",
        '#_img_dark_off_#' => "<span style='display: inline-block;margin-top:40px;line-height:0px;border-radius:50%;font-size: 8px;background-color: white;color:white;border-width:thick;border-color:rgb(25,25,25); border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/main_off.png' width='100px' height='100px' ></span></span>",
        "#_time_widget_#" => "0"
      )
    );
    $r['action']['other']['steam on off'] = array(
      'template' => 'tmplimg',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_img_light_on_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/steam_on.png' width='64' height='64'>",
        '#_img_dark_on_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/steam_on.png' width='64' height='64'>",
        '#_img_light_off_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/steam_off.png' width='64' height='64'>",
        '#_img_dark_off_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/steam_off.png' width='64' height='64'>",
        "#_time_widget_#" => "0"
      )
    );
    $r['action']['other']['backflush on off'] = array(
      'template' => 'tmplimg',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_img_light_on_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/backflush_on.png' width='64' height='64'>",
        '#_img_dark_on_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/backflush_on.png' width='64' height='64'>",
        '#_img_light_off_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/backflush_off.png' width='64' height='64'>",
        '#_img_dark_off_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/backflush_off.png' width='64' height='64'>",
        "#_time_widget_#" => "0"
      )
    );

    $r['info']['binary']['tankStatus'] = array(
      'template' => 'tmplicon',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_icon_on_#' => "<span style='color:red';font-size:1,5em;font-style:bold;'><br>Remplir<br><br></span><img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/reservoir.png' width='64' height='64'>",
        '#_icon_off_#' => "<span style='font-size:1,5em;font-style:bold;'><br>OK</span>",
        "#_time_widget_#" => "0"
      )
    );
    $r['info']['binary']['bbw'] = array(
      'template' => 'tmplicon',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_icon_on_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/bbw_on.png' width='64' height='64'>",
        '#_icon_off_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/bbw_off.png' width='64' height='64'>",
        "#_time_widget_#" => "0"
      )
    );
    $r['info']['binary']['main'] = array(
      'template' => 'tmplicon',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_icon_on_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/main_on.png' width='64' height='64'>",
        '#_icon_off_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/main_off.png' width='64' height='64'>",
        "#_time_widget_#" => "0"
      )
    );
    $r['info']['binary']['backflush'] = array(
      'template' => 'tmplicon',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_icon_on_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/backflush_on.png' width='64' height='64'>",
        '#_icon_off_#' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/backflush_off.png' width='64' height='64'>",
        "#_time_widget_#" => "0"
      )
    );
    $r['info']['string']['machine'] = array(
      'template' => 'tmplmultistate',
      'display' => array('icon' => 'null'),
      'replace' => array(
        "#_desktop_width_#" => "",
        "#_mobile_width_#" => "",
        "#_time_widget_#" => "0"
      ),
      'test' => array(
        array(
          'operation' => "#value# !=''",
          'state_light' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/#value#.png' width='256' height='256'>",
          'state_dark' => "<img class='img-responsive' src='/plugins/".PLUGINNAME."/core/config/img/#value#.png' width='256' height='256'>"
        )
      )
    );
    return $r;
  }

  /**
   * Returns plugin version
   * @return mixed
   */
  public static function getPluginVersion()
  {
    $pluginVersion = '0.0.0';
    try {
      if (!file_exists(dirname(__FILE__) . '/../../plugin_info/info.json')) {
        log::add(__CLASS__, 'warning', '[VERSION] fichier info.json manquant');
      }
      $data = json_decode(file_get_contents(dirname(__FILE__) . '/../../plugin_info/info.json'), true);
      if (!is_array($data)) {
        log::add(__CLASS__, 'warning', '[VERSION] Impossible de décoder le fichier info.json');
      }
      try {
        $pluginVersion = $data['pluginVersion'];
      } catch (\Exception $e) {
        log::add(__CLASS__, 'warning', '[VERSION] Impossible de récupérer la version du plugin');
      }
    } catch (\Exception $e) {
      log::add(__CLASS__, 'warning', '[VERSION] Get ERROR :: ' . $e->getMessage());
    }
    log::add(__CLASS__, 'info', '[VERSION] PluginVersion :: ' . $pluginVersion);
    return $pluginVersion;
  }

  /**
   * Summary of deamon_info
   * @return array
   */
  public static function deamon_info() {
    $return = [
      'log' => __CLASS__,
      'launchable' => 'ok',
      'state' => 'nok'
    ];
    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/'.PLUGINNAME.'d.pid';
    if (file_exists($pid_file)) {
      //log::add(__CLASS__, 'debug', 'deamon_info pid_file=' . $pid_file); 
      $pid = trim(file_get_contents($pid_file));
      if (@posix_getsid($pid)) {
        $return['state'] = 'ok';
      } else {
        shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
        //log::add(__CLASS__, 'debug', 'deamon_info rm pid=' . $pid_file);
      }
    }
    return $return;
  }

    private static function getPython3() {
      return  method_exists('system', 'getCmdPython3') ? 
        system::getCmdPython3(__CLASS__) : 'python3 ';
    }

  /**
   * start the demon when it is asked from GUI or when jeedom is started
   * there is no parameter to send as demon does not require custom information 
   * the demon is just a loop that calls the callback function every 5 secondes when it is activated
   * @throws \Exception
   * @return bool
   */
  public static function deamon_start() {
    self::deamon_stop();
    $deamon_info = self::deamon_info();
    if ($deamon_info['launchable'] != 'ok') {
        throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
    }

    // before running daemon, check if all cache values are cleared
    foreach (eqLogic::byType(__CLASS__, true) as $jee4lm) {
      cache::set(''.PLUGINNAME.'::laststate_'.$jee4lm->getId(),0);
    }
    log::add(__CLASS__, 'debug', 'network='.network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') );
    $path = realpath(dirname(__FILE__) . '/../../resources/'.PLUGINNAME.'d'); // répertoire du démon à modifier
    $cmd = self::getPython3() . " {$path}/".PLUGINNAME."d.py"; // nom du démon à modifier
    $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel(__CLASS__));
//    $cmd .= ' --sockethost ' . config::byKey('sockethost', __CLASS__, JEEDOM_DAEMON_HOST); // host par défaut à modifier
    $cmd .= ' --socketport ' . config::byKey('socketport', __CLASS__, JEEDOM_DAEMON_PORT); // port par défaut à modifier
    $cmd .= ' --callback ' . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/'.PLUGINNAME.'/core/php/'.PLUGINNAME.'d.php'; // chemin de la callback url à modifier (voir ci-dessous)
    $cmd .= ' --cycle ' . config::byKey('cycle', __CLASS__, 2);
    $cmd .= ' --apikey ' . jeedom::getApiKey(__CLASS__); // l'apikey pour authentifier les échanges suivants
    $cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/'.PLUGINNAME.'d.pid'; // et on précise le chemin vers le pid file (ne pas modifier)
    log::add(__CLASS__, 'info', 'Lancement démon:' . self::getPython3() . "{$path}/".PLUGINNAME."d.py");
    $result = exec($cmd . ' >> ' . log::getPathToLog(''.PLUGINNAME.'d') . ' 2>&1 &');     
    while ($i < 10) {
        $deamon_info = self::deamon_info();
        if ($deamon_info['state'] == 'ok') 
          break;
        sleep(1);
        $i++;
    }
    if ($i >= 10) {
        log::add(__CLASS__, 'error', __('Impossible de lancer le démon, vérifiez le log', __FILE__), 'unableStartDeamon');
        return false;
    }
    message::removeAll(__CLASS__, 'unableStartDeamon');
    return true;
  }

  /**
   * stop the demon at the time it is asked from the GUI or when jeedom is stopped/rebooted
   * @return void
   */
  public static function deamon_stop() {
    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/'.PLUGINNAME.'d.pid'; // ne pas modifier
  //  log::add(__CLASS__, 'debug', 'deamon_stop pid_file=' . $pid_file);
    if (file_exists($pid_file)) {
        $pid = intval(trim(file_get_contents($pid_file)));
        system::kill($pid);
      //  log::add(__CLASS__, 'debug', 'deamon_stop pid=' . $pid);
    }
    system::kill(''.PLUGINNAME.'d.py'); // nom du démon à modifier
    sleep(1);
    // before running daemon, check if all cache values are cleared
    foreach (eqLogic::byType(__CLASS__, true) as $jee4lm) {
      cache::set(''.PLUGINNAME.'::laststate_'.$jee4lm->getId(),0);
    }
    
  }
  /**
   * Send a payload to daemon running in background. 
   * message accepted are 'cmd=poll' or 'cmd=stop' with 'id=eqID' encapsulated as json array
   * demon start to query LM every 5 secondes for updating information on the local ip address when poll is selected
   * when cmd=stop is sent, the demon stops to ask status every 5 seconds to machine
   * example of string is json_encode(['cmd'=>'poll','id'=>1],true) to foll for eqlogic 1 status
   * status is fetched based on configuration ip address (host info) if valid
   * @param mixed $_params
   * @throws \Exception
   * @return void
   */
  public static function deamon_send($_params) {
    $deamon_info = self::deamon_info();
    if ($deamon_info['state'] != 'ok') 
        throw new Exception("send to daemon, daemon not started");    
    $_params['apikey'] = jeedom::getApiKey(__CLASS__);
    $payLoad = json_encode($_params);
    $socket = socket_create(AF_INET, SOCK_STREAM, 0);
    if (!$socket) {
      log::add(__CLASS__, 'error', 'send to daemon, error opening socket');
      return;
    } 
    if (!socket_connect($socket, '127.0.0.1', JEEDOM_DAEMON_PORT))
      log::add(__CLASS__, 'error', 'send to daemon, error connecting to daemon socket port');
    else 
      if (!socket_write($socket, $payLoad, strlen($payLoad)))
        log::add(__CLASS__, 'error', 'send to daemon, error writing payload on daemon socket port');
    socket_close($socket);
  }

  public static function backupExclude() {
    return [
        'resources/venv'
    ];
  }

  public function CoffeeMachineSettingSmartStandByAfterLastBrew($eq, $_options) {
    $b =  cmd::byEqLogicIdAndLogicalId($eq, 'smartwakeup')->execCmd();
    $from = cmd::byEqLogicIdAndLogicalId($eq, 'smartwakeupstandbyafter')->execCmd();
    $after = $_options;
    return $eq->CoffeeMachineSettingSmartStandBy($b,$from, $after);
  }
  public function CoffeeMachineSettingSmartStandByAfterPowerOn($eq, $_options) {
    $b =  cmd::byEqLogicIdAndLogicalId($eq, 'smartwakeup')->execCmd();
    $after = cmd::byEqLogicIdAndLogicalId($eq, 'smartwakeupstandbyminutes')->execCmd();
    $from = $_options;
    return $eq->CoffeeMachineSettingSmartStandBy($b,$from, $after);
  }


}

/**
 * Specific class for commands execution
 */
class jee4lm5Cmd extends cmd
{
  public function dontRemoveCmd()
  {
    return $this->getLogicalId() == 'refresh';
  }

  public function getLMValue($_logicalID, $_expected_value)
  {
    $r = cmd::byLogicalId($_logicalID);
    return is_object($r) && $r->execCmd() != $_expected_value;
  }

  /**
   * Loop of command execution where it switches the command to the right function
   * @param mixed $_options
   * @return bool
   */
  public function execute($_options = null)
  {
    $action = $this->getLogicalId();
    $eq = $this->getEqLogic();
    log::add(__CLASS__, 'debug', 'execute action ' . $action . ' with options=' . json_encode($_options));
    switch ($action) {
      case 'refresh':
        return jee4lm5::RefreshLMDashboard($eq);
      case 'start_backflush':
        $eq->CoffeeMachineBackFlushStartCleaning();
        return true;
      case 'getStatus':
        return $eq->getThingDashboardInformation();
      case 'jee4lm_on':
      case 'jee4lm_off':
        $b = $action == 'jee4lm_on';
        $eq->CoffeeMachineChangeMode($b);
        return jee4lm5::RefreshLMDashboard($eq, "post command");
      case 'jee4lm_steam_on':
      case 'jee4lm_steam_off':
        $b = $action == 'jee4lm_steam_on';
        $eq->CoffeeMachineSettingSteamBoilerEnabled($b);
        return jee4lm5::RefreshLMDashboard($eq, "post command");
      case 'jee4lm_smartwakeup_after_lastbrew':
        $eq->CoffeeMachineSettingSmartStandByAfterLastBrew($eq, $_options);
        return true;
      case 'jee4lm_smartwakeup__after_poweron':
        $eq->CoffeeMachineSettingSmartStandByAfterPowerOn($eq, $_options);
        return true;
      case 'jee4lm_smartwakeup_on':
      case 'jee4lm_smartwakeup_off':
        $b = $action == 'jee4lm_smartstandby_on';
        $from = cmd::byEqLogicIdAndLogicalId($eq, 'smartwakeupstandbyafter')->execCmd();
        $eq->CoffeeMachineSettingSmartStandBy($b, 
        cmd::byEqLogicIdAndLogicalId($eq, 'smartwakeupstandbyminutes')->execCmd(),
        $from);
        return true;
      case 'jee4lm_smartwakeupstandbyminutes_slider':
          return true;
      case 'jee4lm_coffee_slider':
        $eq->set_setpoint($_options, 'coffeetarget', "CoffeeBoiler");
        return jee4lm5::RefreshLMDashboard($eq, "post command");
      case 'jee4lm_steam_slider':
        $eq->set_setpoint($_options, 'steamtarget', "SteamBoiler");
        return jee4lm5::RefreshLMDashboard($eq, "post command");
      case 'jee4lm_doseA_slider':
        $eq->set_setpoint($_options, 'Dose1', "BbwDose");
        return jee4lm5::RefreshLMDashboard($eq, "post command");
      case 'jee4lm_doseB_slider':
        $eq->set_setpoint($_options, 'Dose2', "BbwDose");
        return jee4lm5::RefreshLMDashboard($eq, "post command");
      case 'jee4lm_prewet_slider':
        $eq->set_setpoint($_options, '', "PrewetIn");
        return jee4lm5::RefreshLMDashboard($eq, "post command");
      case 'jee4lm_prewet_time_slider':
        $eq->set_setpoint($_options, '', "PrewetOut");
        return jee4lm5::RefreshLMDashboard($eq, "post command");
        case 'jee4lm_bbwA':
        case 'jee4lm_bbwB':
            $eq->CoffeeMachineBrewByWeightChangeMode($_options=='jee4lm_bbwA'?'Dose1':'Dose2');
            return jee4lm5::RefreshLMDashboard($eq, "post command");          
      default:
        return true;
    }
  }

}


