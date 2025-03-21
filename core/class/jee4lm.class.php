<?php

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/mdns.class.php';

const
  LMCLIENT_ID = "7_1xwei9rtkuckso44ks4o8s0c0oc4swowo00wgw0ogsok84kosg",
  LMCLIENT_SECRET = "2mgjqpikbfuok8g4s44oo4gsw0ks44okk4kc4kkkko0c8soc8s",
  LMDEFAULT_PORT_LOCAL = 8081,
  LMMACHINE_TYPE = ['linea-mini', 'micra', 'gs3'],
  LMFILTER_MACHINE_TYPE = 'MACHINE_INSTANCE',
  LMCLOUD_TOKEN = 'https://cms.lamarzocco.io/oauth/v2/token',
  LMCLOUD_CUSTOMER = 'https://cms.lamarzocco.io/api/customer',
  LMCLOUD_GW_BASE_URL = "https://gw-lmz.lamarzocco.io/v1/home",
  LMCLOUD_GW_MACHINE_BASE_URL = "https://gw-lmz.lamarzocco.io/v1/home/machines",
  LMCLOUD_AWS_PROXY = "https://gw-lmz.lamarzocco.io/v1/home/aws-proxy",
  COFFEE_BOILER_1 = "CoffeeBoiler1",
  STEAM_BOILER = "SteamBoiler",
  BBWDOSE="MASSTYPE",
  PREWET_TIME="PREWET",
  PREWET_HOLD="PREWET_HOLD",
  JEEDOM_DAEMON_PORT = '50044',
  JEEDOM_DAEMON_HOST = '127.0.0.1',
  LMBT_ADVERTISING = "_marzocco._tcp.local";

/* source api from HA
https://github.com/zweckj/pylamarzocco/tree/main
*/

/**
 * jee4lm est la classe qui couvre les fonctions relatives au pilotage de la Linea Mini
 */
class jee4lm extends eqLogic
{

  /**
   * check that request is executed when it it a GET with commandID command
   * check if request has a commandId, then check if there is a PENDING/COMPLETED answer or not
   * if there is none, the request is done and was nt requiring a delay
   * @param mixed $_response
   * @param mixed $_serial
   * @param mixed $_header  
   * @return bool
   */
  public static function checkrequest($_response, $_serial = null, $_header = null)
  {
 //   log::add(__CLASS__, 'debug', 'check request');
    if ($_response == '') return true;
  //  log::add(__CLASS__, 'debug', 'check request not empty');
    $r = json_decode($_response, true);
    if ($r==null) return true;
    if (!array_key_exists('data', $r)) return true;    
    $arr = $r['data'];
    if (!array_key_exists('commandId', $arr)) return true;
    $commandID = $arr["commandId"];
    //    log::add(__CLASS__, 'debug', 'check request commandId='.$commandID);
    if ($commandID == '') return true;
    //      log::add(__CLASS__, 'debug', 'check request serial');
    // add serial
    if ($_serial == null) return true;
    //      log::add(__CLASS__, 'debug', 'loop');

    // if there is a commandID then wait for command to succeed   
    for ($i = 0; $i < 5; $i++) {
  //    log::add(__CLASS__, 'debug', 'check request attempt '.($i+1));
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, LMCLOUD_AWS_PROXY . "/" . $_serial . "/commands/" . $commandID);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_HTTPHEADER, $_header == null ? ["Content-Type: application/x-www-form-urlencoded"] : $_header);
      $response = curl_exec($ch);
      curl_close($ch);

