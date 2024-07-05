<?php

require_once __DIR__ . '/../../../core/php/core.inc.php';


class jee4lm extends eqLogic {
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
  
    /**
     * supprime toutes les informations, pour cela cherche les 3 types d'information 
     */
    public static function deadCmd()
  {
    log::add(__CLASS__, 'debug', 'deadcmd start');
    $return = array();
    foreach (eqLogic::byType('jee4lm') as $jee4lm) {
      foreach ($jee4lm->getCmd() as $cmd) {
        preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('username', ''), $matches);
        foreach ($matches[1] as $cmd_id) {
          if (!cmd::byId(str_replace('#', '', $cmd_id))) {
            $return[] = array('detail' => __('jee4lm', __FILE__) . ' ' . $jee4lm->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('Modèle', __FILE__), 'who' => '#' . $cmd_id . '#');
          }
        }
        preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('password', ''), $matches);
        foreach ($matches[1] as $cmd_id) {
          if (!cmd::byId(str_replace('#', '', $cmd_id))) {
            $return[] = array('detail' => __('jee4lm', __FILE__) . ' ' . $jee4lm->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('Modèle', __FILE__), 'who' => '#' . $cmd_id . '#');
          }
        }
        preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('host', ''), $matches);
        foreach ($matches[1] as $cmd_id) {
          if (!cmd::byId(str_replace('#', '', $cmd_id))) {
            $return[] = array('detail' => __('jee4lm', __FILE__) . ' ' . $jee4lm->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('Modèle', __FILE__), 'who' => '#' . $cmd_id . '#');
          }
        }
        preg_match_all("/#([0-9]*)#/", $cmd->getConfiguration('auth_token', ''), $matches);
        foreach ($matches[1] as $cmd_id) {
          if (!cmd::byId(str_replace('#', '', $cmd_id))) {
            $return[] = array('detail' => __('jee4lm', __FILE__) . ' ' . $jee4lm->getHumanName() . ' ' . __('dans la commande', __FILE__) . ' ' . $cmd->getName(), 'help' => __('Modèle', __FILE__), 'who' => '#' . $cmd_id . '#');
          }
        }
      }
    }
    log::add(__CLASS__, 'debug', 'deadcmd end');
    return $return;
  }

