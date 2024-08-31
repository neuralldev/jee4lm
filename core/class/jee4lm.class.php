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

/*
    if ($_path != '/login' && $_path != '/refresh') {
      $mc = cache::byKey('ajaxSystem::sessionToken');
      $sessionToken = $mc->getValue();
      if (trim($mc->getValue()) == '') {
        $sessionToken = self::refreshToken();
      }
      $url .= '&session_token=' . $sessionToken;
    }
    if ($_data !== null && $_type == 'GET') {
      $url .= '&options=' . urlencode(json_encode($_data));
    }
    $request_http = new com_http($url);
    $request_http->setHeader(array(
      'Content-Type: application/json',
      'Autorization: ' . sha512(mb_strtolower(config::byKey('market::username')) . ':' . config::byKey('market::password'))
    ));
    log::add('ajaxSystem', 'debug', '[request] ' . $url . ' => ' . json_encode($_data));
    if ($_type == 'POST') {
      $request_http->setPost(json_encode($_data));
    }
    if ($_type == 'PUT') {
      $request_http->setPut(json_encode($_data));
    }
    $return = json_decode($request_http->exec(30, 1), true);
    $return = is_json($return, $return);
    if (isset($return['error'])) {
      throw new \Exception(__('Erreur lors de la requete à Ajax System : ', __FILE__) . json_encode($return));
    }
    if (isset($return['errors'])) {
      throw new \Exception(__('Erreur lors de la requete à Ajax System : ', __FILE__) . json_encode($return));
    }
    if (isset($return['body'])) {
      return $return['body'];
    }
    return $return;
    */
  }

  public function pull($_options = null)
  {
    log::add(__CLASS__, 'debug', 'pull start');
    $cron = cron::byClassAndFunction(__CLASS__, 'pull', $_options);
    if (is_object($cron)) {
      $cron->remove();
    }
    log::add(__CLASS__, 'debug', 'pull end');
    return;
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
    log::add(__CLASS__, 'debug', '[login] ' . json_encode($data, true));
    cache::set('jee4lm::access_token',""); 
    if ($data['access_token']!='') {
      log::add(__CLASS__, 'debug', '[login] valid');
      config::save('refreshToken', $data['refresh_token'], 'jee4lm');
      config::save('accessToken', $data['access_token'], 'jee4lm');
      config::save('userId', $_username, 'jee4lm');
      config::save('userPwd', $_password, 'jee4lm');
      cache::set('jee4lm::access_token', $data['access_token'], 3600);  
      return true;
    }
    return false;
  }

