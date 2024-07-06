<?php

require_once __DIR__ . '/../../../core/php/core.inc.php';

class jee4lm extends eqLogic
{

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
  public function getInformations()
  {
    log::add(__CLASS__, 'debug', 'getinformation start');
    log::add(__CLASS__, 'debug', 'getinformation stop');
  }

  public function getjee4lm()
  {
    log::add(__CLASS__, 'debug', 'getjee4lm' . "");
    $this->checkAndUpdateCmd(__CLASS__, "");
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

