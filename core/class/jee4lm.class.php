<?php

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

const 
LMCLIENT_ID = "7_1xwei9rtkuckso44ks4o8s0c0oc4swowo00wgw0ogsok84kosg", 
LMCLIENT_SECRET ="2mgjqpikbfuok8g4s44oo4gsw0ks44okk4kc4kkkko0c8soc8s",
LMDEFAULT_PORT_LOCAL = 8081;

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
    return $response;

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
    $data = self::request('https://cms.lamarzocco.io/oauth/v2/token', 
    'username='.$_username. 
    '&password='.$_password.
    '&grant_type=password'. 
    '&client_id='.LMCLIENT_ID.
    '&client_secret='.LMCLIENT_SECRET, 
    'POST');
    log::add(__CLASS__, 'debug', '[login] ' . json_encode($data, true));
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

  public static function detect() 
  {
    log::add(__CLASS__, 'debug', '[detect] start');
    $userID = config::byKey('userID','jee4lm');
    $password = config::byKey('userPwd','jee4lm');
    $mc = cache::byKey('jee4lm::access_token');
    $token = trim($mc->getValue());
    // try to detect the machines only if token succeeded
    if ($userID=='' || $password =='' || $token=='') {
      log::add(__CLASS__, 'debug', "[detect] login=$userID");
      log::add(__CLASS__, 'debug', "[detect] password=$password");
      log::add(__CLASS__, 'debug', "[detect] token=$token");
      log::add(__CLASS__, 'debug', '[detect] login not done, exit');
      return false;
    }
    $data = self::request('https://cms.lamarzocco.io/api/customer',null,'GET',["Authorization : Bearer {access_token}"]);
    log::add(__CLASS__, 'debug', 'detect='.json_encode($data, true));
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

