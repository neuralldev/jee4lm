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
    $this->authenticate();
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
    $host = $this->getConfiguration('host', null);
    if ($host=="") {
      log::add(__CLASS__, 'debug', 'cannot authenticate as there is no host defined');
      return;
    }
    $url = "https://".$host.":".LMDEFAULT_PORT_LOCAL.'/auth';
    // Utiliser cURL ou une autre méthode pour appeler l'API de La Marzocco
    log::add(__CLASS__, 'debug', 'authenticate query url='.$url);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    // curl_setopt($ch, CURLOPT_URL, "https://api.lamarzocco.com/auth");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['CLIENT_ID' => LMCLIENT_ID, 'CLIENT_SECRET' => LMCLIENT_SECRET]));
    $response = curl_exec($ch);
    if (!$response) {
      log::add(__CLASS__, 'debug', 'authenticate error, cannot fetch token');
      $error_msg = curl_error($ch);
      $err_no = curl_errno($ch);
      log::add(__CLASS__, 'debug', "authenticate error no=$err_no message=$error_msg");
    } else {
      log::add(__CLASS__, 'debug', "authenticate response=".$response);
      $items = json_decode($response, true);
      $this->setConfiguration('auth_token', $items['token']);
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