      if ($response != '') {
        $arr = json_decode($response, true);
        switch ($arr['data']['status']) {
          case "COMPLETED":
            return true;
          case "PENDING":
            break;
          default:
            break;
        }
      }
      sleep(3);
    }
    return false;
  }

  /**
   * build path to rest api to local machine or remote web site depending on prensence of ip address
   * @param mixed $_serial
   * @param mixed $_ip
   * @return mixed
   */
  public function getPath($_serial, $_ip='') {
    return $_ip == '' ? LMCLOUD_GW_MACHINE_BASE_URL. '/' . $_serial : "http://".$_ip.":".LMDEFAULT_PORT_LOCAL."/api/v1";
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
   * @return array
   */
  public static function request($_path, $_data = null, $_type = 'GET', $_header = null, $_serial = null)
  {
    // Utiliser cURL ou une autre méthode pour appeler l'API de La Marzocco
   log::add(__CLASS__, 'debug', 'request query url='.$_path." with date=".$_data." and type=".$_type);
   
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $_path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $_header == null ? ["Content-Type: application/x-www-form-urlencoded"] : $_header);
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
      if ($err_no == 7) {// connection problem 
        // log::add(__CLASS__, 'debug', "disabling ON/OFF as no connection to machine");
        $err =[ 'status' => false, 'error'=>$err_no];
        curl_close($ch);
        return $err;
      }
    } // else
 //     log::add(__CLASS__, 'debug', "request response ok with ".$response); //.$response);
    curl_close($ch);
 //   log::add(__CLASS__, 'debug', 'request stop');
    if ($_serial !='') jee4lm::checkrequest($response, $_serial, $_header);
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

    if ($_username == '' || $_password == '') {
      log::add(__CLASS__, 'debug', 'login empty username or password');
      return '';
    }
    // login to LM cloud attempt to get the token 
    $data = self::request(
      LMCLOUD_TOKEN,
      'username=' . $_username .
      '&password=' . $_password .
      '&grant_type=password' .
      '&client_id=' . LMCLIENT_ID .
      '&client_secret=' . LMCLIENT_SECRET,
      'POST'
    );
    log::add(__CLASS__, 'debug', 'login ' . json_encode($data, true));
    cache::delete('jee4lm::access_token'); // for any login attempt, reset cache with token, as it will change
    config::save('refreshToken', '', 'jee4lm');
    config::save('accessToken', '', 'jee4lm');
    if ($data['access_token'] != '') {
      config::save('refreshToken', $data['refresh_token'], 'jee4lm');
      config::save('accessToken', $data['access_token'], 'jee4lm');
      config::save('userId', $_username, 'jee4lm');
      config::save('userPwd', $_password, 'jee4lm');
      cache::set('jee4lm::access_token', $data['access_token'], 300);
      log::add(__CLASS__, 'debug', 'login valid');
      return $data['access_token'];
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
    $refresh = config::byKey('refreshToken', 'jee4lm');
  //  $_username = config::byKey('userId', 'jee4lm');
  //  $_password = config::byKey('userPwd', 'jee4lm');
    config::save('refreshToken', '', 'jee4lm');
    config::save('accessToken', '', 'jee4lm');
    // try to detect the machines only if token succeeded
    log::add(__CLASS__, 'debug', 'refresh token');
    $data = self::request(
      LMCLOUD_TOKEN,
      'grant_type=refresh_token' .
      '&refresh_token=' . $refresh .
      '&client_id=' . LMCLIENT_ID .
      '&client_secret=' . LMCLIENT_SECRET,
      'POST'
    );
    //    log::add(__CLASS__, 'debug', 'tokenrequest=' . json_encode($data, true));
    cache::delete('jee4lm::access_token');
    if ($data['access_token'] != '') {
      cache::set('jee4lm::access_token', $data['access_token'], 300);
      config::save('refreshToken', $data['refresh_token'], 'jee4lm');
      config::save('accessToken', $data['access_token'], 'jee4lm');
      return $data['access_token'];
    }
    return '';
  }

  /**
   * getToken retrieve the current token stored in the cache. of the value has expired it calls
   * the refresh routine to renew it 
   * @param $_local jee4lm
   * @return mixed
   */
  public static function getToken($_local = null)
  {
    if ($_local != null)
      if ($_local->getConfiguration('host', '') !='') // if set to local communication do not use the web token mechanism and just take communicationkey
        return $_local->getConfiguration('communicationKey', '');
    $mc = cache::byKey('jee4lm::access_token');
    $access_token = $mc->getValue();
    if (config::byKey('accessToken', 'jee4lm') == '') // no login performed yet
      return '';
    if ($access_token == '')
      $access_token = self::refreshToken();
    return $access_token;
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
    if ($heureActuelle >= 22 || $heureActuelle < 6) {
//      log::add(__CLASS__, 'debug', 'cron exit out of hours ('.$heureActuelle.')');
      return;
    } else {
//      log::add(__CLASS__, 'debug', 'cron in hours ('.$heureActuelle.')');
    }
    foreach (eqLogic::byType(__CLASS__, true) as $jee4lm) {
      $mc = cache::byKey('jee4lm::laststate_'.$jee4lm->getId());
      if ($mc==null)
        $ls = 0;
      else
        $ls = $mc->getValue();
        if (($serial = $jee4lm->getConfiguration('serialNumber')) != '') {
          /* lire les infos de l'équipement ici */
          $slug = $jee4lm->getConfiguration('type');
          $id = $jee4lm->getId();
          $state = 0 + cmd::byEqLogicIdAndLogicalId($id, 'machinemode')->execCmd();
          $ip = $jee4lm->getConfiguration('host');
          log::add(__CLASS__, 'debug', "cron ID=$id serial=$serial slug=$slug state=$state host=$ip");
          if ($slug != '') { // if there is a type of machine defined 
            if ($state == 0) { // if machine is off, refresh information only every 5 minutes if on the web, every minute if local
              if ($minuteActuelle % 5 && $ip == '') {
                log::add(__CLASS__, 'debug', 'cron exit machine is off');
                return;
              }
            }
            
            if ($ls ==1) // if daemon is running no need to refresh, exit
              {
                log::add(__CLASS__, 'debug', 'cron exit as daemon has taken over');
                return;
              }
            $token = self::getToken($jee4lm); // send query for token and refresh it if necessary
            if ($token != '')
              if ($ip!='')
                $error = !self::RefreshAllInformation($jee4lm, 3); // translate registers to jeedom values, return true if successful             
              else
                if ($state == 0) // just scan status, all information will be refreshed only if up
                  $error = !$jee4lm->getInformations(); // translate registers to jeedom values, return true if successful
                else {
                  $error = !self::RefreshAllInformation($jee4lm, 3); // translate registers to jeedom values, return true if successful             
                  if ($ip=='') $error |= !$jee4lm->getInformations(); // translate registers to jeedom values, return true if successful
                }
            if ($error)
              log::add(__CLASS__, 'debug', 'cron error on read/getconfiguration');
          }
        } else
          log::add(__CLASS__, 'debug', 'equipment has no serial number, cron skiped');
      }
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
    /*
      log::add(__CLASS__, 'debug', 'presave start');
      $ip = $this->getConfiguration('host','');
      $token=$this->getConfiguration('communicationKey','');
      if ($ip !='' && $token!='') {
        log::add(__CLASS__, 'debug', 'check machine at ip '.$ip);
        $data = self::request(
         "http://".$ip.":".LMDEFAULT_PORT_LOCAL."/api/v1/config","", '', ["Authorization: Bearer $token"]);
        if ($data != null) 
          if ($data['status_code'] != 403) { // check that we have information returned
            log::add(__CLASS__, 'debug', "machine answer=".json_encode($data));
            return true;
          } else { // clear ip field as there is no anwser
            $ip = $this->setConfiguration('host','');
            log::add(__CLASS__, 'debug', "error with request");
            return false;
          }
        else {
          $ip = $this->setConfiguration('host','');
          log::add(__CLASS__, 'debug', "error with request");
          return false;
        }
      }
        */      
  }

  /**
   * Reads and refresh all the values of an equipment previously created by detection routine
   * the function takes only the target equipment to refresh as argument
   * @param eqLogic $_eq
   * @param numeric $_poll 0 = regular call, 1 = switch on/off, 2 = called from callback, 3 = cron
   * @return bool
   */
  public static function RefreshAllInformation($_eq, $_poll = 0)
  {
//    log::add(__CLASS__, 'debug', 'refresh all information');
    $serial = $_eq->getConfiguration('serialNumber');
    $ip = $_eq->getConfiguration('host');
    $id = $_eq->getId();
    $uid = uniqid();
    $localcall = $ip !='' ? 'local' : 'remote';
  //  log::add(__CLASS__, 'debug', "refresh $uid $localcall serial=$serial id=$id ip=$ip poll=$_poll");
    $token = self::getToken($_eq);
    $data = self::request(
        $_eq->getPath($serial, $ip). ($ip == '' ? '/configuration' : "/config"),
      null, 
      'GET', 
      ["Authorization: Bearer $token"]);
    //log::add(__CLASS__, 'debug', "refresh called got data ".json_encode($data));
    // check if local or remote config info is fetched
    if ($data && ($ip || $data['status'])) { // check that we have information returned
      log::add(__CLASS__, 'debug', "refresh $uid parse info");
      $machine = $data['data'] ?? $data;
      // $machine = $ip!=''?$data:$data['data']; // structure of returned information is the same but not at same level
      $bbw = $machine['recipes'][0];
      $bbwset = $machine['recipeAssignment'][0];
      $g = $machine['groupCapabilities'][0];
      $reglage = $g['doses'][0];
      $boilers = $machine['boilers'];
      $preinfusion = $machine['preinfusionSettings'];
      $fw = $machine['firmwareVersions'];

      $mc = cache::byKey('jee4lm::laststate_'.$id);
      $ls = $mc==null ? 0: $mc->getValue(); //previous state
      $ns = $machine['machineMode'] == "StandBy" ? 0 : 1; // next state (go to state)
      $_eq->checkAndUpdateCmd('hbmode',$ns ? 'off' : 'heat');

      switch ($_poll) { // select action based on source of call
        case 0: // called direct
          log::add(__CLASS__, 'debug', "refresh $uid ls=$ls ns=$ns from direct call");
          if ($ls != $ns)  // if there is a state change, this is switch off as demon is running when on
            cache::set('jee4lm::laststate_'.$id,$ns);
          break; // called from refresh all info 
        case 1: // on manual action toggle daemon
          log::add(__CLASS__, 'debug', "refresh $uid ls=$ls ns=$ns from switch on/off action");
         // if ($ls != $ns) // if there is a state change, supprimé car souci si le cache est planté
            cache::set('jee4lm::laststate_'.$id,$ns);
          if (self::deamon_info()['state'] == 'ok') 
              self::deamon_send(['id' => $id, 'lm'=> $ns ?'poll':'stop']);
          if ($ns == 1) // if switched on, exit as demon will refresh all info
            return true;
          break;
        case 2 : // called from callback as refreshing value
          log::add(__CLASS__, 'debug', "refresh $uid ls=$ls ns=$ns from callback call");
          if ($ls != $ns || $ns==1) { // if there is a state change, this is switch off as demon is running when on
            cache::set('jee4lm::laststate_'.$id,0);
            if (self::deamon_info()['state'] == 'ok') 
              self::deamon_send(['id' => $id, 'lm'=> 'stop']);
          }
          break; // refresh all info 
        case 3 : // called from cron
          log::add(__CLASS__, 'debug', "refresh $uid ls=$ls ns=$ns from cron");
          if ($ls != $ns) { // if there is a state change, this is switch off as demon is running when on
            cache::set('jee4lm::laststate_'.$id,$ns);
            if (self::deamon_info()['state'] == 'ok') 
              self::deamon_send(['id' => $id, 'lm'=> $ns ?'poll':'stop']);
            if ($ns==1) // on switch on, cancel read as demon will take over, else refresh
              return true;
          }
          break; // refresh all info 
      }
      $_eq->checkAndUpdateCmd('plumbedin', $machine['isPlumbedIn']);
      $_eq->checkAndUpdateCmd('backflush',$machine['isBackFlushEnabled']);
      $_eq->checkAndUpdateCmd('tankStatus',!$machine['tankStatus']);
      $_eq->checkAndUpdateCmd('bbwmode',$bbwset['recipe_dose']);
      $_eq->checkAndUpdateCmd('bbwfree',!$machine['scale']['connected'] || ($machine['scale']['connected'] && $bbwset['recipe_dose'] != 'A' && $bbwset['recipe_dose'] != 'B'));
      $_eq->checkAndUpdateCmd('bbwdoseA',$bbw['recipe_doses'][0]['target']);
      $_eq->checkAndUpdateCmd('bbwdoseB',$bbw['recipe_doses'][1]['target']);
      $_eq->checkAndUpdateCmd('groupDoseMode',$reglage['doseIndex']);
      $_eq->checkAndUpdateCmd('groupDoseType',$reglage['doseType']);
      $_eq->checkAndUpdateCmd('groupDoseMax',$reglage['stopTarget']);
      $_eq->checkAndUpdateCmd('machinemode',$machine['machineMode'] == "StandBy" ? false : true);
      $_eq->checkAndUpdateCmd('hbmode',$machine['machineMode'] == "StandBy" ? 'off' : 'heat');
      
      $_eq->checkAndUpdateCmd('isbbw',$machine['scale']['address'] == '' ? false : true);
      $_eq->checkAndUpdateCmd('isscaleconnected',$machine['scale']['connected']);
      $_eq->checkAndUpdateCmd( 'scalebattery',$machine['scale']['battery']);
      foreach ($boilers as $boiler) {
        if ($boiler['id'] == STEAM_BOILER) {
          $_eq->checkAndUpdateCmd('steamenabled',$boiler['isEnabled']);
          $_eq->checkAndUpdateCmd('steamtarget',$boiler['target']);
          $_eq->checkAndUpdateCmd('steamcurrent',$boiler['current']); 
          $_eq->checkAndUpdateCmd( 'displaysteam',$boiler['isEnabled'] ?"ON":"OFF"); 
        }
        if ($boiler['id'] == COFFEE_BOILER_1) {
          $_eq->checkAndUpdateCmd('coffeeenabled',$boiler['isEnabled']);
          $_eq->checkAndUpdateCmd('coffeetarget',$boiler['target']);
          $_eq->checkAndUpdateCmd('coffeecurrent',$boiler['current']); 
          $_eq->checkAndUpdateCmd('displaycoffee',$machine['machineMode']=="StandBy" ? $boiler['current']." °C":"<span style='color:".($boiler['current']+2>=$boiler['target']?'green':'red').";'>".$boiler['target']."°C / ".$boiler['current']."°C</span>"); 
          //log::add(__CLASS__, 'debug', $boiler['current']." ".$boiler['target']);
        }
      }
      $_eq->checkAndUpdateCmd('preinfusionmode',$preinfusion['mode'] == 'Enabled');
      $_eq->checkAndUpdateCmd('prewet',$preinfusion['Group1'][0]['preWetTime'] > 0) && ($preinfusion['Group1'][0]['preWetHoldTime'] > 0) && (!$machine['isPlumbedIn']);
      $_eq->checkAndUpdateCmd('prewettime',$preinfusion['Group1'][0]['preWetTime']);
      $_eq->checkAndUpdateCmd('prewetholdtime',$preinfusion['Group1'][0]['preWetHoldTime']);
      $_eq->checkAndUpdateCmd('fwversion',$fw[0]['fw_version']);
      $_eq->checkAndUpdateCmd('gwversion',$fw[1]['fw_version']);

      // if we come from callback make an additional call to detect bbw info
      if ($_poll == 2)
        return $_eq->getInformations();
      else
        return true;
    
    // as machine is not reachable, hide on/off button

  }
  if ($data['status'] == false && $data['error'] == 7) { // if machine is not reachable, disable on/off button and change display
    log::add(__CLASS__, 'debug', 'machine not reachable section code');
    $_eq->checkAndUpdateCmd('displaycoffee','OFF');
    $_eq->checkAndUpdateCmd('machinemode',false);
    $_eq->checkAndUpdateCmd('tankStatus',false);
    cache::set('jee4lm::laststate_'.$id,0);

  }
  return false;
  }

  /**
   * Reads and create/refresh all the values from the internet web site of an equipment previously created by detection routine
   * the function takes only the target equipment to refresh as argument
   * @param eqLogic $_eq
   * @return bool
   */
  public static function CreateConfiguration($_eq)
  {
    log::add(__CLASS__, 'debug', 'read configuration');
    $serial = $_eq->getConfiguration('serialNumber');
    $slug = $_eq->getConfiguration('type');
    $token = self::getToken();
    $data = self::request(LMCLOUD_GW_MACHINE_BASE_URL . '/' . $serial . '/configuration', null, 'GET', ["Authorization: Bearer $token"]);
    //log::add(__CLASS__, 'debug', 'config='.json_encode($data, true));
    if ($data['status'] == true) {
      $machine = $data['data'];
      $bbwset = $machine['recipeAssignment'][0];
      $boilers = $machine['boilers'];
      $free=!$machine['scale']['connected'] || ($machine['scale']['connected'] && $bbwset['recipe_dose'] != 'A' && $bbwset['recipe_dose'] != 'B');

      if ($slug == "linea_mini") { // linea mini
        $_eq->AddCommand("Sur réseau d'eau", 'plumbedin', 'info', 'binary', null, null, null, 1);
        $_eq->AddCommand("Etat Backflush", 'backflush', 'info', 'binary', "jee4lm::backflush", null, null, 0);
        $_eq->AddCommand("Réservoir plein", 'tankStatus', 'info', 'binary', "jee4lm::tankStatus", null, null, 1, 'default', 'default', 'default', 'default', null, 0, false, null, null, null, 0);
        $_eq->AddCommand("BBW Etat", 'bbwmode', 'info', 'string', null, null, null, 0);
        $_eq->AddCommand("BBW Libre", 'bbwfree', 'info', 'binary', "jee4lm::bbw nodose", null, null, 1, 'default', 'default', 'default', 'default', null, 0, false, null, null, null, 0);
        $_eq->AddCommand("BBW Dose A", 'bbwdoseA', 'info', 'numeric', ($bbwset['recipe_dose'] == 'A' && !$free ? "jee4lm::bbw dose" : "jee4lm::bbw dose inactive"), "g", null, 1, 'default', 'default', 'default', 'default', null, 0, false, null, null, null, 0);
        $_eq->AddCommand("BBW Dose B", 'bbwdoseB', 'info', 'numeric', ($bbwset['recipe_dose'] == 'B' && !$free ? "jee4lm::bbw dose" : "jee4lm::bbw dose inactive"), "g", null, 1, 'default', 'default', 'default', 'default', null, 0, false, null, null, null, 0);
        $_eq->AddCommand("Groupe Réglage sur Dose", 'groupDoseMode', 'info', 'string', null, null, null, 0);
        $_eq->AddCommand("Groupe Type de Dose", 'groupDoseType', 'info', 'string', null, null, null, 0);
        $_eq->AddCommand("Groupe Dose max", 'groupDoseMax', 'info', 'numeric', null, "g", null, 0);
        $_eq->AddCommand("Etat", 'machinemode', 'info', 'binary', "jee4lm::main", null, 'THERMOSTAT_STATE', 0);
        $_eq->AddCommand("BBW Présent", 'isbbw', 'info', 'binary', null, null, null, 0);
        $_eq->AddCommand("BBW balance connectée", 'isscaleconnected', 'info', 'binary', "jee4lm::bbw", null, null, 1);
        $_eq->setConfiguration("scalemac", $machine['scale']['address']);
        $_eq->setConfiguration("scalename", $machine['scale']['name']);
        $_eq->AddCommand("BBW batterie", 'scalebattery', 'info', 'numeric', null, "%", 'tile', 1, null, null, 'default', 'default', '0', '100');
        foreach ($boilers as $boiler) {
          if ($boiler['id'] == STEAM_BOILER) {
            $_eq->AddCommand("Vapeur activée", 'steamenabled', 'info', 'binary', "jee4lm::steam", null, 'THERMOSTAT_STATE', 0);
            $_eq->AddCommand("Vapeur temperature cible", 'steamtarget', 'info', 'numeric', null, '°C', 'THERMOSTAT_SETPOINT', 0);
            $_eq->AddCommand("Vapeur température actuelle", 'steamcurrent', 'info', 'numeric', null, '°C', 'THERMOSTAT_TEMPERATURE', 0);
            $_eq->AddCommand("Chaudière Vapeur", 'displaysteam', 'info', 'string', null, null, null, 1);
          }
          if ($boiler['id'] == COFFEE_BOILER_1) {
            $_eq->AddCommand("Cafetière activée", 'coffeeenabled', 'info', 'binary', null, null, 'THERMOSTAT_STATE', 0);
            $_eq->AddCommand("Cafetière temperature cible", 'coffeetarget', 'info', 'numeric', null, '°C', 'THERMOSTAT_SETPOINT', 0);
            $_eq->AddCommand("Cafetière temperature actuelle", 'coffeecurrent', 'info', 'numeric', null, '°C', 'THERMOSTAT_TEMPERATURE', 0);
            $_eq->AddCommand("Chaudière café", 'displaycoffee', 'info', 'string', null, null, null, 1);
            // calcule affichage
          }
        }
        $_eq->AddCommand("Préinfusion", 'preinfusionmode', 'info', 'binary', null, null, null, 1);
        $_eq->AddCommand("Prétrempage", 'prewet', 'info', 'binary', null, null, null, 1);
        $_eq->AddCommand("Prétrempage durée", 'prewettime', 'info', 'numeric', null, 's', 'THERMOSTAT_SETPOINT', 0);
        $_eq->AddCommand("Prétrempage pause", 'prewetholdtime', 'info', 'numeric', null, 's', 'THERMOSTAT_SETPOINT', 0);
        $_eq->AddCommand("Version Firmware", 'fwversion', 'info', 'string', null, null, null, 1);
        $_eq->AddCommand("Version Gateway", 'gwversion', 'info', 'string', null, null, null, 1);
        $_eq->AddCommand("Mode", 'hbmode', 'info', 'string', null, null, "THERMOSTAT_MODE", 0);
        $_eq->AddAction("jee4lm_test", "TEST", "", "button", 0);

        $_eq->AddAction("jee4lm_bbwA","BBW Dose A","button","",1);
        $_eq->AddAction("jee4lm_bbwB","BBW Dose B","button","",1);
        $_eq->AddAction("jee4lm_on", "heat", "jee4lm::main on off", "THERMOSTAT_MODE", 1);
        $_eq->AddAction("jee4lm_off", "off", "jee4lm::main on off", "THERMOSTAT_MODE", 1);
        $_eq->AddAction("jee4lm_auto", "Auto", "jee4lm::main on off", "THERMOSTAT_MODE", 0);
        $_eq->AddAction("jee4lm_steam_on", "Vapeur ON", "jee4lm::steam on off", "", 1);
        $_eq->AddAction("jee4lm_steam_off", "Vapeur OFF", "jee4lm::steam on off", "", 1);
        $_eq->AddAction("refresh", __('Rafraichir', __FILE__));
        $_eq->AddAction("jee4lm_coffee_slider", "Régler consigne café", "button", "THERMOSTAT_SET_SETPOINT", 1, "slider", 85, 105, 0.5);
        //      $_eq->AddAction("jee4lm_steam_slider", "Régler consigne vapeur", "button", "THERMOSTAT_SET_SETPOINT", 1, "slider", 39,134, 1);
        $_eq->AddAction("jee4lm_prewet_slider", "Régler consigne mouillage", "button", "THERMOSTAT_SET_SETPOINT", 1, "slider", 2, 9, 0.5);
        $_eq->AddAction("jee4lm_prewet_time_slider", "Régler consigne pause mouillage", "button", "THERMOSTAT_SET_SETPOINT", 1, "slider", 1, 9, 0.5);
        $_eq->AddAction("jee4lm_doseA_slider", "Régler Dose A", "button", "", 1, "slider", 5, 100, 0.5);
        $_eq->AddAction("jee4lm_doseB_slider", "Régler Dose B", "button", "", 1, "slider", 5, 100, 0.5);
        $_eq->AddAction("start_backflush", "Démarrer backflush", "jee4lm::backflush on off");
        $_eq->linksetpoint("jee4lm_coffee_slider", "coffeetarget");
        //    $_eq->linksetpoint("jee4lm_steam_slider", "steamtarget"); 
        $_eq->linksetpoint("jee4lm_prewet_slider", "prewettime");
        $_eq->linksetpoint("jee4lm_prewet_time_slider", "preWetHoldTime");
        $_eq->linksetpoint("jee4lm_on", "machinemode");
        $_eq->linksetpoint("jee4lm_off", "machinemode");
        $_eq->linksetpoint("jee4lm_steam_on", "steamenabled");
        $_eq->linksetpoint("jee4lm_steam_off", "steamenabled");
        $_eq->linksetpoint("jee4lm_doseA_slider", "bbwdoseA");
        $_eq->linksetpoint("jee4lm_doseB_slider", "bbwdoseB");
        // add machine slug to display machine by type
        $_eq->AddCommand("Machine", 'machine', 'info', 'string', "jee4lm::machine", null, null, 1);
        $_eq->save();
      }
    }
    /*
   config={"status":true,"data":
   {"version":"v1",
   "preinfusionModesAvailable":["ByDoseType"],
   "machineCapabilities":[{"family":"LINEA","groupsNumber":1,"coffeeBoilersNumber":1,"hasCupWarmer":false,"steamBoilersNumber":1,"teaDosesNumber":1,"machineModes":["BrewingMode","StandBy"],"schedulingType":"smartWakeUpSleep"}],
   "machine_sn":"Sn2307902283","machine_hw":"0","isPlumbedIn":false,"isBackFlushEnabled":false,"standByTime":0,"tankStatus":true,"settings":[],
   "recipes":[{"id":"Recipe1","dose_mode":"Mass",
   "recipe_doses":[{"id":"A","target":32},{"id":"B","target":45}]}],
   "recipeAssignment":[{"dose_index":"DoseA","recipe_id":"Recipe1","recipe_dose":"A","group":"Group1"}],
   "groupCapabilities":[{"capabilities":{"groupType":"AV_Group","groupNumber":"Group1","boilerId":"CoffeeBoiler1","hasScale":false,"hasFlowmeter":false,"numberOfDoses":1},
   "doses":[{"groupNumber":"Group1","doseIndex":"DoseA","doseType":"MassType","stopTarget":32}],"doseMode":{"groupNumber":"Group1","brewingType":"ManualType"}}],
   "machineMode":"StandBy",
   "teaDoses":{"DoseA":{"doseIndex":"DoseA","stopTarget":0}},
   "scale":{"connected":false,"address":"44:b7:d0:74:5f:90","name":"LMZ-745F90","battery":64},
   "boilers":[{"id":"SteamBoiler","isEnabled":false,"target":0,"current":0},
   {"id":"CoffeeBoiler1","isEnabled":true,"target":89,"current":42}],"boilerTargetTemperature":{"SteamBoiler":0,"CoffeeBoiler1":89},
   "preinfusionMode":{"Group1":{"groupNumber":"Group1","preinfusionStyle":"PreinfusionByDoseType"}},"preinfusionSettings":{"mode":"Enabled","Group1":[{"groupNumber":"Group1","doseType":"DoseA","preWetTime":2,"preWetHoldTime":3}]},"wakeUpSleepEntries":[{"id":"T6aLl42","days":["monday","tuesday","wednesday","thursday","friday","saturday","sunday"],"steam":false,"enabled":false,"timeOn":"24:0","timeOff":"24:0"}],"smartStandBy":{"mode":"LastBrewing","minutes":10,"enabled":true},"clock":"2024-08-31T14:47:45","firmwareVersions":[{"name":"machine_firmware","fw_version":"2.12"},{"name":"gateway_firmware","fw_version":"v3.6-rc4"}]}} 
    */
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
    $_iconname = null,
    $_calculValueOffset = null,
    $_historizeRound = null,
    $_noiconname = null,
    $_warning = null,
    $_danger = null,
    $_invert = 0
  ) {
    $createCmd = true;
    $Command = $this->getCmd('info', $_logicalId);
    if (!is_object($Command)) { // check if info is already defined, if yes avoid duplicating
      $Command = cmd::byEqLogicIdCmdName($this->getId(), $_logicalId);
      if (is_object($Command)) $createCmd = false;
    }

    if ($createCmd) {
      // basic settings
      $Command = new jee4lmCmd();
      // $Command->setId(null);
      $Command->setLogicalId($_logicalId);
      $Command->setEqLogic_id($this->getId());
      $Command->setName($_Name);
      $Command->setType($_Type);
      $Command->setSubType($_SubType);
    }

    $Command->setIsVisible($_IsVisible);
    if ($_IsHistorized != null)
      $Command->setIsHistorized(strval($_IsHistorized));
    if ($_Template != null) {
      $Command->setTemplate('dashboard', $_Template);
      $Command->setTemplate('mobile', $_Template);
    }
    if ($_unite != null && $_SubType == 'numeric')
      $Command->setUnite($_unite);
    if ($_icon != 'default')
      $Command->setdisplay('icon', '<i class="' . $_icon . '"></i>');
    if ($_forceLineB != 'default')
      $Command->setdisplay('forceReturnLineBefore', 1);
    if ($_iconname != 'default')
      $Command->setdisplay('showIconAndNamedashboard', 1);
    if ($_noiconname != null) {
      $Command->setdisplay('showIconAndNamedashboard', 0);
      $Command->setdisplay('showNameOndashboard', 0);
    }
    if ($_calculValueOffset != null)
      $Command->setConfiguration('calculValueOffset', $_calculValueOffset);
    if ($_historizeRound != null)
      $Command->setConfiguration('historizeRound', $_historizeRound);
    if ($_generic_type != null)
      $Command->setGeneric_type($_generic_type);
    if ($_repeatevent == true && $_Type == 'info')
      $Command->setConfiguration('repeatEventManagement', 'never');
    if ($_valuemin != 'default')
      $Command->setConfiguration('minValue', $_valuemin);
    if ($_valuemax != 'default')
      $Command->setConfiguration('maxValue', $_valuemax);
    if ($_warning != null)
      $Command->setDisplay("warningif", $_warning);
    if ($_order != null)
      $Command->setOrder($_order);
    if ($_danger != null)
      $Command->setDisplay("dangerif", $_danger);
    if ($_invert != null)
      $Command->setDisplay('invertBinary', $_invert);
    $Command->save();
    // log::add(__CLASS__, 'debug', 'command saved');
    
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
    // log::add(__CLASS__, 'debug', ' add action ' . $actionName);
    $createCmd = true;
    $command = $this->getCmd('action', $_actionName);
    if (!is_object($command)) { // check if action is already defined, if yes avoid duplicating
      $command = cmd::byEqLogicIdCmdName($this->getId(), $_actionTitle);
      if (is_object($command)) $createCmd = false;
    }
    if ($createCmd)  // only if action is not yet defined
      {
        $command = new jee4lmCmd();
        $command->setLogicalId($_actionName);
        $command->setName($_actionTitle);
        $command->setType('action');
        $command->setSubType($_SubType);
        $command->setEqLogic_id($this->getId());
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
    // log::add(__CLASS__, 'debug', 'slider value='.$v);
    //find setpoint value and store it on stove as it after slider move
    if ($v > 0)
      switch($_type) {
        case BBWDOSE:
          // set dose for Brew by Weight doses A and B
          $this->setRecipeDose($v, $_logicalID);
          break;
        case COFFEE_BOILER_1:
        case STEAM_BOILER:
          // set coffee boiler temperature targer (does not work on steam boiler of linea mini)
          $this->setBoilerTarget($v, $_type);
          break;
        case PREWET_TIME:
          // read actual value for the other slider as both have to be sent together
          $d = cmd::byEqLogicIdAndLogicalId($this->getId(), 'prewetholdtime')->execCmd();
          $this->setPreinfusionSettings($v,$d);
          break;
        case PREWET_HOLD:
          // read actual value for the other slider as both have to be sent together
          $d = cmd::byEqLogicIdAndLogicalId($this->getId(), 'prewettime')->execCmd();
          $this->setPreinfusionSettings($d, $v);
          break;
        }
  }

  /**
   * retrieve miscelleanous statistics from LM
   * not used yet
   * @return void
   */
  public function getStatistics()
  {
    log::add(__CLASS__, 'debug', 'get basic counters');
    $serial = $this->getConfiguration('serialNumber');
    $ip = $this->getConfiguration('host');
    $token = self::getToken();
    $data = self::request($this->getPath($serial) . '/statistics/counters', "", 'GET', ["Authorization: Bearer $token"], $ip==''?$serial:null);
    log::add(__CLASS__, 'debug', 'config=' . json_encode($data, true));
  }

/**
 * Start of stop the Daemon to call the callback every 5 seconds 
 * @param mixed $_rate 0=switch off, > 0 start calling callback every 5 seconds
 * @return void
 */

  /**
   * Switch machine ON/OFF accoding to a boolean value
   * @param mixed $_toggle
   * @return void
   */
  public function switchCoffeeBoilerONOFF($_toggle)
  {
    log::add(__CLASS__, 'debug', 'switch coffee boiler to '.($_toggle ? 'ON' : 'OFF'));
    $serial = $this->getConfiguration('serialNumber');
    $token = self::getToken();
    self::request($this->getPath($serial) . '/status', 'status=' . ($_toggle ? "BrewingMode" : "StandBy"), 'POST', ["Authorization: Bearer $token"],  $serial);
    $this->checkAndUpdateCmd('hbmode', $_toggle ? 'heat' : 'off');
  }

  /**
   * Switch Steam ON/OFF according to a boolean value
   * @param mixed $_toggle
   * @return void
   */
  public function switchSteamBoilerONOFF($_toggle)
  {
    log::add(__CLASS__, 'debug', 'enable/disable steam boiler');
    $serial = $this->getConfiguration('serialNumber');
//    $ip = $this->getConfiguration('host');
    $token = self::getToken();
    self::request($this->getPath($serial)  . '/enable-boiler', 'identifier=SteamBoiler&state=' . ($_toggle ? "enabled" : "disabled"), 'POST', ["Authorization: Bearer $token"],  $serial);
 //   log::add(__CLASS__, 'debug', 'config=' . json_encode($data, true));
  }

  /**
   * Select mode for Preinfusion/Prebew.
   * set to Disabled if prebrew, set to enabled if prebrew
   * @param mixed $_mode values accepted : Enabled,Disabled,TypeB
   * @return void
   */
  public function setPreinfusionStatus($_mode)
  {
    // preinfusion = TypeB, prebrew=Enabled/Disabled
    log::add(__CLASS__, 'debug', 'select prebrew or preinfusion');
    $serial = $this->getConfiguration('serialNumber');
    $ip = $this->getConfiguration('host');
    $token = self::getToken();
    $data = self::request($this->getPath($serial). '/enable-preinfusion', 'mode=' . $_mode, 'POST', ["Authorization: Bearer $token"], $ip==''?$serial:null);
    log::add(__CLASS__, 'debug', 'config=' . json_encode($data, true));
  }

  /**
   * set the LM boiler target temperature for coffee or steam boiler according to $type value
   * @param mixed $_value value in celsius
   * @param mixed $_identifier by default this is coffee boiler temperature for group 1
   * @return void
   */
  public function setBoilerTarget($_value, $_identifier = COFFEE_BOILER_1)
  {
    log::add(__CLASS__, 'debug', 'switch steam on or off');
    $serial = $this->getConfiguration('serialNumber');
    $ip = $this->getConfiguration('host');
    $token = self::getToken();
    $data = self::request($this->getPath($serial). '/target-boiler', 'identifier=' . $_identifier . '&value=' . $_value, 'POST', ["Authorization: Bearer $token"], $ip==''?$serial:null);
    log::add(__CLASS__, 'debug', 'config='.json_encode($data, true));
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
  public function setPlumbinStatus($_toggle)
  {
    log::add(__CLASS__, 'debug', 'enable/disable plumbed in ');
    $serial = $this->getConfiguration('serialNumber');
    $ip = $this->getConfiguration('host');
    $token = self::getToken();
    $data = self::request($this->getPath($serial). '/enable-plumbin', 'enable=' . ($_toggle ? 'true' : 'false'), 'POST', ["Authorization: Bearer $token"],$ip==''?$serial:null);
    log::add(__CLASS__, 'debug', 'config=' . json_encode($data, true));
  }

  /**
   * Returns the total number of flushes of the coffee group done since the machine has been setup
   * the information is not displayed by the plugin at the moment but will be used later
   * @return void
   */
  public function getMachineUses()
  {
    log::add(__CLASS__, 'debug', 'get number of uses');
    $serial = $this->getConfiguration('serialNumber');
    $ip = $this->getConfiguration('host');
    $token = self::getToken();
    $data = self::request($this->getPath($serial). '/machine_uses', '', 'POST', ["Authorization: Bearer $token"], $ip==''?$serial:null);
    log::add(__CLASS__, 'debug', 'uses=' . json_encode($data, true));
  }

  /**
   * Set the Dose to use with Group on GB3 or Brew By Weight on Linea Mini. On Mini, 
   * Dose A and B hold the two possible values offered by BBW. 
   * this API is not used on Micra.
   * @param mixed $weight
   * @param mixed $dose
   * @return void
   */
  public function setRecipeDose($_weight, $_dose)
  {
    // $dose = 'A' or 'B'
    //"groupNumber":"Group1","doseIndex":"DoseA","doseType":"MassType","value":32

    if ($_dose == 'A') {
      $doseA = 0 + $_weight;
      $doseB = cmd::byEqLogicIdAndLogicalId($this->getId(), 'bbwDoseB')->execCmd();
    } else {
      $doseB = 0 + $_weight;
      $doseA = cmd::byEqLogicIdAndLogicalId($this->getId(), 'bbwDoseA')->execCmd();
    }
    //  log::add(__CLASS__, 'debug', 'set doses for BBW Dose A='.$doseA.'g B='.$doseB.'g');
    $serial = $this->getConfiguration('serialNumber');
    $ip = $this->getConfiguration('host');
    $token = self::getToken();

    // update recipe
    //"recipeAssignment":[{"dose_index":"DoseA","recipe_id":"Recipe1","recipe_dose":"B","group":"Group1"}]
    //                    t={group:e.group,doseIndex:e.dose_index,recipeId:e.recipe_id,recipeDose:e.recipe_dose},
//  $d = ["group"=>"Group1", "doseIndex" => "Dose$_dose", "recipeId" => "Recipe1", "recipeDose" => $_dose];
    // log::add(__CLASS__, 'debug', "active recipe POST with d=".json_encode($d));
    // self::request(LMCLOUD_GW_MACHINE_BASE_URL.'/'.$serial.'/recipes/active-recipe',
    //   $d,
    //   'POST',["Authorization: Bearer $token"],$serial);

    // update list of doses
    $recipedoses = [['id' => 'A', 'target' => $doseA], ['id' => 'B', 'target' => $doseB]];
    $d = ["recipeId" => "Recipe1", "doseMode" => "Mass", "recipeDoses" => $recipedoses];
//      log::add(__CLASS__, 'debug', "send PUT ".$this->getPath($serial, $ip). '/recipes/ with d='.json_encode($d));
// force by web site
    $req = self::request(
      $this->getPath($serial).'/recipes/',
      $d,
      'PUT',
      ["cache-control: no-cache", "content-type: application/json", "Authorization: Bearer $token"],
      $serial
    );
      log::add(__CLASS__, 'debug', "set target dose returned=".json_encode($req));
  } 

  public function setActiveBBWRecipe($_dose)
  {
    log::add(__CLASS__, 'debug', "set bbw active dose to $_dose");

    // $dose = 'A' or 'B'
    $d = ["group"=> "Group1",
            "doseIndex"=> "DoseA",
            "recipeId"=> "Recipe1",
            "recipeDose"=> "Dose".$_dose];
    
    $serial = $this->getConfiguration('serialNumber');
    $ip = $this->getConfiguration('host');
    $token = self::getToken();

    $req = self::request(
      $this->getPath($serial).'/recipes/active-recipe',
      $d,
      'POST',
      ["Authorization: Bearer $token"],
      ''
    );
      log::add(__CLASS__, 'debug', "set bbw active dose returned=".json_encode($req));
  }

/**
 * select which brew by weight dose is used, either A or B
 * @param mixed $_dose
 * @param integer $_weight
 * @return void
 */
public function setScaleTarget($_dose, $_weight) {
  log::add(__CLASS__, 'debug', "set dose $_dose to $_weight");
  if ($this->getCmd(null, 'isbbw')->execCmd()) {
    $_weight=  $this->getCmd(null, 'bbwDose'.$_dose)->execCmd();
    $serial = $this->getConfiguration('serialNumber');
    $token = self::getToken($serial);
    $data = self::request(
      $this->getPath($serial) . '/scale/target-dose',
      "group=Group1&dose_index=Dose$_dose&dose_type=MassType&value=$_weight",
      'POST',
      ["Authorization: Bearer $token"],
      ''
    );
    log::add(__CLASS__, 'debug', 'scaletarget=' . json_encode($data, true));

  }
}

  /**
   * Summary of setPreinfusionSettings
   * @param int $_time 
   * @param int $_hold
   * @return void
   */
  public function setPreinfusionSettings($_time, $_hold) {
    log::add(__CLASS__, 'debug', "set preinfusion start t=$_time h=$_hold");
    $_time *=1000;
    $_hold *=1000;
    $serial = $this->getConfiguration('serialNumber');
    $token = self::getToken();
    $data = self::request(
      $this->getPath($serial) . '/setting-preinfusion',
      "group=Group1&button=DoseA&wetTimeMs=$_time&holdTimeMs=$_hold",
      'POST',
      ["Authorization: Bearer $token"],
      ''
    );
    log::add(__CLASS__, 'debug', 'preinfusion=' . json_encode($data, true));
  }

  /**
   * Start the Backflush. I recommend using the app for this purpose, it is much more convenient
   * as it monitors the backflush and this is not.
   * @return void
   */
  public function startBackflush()
  {
    log::add(__CLASS__, 'debug', 'backflush start');
    $serial = $this->getConfiguration('serialNumber');
    $ip = $this->getConfiguration('host');
    $token = self::getToken();
    $data = self::request(
      $this->getPath($serial) . '/enable-backflush',
      'enable=true',
      'POST',
      ["Authorization: Bearer $token"],
      $ip==''?$serial:null
    );
    log::add(__CLASS__, 'debug', 'config=' . json_encode($data, true));
  }


/**
 * Detects TCP connections.
 *
 * This static method is responsible for detecting TCP connections of LM machines.
 *
 * @return array with ip and name found in DNS records
 */
public static function tcpdetect() 
{
  log::add(__CLASS__, 'debug', '[detect] local ip');

  try {
    $mdns = new mDNS();
  } catch (Exception $e) {
    log::add(__CLASS__, 'error', 'Failed to create mDNS instance: ' . $e->getMessage());
    return [];
  }

  // qtype is the type of the query, i.e. the type of Resource Record which should be returned in responses.
  // qclass is Class code, 1 a.k.a. "IN" for the Internet and IP networks
  $mdns->query(LMBT_ADVERTISING, 1, 12, "");
  $cc = 15;
  $lm = [];

    while ($cc > 0) {
      $inpacket = $mdns->readIncoming();
//      log::add(__CLASS__, 'debug', '[detect] packet=' . json_encode($inpacket, true));
      if ($inpacket != null && $inpacket->packetheader->getAnswerRRs() > 0) {
        log::add(__CLASS__, 'debug', '[detect] found '.$inpacket->packetheader->getAnswerRRs().' answers');
        $answer = $inpacket->answerrrs[0];
        if (is_object($answer)) {
          switch ($answer->qtype) {
            case 12: //PTR
              log::add(__CLASS__, 'debug', '[detect] found pointer on ' . $answer->name);
              if ($answer->name == LMBT_ADVERTISING) {
                $mdns->query($answer->data, 1, 33, "");
                $cc = 15;
              }
              break;
            case 33: // SRV
              $d = $answer->data;
              $port = ($d[4] << 8) + $d[5];
              $target = implode('', array_map('chr', array_slice($d, 7, $d[6]))) . ".local";
              $lm[$answer->name] = ["port" => $port, "ip" => "", "target" => $target];
              $mdns->query($target, 1, 1, "");
              $cc = 15;
              break;
            case 1: //NAME
              $ip = implode('.', $answer->data);
              foreach ($lm as $key => $value) {
                if ($value['target'] == $answer->name) {
                  $lm[$key]['ip'] = $ip;
                  $lm[$key]['name'] = $answer->name;
                  log::add(__CLASS__, 'debug', '[detect] name=' . $answer->name . ' ip=' . $ip);
                  $cc = 1; //exit loop as found
                }
              }
              break;
          }
        }
      }
      $cc--;
    }
    return $lm;
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
    $data = self::request(LMCLOUD_CUSTOMER, null, 'GET', ["Authorization: Bearer $token"]);
    log::add(__CLASS__, 'debug', 'detect=' . json_encode($data, true));
    if ($data["status"] != true)
      return false;
    foreach ($data['data']['fleet'] as $machines) {
      log::add(__CLASS__, 'debug', 'detect found ' . ($uuid = $machines['uuid']) . " " . $machines['name'] . '(' . $machines['machine']['model']['name'] . ') SN=' . $machines['machine']['serialNumber']);
      log::add(__CLASS__, 'debug', 'type=' . $machines['machine']['type']);
      if ($machines['machine']['type'] == LMFILTER_MACHINE_TYPE) {
        $d = DateTime::createFromFormat(DateTime::ATOM, $machines['paringDate']);
        log::add(__CLASS__, 'debug', 'slug=' . ($slug = $machines['machine']['model']['slug']));
        log::add(__CLASS__, 'debug', 'key=' . $machines['communicationKey']);
        log::add(__CLASS__, 'debug', 'detect paired on ' . $d->format("d/m/y"));
        // now check if machine is already created as an eqlogic
        $eqLogic = eqLogic::byLogicalId($uuid, 'jee4lm');
        if (!is_object($eqLogic)) {
          $eqLogic = new jee4lm();
          $eqLogic->setEqType_name('jee4lm');
          $eqLogic->setIsEnable(1);
          $eqLogic->setName($machines['name']);
          $eqLogic->setCategory('heating', 1);
          $eqLogic->setIsVisible(1);
          log::add(__CLASS__, 'debug', 'create eqlogif for uuid '.$uuid);
        } else
          log::add(__CLASS__, 'debug', $uuid.' uuid already exists, update only');
        $eqLogic->setConfiguration('type', $slug);
        $eqLogic->setConfiguration('communicationKey', $machines['communicationKey']);
        $eqLogic->setConfiguration('pairingDate', $d->format("d/m/y"));
        $eqLogic->setConfiguration('model', $machines['machine']['model']['name']);
        $eqLogic->setLogicalId($uuid);
        // now get configuration of machine
        $eqLogic->setConfiguration('serialNumber', $machines['machine']['serialNumber']);
        $eqLogic->save();
        // create commands before setting display
        jee4lm::CreateConfiguration($eqLogic);
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
          //  'jee4lm_steam_slider' => [6,1],
          'tankStatus' => [1, 1],
          'jee4lm_steam_off' => [2, 3],
          'jee4lm_steam_on' => [2, 3],
          //  'steamcurrent' => [3,3],
          //  'steamtarget' => [3,3],
          'steamenabled' => [1, 1],
          'fwversion' => [7, 1],
          'gwversion' => [7, 3],
          'groupDoseMax' => [1, 1],
          'displaycoffee' => [3, 1],
          'displaysteam' => [3, 3],
          'jee4lm_bbwA' => [7, 2],
          'jee4lm_bbwB' => [7, 2]
        ];

        $displayStuff = [
          "layout::dashboard::table::parameters" =>
            [
              "center" => "0",
              "styletable" => "background-image: url(/plugins/jee4lm/core/config/img/bg_model_2.png);background-repeat: no-repeat; background-size: 100% 36%;",
              "styletd" => "",
              "style::td::1::1" => "font-size:larger;",
              "text::td::1::1" => "<br>Réservoir à eau<br>",
              "text::td::1::3" => "<br>Balance connectée<br>",
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
          'layout::dashboard::table::nbLine' => '7',
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
        jee4lm::RefreshAllInformation($eqLogic);
      }
      log::add(__CLASS__, 'debug', 'loop to next machine');
    }
    log::add(__CLASS__, 'debug', 'end parsing');
    /*
    detect=
    {"status":true,
    "status_code":200,
    "data":
      {"uuid":"78df57ee-3d7c-4081-9bea-c466e93d74c6",
      "name":"gluzman thierry",
      "active":true,
      "country":{
        "uuid":"d73b3872-985d-4b0b-8934-a41358495af8",
        "name":"France",
        "alpha2Code":"FR",
        "country":"fr_FR"},
      "email":"gluzmanandco@gmail.com",
      "image":null,
      "role":{
        "uuid":"e1516a97-a7fe-4a16-8aa4-a7c54b64a7f6",
        "name":"Customer",
        "role":"ROLE_CUSTOMER",
        "description":"Default role for user registered with mobile application"
      },
      "surname":null,"
      username":"gluzmanandco@gmail.com",
      "authenticationType":"INTERNAL",
      "customerAuthenticationType":[],
      "birthday":null,
      "businessSector":null,
      "disposalCommercial":true,
      "disposalThirdParty":true,
      "disposalProfile":true,
      "emailConfirmed":true,
      "fleet":[
        {"uuid":"0c4354ae-44d6-44a2-bb0e-e875f67be18b",
        "name":"LM049632",
        "communicationKey":"4f902aeb455cc285f2f9c3e096951758dd13043c252741ecf67246cd2655ef75",
        "customer":"gluzmanandco@gmail.com",
        "machine":{
          "uuid":"600b7183-a4fd-41b0-8a28-7fadf23bc021",
          "name":null,
          "model":{
            "uuid":"9c8752bb-1272-472b-a853-0786d5c4acce",
            "name":"Linea Mini",
            "description":"Linea Mini",
            "slug":"linea_mini"
            },
          "ownerNumber":1,
          "serialNumber":"LM049632",
          "type":"MACHINE_INSTANCE"
        },
        "paringDate":"2023-10-13T11:31:35+00:00",
        "machineUse":{
          "uuid":"000b6014-ace4-4bed-81d3-0c18d06cebd8",
          "name":"1. Domicile",
          "country":{
            "uuid":"d73b3872-985d-4b0b-8934-a41358495af8",
            "name":"France",
            "alpha2Code":"FR",
            "country":"fr_FR"},
            "description":null
          }
          }
        ],
        "gender":null,"hobbies":[],"ownMachine":1,"privacy":null,
        "socialNetworkId":null,"business":null,"businessType":null,"coffeeRoaster":null,
        "countryOther":null,"areaOperation":null,"brandServiced":null,
        "personOfContact":null,"phoneModel":null,"id":133029}}
    */
    return true;
  }
  /**
   * test Bluetooth integration, send message to daemon only when a port is detected, in other cases 
   * scale and machine are seen by remote BLEA and cannot be used by plugin directly
   * @return void
   */
  public function SetLMBluetooh() {
    log::add(__CLASS__, 'debug', '[setbluetooth] start');
    $id = $this->getId(); 
    $u = config::byKey('userId','jee4lm');
    $t = $this->getConfiguration('communicationKey');
    $s=$this->getConfiguration('serialNumber');

    $port = jeedom::getBluetoothMapping(config::byKey('port', 'jee4lm'));
    if ($port=='')
      log::add(__CLASS__, 'debug', '[bluetooth] no port defined on plugin configuration screen, abort');
    elseif (self::deamon_info()['state'] == 'ok')
      self::deamon_send(['id' => $id, 'lm'=> 'bt', 'bt'=>'login', 'username' => $u, 'token' => $t, 'serial' =>$s, 'dev' =>'']);
    log::add(__CLASS__, 'debug', '[setbluetooth] stop');
  } 

  // add logic to monitor BBW presence
  // as jeedom does not have core Bluetooth support, 3 methodes are used to fetch scale presence
  // first the routine searches for the mac addeess definition in a BLEA plugin on the same jeedom server
  // in second it searches for any eqlogic of the jmqtt plugin that would have the mac address as name
  // then third it searches for any eqlogic (virtual obviously) that has such a name set under the 'MAISON' object (small and caps accepted)
  // in the case of mqtt or virtual object, it searches for the equivalence of a BLEA object, e.g. a "present" info command working the same way
  // so, the BT monitoring can be set elsewhere and mirrored on the mosquitto server as a MQTT object then published on jeedom
  // so that the plugin can see it even by remote

  public function searchForBBW()
  {
    $mac = $this->getConfiguration('scalemac');
    if ($mac == '') return false; // no scale found while detecting eqlogic, if scale was added later, rescan to create information

    log::add(__CLASS__, 'debug', 'search scale ' . $mac);

    // check if BLEA is installed and search for scale
    $blea = eqLogic::byLogicalId($mac, 'blea');
    if (is_object($blea)) {
      $bbwID = $blea->getId();
      $cmd = cmd::byEqLogicIdAndLogicalId($bbwID, 'present');
      if ($cmd != null) {
        $present = $cmd->execCmd();
        log::add(__CLASS__, 'debug', 'found scale in Blea with BT address ' . ($present == 1 ? 'allumé' : 'éteint'));
        return $present;
      }
      return false;
    }
    
    // check if JMQTT is installed and search for scale
    $jmqttCollection = eqLogic::byType('jmqtt', true);
    foreach ($jmqttCollection as $eqJ) {
      if (strcasecmp($eqJ->getName(), $mac) == 0) {
        $jcmd = cmd::byEqLogicIdCmdName($eqJ->getId(), 'present');
        if ($jcmd != null) {
          $present = $jcmd->execCmd();
          log::add(__CLASS__, 'debug', 'found scale in jmqtt with BT address ' . ($present == 1 ? 'allumé' : 'éteint'));
          return $present;
        }
        return false;    
      }
      log::add(__CLASS__, 'debug', 'jmqtt loop');
    }

    // search as an object name in root MAISON object
    $bbwcollection = eqLogic::byObjectNameEqLogicName('MAISON', $mac);
    $bbwcollection1 = eqLogic::byObjectNameEqLogicName('MAISON', strtoupper($mac));
    $bbwcollection = array_merge($bbwcollection, $bbwcollection1);
    if ($bbwcollection != null) {
      foreach ($bbwcollection as $bbw) {
        //       log::add(__CLASS__, 'debug', 'bbw='.json_encode($bbw));
        $bbwID = $bbw->getId();
        $scmd = cmd::byEqLogicIdCmdName($bbwID, 'present');
        if ($scmd != null) {
          $present = $scmd->execCmd();
          log::add(__CLASS__, 'debug', 'found scale as standard equipment with BT address ' . ($present == 1 ? 'allumé' : 'éteint'));
          return $present;
        }
      }
    }
    return false;
  }

  /**
   * Refreshes the main counters and not all the information, this is mostly used when there is no
   * local ip defined and the machine is turned on. it mainly fetches the boiler temperature growth and on/off state
   * @return bool
   */
  public function getInformations()
  {
    log::add(__CLASS__, 'debug', 'getinformation start');
    $serial = $this->getConfiguration('serialNumber');
//    $ip = $this->getConfiguration('host');
    $token = self::getToken();
    $arr = self::request($this->getPath($serial) . '/status', '', 'GET', ["Authorization: Bearer $token"]);
    log::add(__CLASS__, 'debug', 'getinformation got feedback '.json_encode($arr));
    if ($arr != null) {
      log::add(__CLASS__, 'debug', 'getinformation got feedback '.json_encode($arr));
      if(array_key_exists('status', $arr)) {
        log::add(__CLASS__, 'debug', 'getinformation process information');
        $this->checkAndUpdateCmd('machinemode',$arr['data']['MACHINE_STATUS'] == 'BrewingMode');
        $this->checkAndUpdateCmd('coffeecurrent',$arr['data']['TEMP_COFFEE']);
        //      $this->getCmd(null, 'steamcurrent')->event($arr['data']['TEMP_STEAM']);
        $this->checkAndUpdateCmd('tankStatus',$arr['data']['LEVEL_TANK']?0:1);
        $this->checkAndUpdateCmd('backflush',$arr['data']['MACHINE_REMOTSETS']['BACKFLUSH_ENABLE']?1:0);
        $this->checkAndUpdateCmd('steamenabled',$arr['data']['MACHINE_REMOTSETS']['BOILER_ENABLE']?1:0);
        $this->checkAndUpdateCmd('plumbedin',$arr['data']['MACHINE_REMOTSETS']['PLUMBIN_ENABLE']?1:0);

        $machinestate = ($arr['data']['MACHINE_STATUS'] == 'BrewingMode');
        $coffeetarget = $this->getCmd(null, 'coffeetarget')->execCmd();
        $display = !$machinestate ? $arr['data']['TEMP_COFFEE'].' °C' :
          "<span style='color:" . ($arr['data']['TEMP_COFFEE'] + 2 >= $coffeetarget ? 'green' : 'red') . ";'>" . $coffeetarget . "°C / " . $arr['data']['TEMP_COFFEE'] . "°C</span>";
          $this->checkAndUpdateCmd('displaycoffee',$display);
        log::add(__CLASS__, 'debug', 'getinformation coffee boiler temp=' . $arr['data']['TEMP_COFFEE'] . ' tank=' . $arr['data']['LEVEL_TANK']);

        $steamstate = $arr['data']['MACHINE_REMOTSETS']['BOILER_ENABLE'];
        //      $steamcurrent = $arr['data']['TEMP_STEAM'];
        //      $steamtarget = $this->getCmd(null, 'steamtarget')->execCmd();
        if (!$steamstate)
          $display = 'OFF';
        else
          $display = "<span style='color:green'>ON</span>";
        $this->checkAndUpdateCmd('displaysteam',$display);

        // if machine is on hide HEAT button else show it
        $cmd = $this->getCmd(null, 'jee4lm_on');
        $cmd->setIsVisible($machinestate?0:1);          
        $cmd->save();

        if (!$this->getCmd(null, 'isbbw')->execCmd() || !$this->searchForBBW()) { //present
            $this->getCmd(null, 'bbwfree')->setDisplay('template', "jee4lm::bbw nodose active");
            $this->getCmd(null, 'bbwdoseA')->setDisplay('template', ("jee4lm::bbw dose inactive"));
            $this->getCmd(null, 'bbwdoseB')->setDisplay('template', ("jee4lm::bbw dose inactive"));
            log::add(__CLASS__, 'debug', 'getinformation no scale');
          } else {
          // change display of doses
          $free = $this->getCmd(null, 'bbwfree')->execCmd();
          $bbwmode = $this->getCmd(null, 'bbwmode')->execCmd();
          $this->getCmd(null, 'bbwfree')->setDisplay('template', $bbwmode == 'A' || $bbwmode == 'B' ? "jee4lm::bbw nodose inactive" : "jee4lm::bbw nodose active");
          $this->getCmd(null, 'bbwdoseA')->setDisplay('template', $bbwmode == 'A' && !$free ? "jee4lm::bbw dose" : "jee4lm::bbw dose inactive");
          $this->getCmd(null, 'bbwdoseB')->setDisplay('template', $bbwmode == 'B' && !$free ? "jee4lm::bbw dose" : "jee4lm::bbw dose inactive");
          log::add(__CLASS__, 'debug', "bbw scale on display mode=$bbwmode continuous=$free");
        } 
     }      
    }
    return true;
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
        array('operation' => '#value# <= 10', 'state_light' => '<span style="font-size: 24px;color:red">#value# %</span>', 'state_dark' => '<span style="font-size: 24px;color:red">#value# %</span>'),
        array('operation' => '#value# > 10 && #value# <=70', 'state_light' => '<span style="font-size: 24px;color:orange">#value# %</span>', 'state_dark' => '<span style="font-size: 24px;color:orange">#value# %</span>'),
        array('operation' => '#value# > 70', 'state_light' => '<span style="font-size: 20px;color:green">#value# %</span>', 'state_dark' => '<span style="font-size: 20px;color:green">#value# %</span>')
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
        '#_icon_on_#' => "<span style='display:inline-block;line-height:0px;border-radius:50%;font-size: 8px;background-color: gray;color:white;border-width:thick;border-color:red; border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/jee4lm/core/config/img/nodose_on.png' width='58px' height='57px' ></span></span>",
        '#_icon_off_#' => "<span style='display:inline-block;line-height:0px;border-radius:50%;font-size: 8px;background-color: gray;color:white;border-width:thick;border-color:lightgray; border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/jee4lm/core/config/img/nodose_off.png' width='58px' height='57px' ></span></span>",
        "#_time_widget_#" => "0"
      )
    );
    $r['action']['other']['main on off'] = array(
      'template' => 'tmplimg',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_img_light_on_#' => "<span style='display: inline-block;margin-top:40px;line-height:0px;border-radius:50%;font-size: 8px;background-color: white;color:white;border-width:thick;border-color:white; border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/jee4lm/core/config/img/main_on.png' width='100px' height='100px' ></span></span>",
        '#_img_dark_on_#' => "<span style='display: inline-block;margin-top:40px;line-height:0px;border-radius:50%;font-size: 8px;background-color: white;color:white;border-width:thick;border-color:rgb(25,25,25); border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/jee4lm/core/config/img/main_on.png' width='100px' height='100px' ></span></span>",
        '#_img_light_off_#' => "<span style='display: inline-block;margin-top:40px;line-height:0px;border-radius:50%;font-size: 8px;background-color: white;color:white;border-width:thick;border-color:white; border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/jee4lm/core/config/img/main_off.png' width='100px' height='100px' ></span></span>",
        '#_img_dark_off_#' => "<span style='display: inline-block;margin-top:40px;line-height:0px;border-radius:50%;font-size: 8px;background-color: white;color:white;border-width:thick;border-color:rgb(25,25,25); border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/jee4lm/core/config/img/main_off.png' width='100px' height='100px' ></span></span>",
        "#_time_widget_#" => "0"
      )
    );
    $r['action']['other']['steam on off'] = array(
      'template' => 'tmplimg',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_img_light_on_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/steam_on.png' width='64' height='64'>",
        '#_img_dark_on_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/steam_on.png' width='64' height='64'>",
        '#_img_light_off_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/steam_off.png' width='64' height='64'>",
        '#_img_dark_off_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/steam_off.png' width='64' height='64'>",
        "#_time_widget_#" => "0"
      )
    );
    $r['action']['other']['backflush on off'] = array(
      'template' => 'tmplimg',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_img_light_on_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/backflush_on.png' width='64' height='64'>",
        '#_img_dark_on_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/backflush_on.png' width='64' height='64'>",
        '#_img_light_off_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/backflush_off.png' width='64' height='64'>",
        '#_img_dark_off_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/backflush_off.png' width='64' height='64'>",
        "#_time_widget_#" => "0"
      )
    );

    $r['info']['binary']['tankStatus'] = array(
      'template' => 'tmplicon',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_icon_on_#' => "<span style='color:red';font-size:1,5em;font-style:bold;'><br>Remplir<br><br></span><img class='img-responsive' src='/plugins/jee4lm/core/config/img/reservoir.png' width='64' height='64'>",
        '#_icon_off_#' => "<span style='font-size:1,5em;font-style:bold;'><br>OK</span>",
        "#_time_widget_#" => "0"
      )
    );
    $r['info']['binary']['bbw'] = array(
      'template' => 'tmplicon',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_icon_on_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/bbw_on.png' width='64' height='64'>",
        '#_icon_off_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/bbw_off.png' width='64' height='64'>",
        "#_time_widget_#" => "0"
      )
    );
    $r['info']['binary']['main'] = array(
      'template' => 'tmplicon',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_icon_on_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/main_on.png' width='64' height='64'>",
        '#_icon_off_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/main_off.png' width='64' height='64'>",
        "#_time_widget_#" => "0"
      )
    );
    $r['info']['binary']['backflush'] = array(
      'template' => 'tmplicon',
      'display' => array('icon' => 'null'),
      'replace' => array(
        '#_icon_on_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/backflush_on.png' width='64' height='64'>",
        '#_icon_off_#' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/backflush_off.png' width='64' height='64'>",
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
          'state_light' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/#value#.png' width='256' height='256'>",
          'state_dark' => "<img class='img-responsive' src='/plugins/jee4lm/core/config/img/#value#.png' width='256' height='256'>"
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
    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
    if (file_exists($pid_file)) {
      $pid = trim(file_get_contents($pid_file));
      if (@posix_getsid($pid)) {
        $return['state'] = 'ok';
      } else {
        shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
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
      cache::set('jee4lm::laststate_'.$jee4lm->getId(),0);
    }

    $path = realpath(dirname(__FILE__) . '/../../resources/jee4lmd'); // répertoire du démon à modifier
    $cmd = self::getPython3() . " {$path}/jee4lmd.py"; // nom du démon à modifier
    $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel(__CLASS__));
   // $cmd .= ' --sockethost ' . config::byKey('sockethost', __CLASS__, JEEDOM_DAEMON_HOST); // host par défaut à modifier
    $cmd .= ' --socketport ' . config::byKey('socketport', __CLASS__, JEEDOM_DAEMON_PORT); // port par défaut à modifier
    $cmd .= ' --callback ' . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/jee4lm/core/php/jee4lmd.php'; // chemin de la callback url à modifier (voir ci-dessous)
    $cmd .= ' --cycle ' . config::byKey('cycle', __CLASS__, 2);
    $cmd .= ' --apikey ' . jeedom::getApiKey(__CLASS__); // l'apikey pour authentifier les échanges suivants
    $cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/deamon.pid'; // et on précise le chemin vers le pid file (ne pas modifier)
    log::add(__CLASS__, 'info', 'Lancement démon:' . self::getPython3() . "{$path}/jee4lmd.py");
    $result = exec($cmd . ' >> ' . log::getPathToLog('jee4lmd') . ' 2>&1 &');     
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
    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid'; // ne pas modifier
    if (file_exists($pid_file)) {
        $pid = intval(trim(file_get_contents($pid_file)));
        system::kill($pid);
    }
    system::kill('jee4lmd.py'); // nom du démon à modifier
    sleep(1);
    // before running daemon, check if all cache values are cleared
    foreach (eqLogic::byType(__CLASS__, true) as $jee4lm) {
      cache::set('jee4lm::laststate_'.$jee4lm->getId(),0);
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
}

/**
 * Specific class for commands execution
 */
class jee4lmCmd extends cmd
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
        return jee4lm::RefreshAllInformation($eq);
      case 'start_backflush':
        $eq->startBackflush();
        return true;
      case 'getStatus':
        return $eq->getInformations();
      case 'jee4lm_on':
      case 'jee4lm_off':
        $b = $action == 'jee4lm_on';
        $eq->switchCoffeeBoilerONOFF($b);
        return jee4lm::RefreshAllInformation($eq, 1);
      case 'jee4lm_steam_on':
      case 'jee4lm_steam_off':
        $b = $action == 'jee4lm_steam_on';
        $eq->switchSteamBoilerONOFF($b);
        return jee4lm::RefreshAllInformation($eq);
      case 'jee4lm_coffee_slider':
        $eq->set_setpoint($_options, 'coffeetarget', COFFEE_BOILER_1);
        return jee4lm::RefreshAllInformation($eq);
      case 'jee4lm_steam_slider':
        $eq->set_setpoint($_options, 'steamtarget', STEAM_BOILER);
        return jee4lm::RefreshAllInformation($eq);
      case 'jee4lm_doseA_slider':
        $eq->set_setpoint($_options, 'A', BBWDOSE);
        return jee4lm::RefreshAllInformation($eq);
      case 'jee4lm_doseB_slider':
        $eq->set_setpoint($_options, 'B', BBWDOSE);
        return jee4lm::RefreshAllInformation($eq);
      case 'jee4lm_prewet_slider':
        $eq->set_setpoint($_options, '', PREWET_TIME);
        return jee4lm::RefreshAllInformation($eq);
      case 'jee4lm_prewet_time_slider':
        $eq->set_setpoint($_options, '', PREWET_HOLD);
        return jee4lm::RefreshAllInformation($eq);
        case 'jee4lm_bbwA':
        case 'jee4lm_bbwB':
            $eq->setActiveBBWRecipe($_options=='jee4lm_bbwA'?'A':'B');
            return jee4lm::RefreshAllInformation($eq);          
      case 'jee4lm_test':
//          $eq->SetLMBluetooh();
        $eq->tcpdetect();
      default:
        return true;
    }
  }

}