/***************************************************************************/

    public function authenticate() {
        $username = $this->getConfiguration('username');
        $password = $this->getConfiguration('password');
        $host = $this->getConfiguration('host', null);
        log::add(__CLASS__, 'debug', 'start authenticate ' . $username . '=' . $password);

        // Utiliser cURL ou une autre méthode pour appeler l'API de La Marzocco
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.lamarzocco.com/auth");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['username' => $username, 'password' => $password]));
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            log::add(__CLASS__, 'debug', 'authenticate : LM cloud answer not received');
            return;
        }

        $this->setConfiguration('auth_token', $token = json_decode($response, true)['token']);
        log::add(__CLASS__, 'debug', 'authenticate done with token='+$token);
    }

    public function getLMValue($jee4lm) {
        if ($jee4lm->getIsEnable()) {
            $id = $jee4lm->getId();
            log::add(__CLASS__, 'debug', "received jeedom ID=" . $id);

            $auth_token = $this->getConfiguration('auth_token');
            if ($auth_token=="") {
                log::add(__CLASS__, 'debug', 'getvalue : token not defined, aborting');
                return FALSE;
            }
    
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.lamarzocco.com/status");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $auth_token"]);
            $response = curl_exec($ch);
            curl_close($ch);
    
            if (!$response) {
                log::add(__CLASS__, 'debug', 'status : LM cloud answer not received');
                return FALSE;
            }
            // Traitez et retournez les données
            return json_decode($response, true);
        }        
        // if jeedom equipment is not eanbled, then do nothing but do not return an error
        log::add(__CLASS__, 'debug', 'getLMValue: equipment is not enabled in Jeedom');
        return FALSE;
    }
    public static function cron()
    {
      log::add(__CLASS__, 'debug', 'cron start');
      foreach (eqLogic::byType(__CLASS__, true) as $jee4lm) {
        if ($jee4lm->getIsEnable()) {
          if (($token = $jee4lm->getConfiguration('auth_token')) != '') {
            $id = $jee4lm->getId();
            log::add(__CLASS__, 'debug', "cron for ID=" . $id);
              $status_return = $jee4lm->getLMValue($jee4lm); // send query
              if ($status_return !="")
                  log::add(__CLASS__, 'debug', 'LM has returned =' . $status_return);
                else
                  log::add(__CLASS__, 'debug', 'LM has not return info');
          } else 
              log::add(__CLASS__, 'debug', 'token not set, cron skiped');
        } else
        log::add(__CLASS__, 'debug', 'equipment is disabled, cron skiped');
      }
      log::add(__CLASS__, 'debug', 'cron end');
    }

    public function AddAction($actionName, $actionTitle, $template = null, $generic_type = null, $visible=1, $SubType = 'other', $min=null, $max=null, $step=null)
    {
      log::add(__CLASS__, 'debug', ' add action ' . $actionName);
      $createCmd = true;
      $command = $this->getCmd(null, $actionName);
      if (!is_object($command)) { // check if action is already defined, if yes avoid duplicating
        $command = cmd::byEqLogicIdCmdName($this->getId(), $actionTitle);
        if (is_object($command))
          $createCmd = false;
      }
      if ($createCmd) { // only if action is not yet defined
        if (!is_object($command)) {
          $command = new jee4lmCmd();
          $command->setLogicalId($actionName);
          $command->setIsVisible($visible);
          $command->setName($actionTitle);
        }
        if ($template != null) {
          $command->setTemplate('dashboard', $template);
          $command->setTemplate('mobile', $template);
        }
        $command->setType('action');
        $command->setSubType($SubType);
        $command->setEqLogic_id($this->getId());
        if ($generic_type != null)
          $command->setGeneric_type($generic_type);
        if ($min != null)
          $command->setConfiguration('minValue', $min);
        if ($max != null)
          $command->setConfiguration('maxValue', $max);
          if ($step != null)
          $command->setDisplay('step', $step);
        $command->save();
      }
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
      private function toggleVisible($_logicalId, $state)
      {
        $Command = $this->getCmd(null, $_logicalId);
        if (is_object($Command)) {
          log::add(__CLASS__, 'debug', 'toggle visible state of ' . $_logicalId . " to " . $state);
          // basic settings
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
          // check for error
        }
      }

      public function postSave()
      {
        log::add(__CLASS__, 'debug', 'postsave start');
    
        $_eqName = $this->getName();
        log::add(__CLASS__, 'info', 'Sauvegarde de l\'équipement [postSave()] : ' . $_eqName);
        $order = 1;
    
        if (!is_file(__DIR__ . '/../config/lmlm.json')) {
          log::add(__CLASS__, 'debug', 'postsave no file found for ' . $_eqName . ', then do nothing');
          return;
        }
        $content = file_get_contents(__DIR__ . '/../config/lmlm.json');
        if (!is_json($content)) {
          log::add(__CLASS__, 'debug', 'postsave content is not json');
          return;
        }
        $device = json_decode($content, true);
        if (!is_array($device) || !isset($device['commands'])) {
          log::add(__CLASS__, 'debug', 'postsave array cannot be decoded ');
          return true;
        }
        $Equipement = eqlogic::byId($this->getId());
        $Equipement->setConfiguration('state_register',$device['configuration']['state']);
        $Equipement->setConfiguration('error_register',$device['configuration']['error']);
        $order = 0;
        log::add(__CLASS__, 'debug', 'postsave add commands on ID ' . $this->getId());
        foreach ($device['commands'] as $item) {
          log::add(__CLASS__, 'debug', 'postsave found commands array name=' . json_encode($item));
          // item name must match to json structure table items names, if not it takes null
          if ($item['name'] != '' && $item['logicalId'] != '') {
            $Equipement->AddCommand(
              $item['name'],
              'jee4lm_' . $item['logicalId'],
              $item['type'],
              $item['subtype'],
              ($item['template']==''?'tile':$item['template']),
              $item['unit'],
              $item['generictype'],
              ($item['visible'] != '' ? $item['visible'] : '1'),
              'default',
              'default',
              $item['min'],
              $item['max'],
              $order,
              $item['history'],
              false,
              'default',
              $item['offset'],
              null,
              null,
              $item['warningif'],
              $item['dangerif'],
              $item['invert']
            );
            $order++;
          }
        }

    $Equipement->AddCommand(__('Etat', __FILE__), 'jee4lm_state', "info", "binary", 'binary', '', '', 1, 'default', 'default', 'default', 'default', $order, '0', true, 'default', null, 2, null, null, null, 0);
    log::add(__CLASS__, 'debug', 'check refresh in postsave');

    /* create on, off, unblock and refresh actions */
    $Equipement->AddAction("jee4lm_on", "ON");
    $Equipement->AddAction("jee4lm_off", "OFF");
    $Equipement->AddAction("refresh", __('Rafraichir', __FILE__));
    $Equipement->AddAction("jee4lm_slider", "Régler consigne", "button", "THERMOSTAT_SET_SETPOINT", 1, "slider", 85,105, 1);
    $Equipement->linksetpoint("jee4lmslider", ""); 
    log::add(__CLASS__, 'debug', 'postsave stop');
    // now refresh
    $this->getInformations();
  }

  public function preUpdate()
  {
    log::add(__CLASS__, 'debug', 'preupdate start');

    if ($this->getConfiguration('host') == '') {
      throw new Exception(__((__('Le champ host ne peut être vide pour l\'équipement ', __FILE__)) . $this->getName(), __FILE__));
    }
    $this->authenticate();
    log::add(__CLASS__, 'debug', 'preupdate stop');
  }

  public function postUpdate()
  {
    log::add(__CLASS__, 'debug', 'postupdate start');
    //self::cron($this->getId());
    log::add(__CLASS__, 'debug', 'postupdate stop');
  }

  public function preRemove()
  {
  }

  public function postRemove()
  {
  }
  public function getInformations()
  {
    log::add(__CLASS__, 'debug', 'getinformation start');
    return $this->getLMValue($this);
    //log::add(__CLASS__, 'debug', 'getinformation stop');
  }

  public function getjee4lm()
  {
    log::add(__CLASS__, 'debug', 'getjee4lm' . "");
    $this->checkAndUpdateCmd('jee4lm', "");
  }

  public static function templateWidget(){
    $returned = array('action' => array('string' => array()), 'info' => array('string' => array()));
    $returned['action']['other']['mylock'] = array(
      'template' => 'tmplicon',
      'replace' => array(
        '#_icon_on_#' => '<i class=\'icon_green icon jeedom-lock-ouvert\'></i>',
        '#_icon_off_#' => '<i class=\'icon_red icon jeedom-lock-ferme\'></i>'
      )
    );
    $returned['info']['string']['mypellets'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# == 0','state_light' => 'Arrêt','state_dark' => 'Arrêt'),
        array('operation' => '#value# >= 1 && #value# <= 9','state_light' => '#value#','state_dark' => '#value#'),
        array('operation' => '#value# == 10 && #value# <= 5','state_light' => 'extinction','state_dark' => 'extinction'),
        array('operation' => '#value# == 255','state_light' => 'Allumage', 'state_dark' => 'Allumage')
      )
    );
    $returned['info']['string']['mypower'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# == 0','state_light' => 'Arrêt','state_dark' => 'Arrêt'),
        array('operation' => '#value# >= 1 && #value# <= 3','state_light' => '#value# Basse','state_dark' => '#value# Basse'),
        array('operation' => '#value# >= 4 && #value# <= 5','state_light' => '#value# Moyenne','state_dark' => '#value# Moyenne'),
        array('operation' => '#value# == 6','state_light' => '#value# Haute','state_dark' => '#value# Haute'),
        array('operation' => '#value# == 7','state_light' => 'Auto', 'state_dark' => 'Auto')
      )
    );
    $returned['info']['binary']['mylocked'] = array(
      'template' => 'tmplicon',
      'replace' => array(
        '#_icon_on_#' => '<span style="font-size:20px!important;color:green;"><br/>Non</span>',
        '#_icon_off_#' => '<span style="font-size:20px!important;color:red;"><br/>Oui</span>'
        )
    );
    $returned['info']['numeric']['myerror'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# == 0','state_light' => '<span style="font-size:20px!important;color:green;"><br/>Non</span>','state_dark' => '<span style="font-size:20px!important;color:green;"><br/>Non</span>'),
        array('operation' => '#value# != 0','state_light' => '<span style="font-size:20px!important;color:red;"><br/>#value#</span>','state_dark' => '<span style="font-size:20px!important;color:red;"><br/>#value#</span>')
      )
    );
    return $returned;
  }
  

}



class jee4lmCmd extends cmd {
    public function dontRemoveCmd()
    {
      if ($this->getLogicalId() == 'refresh') {
        return true;
      }
      return false;
    }
    public function execute($_options = null) {
      $action = $this->getLogicalId();
      log::add(__CLASS__, 'debug', 'execute action ' . $action);
      switch ($action) {
          case 'refresh':
            $this->getEqLogic()->getInformations();
            break;
          case 'getStatus':
            return $this->getEqLogic()->getInformations();
      }
    }
}