public static function LMgetConfiguration($serial, $eq) {
  log::add(__CLASS__, 'debug', 'read configuration');
  $mc = cache::byKey('jee4lm::access_token');
  $token = trim($mc->getValue());
  // try to detect the machines only if token succeeded
  if ($token=='') {
    log::add(__CLASS__, 'debug', '[get config] login not done or token empty, exit');
    return false;
  }
  $token=config::byKey('accessToken','jee4lm'); 
   $data = self::request(LMCLOUD_GW_MACHINE_BASE_URL.'/'.$serial.'/configuration',null,'GET',["Authorization: Bearer $token"]);
  log::add(__CLASS__, 'debug', 'config='.json_encode($data, true));
  if ($data['status']== true) {
    $machine = $data['data'];
    if ($machine['machineCapabilities'][0]['family']=='LINEA') { // linea mini
      log::add(__CLASS__, 'debug', 'S/N='.$machine['machine_sn']);
      log::add(__CLASS__, 'debug', 'plumbedin='.($machine['isPlumbedIn']?'yes':'no'));
      log::add(__CLASS__, 'debug', 'backflush in process='.($machine['isBackFlushEnabled']?'yes':'no'));
      log::add(__CLASS__, 'debug', 'tankStatus='.($machine['tankStatus']?'ok':'empty'));
      $bbw = $machine['recipes'][0];
      $bbwset = $machine['recipeAssignment'][0];
      log::add(__CLASS__, 'debug', 'bbwmode='.$bbwset['recipe_dose']);
      log::add(__CLASS__, 'debug', 'bbwdoseA='.$bbw['recipe_doses'][0]['target']);
      log::add(__CLASS__, 'debug', 'bbwdoseB='.$bbw['recipe_doses'][1]['target']);
      $g = $machine['groupCapabilities'][0];
      $reglage = $g['doses'][0];
      log::add(__CLASS__, 'debug', 'groupDoseMode='.$reglage['doseIndex']);
      log::add(__CLASS__, 'debug', 'groupDoseType='.$reglage['doseType']);
      log::add(__CLASS__, 'debug', 'groupDoseStop='.$reglage['stopTarget']);
      log::add(__CLASS__, 'debug', 'actualmode='.$machine['machineMode']);
      log::add(__CLASS__, 'debug', 'isbbw='.($machine['scale']['address']!=''?'yes':'no'));
      log::add(__CLASS__, 'debug', 'isscaleconnected='.($machine['scale']['connected']?'yes':'no'));
      log::add(__CLASS__, 'debug', 'scalemac='.$machine['scale']['address']);
      log::add(__CLASS__, 'debug', 'scalename='.$machine['scale']['name']);
      log::add(__CLASS__, 'debug', 'scalebattery='.$machine['scale']['battery']);
      $boilers = $machine['boilers'];
      foreach($boilers as $boiler) {
        if ($boiler['id']=='SteamBoiler')
        {
          log::add(__CLASS__, 'debug', 'steamenabled='.($boiler['isEnabled']?'yes':'no'));
          log::add(__CLASS__, 'debug', 'steamtarget='.$boiler['target']);
          log::add(__CLASS__, 'debug', 'steamcurrent='.$boiler['current']);
        }
        if ($boiler['id']=='CoffeeBoiler1')
        {
          log::add(__CLASS__, 'debug', 'steamenabled='.($boiler['isEnabled']?'yes':'no'));
          log::add(__CLASS__, 'debug', 'steamtarget='.$boiler['target']);
          log::add(__CLASS__, 'debug', 'steamcurrent='.$boiler['current']);
        }
      }
      $preinfusion = $machine['preinfusionSettings'];
      log::add(__CLASS__, 'debug', 'preinfusion='.($preinfusion['mode']=='Enabled'));
      log::add(__CLASS__, 'debug', 'prewetTime='.$preinfusion['Group1'][0]['preWetTime']);
      log::add(__CLASS__, 'debug', 'preWetHoldTime='.$preinfusion['Group1'][0]['preWetHoldTime']);
      log::add(__CLASS__, 'debug', 'prewetdose='.$preinfusion['Group1'][0]['doseType']);
      $fw = $machine['firmwareVersions'];
      log::add(__CLASS__, 'debug', 'fwversion='.$fw[0]['fw_version']);
      log::add(__CLASS__, 'debug', 'gwversion='.$fw[1]['fw_version']);

    }
  }
  /*
  {"status":true,
  "data":
  {
  "version":"v1",
  "preinfusionModesAvailable":["ByDoseType"],
  "machineCapabilities":
    [{"family":"LINEA",
    "groupsNumber":1,
    "coffeeBoilersNumber":1,
    "hasCupWarmer":false,
    "steamBoilersNumber":1,
    "teaDosesNumber":1,
    "machineModes":[
      "BrewingMode","StandBy"],
    "schedulingType":"smartWakeUpSleep"}
    ],
  "machine_sn":"Sn2307902283",
  "machine_hw":"0",
  "isPlumbedIn":false,
  "isBackFlushEnabled":false,
  "standByTime":0,
  "tankStatus":true,
  "settings":[],
  "recipes":[
    {"id":"Recipe1",
     "dose_mode":"Mass",
     "recipe_doses":[
      {"id":"A","target":30},
      {"id":"B","target":45}
      ]
    }
  ],
  "recipeAssignment":[
    {"dose_index":"DoseA",
    "recipe_id":"Recipe1",
    "recipe_dose":"A",
    "group":"Group1"}
  ],
  "groupCapabilities":[
    {"capabilities":
      {"groupType":"AV_Group",
      "groupNumber":"Group1",
      "boilerId":"CoffeeBoiler1",
      "hasScale":false,
      "hasFlowmeter":false,
      "numberOfDoses":1
    },
    "doses":[
      {"groupNumber":"Group1",
      "doseIndex":"DoseA",
      "doseType":"MassType",
      "stopTarget":30}
    ],
    "doseMode":{
      "groupNumber":"Group1",
      "brewingType":"ManualType"}
    }
  ],
  "machineMode":"StandBy",
  "teaDoses":
    {"DoseA":{
      "doseIndex":"DoseA",
      "stopTarget":0}
    },
  "scale":
    {"connected":false,
    "address":"44:b7:d0:74:5f:90",
    "name":"LMZ-745F90",
    "battery":65},
  "boilers":[
    {"id":"SteamBoiler",
    "isEnabled":false,
    "target":0,
    "current":0},
    {"id":"CoffeeBoiler1",
    "isEnabled":true,
    "target":89,
    "current":84}
  ],
  "boilerTargetTemperature":
    {"SteamBoiler":0,"CoffeeBoiler1":89},
  "preinfusionMode":
    {"Group1":
      {"groupNumber":"Group1","preinfusionStyle":"PreinfusionByDoseType"}
    },
  "preinfusionSettings":{
    "mode":"Enabled",
    "Group1":[
      {"groupNumber":"Group1","doseType":"DoseA","preWetTime":2,"preWetHoldTime":3}
      ]
  },
  "wakeUpSleepEntries":[
    {"id":"T6aLl42",
    "days":["monday","tuesday","wednesday","thursday","friday","saturday","sunday"],
    "steam":false,
    "enabled":false,
    "timeOn":"24:0","timeOff":"24:0"}
  ],
  "smartStandBy":{"mode":"LastBrewing","minutes":10,"enabled":true},
  "clock":"2024-08-30T16:09:06",
  "firmwareVersions":[
    {"name":"machine_firmware","fw_version":"2.12"},
    {"name":"gateway_firmware","fw_version":"v3.6-rc4"}
  ]
  }
}
  */
  return true;
}

  public static function detect() 
  {
    log::add(__CLASS__, 'debug', '[detect] start');
    $mc = cache::byKey('jee4lm::access_token');
    $token = trim($mc->getValue());
    // try to detect the machines only if token succeeded
    if ($token=='') {
      log::add(__CLASS__, 'debug', '[detect] login not done or token empty, exit');
      return false;
    }
    $token=config::byKey('accessToken','jee4lm');
    log::add(__CLASS__, 'debug', '[detect] token='.json_encode($token));
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
        self::LMgetConfiguration($machines['machine']['serialNumber'], $eqLogic);
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
        // Add logic to retrieve the machine status
        $auth_token = $this->getConfiguration('auth_token');
        if ($auth_token=="") {
          log::add(__CLASS__, 'debug', 'getinformation, no token, exiting');
          return;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.lamarzocco.com/status");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $auth_token"]);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
          log::add(__CLASS__, 'debug', 'getinformation cannot get status');
        }
        // debug phase
        log::add(__CLASS__, 'debug', json_decode($response, true));
        // debug phase

        log::add(__CLASS__, 'debug', 'getinformation stop');
        return json_decode($response, true);
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
      case 'start_backflush':
        return $eq->startBackflush();
      case 'getStatus':
        return $eq->getInformations();
    }
  }

}

