<?php

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

const 
LMCLIENT_ID = "7_1xwei9rtkuckso44ks4o8s0c0oc4swowo00wgw0ogsok84kosg", 
LMCLIENT_SECRET ="2mgjqpikbfuok8g4s44oo4gsw0ks44okk4kc4kkkko0c8soc8s",
LMDEFAULT_PORT_LOCAL = 8081, 
LMMACHINE_TYPE = ['linea-mini','micra','gs3'],
LMFILTER_MACHINE_TYPE='MACHINE_INSTANCE',
LMCLOUD_TOKEN = 'https://cms.lamarzocco.io/oauth/v2/token',
LMCLOUD_CUSTOMER = 'https://cms.lamarzocco.io/api/customer',
LMCLOUD_GW_BASE_URL = "https://gw-lmz.lamarzocco.io/v1/home",
LMCLOUD_GW_MACHINE_BASE_URL = "https://gw-lmz.lamarzocco.io/v1/home/machines";

class jee4lm extends eqLogic
{

  
  /*
  $MACHINE_NAME = "machine_name";
  $SERIAL_NUMBER = "serial_number";
  $CONF_MACHINE = "machine";
  $CONF_USE_BLUETOOTH = "use_bluetooth";
  */

  public static function request($_path, $_data = null, $_type = 'GET', $_header= null) {
    // Utiliser cURL ou une autre méthode pour appeler l'API de La Marzocco
    log::add(__CLASS__, 'debug', 'request query url='.$_path);
    log::add(__CLASS__, 'debug', 'request data='.$_data);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $_path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    if ($_header == null)
      curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/x-www-form-urlencoded"]);
    else
      curl_setopt($ch, CURLOPT_HTTPHEADER, $_header);
    if ($_type=="POST") {
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $_data);
    }
    $response = curl_exec($ch);
    if (!$response) {
      log::add(__CLASS__, 'debug', 'request error, cannot fetch info');
      $error_msg = curl_error($ch);
      $err_no = curl_errno($ch);
      log::add(__CLASS__, 'debug', "request error no=$err_no message=$error_msg");
    } else 
      log::add(__CLASS__, 'debug', "request response=".$response);
    curl_close($ch);
    log::add(__CLASS__, 'debug', 'request stop');
    return json_decode($response,true);
  }

  public static function login($_username, $_password)
  {
    // login to LM cloud attempt to get the token 
    $data = self::request(LMCLOUD_TOKEN, 
    'username='.$_username. 
    '&password='.$_password.
    '&grant_type=password'. 
    '&client_id='.LMCLIENT_ID.
    '&client_secret='.LMCLIENT_SECRET, 
    'POST');
    log::add(__CLASS__, 'debug', 'login ' . json_encode($data, true));
    cache::delete('jee4lm::access_token'); 
    config::save('refreshToken', '', 'jee4lm');
    config::save('accessToken', '', 'jee4lm');
  if ($data['access_token']!='') {
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
  public static function refreshToken() {
    $refresh=config::byKey('refreshToken', 'jee4lm');
    $_username=config::byKey('userId', 'jee4lm');
    $_password=config::byKey('userPwd', 'jee4lm');
    config::save('refreshToken', '', 'jee4lm');
    config::save('accessToken', '', 'jee4lm');
  // try to detect the machines only if token succeeded
    log::add(__CLASS__, 'debug', 'refresh token');
    $data = self::request(LMCLOUD_TOKEN, 
      'grant_type=refresh_token'.
      '&refresh_token='.$refresh. 
      '&client_id='.LMCLIENT_ID.
      '&client_secret='.LMCLIENT_SECRET, 
      'POST');
    log::add(__CLASS__, 'debug', 'tokenrequest=' . json_encode($data, true));
    cache::delete('jee4lm::access_token'); 
    if ($data['access_token']!='') {
      cache::set('jee4lm::access_token', $data['access_token'], 300);    
      config::save('refreshToken',  $data['refresh_token'], 'jee4lm');
      config::save('accessToken', $data['access_token'], 'jee4lm');
      return $data['access_token'];
    }    
    return '';
  }

  public static function getToken() {
    $mc = cache::byKey('jee4lm::access_token');
    $access_token = $mc->getValue();
    if (config::byKey('accessToken', 'jee4lm')=='') // no login performed yet
      return '';
    if ($access_token =='') 
      $access_token = self::refreshToken();
    return $access_token;
  }

  /*
  la fonction CRON permet d'interroger les registres toutes les minutes. 
  le temps de mise à jour du poele peut aller de 1 à 5 minutes selon la source qui a déclenché le réglage
  depuis l'application cloud c'est plus long à être pris en compte
  */
  public static function cron()
  {
    log::add(__CLASS__, 'debug', 'cron start');
    foreach (eqLogic::byType(__CLASS__, true) as $jee4lm) {
      if ($jee4lm->getIsEnable()) {
        if (($serial = $jee4lm->getConfiguration('serialNumber')) != '') {
          /* lire les infos de l'équipement ici */
          $slug= $jee4lm->getConfiguration('type');
          $id = $jee4lm->getId();
          log::add(__CLASS__, 'debug', "cron for ID=" . $id);
          log::add(__CLASS__, 'debug', "cron     serial=" . $serial);
          log::add(__CLASS__, 'debug', "cron     slug=" . $slug);
          if ($slug!= '') {
            $token = self::getToken(); // send query for token and refresh it if necessary
            if ($token !='')
              if ($jee4lm->readconfiguration($jee4lm)) // translate registers to jeedom values, return true if successful
                log::add(__CLASS__, 'debug', 'cron ok');
              else
                log::add(__CLASS__, 'debug', 'cron error on readconfiguration');
          }
        } 
      } else 
      log::add(__CLASS__, 'debug', 'equipment is disabled, cron skiped');
    }
    log::add(__CLASS__, 'debug', 'cron end');
  }

  public static function pull($_options = null)
  {
    log::add(__CLASS__, 'debug', 'pull start');
    $cron = cron::byClassAndFunction(__CLASS__, 'pull', $_options);
    if (is_object($cron)) {
      $cron->remove();
    }
    log::add(__CLASS__, 'debug', 'pull end');
  }
   
  public static function deadCmd()
  {
    
    log::add(__CLASS__, 'debug', 'deadcmd start');
    $return = array();
    foreach (eqLogic::byType(__CLASS__) as $eql) {
      foreach ($eql->getCmd() as $cmd) {
        preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('username', ''), $matches);
        foreach ($matches[1] as $cmd_id) {
          if (!cmd::byId(str_replace('#', '', $cmd_id))) {
            $return[] = array('detail' => __(__CLASS__, __FILE__) . ' ' . $eql->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('Modèle', __FILE__), 'who' => "#" . $cmd_id . "#");
          }
        }
        preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('password', ''), $matches);
        foreach ($matches[1] as $cmd_id) {
          if (!cmd::byId(str_replace('#', '', $cmd_id))) {
            $return[] = array('detail' => __(__CLASS__, __FILE__) . ' ' . $eql->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('Modèle', __FILE__), 'who' => '#' . $cmd_id . '#');
          }
        }
        preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('host', ''), $matches);
        foreach ($matches[1] as $cmd_id) {
          if (!cmd::byId(str_replace('#', '', $cmd_id))) {
            $return[] = array('detail' => __(__CLASS__, __FILE__) . ' ' . $eql->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('Modèle', __FILE__), 'who' => '#' . $cmd_id . '#');
          }
        }
        preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('auth_token', ''), $matches);
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

  private function getMachines()
  {

  }
  private function toggleVisible($_logicalId, $state)
  {
    $Command = $this->getCmd(null, $_logicalId);
    if (is_object($Command)) {
      log::add(__CLASS__, 'debug', 'toggle visible state of ' . $_logicalId . " to " . $state);
      $Command->setIsVisible($state);
      $Command->save();
      return true;
    }
    return false;
  }
  public function refresh()
  {
    foreach ($this->getCmd() as $cmd) {
      $s = print_r($cmd, 1);
      log::add(__CLASS__, 'debug', 'refresh  cmd: ' . $s);
      $cmd->execute();
    }
  }

  public function postSave()
  {
    log::add(__CLASS__, 'debug', 'postsave start');
  }

  public function preUpdate()
  {
    log::add(__CLASS__, 'debug', 'preupdate start');
    log::add(__CLASS__, 'debug', 'preupdate stop');
  }

  public function postUpdate()
  {
    log::add(__CLASS__, 'debug', 'postupdate start');
//    $this->applyModuleConfiguration();
    log::add(__CLASS__, 'debug', 'postupdate stop');
  }

  public function preRemove()
  {
  }

  public function postRemove()
  {
  }

  public function startBackflush()
  {
    log::add(__CLASS__, 'debug', 'backflush start');
    log::add(__CLASS__, 'debug', 'backflush stop');
  }

public static function readConfiguration($eq) {
  log::add(__CLASS__, 'debug', 'read configuration');
  $serial=$eq->getConfiguration('serialNumber'); 
  $token=self::getToken();
  $data = self::request(LMCLOUD_GW_MACHINE_BASE_URL.'/'.$serial.'/configuration',null,'GET',["Authorization: Bearer $token"]);
  log::add(__CLASS__, 'debug', 'config='.json_encode($data, true));
  if ($data['status']== true) {
    $machine = $data['data'];
    if ($machine['machineCapabilities'][0]['family']=='LINEA') { // linea mini
      log::add(__CLASS__, 'debug', 'S/N='.$machine['machine_sn']);
      
      $cmd=$eq->AddCommand("Sur réseau d'eau",'plumbedin','info','binary', null, null,null,1);  
      $cmd->event($machine['isPlumbedIn']);    
      log::add(__CLASS__, 'debug', 'plumbedin='.($machine['isPlumbedIn']?'yes':'no'));

      $cmd=$eq->AddCommand("Etat Backflush",'backflush','info','binary', null, null,null,1);
      $cmd->event($machine['isBackFlushEnabled']);    
      log::add(__CLASS__, 'debug', 'backflush='.($machine['isBackFlushEnabled']?'yes':'no'));

      $cmd=$eq->AddCommand("Réservoir plein",'tankStatus','info','binary', null, null,null,1);
      $cmd->event($machine['tankStatus']);    
      log::add(__CLASS__, 'debug', 'tankStatus='.($machine['tankStatus']?'ok':'empty'));

      $bbw = $machine['recipes'][0];
      $bbwset = $machine['recipeAssignment'][0];

      $cmd=$eq->AddCommand("BBW Etat",'bbwmode','info','string', null, null,null,1);
      $cmd->event($bbwset['recipe_dose']);    
      log::add(__CLASS__, 'debug', 'bbwmode='.$bbwset['recipe_dose']);

      $cmd=$eq->AddCommand("BBW Dose A",'bbwdoseA','info','numeric', null, "g",null,1);
      $cmd->event($bbw['recipe_doses'][0]['target']);    
      log::add(__CLASS__, 'debug', 'bbwdoseA='.$bbw['recipe_doses'][0]['target']);

      $cmd=$eq->AddCommand("BBW Dose B",'bbwdoseB','info','numeric', null, "g",null,1);
      $cmd->event($bbw['recipe_doses'][1]['target']);    
      log::add(__CLASS__, 'debug', 'bbwdoseB='.$bbw['recipe_doses'][1]['target']);

      $g = $machine['groupCapabilities'][0];
      $reglage = $g['doses'][0];
      
      $cmd=$eq->AddCommand("Groupe Réglage sur Dose",'groupDoseMode','info','string', null, null,null,1);
      $cmd->event($reglage['doseIndex']); 
      log::add(__CLASS__, 'debug', 'groupDoseMode='.$reglage['doseIndex']);

      $cmd=$eq->AddCommand("Groupe Type de Dose",'groupDoseType','info','string', null, null,null,1);
      $cmd->event($reglage['doseType']); 
      log::add(__CLASS__, 'debug', 'groupDoseType='.$reglage['doseType']);
 
      $cmd=$eq->AddCommand("Groupe Dose max",'groupDoseMax','info','numeric', null, "g",null,1);
      $cmd->event($reglage['stopTarget']); 
      log::add(__CLASS__, 'debug', 'groupDoseMax='.$reglage['stopTarget']);
      
      $cmd=$eq->AddCommand("Etat",'machinemode','info','binary', null, null,null,1);
      $cmd->event(($machine['machineMode']=="StandBy"?false:true)); 
      log::add(__CLASS__, 'debug', 'machinemode='.$machine['machineMode']);

      $cmd=$eq->AddCommand("BBW Présent",'isbbw','info','binary', null, null,null,1);
      $cmd->event(($machine['scale']['address']==''?false:true)); 
      log::add(__CLASS__, 'debug', 'isbbw='.($machine['scale']['address']!=''?'yes':'no'));

      $cmd=$eq->AddCommand("BBW balance connectée",'isscaleconnected','info','binary', null, null,null,1);
      $cmd->event($machine['scale']['connected']); 
      log::add(__CLASS__, 'debug', 'isscaleconnected='.($machine['scale']['connected']?'yes':'no'));

      log::add(__CLASS__, 'debug', 'scalemac='.$machine['scale']['address']);
      $eq->setConfiguration("scalemac",$machine['scale']['address']);

      log::add(__CLASS__, 'debug', 'scalename='.$machine['scale']['name']);
      $eq->setConfiguration("scalename",$machine['scale']['name']);

      $cmd=$eq->AddCommand("BBW batterie",'scalebattery','info','numeric', null, "%",null,1);
      $cmd->event($machine['scale']['battery']); 
      log::add(__CLASS__, 'debug', 'scalebattery='.$machine['scale']['battery']);

      $boilers = $machine['boilers'];
      foreach($boilers as $boiler) {
        if ($boiler['id']=='SteamBoiler')
        {
          $cmd=$eq->AddCommand("Vapeur activée",'steamenabled','info','binary', null, null,null,1);
          $cmd->event($boiler['isEnabled']); 
          log::add(__CLASS__, 'debug', 'steamenabled='.($boiler['isEnabled']?'yes':'no'));

          $cmd=$eq->AddCommand("Vapeur temperature cible",'steamtarget','info','numeric', null, '°C',null,1);
          $cmd->event($boiler['target']); 
          log::add(__CLASS__, 'debug', 'steamtarget='.$boiler['target']);

          $cmd=$eq->AddCommand("Vapeur température actuelle",'steamcurrent','info','binary', null, '°C',null,1);
          $cmd->event($boiler['current']); 
          log::add(__CLASS__, 'debug', 'steamcurrent='.$boiler['current']);
        }
        if ($boiler['id']=='CoffeeBoiler1')
        {
          $cmd=$eq->AddCommand("Cafetière activée",'coffeeenabled','info','binary', null, null,null,1);
          $cmd->event($boiler['isEnabled']); 
          log::add(__CLASS__, 'debug', 'coffeeenabled='.($boiler['isEnabled']?'yes':'no'));

          $cmd=$eq->AddCommand("Cafetière temperature cible",'coffeetarget','info','numeric', null, '°C',null,1);
          $cmd->event($boiler['target']); 
          log::add(__CLASS__, 'debug', 'coffeetarget='.$boiler['target']);

          $cmd=$eq->AddCommand("Cafetière temperature actuelle",'coffeecurrent','info','numeric', null, '°C',null,1);
          $cmd->event($boiler['current']); 
          log::add(__CLASS__, 'debug', 'coffeecurrent='.$boiler['current']);
        }
      }
      $preinfusion = $machine['preinfusionSettings'];
      $cmd=$eq->AddCommand("Préinfusion",'preinfusionmode','info','binary', null, null,null,1);
      $cmd->event($preinfusion['mode']=='Enabled'); 
      log::add(__CLASS__, 'debug', 'preinfusionmode='.($preinfusion['mode']=='Enabled'));

      $cmd=$eq->AddCommand("Prétrempage",'prewet','info','binary', null, null,null,1);
      $cmd->event($preinfusion['Group1'][0]['preWetTime']>0 && $preinfusion['Group1'][0]['preWetHoldTime'] >0); 

      $cmd=$eq->AddCommand("Prétrempage durée",'prewettime','info','numeric', null, 's',null,1);
      $cmd->event($preinfusion['Group1'][0]['preWetTime']); 
      log::add(__CLASS__, 'debug', 'prewetTime='.$preinfusion['Group1'][0]['preWetTime']);

      $cmd=$eq->AddCommand("Prétrempage pause",'prewetholdtime','info','numeric', null, 's',null,1);
      $cmd->event($preinfusion['Group1'][0]['preWetHoldTime']); 
      log::add(__CLASS__, 'debug', 'preWetHoldTime='.$preinfusion['Group1'][0]['preWetHoldTime']);
      
//      log::add(__CLASS__, 'debug', 'prewetdose='.$preinfusion['Group1'][0]['doseType']);
      $fw = $machine['firmwareVersions'];
      $cmd=$eq->AddCommand("Version Firmware",'fwversion','info','other', null, null,null,1);
      $cmd->event($fw[0]['fw_version']); 
      log::add(__CLASS__, 'debug', 'fwversion='.$fw[0]['fw_version']);

      $cmd=$eq->AddCommand("Version Gateway",'gwversion','info','other', null, null,null,1);
      $cmd->event($fw[1]['fw_version']); 
      log::add(__CLASS__, 'debug', 'gwversion='.$fw[1]['fw_version']);
    }
  }
  /*
 config={"status":true,"data":
 {"version":"v1",
 "preinfusionModesAvailable":["ByDoseType"],
 "machineCapabilities":[{"family":"LINEA","groupsNumber":1,"coffeeBoilersNumber":1,"hasCupWarmer":false,"steamBoilersNumber":1,"teaDosesNumber":1,"machineModes":["BrewingMode","StandBy"],"schedulingType":"smartWakeUpSleep"}],
 "machine_sn":"Sn2307902283","machine_hw":"0","isPlumbedIn":false,"isBackFlushEnabled":false,"standByTime":0,"tankStatus":true,"settings":[],
 "recipes":[{"id":"Recipe1","dose_mode":"Mass",
 "recipe_doses":[{"id":"A","target":32},{"id":"B","target":45}]}],"recipeAssignment":[{"dose_index":"DoseA","recipe_id":"Recipe1","recipe_dose":"A","group":"Group1"}],
 "groupCapabilities":[{"capabilities":{"groupType":"AV_Group","groupNumber":"Group1","boilerId":"CoffeeBoiler1","hasScale":false,"hasFlowmeter":false,"numberOfDoses":1},
 "doses":[{"groupNumber":"Group1","doseIndex":"DoseA","doseType":"MassType","stopTarget":32}],"doseMode":{"groupNumber":"Group1","brewingType":"ManualType"}}],
 "machineMode":"StandBy",
 "teaDoses":{"DoseA":{"doseIndex":"DoseA","stopTarget":0}},"scale":{"connected":false,"address":"44:b7:d0:74:5f:90","name":"LMZ-745F90","battery":64},"boilers":[{"id":"SteamBoiler","isEnabled":false,"target":0,"current":0},
 {"id":"CoffeeBoiler1","isEnabled":true,"target":89,"current":42}],"boilerTargetTemperature":{"SteamBoiler":0,"CoffeeBoiler1":89},
 "preinfusionMode":{"Group1":{"groupNumber":"Group1","preinfusionStyle":"PreinfusionByDoseType"}},"preinfusionSettings":{"mode":"Enabled","Group1":[{"groupNumber":"Group1","doseType":"DoseA","preWetTime":2,"preWetHoldTime":3}]},"wakeUpSleepEntries":[{"id":"T6aLl42","days":["monday","tuesday","wednesday","thursday","friday","saturday","sunday"],"steam":false,"enabled":false,"timeOn":"24:0","timeOff":"24:0"}],"smartStandBy":{"mode":"LastBrewing","minutes":10,"enabled":true},"clock":"2024-08-31T14:47:45","firmwareVersions":[{"name":"machine_firmware","fw_version":"2.12"},{"name":"gateway_firmware","fw_version":"v3.6-rc4"}]}}
2223|[2024-08-31 14:49:02] DEBUG  
  */
  return true;
}


public function AddCommand(
  $Name,$_logicalId,$Type = 'info',$SubType = 'binary',$Template = null, $unite = null,$generic_type = null,$IsVisible = 1,$icon = 'default',$forceLineB = 'default', $valuemin = 'default',
  $valuemax = 'default', $_order = null, $IsHistorized =0, $repeatevent = false, $_iconname = null, $_calculValueOffset = null, $_historizeRound = null, $_noiconname = null, $_warning = null, $_danger = null, $_invert = 0 ) 
  {

 
  $createCmd = true;
  $Command = $this->getCmd(null, $_logicalId);
  if (!is_object($Command)) { // check if action is already defined, if yes avoid duplicating
    $Command = cmd::byEqLogicIdCmdName($this->getId(), $_logicalId);
    if (is_object($Command)) {
      $createCmd = false;
      log::add(__CLASS__, 'debug', ' command already exists ');
    }
  }

  if ($createCmd) {
    log::add(__CLASS__, 'debug', ' add record for ' . $Name);
    if (!is_object($Command)) {
      // basic settings
      $Command = new jee4lmCmd();
      // $Command->setId(null);
      $Command->setLogicalId($_logicalId);
      $Command->setEqLogic_id($this->getId());
      $Command->setName($Name);
      $Command->setType($Type);
      $Command->setSubType($SubType);
    }
    
    $Command->setIsVisible($IsVisible);
    if ($IsHistorized!=null) $Command->setIsHistorized(strval($IsHistorized));
    if ($Template != null) {
      $Command->setTemplate('dashboard', $Template);
      $Command->setTemplate('mobile', $Template);
    }
    if ($unite != null && $SubType == 'numeric')
      $Command->setUnite($unite);
    if ($icon != 'default')
      $Command->setdisplay('icon', '<i class="' . $icon . '"></i>');
    if ($forceLineB != 'default')
      $Command->setdisplay('forceReturnLineBefore', 1);
    if ($_iconname != 'default')
      $Command->setdisplay('showIconAndNamedashboard', 1);
    if ($_noiconname != null)
      $Command->setdisplay('showNameOndashboard', 0);
    if ($_calculValueOffset != null)
      $Command->setConfiguration('calculValueOffset', $_calculValueOffset);
    if ($_historizeRound != null)
      $Command->setConfiguration('historizeRound', $_historizeRound);
    if ($generic_type != null)
      $Command->setGeneric_type($generic_type);
    if ($repeatevent == true && $Type == 'info')
      $Command->setConfiguration('repeatEventManagement', 'never');
    if ($valuemin != 'default')
      $Command->setConfiguration('minValue', $valuemin);
    if ($valuemax != 'default')
      $Command->setConfiguration('maxValue', $valuemax);
    if ($_warning != null)
      $Command->setDisplay("warningif", $_warning);
    if ($_order != null)
      $Command->setOrder($_order);
    if ($_danger != null)
      $Command->setDisplay("dangerif", $_danger);
    if ($_invert != null)
      $Command->setDisplay('invertBinary', $_invert);      
    $Command->save();
    log::add(__CLASS__, 'debug', 'command saved');
  }
  log::add(__CLASS__, 'debug', ' addcommand end');
  return $Command;
}


public function toggleMain() {
  log::add(__CLASS__, 'debug', 'toggle Main start');
  $mc = cache::byKey('jee4lm::access_token');
  $token = trim($mc->getValue());
  // try to detect the machines only if token succeeded
  if ($token=='') {
    log::add(__CLASS__, 'debug', '[detect] login not done or token empty, exit');
    return false;
  }
  $token=config::byKey('accessToken','jee4lm');
  $serial=config::byKey('serialNumber','jee4lm');
  $status=(true?"BrewingMode":"Standby");
  $data = self::request(LMCLOUD_GW_MACHINE_BASE_URL.'/'.$serial.'/status',"status=".$status,'POST',["Authorization: Bearer $token"]);
  log::add(__CLASS__, 'debug', 'config='.json_encode($data, true));

}

  public static function detect() 
  {
    log::add(__CLASS__, 'debug', '[detect] start');
    $token = self::getToken();
    // try to detect the machines only if token succeeded
    if ($token=='') {
      log::add(__CLASS__, 'debug', '[detect] login not done or token empty, exit');
      return false;
    }
    $data = self::request(LMCLOUD_CUSTOMER,null,'GET',["Authorization: Bearer $token"]);
    log::add(__CLASS__, 'debug', 'detect='.json_encode($data, true));
    if ($data["status"] != true)
      return false;
    foreach ($data['data']['fleet'] as $machines) {
      log::add(__CLASS__, 'debug', 'detect found '.($uuid=$machines['uuid'])." ".$machines['name'].'('.$machines['machine']['model']['name'].') SN='.$machines['machine']['serialNumber']);
      log::add(__CLASS__, 'debug', 'type='.$machines['machine']['type']);
      if ($machines['machine']['type'] == LMFILTER_MACHINE_TYPE) {
        $d = DateTime::createFromFormat(DateTime::ATOM, $machines['paringDate']);
        log::add(__CLASS__, 'debug', 'slug='.($slug=$machines['machine']['model']['slug']));
        log::add(__CLASS__, 'debug', 'key='.$machines['communicationKey']);
        log::add(__CLASS__, 'debug', 'detect paired on '.$d->format("d/m/y"));  
        // now check if machine is already created as an eqlogic
        $eqLogic = eqLogic::byLogicalId($uuid, 'jee4lm');
        if (!is_object($eqLogic)) {
          $eqLogic = new jee4lm();
          $eqLogic->setEqType_name('jee4lm');
          $eqLogic->setIsEnable(1);
          $eqLogic->setName($machines['name']);
          $eqLogic->setCategory('other', 1);
          $eqLogic->setIsVisible(1);
          log::add(__CLASS__, 'debug', 'uuid created');
        } else
          log::add(__CLASS__, 'debug', 'uuid update only');
        $eqLogic->setConfiguration('type', $slug);
        $eqLogic->setConfiguration('communicationKey', $machines['communicationKey']);
        $eqLogic->setConfiguration('pairingDate', $d->format("d/m/y"));
        $eqLogic->setConfiguration('model', $machines['machine']['model']['name']);     
        $eqLogic->setLogicalId($uuid);
        log::add(__CLASS__, 'debug', 'eqlogic saved');
        // now get configuration of machine
        $eqLogic->setConfiguration('serialNumber', $machines['machine']['serialNumber']);     
       // self::LMgetConfiguration($machines['machine']['serialNumber'], $eqLogic);
        $eqLogic->save();
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
        "gender":null,"hobbies":[],"ownMachine":1,"privacy":null,"socialNetworkId":null,"business":null,"businessType":null,"coffeeRoaster":null,"countryOther":null,"areaOperation":null,"brandServiced":null,"personOfContact":null,"phoneModel":null,"id":133029}}
    */
      return true;
  }

  public function getInformations()
  {
    log::add(__CLASS__, 'debug', 'getinformation start');
    $machines = eqLogic::byType(__CLASS__);
    foreach ($machines as $machine) {
      $serial = $machine->getConfiguration('serialNumber', 'jee4lm');
      log::add(__CLASS__, 'debug', "fetched $serial");
    }
    log::add(__CLASS__, 'debug', 'getinformation stop');
    return true;
  }

  public function getjee4lm()
  {
    log::add(__CLASS__, 'debug', "getjee4lm");
    $this->checkAndUpdateCmd(__CLASS__, "");
  }
  public function authenticate() {
    log::add(__CLASS__, 'debug', 'authenticate start');

    // Add logic to authenticate with La Marzocco API
//    $username = $this->getConfiguration('username');
//    $password = $this->getConfiguration('password');
//    log::add(__CLASS__, 'debug', "try to log with u=$username p=$password");
//    if ($username=="" || $password=="") {
//      log::add(__CLASS__, 'debug', 'cannot authenticate as there is no user/password defined');
//      $this->setConfiguration('auth_token', '');
//     log::add(__CLASS__, 'debug', 'token storage cleared');
//      return;
//    }
    $host = "https://cms.lamarzocco.io/oauth/v2/token";
    $username = $this->getConfiguration('username', null);
    $password = $this->getConfiguration('password', null);

    if ($host=="") {
      log::add(__CLASS__, 'debug', 'cannot authenticate as there is no host defined');
      return;
    }
    $url = $host;
    // Utiliser cURL ou une autre méthode pour appeler l'API de La Marzocco
    log::add(__CLASS__, 'debug', 'authenticate query url='.$url);

    $data = 
      'username='.$username. 
      '&password='.$password.
      '&grant_type=password'. 
      '&client_id='.LMCLIENT_ID.
      '&client_secret='.LMCLIENT_SECRET;

    log::add(__CLASS__, 'debug', 'authenticate data='.$data);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/x-www-form-urlencoded"]);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $response = curl_exec($ch);
    if (!$response) {
      log::add(__CLASS__, 'debug', 'authenticate error, cannot fetch token');
      $error_msg = curl_error($ch);
      $err_no = curl_errno($ch);
      log::add(__CLASS__, 'debug', "authenticate error no=$err_no message=$error_msg");
    } else {
      log::add(__CLASS__, 'debug', "authenticate response=".$response);
      $items = json_decode($response, true);
      if (isset($items['access_token']))
        $this->setConfiguration('auth_token', $items['access_token']);
      else
        log::add(__CLASS__, 'debug', "no token found in response");
      }
    curl_close($ch);
    log::add(__CLASS__, 'debug', 'authenticate stop');
  }

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
		}
		catch (\Exception $e) {
			log::add(__CLASS__, 'warning', '[VERSION] Get ERROR :: ' . $e->getMessage());
		}
		log::add(__CLASS__, 'info', '[VERSION] PluginVersion :: ' . $pluginVersion);
        return $pluginVersion;
    }
}

class jee4lmCmd extends cmd
{
  public function dontRemoveCmd()
  {
    if ($this->getLogicalId() == 'refresh') {
      return true;
    }
    return false;
  }
  public function execute($_options = null)
  {
    $action = $this->getLogicalId();
    $eq = $this->getEqLogic();
    log::add(__CLASS__, 'debug', 'execute action ' . $action);
    switch ($action) {
      case 'refresh':
        return $eq->getInformations();
        case 'toggleonoff':
          return $eq->toggleMain();
        case 'start_backflush':
        return $eq->startBackflush();
      case 'getStatus':
        return $eq->getInformations();
    }
  }

}

