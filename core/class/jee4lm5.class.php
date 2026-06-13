<?php

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

const
  PLUGINNAME = 'jee4lm5',
  LMMODELCODE = ['LINEAMINI'],
  JEEDOM_DAEMON_PORT = '50044',
  TOKEN_TIME_TO_REFRESH = 4 * 60 * 60,
  PENDING_COMMAND_TIMEOUT = 10;

class jee4lm5 extends eqLogic
{

  public static function login(string $_username, string $_password): string
  {
    log::add(__CLASS__, 'debug', 'login start');
    if ($_username == '' || $_password == '') {
      log::add(__CLASS__, 'debug', 'login empty username or password');
      return '';
    }
    $payload = ["command" => "lm", "function" => "login", "username" => $_username, "password" => $_password];
    self::deamon_send($payload);
    return '';
  }

  /**
   * Cron runs every minute, polls dashboard and settings/schedule every 2 minutes.
   * No blocking sleep — daemon is async, all sends are fire-and-forget.
   */
  public static function cron()
  {
    $heureActuelle  = (int)date('H');
    $minuteActuelle = (int)date('i');

    if ($heureActuelle >= 22 || $heureActuelle < 6) {
      log::add(__CLASS__, 'debug', 'cron: out of hours, skipping');
      return;
    }

    foreach (jee4lm5::byType(__CLASS__, true) as $jee4lm) {
      if (!($jee4lm instanceof jee4lm5)) continue;
      if ($jee4lm->getConfiguration('serialNumber') == '') {
        log::add(__CLASS__, 'debug', 'cron: no serial, skipping');
        continue;
      }
      // Always refresh dashboard
      $jee4lm->getThingDashboard();
      // Settings + schedule every 2 minutes — no sleep needed, daemon is async
      if ($minuteActuelle % 2 === 0) {
        $jee4lm->getThingSettings();
        $jee4lm->getThingSchedule();
      }
    }
  }

  public static function pull($_options = null)
  {
  }

  /**
   * Refresh function from Jeedom — triggers all commands.
   */
  public function refresh()
  {
    foreach ($this->getCmd() as $cmd) {
      $cmd->execute();
    }
  }

  public function preSave(): void
  {
  }

  /**
   * DoCreateThing creates all Jeedom commands/actions from the widget list returned by the machine.
   * Called once on first dashboard reception (when init=1 is set).
   */
  public static function DoCreateThing($_eq, $data)
  {
    if ($data == '' || !($_eq instanceof jee4lm5)) return false;

    foreach ($data['widgets'] as $w) {
      switch ($w["code"]) {
        case "CMBrewByWeightDoses":
          log::add(__CLASS__, 'debug', 'brewbyweight');
          $_eq->AddCommand("BBW Présent",       'isbbw',           'info', 'binary',  null,                              null, null,                0);
          $_eq->AddCommand("Balance connectée", 'isscaleconnected','info', 'binary',  PLUGINNAME . "::bbw",              null, null,                1);
          $_eq->AddCommand("BBW Etat",          'bbwmode',         'info', 'string',  null,                              null, null,                0);
          $_eq->AddCommand("Continu",           'bbwfree',         'info', 'binary',  PLUGINNAME . "::bbw_nodose",       null, null,                1, 'default', 'default', 'default', 'default', null, 0, false, null, null, null, 0);
          $_eq->AddCommand("Dose 1",            'bbwdoseA',        'info', 'numeric', PLUGINNAME . "::bbw_dose_inactive","g",  0);
          $_eq->AddCommand("Dose 2",            'bbwdoseB',        'info', 'numeric', PLUGINNAME . "::bbw_dose_inactive","g",  0);
          $_eq->AddAction("jee4lm_bbwA",       "BBW Dose 1",    "button", "", 1);
          $_eq->AddAction("jee4lm_bbwB",       "BBW Dose 2",    "button", "", 1);
          $_eq->AddAction("jee4lm_doseA_slider","Régler Dose 1", "button", "", 1, "slider",
            $w["output"]["doses"]["Dose1"]["doseMin"],
            $w["output"]["doses"]["Dose1"]["doseMax"],
            $w["output"]["doses"]["Dose1"]["doseStep"]);
          $_eq->AddAction("jee4lm_doseB_slider","Régler Dose 2", "button", "", 1, "slider",
            $w["output"]["doses"]["Dose2"]["doseMin"],
            $w["output"]["doses"]["Dose2"]["doseMax"],  // was incorrectly using Dose1 max
            $w["output"]["doses"]["Dose2"]["doseStep"]);
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
          $_eq->AddCommand("Cafetière activée",          'coffeeenabled', 'info', 'binary',  null, null,  'THERMOSTAT_STATE',    0);
          $_eq->AddCommand("Cafetière temperature cible",'coffeetarget',  'info', 'numeric', null, '°C', 'THERMOSTAT_SETPOINT', 0);
          $_eq->AddCommand("Cafetière temperature actuelle",'coffeecurrent','info','numeric',null, '°C', 'THERMOSTAT_TEMPERATURE', 0);
          $_eq->AddCommand("Chaudière café",             'displaycoffee', 'info', 'string',  null, null,  null,                  1);
          $_eq->AddAction("jee4lm_coffee_slider", "Régler consigne café", "button", "THERMOSTAT_SET_SETPOINT", 1, "slider",
            $w["output"]["targetTemperatureMin"],
            $w["output"]["targetTemperatureMax"],
            $w["output"]["targetTemperatureStep"]);
          break;

        case "CMSteamBoilerTemperature":
          log::add(__CLASS__, 'debug', 'steam');
          $_eq->AddCommand("Vapeur activée",           'steamenabled',  'info', 'binary',  PLUGINNAME . "::steam", null,  'THERMOSTAT_STATE',    0, 'default', 'default', 'default', 'default', null, 0, false, 0, null, null, 0);
          $_eq->AddCommand("Vapeur temperature cible", 'steamtarget',   'info', 'numeric', null,                   '°C', 'THERMOSTAT_SETPOINT', 0);
          $_eq->AddCommand("Vapeur température actuelle",'steamcurrent','info', 'numeric', null,                   '°C', 'THERMOSTAT_TEMPERATURE', 0);
          $_eq->AddCommand("Chaudière Vapeur",         'displaysteam',  'info', 'string',  null,                   null,  null,                  1);
          $_eq->AddAction("jee4lm_steam_slider", "Régler consigne vapeur", "button", "THERMOSTAT_SET_SETPOINT", 1, "slider",
            $w["output"]["targetTemperatureMin"],
            $w["output"]["targetTemperatureMax"],
            $w["output"]["targetTemperatureStep"]);
          $_eq->AddAction("jee4lm_steam_on",  "Vapeur ON",  PLUGINNAME . "::steam on off", "", 1);
          $_eq->AddAction("jee4lm_steam_off", "Vapeur OFF", PLUGINNAME . "::steam on off", "", 1);
          break;

        case "CMPreBrewing":
          log::add(__CLASS__, 'debug', 'pretrempage');
          $_eq->AddCommand("Prétrempage",       'prewet',         'info', 'binary',  "ENERGY_STATE",             null, null,                    0);
          $_eq->AddCommand("Prétrempage durée", 'prewettime',     'info', 'numeric', null,                       's',  'THERMOSTAT_SETPOINT',   0);
          $_eq->AddCommand("Prétrempage pause", 'prewetholdtime', 'info', 'numeric', null,                       's',  'THERMOSTAT_SETPOINT',   0);
          $_eq->AddAction("jee4lm_prewet_slider",      "Régler consigne mouillage",       "slider", "THERMOSTAT_SET_SETPOINT", 1, "slider",
            $w["output"]["times"]["PreBrewing"]["secondsMin"]["In"],
            $w["output"]["times"]["PreBrewing"]["secondsMax"]["In"],
            $w["output"]["times"]["PreBrewing"]["secondsStep"]["In"]);
          $_eq->AddAction("jee4lm_prewet_time_slider", "Régler consigne pause mouillage", "slider", "THERMOSTAT_SET_SETPOINT", 1, "slider",
            $w["output"]["times"]["PreBrewing"]["secondsMin"]["Out"],
            $w["output"]["times"]["PreBrewing"]["secondsMax"]["Out"],
            $w["output"]["times"]["PreBrewing"]["secondsStep"]["Out"]);
          $_eq->AddAction("jee4lm_prewet_on",  "Prémouillage on",  "binarySwitch", "ENERGY_ON",  1);
          $_eq->AddAction("jee4lm_prewet_off", "Prémouillage off", "binarySwitch", "ENERGY_OFF", 1);
          break; // FIX #9: break was missing, causing fall-through into CMPreExtraction

        case "CMPreExtraction":
          log::add(__CLASS__, 'debug', 'preinfusion');
          $_eq->AddCommand("Préinfusion", 'preinfusionmode', 'info', 'binary', null, null, null, 0);
          $_eq->AddAction("jee4lm_preextraction_on",  "Prémouillage on",  "binarySwitch", "ENERGY_ON",  1);
          $_eq->AddAction("jee4lm_preextraction_off", "Prémouillage off", "binarySwitch", "ENERGY_OFF", 1);
          break;
      }
    }

    $_eq->AddCommand("Sur réseau d'eau",  'plumbedin',   'info', 'binary', null,                           null, null,             1);
    $_eq->AddCommand("Etat Backflush",    'backflush',   'info', 'binary', null,                           null, null,             0);
    $_eq->AddCommand("Dernier Backflush", 'last_backflush','info','string',PLUGINNAME . "::backflush",     null, null,             1, 'default', 'default', 'default', 'default', null, 0, false, 0, null, null, 0);
    $_eq->AddCommand("Réservoir plein",   'tankStatus',  'info', 'binary', PLUGINNAME . "::tankStatus",    null, null,             1, 'default', 'default', 'default', 'default', null, 0, false, 0, null, null, 0);
    $_eq->AddCommand("Etat",             'machinemode', 'info', 'binary', PLUGINNAME . "::main",           null, 'THERMOSTAT_STATE', 0);
    $_eq->AddCommand("Version Firmware", 'fwversion',   'info', 'string', null,                           null, null,             1);
    $_eq->AddCommand("Version Gateway",  'gwversion',   'info', 'string', null,                           null, null,             1);
    $_eq->AddCommand("Mode",             'hbmode',      'info', 'string', null,                           null, "THERMOSTAT_MODE", 0);
    $_eq->AddCommand("SmartWakeup",                  'smartwakeup',                'info', 'binary', null, null, "ENERGY_STATE", 0);
    $_eq->AddCommand("SmartWakeup durée",            'smartwakeupstandbyminutes',  'info', 'numeric',null, null, null,           0);
    $_eq->AddCommand("SmartWakeup depuis",           'smartwakeupstandbyafter',    'info', 'string', PLUGINNAME . "::smartwakeup", null, null, 1);

    $_eq->AddAction("jee4lm_test",      "TEST",           "",                           "button",        0);
    $_eq->AddAction("jee4lm_on",        "heat",           PLUGINNAME . "::main on off", "THERMOSTAT_MODE", 1);
    $_eq->AddAction("jee4lm_off",       "off",            PLUGINNAME . "::main on off", "THERMOSTAT_MODE", 1);
    $_eq->AddAction("jee4lm_auto",      "Auto",           PLUGINNAME . "::main on off", "THERMOSTAT_MODE", 0);
    $_eq->AddAction("refresh",          __('Rafraichir', __FILE__));
    $_eq->AddAction("start_backflush",  "Démarrer backflush", PLUGINNAME . "::backflush on off");
    $_eq->AddAction("jee4lm_smartwakeup_on",                 "Réveil on",    "binarySwitch", "ENERGY_ON",  1);
    $_eq->AddAction("jee4lm_smartwakeup_off",                "Réveil off",   "binarySwitch", "ENERGY_OFF", 1);
    $_eq->AddAction("jee4lm_smartwakeupstandbyminutes_slider","Régler durée", "slider",       null,         1, "slider", 0, 240, 10);
    $_eq->AddAction("jee4lm_smartwakeup_after_lastbrew", "Dernier café");
    $_eq->AddAction("jee4lm_smartwakeup_after_poweron",  "Allumage");
    $_eq->AddCommand("Machine", 'machine', 'info', 'string', PLUGINNAME . "::machine", null, null, 1);

    $_eq->save();

    $_eq->linksetpoint("jee4lm_coffee_slider",                   "coffeetarget");
    $_eq->linksetpoint("jee4lm_steam_slider",                    "steamtarget");
    $_eq->linksetpoint("jee4lm_prewet_slider",                   "prewettime");
    $_eq->linksetpoint("jee4lm_prewet_time_slider",              "prewetholdtime"); // FIX: was "preWetHoldTime"
    $_eq->linksetpoint("jee4lm_smartwakeupstandbyminutes_slider","smartwakeupstandbyminutes");
    $_eq->linksetpoint("jee4lm_on",               "machinemode");
    $_eq->linksetpoint("jee4lm_off",              "machinemode");
    $_eq->linksetpoint("jee4lm_steam_on",         "steamenabled");
    $_eq->linksetpoint("jee4lm_steam_off",        "steamenabled");
    $_eq->linksetpoint("jee4lm_smartwakeup_on",   "smartwakeup");
    $_eq->linksetpoint("jee4lm_smartwakeup_off",  "smartwakeup");
    $_eq->linksetpoint("jee4lm_prewet_on",        "prewet");
    $_eq->linksetpoint("jee4lm_prewet_off",       "prewet");
    $_eq->linksetpoint("jee4lm_preextraction_on", "preinfusionmode");
    $_eq->linksetpoint("jee4lm_preextraction_off","preinfusionmode");

    return true;
  }

  /**
   * Add or update an info command on this equipment.
   */
  public function AddCommand(
    $_Name, $_logicalId,
    $_Type = 'info', $_SubType = 'binary',
    $_Template = null, $_unite = null, $_generic_type = null,
    $_IsVisible = 1,
    $_icon = 'default', $_forceLineB = 'default',
    $_valuemin = 'default', $_valuemax = 'default',
    $_order = null, $_IsHistorized = 0, $_repeatevent = false,
    $_iconname = 0, $_calculValueOffset = null, $_historizeRound = null,
    $_noiconname = 0, $_warning = null, $_danger = null, $_invert = 0
  ) {
    $createCmd = true;
    log::add(__CLASS__, 'debug', 'add command ' . $_Name . ' logicalId=' . $_logicalId);
    $Command = $this->getCmd('info', $_logicalId);
    if (!is_object($Command)) {
      $Command = cmd::byEqLogicIdCmdName($this->getId(), $_logicalId);
      if (is_object($Command)) $createCmd = false;
    } else {
      $createCmd = false;
    }

    if ($createCmd) {
      $Command = new jee4lm5Cmd();
      $Command->setLogicalId($_logicalId);
      $Command->setEqLogic_id($this->getId());
      $Command->setName($_Name);
      $Command->setType($_Type);
      $Command->setSubType($_SubType);
      $Command->save();
    }

    $Command->setIsVisible($_IsVisible);
    if ($_IsHistorized !== null)       $Command->setIsHistorized(strval($_IsHistorized));
    if ($_Template !== null) {
      $Command->setTemplate('dashboard', $_Template);
      $Command->setTemplate('mobile',    $_Template);
    }
    if ($_unite !== null && $_SubType == 'numeric') $Command->setUnite($_unite);
    if ($_icon !== 'default')      $Command->setdisplay('icon', '<i class="' . $_icon . '"></i>');
    if ($_forceLineB !== 'default')$Command->setdisplay('forceReturnLineBefore', 1);
    if ($_iconname !== 'default')  $Command->setdisplay('showIconAndNamedashboard', 1);
    if ($_noiconname !== null) {
      $Command->setdisplay('showIconAndNamedashboard', $_noiconname);
      $Command->setdisplay('showNameOndashboard',       $_noiconname);
    }
    if ($_calculValueOffset !== null) $Command->setConfiguration('calculValueOffset', $_calculValueOffset);
    if ($_historizeRound !== null)    $Command->setConfiguration('historizeRound',    $_historizeRound);
    if ($_generic_type !== null)      $Command->setGeneric_type($_generic_type);
    if ($_repeatevent === true && $_Type == 'info') $Command->setConfiguration('repeatEventManagement', 'never');
    if ($_valuemin !== 'default') $Command->setConfiguration('minValue', $_valuemin);
    if ($_valuemax !== 'default') $Command->setConfiguration('maxValue', $_valuemax);
    if ($_warning !== null) $Command->setDisplay("warningif", $_warning);
    if ($_order !== null)   $Command->setOrder($_order);
    if ($_danger !== null)  $Command->setDisplay("dangerif", $_danger);
    if ($_invert !== null)  $Command->setDisplay('invertBinary', $_invert);
    $Command->save();
    return $Command;
  }

  /**
   * Add or update an action command on this equipment.
   */
  public function AddAction(
    $_actionName, $_actionTitle,
    $_template = null, $_generic_type = null,
    $_visible = 1, $_SubType = 'other',
    $_min = null, $_max = null, $_step = null
  ) {
    $createCmd = true;
    $command = $this->getCmd('action', $_actionName);
    if (!is_object($command)) {
      $command = cmd::byEqLogicIdCmdName($this->getId(), $_actionTitle);
      if (is_object($command)) $createCmd = false;
    } else {
      $createCmd = false;
    }

    if ($createCmd) {
      $command = new jee4lm5Cmd();
      $command->setLogicalId($_actionName);
      $command->setName($_actionTitle);
      $command->setType('action');
      $command->setSubType($_SubType);
      $command->setEqLogic_id($this->getId());
      $command->save();
    }
    $command->setIsVisible($_visible);
    if ($_template !== null) {
      $command->setTemplate('dashboard', $_template);
      $command->setTemplate('mobile',    $_template);
    }
    if ($_generic_type !== null) $command->setGeneric_type($_generic_type);
    if ($_min !== null) $command->setConfiguration('minValue', $_min);
    if ($_max !== null) $command->setConfiguration('maxValue', $_max);
    if ($_step !== null) $command->setDisplay('parameters', array('step' => $_step));
    $command->save();
  }

  /**
   * Link a slider action to its info setpoint command.
   */
  public function linksetpoint($_slider, $_setpointlogicalID)
  {
    $set_setpoint = $_slider          !== null ? cmd::byEqLogicIdAndLogicalId($this->getId(), $_slider)          : null;
    $setpoint     = $_setpointlogicalID !== null ? cmd::byEqLogicIdAndLogicalId($this->getId(), $_setpointlogicalID) : null;
    if ($set_setpoint === null || $setpoint === null) {
      log::add(__CLASS__, 'debug', "setpoint: command not found slider=$_slider target=$_setpointlogicalID");
    } else {
      $set_setpoint->setValue($setpoint->getId());
      $set_setpoint->save();
    }
  }

  /**
   * Dispatch slider value to the right machine command.
   */
  public function set_setpoint($_options, $_logicalID, $_type)
  {
    $v = $_options["slider"];
    log::add(__CLASS__, 'debug', "set_setpoint type=$_type logicalID=$_logicalID v=$v");
    if ($v <= 0) return;
    switch ($_type) {
      case "BbwDose":
        $this->CoffeeMachineBrewByWeightSettingDoses($v, $_logicalID);
        break;
      case "CoffeeBoiler":
        $this->CoffeeMachineSettingCoffeeBoilerTargetTemperature($v);
        break;
      case "SteamBoiler":
        $this->CoffeeMachineSettingSteamBoilerTargetTemperature($v);
        break;
      case "PrewetIn":
        $d = cmd::byEqLogicIdAndLogicalId($this->getId(), 'prewetholdtime')->execCmd();
        $this->CoffeeMachinePreBrewingChangeTimes($v, $d);
        break;
      case "PrewetOut":
        $d = cmd::byEqLogicIdAndLogicalId($this->getId(), 'prewettime')->execCmd();
        $this->CoffeeMachinePreBrewingChangeTimes($d, $v);
        break;
    }
  }

  // ------------------------------------------------------------------
  // Daemon command senders
  // ------------------------------------------------------------------

  public function getThingDashboard()
  {
    log::add(__CLASS__, 'debug', 'getThingDashboard');
    $serial  = $this->getConfiguration('serialNumber');
    $payload = ["command" => "lm", "function" => "dash", "id" => $this->getId(), "serial" => $serial];
    self::deamon_send($payload);
  }

  public function getThingSettings()
  {
    $serial  = $this->getConfiguration('serialNumber');
    $payload = ["command" => "lm", "function" => "settings", "id" => $this->getId(), "serial" => $serial];
    self::deamon_send($payload);
  }

  public function getThingSchedule()
  {
    $serial  = $this->getConfiguration('serialNumber');
    $payload = ["command" => "lm", "function" => "schedule", "id" => $this->getId(), "serial" => $serial];
    self::deamon_send($payload);
  }

  /**
   * Power machine on (true) or off (false).
   * Also drives the daemon dashboard polling loop.
   */
  public function CoffeeMachineChangeMode(bool $_toggle)
  {
    log::add(__CLASS__, 'debug', 'CoffeeMachineChangeMode ' . ($_toggle ? 'ON' : 'OFF'));
    $serial  = $this->getConfiguration('serialNumber');
    $payload = [
      "command"  => "lm",
      "function" => "CoffeeMachineChangeMode",
      "value"    => ($_toggle ? 1 : 0),
      "id"       => $this->getId(),
      "serial"   => $serial,
    ];
    self::deamon_send($payload);
    $this->checkAndUpdateCmd('hbmode', $_toggle ? 'heat' : 'off');
  }

  public function CoffeeMachineSettingSteamBoilerEnabled(bool $_toggle)
  {
    log::add(__CLASS__, 'debug', 'CoffeeMachineSettingSteamBoilerEnabled ' . ($_toggle ? 'ON' : 'OFF'));
    $serial  = $this->getConfiguration('serialNumber');
    $payload = ["command" => "lm", "function" => "CoffeeMachineSettingSteamBoilerEnabled", "value" => ($_toggle ? 1 : 0), "id" => $this->getId(), "serial" => $serial];
    self::deamon_send($payload);
  }

  public function CoffeeMachineSettingCoffeeBoilerTargetTemperature($_value)
  {
    log::add(__CLASS__, 'debug', "CoffeeMachineSettingCoffeeBoilerTargetTemperature v=$_value");
    $serial  = $this->getConfiguration('serialNumber');
    $payload = ["command" => "lm", "function" => "CoffeeMachineSettingCoffeeBoilerTargetTemperature", "value" => $_value, "id" => $this->getId(), "serial" => $serial];
    self::deamon_send($payload);
  }

  public function CoffeeMachineSettingSteamBoilerTargetTemperature($_value)
  {
    log::add(__CLASS__, 'debug', "CoffeeMachineSettingSteamBoilerTargetTemperature v=$_value");
    $serial  = $this->getConfiguration('serialNumber');
    $payload = ["command" => "lm", "function" => "CoffeeMachineSettingSteamBoilerTargetTemperature", "value" => $_value, "id" => $this->getId(), "serial" => $serial];
    self::deamon_send($payload);
  }

  public function CoffeeMachinePreInfusionChangeMode($_toggle)
  {
    log::add(__CLASS__, 'debug', 'CoffeeMachinePreInfusionChangeMode');
    $serial  = $this->getConfiguration('serialNumber');
    $payload = ["command" => "lm", "function" => "CoffeeMachinePreInfusionChangeMode", "value" => $_toggle, "id" => $this->getId(), "serial" => $serial];
    self::deamon_send($payload);
  }

  public function CoffeeMachinePreBrewingChangeMode($_toggle)
  {
    log::add(__CLASS__, 'debug', 'CoffeeMachinePreBrewingChangeMode');
    $serial  = $this->getConfiguration('serialNumber');
    $payload = ["command" => "lm", "function" => "CoffeeMachinePreBrewingChangeMode", "value" => $_toggle, "id" => $this->getId(), "serial" => $serial];
    self::deamon_send($payload);
  }

  public function CoffeeMachineBrewByWeightSettingDoses($_weight, $_dose)
  {
    log::add(__CLASS__, 'debug', "CoffeeMachineBrewByWeightSettingDoses dose=$_dose weight=$_weight");
    $serial = $this->getConfiguration('serialNumber');
    // FIX #4: was $$dose1 (variable variable). Read current sibling dose from Jeedom state.
    $dose1 = ($_dose == "bbwdoseA") ? $_weight : (float)cmd::byEqLogicIdAndLogicalId($this->getId(), 'bbwdoseA')->execCmd();
    $dose2 = ($_dose == "bbwdoseB") ? $_weight : (float)cmd::byEqLogicIdAndLogicalId($this->getId(), 'bbwdoseB')->execCmd();
    $payload = ["command" => "lm", "function" => "CoffeeMachineBrewByWeightSettingDoses", "value" => $dose1, "value2" => $dose2, "id" => $this->getId(), "serial" => $serial];
    self::deamon_send($payload);
  }

  public function CoffeeMachineBrewByWeightChangeMode($_dose)
  {
    log::add(__CLASS__, 'debug', "CoffeeMachineBrewByWeightChangeMode dose=$_dose");
    $serial  = $this->getConfiguration('serialNumber');
    $payload = ["command" => "lm", "function" => "CoffeeMachineBrewByWeightChangeMode", "value" => $_dose, "id" => $this->getId(), "serial" => $serial];
    self::deamon_send($payload);
  }

  public function CoffeeMachinePreBrewingChangeTimes($_time, $_hold)
  {
    log::add(__CLASS__, 'debug', "CoffeeMachinePreBrewingChangeTimes t=$_time h=$_hold");
    $serial  = $this->getConfiguration('serialNumber');
    $payload = ["command" => "lm", "function" => "CoffeeMachinePreBrewingChangeTimes", "value" => $_time, "value2" => $_hold, "id" => $this->getId(), "serial" => $serial];
    self::deamon_send($payload);
  }

  public function CoffeeMachineBackFlushStartCleaning()
  {
    log::add(__CLASS__, 'debug', 'CoffeeMachineBackFlushStartCleaning');
    $serial  = $this->getConfiguration('serialNumber');
    $payload = ["command" => "lm", "function" => "CoffeeMachineBackFlushStartCleaning", "id" => $this->getId(), "serial" => $serial];
    self::deamon_send($payload);
  }

  public function CoffeeMachineSettingSmartStandBy(bool $_enable, int $_minutes, string $_after)
  {
    log::add(__CLASS__, 'debug', "CoffeeMachineSettingSmartStandBy enable=$_enable minutes=$_minutes after=$_after");
    $serial  = $this->getConfiguration('serialNumber');
    // FIX #3: was $$_enable (variable variable)
    $payload = [
      "command"  => "lm",
      "function" => "CoffeeMachineSettingSmartStandBy",
      "value"    => ($_enable ? 1 : 0),
      "value2"   => $_minutes,
      "value3"   => $_after,
      "id"       => $this->getId(),
      "serial"   => $serial,
    ];
    self::deamon_send($payload);
  }

  public function CoffeeMachineSettingSmartStandByAfterLastBrew($eq, $_options)
  {
    $b     = (bool)cmd::byEqLogicIdAndLogicalId($eq->getId(), 'smartwakeup')->execCmd();
    $from  = cmd::byEqLogicIdAndLogicalId($eq->getId(), 'smartwakeupstandbyafter')->execCmd();
    $after = $_options;
    return $eq->CoffeeMachineSettingSmartStandBy($b, (int)$from, $after);
  }

  public function CoffeeMachineSettingSmartStandByAfterPowerOn($eq, $_options)
  {
    $b      = (bool)cmd::byEqLogicIdAndLogicalId($eq->getId(), 'smartwakeup')->execCmd();
    $after  = (int)cmd::byEqLogicIdAndLogicalId($eq->getId(), 'smartwakeupstandbyminutes')->execCmd();
    $from   = $_options;
    return $eq->CoffeeMachineSettingSmartStandBy($b, $after, $from);
  }

  public function CoffeeMachineSettingPreInfusionEnabled($eq, $b)
  {
    $this->checkAndUpdateCmd('preinfusionmode', $b ? 1 : 0);
    return true;
  }

  public function CoffeeMachineSettingPreWetEnabled($eq, $b)
  {
    $this->checkAndUpdateCmd('prewet', $b ? 1 : 0);
    return $eq->CoffeeMachinePreBrewingChangeMode($b);
  }

  // ------------------------------------------------------------------
  // Device discovery
  // ------------------------------------------------------------------

  public static function detect()
  {
    log::add(__CLASS__, 'debug', 'detect request sent');
    $payload = ["command" => "lm", "function" => "detect"];
    self::deamon_send($payload);
  }

  /**
   * Process the list of things returned by the daemon after a detect command.
   * $data is an array of Thing::to_dict() — keys are Python snake_case field names.
   * coffee_station is a raw dict from the API (camelCase keys).
   */
  public static function processdetect($data)
  {
    log::add(__CLASS__, 'debug', '[detect] received data');
    if (empty($data)) return false;

    foreach ($data as $machine) {
      // DeviceType::MACHINE = "CoffeeMachine"
      if ($machine['type'] !== 'CoffeeMachine') continue;

      // coffee_station is a raw API dict — camelCase keys
      $coffeeStation = $machine['coffee_station'] ?? null;
      if (!$coffeeStation) continue;

      $uuid       = $coffeeStation['id']                       ?? '';
      $name       = $machine['name']                           ?? '';
      $modelCode  = $machine['model_code']                     ?? ''; // snake_case from to_dict()
      $modelName  = $machine['model_name']                     ?? '';
      $imageUrl   = $machine['image_url']                      ?? '';
      // serialNumber lives in coffee_station.coffeeMachine (raw camelCase API dict)
      $serialNumber = $coffeeStation['coffeeMachine']['serialNumber'] ?? '';

      log::add(__CLASS__, 'debug', "detect found uuid=$uuid name=$name modelCode=$modelCode SN=$serialNumber");

      // FIX #11: DateTime::createFromFormat is a static factory, not an instance method
      // connection_date from to_dict() is a Python datetime serialized to ISO string by mashumaro
      $connDate = $machine['connection_date'] ?? null;
      $d = $connDate ? DateTime::createFromFormat(DateTime::ATOM, $connDate) : false;
      if ($d instanceof DateTime) {
        log::add(__CLASS__, 'debug', 'detect paired on ' . $d->format("d/m/Y"));
      } else {
        log::add(__CLASS__, 'debug', 'detect: unable to parse connection_date');
        $d = new DateTime();
      }

      $eqLogic = eqLogic::byLogicalId($uuid, PLUGINNAME);
      if (!is_object($eqLogic)) {
        $eqLogic = new jee4lm5();
        $eqLogic->setEqType_name(PLUGINNAME);
        $eqLogic->setIsEnable(1);
        $eqLogic->setName($name);
        $eqLogic->setCategory('heating', 1);
        $eqLogic->setIsVisible(1);
        log::add(__CLASS__, 'debug', "create eqlogic uuid=$uuid");
      } else {
        log::add(__CLASS__, 'debug', "$uuid already exists, updating");
      }

      $eqLogic->setLogicalId($uuid);
      $eqLogic->setConfiguration('type',         'CoffeeMachine');
      $eqLogic->setConfiguration('pairingDate',  $d->format("d/m/Y H:i:s"));
      $eqLogic->setConfiguration('modelName',    $modelName);
      $eqLogic->setConfiguration('modelCode',    $modelCode);
      $eqLogic->setConfiguration('imageUrl',     $imageUrl);
      $eqLogic->setConfiguration('serialNumber', $serialNumber);

      // Check accessories for scale (raw camelCase API dict)
      foreach (($coffeeStation['accessories'] ?? array()) as $accessory) {
        if (($accessory['type'] ?? '') === 'ScaleAcaiaLunar') {
          $eqLogic->setConfiguration('bbw',       true);
          $eqLogic->setConfiguration('scaleName', $accessory['name'] ?? '');
        }
      }

      $eqLogic->setConfiguration('init', 1);
      $eqLogic->save();

      // Build display map
      $display_map = array(
        'scalebattery'             => array(1, 3),
        'machine'                  => array(1, 2),
        'isscaleconnected'         => array(1, 3),
        'bbwdoseA'                 => array(4, 1),
        'bbwdoseB'                 => array(4, 2),
        'bbwfree'                  => array(4, 3),
        'bbwmode'                  => array(4, 3),
        'groupDoseType'            => array(1, 1),
        'coffeeenabled'            => array(1, 1),
        'isbbw'                    => array(1, 3),
        'coffeecurrent'            => array(3, 1),
        'coffeetarget'             => array(3, 1),
        'start_backflush'          => array(2, 1),
        'machinemode'              => array(1, 1),
        'backflush'                => array(2, 1),
        'last_backflush'           => array(2, 1),
        'jee4lm_off'               => array(2, 2),
        'jee4lm_on'                => array(2, 2),
        'groupDoseMode'            => array(1, 1),
        'preinfusionmode'          => array(5, 1),
        'jee4lm_preextraction_on'  => array(5, 1),
        'jee4lm_preextraction_off' => array(5, 1),
        'prewet'                   => array(5, 1),
        'jee4lm_prewet_on'         => array(5, 1),
        'jee4lm_prewet_off'        => array(5, 1),
        'plumbedin'                => array(5, 2),
        'prewettime'               => array(5, 3),
        'prewetholdtime'           => array(5, 3),
        'jee4lm_doseA_slider'      => array(6, 3),
        'jee4lm_doseB_slider'      => array(6, 3),
        'jee4lm_coffee_slider'     => array(6, 1),
        'jee4lm_prewet_slider'     => array(6, 2),
        'jee4lm_prewet_time_slider'=> array(6, 2),
        'jee4lm_steam_slider'      => array(6, 1),
        'tankStatus'               => array(1, 1),
        'jee4lm_steam_off'         => array(2, 3),
        'jee4lm_steam_on'          => array(2, 3),
        'steamcurrent'             => array(3, 3),
        'steamtarget'              => array(3, 3),
        'steamenabled'             => array(1, 1),
        'fwversion'                => array(7, 1),
        'gwversion'                => array(7, 3),
        'displaycoffee'            => array(3, 1),
        'displaysteam'             => array(3, 3),
        'jee4lm_bbwA'              => array(7, 2),
        'jee4lm_bbwB'              => array(7, 2),
        'smartwakeup'              => array(8, 1),
        'jee4lm_smartwakeup_on'    => array(8, 1),
        'jee4lm_smartwakeup_off'   => array(8, 1),
        'smartwakeupstandbyafter'  => array(8, 1),
        'smartwakeupstandbyminutes'=> array(8, 1),
        'smartwakeupstandbyminutes_slider' => array(8, 1),
      );

      $displayStuff = array(
        "layout::dashboard::table::parameters" => array(
          "center"       => "0",
          "styletable"   => "background-image: url(/plugins/" . PLUGINNAME . "/core/config/img/bg_model_2.png);background-repeat: no-repeat; background-size: 100% 40%;",
          "styletd"      => "",
          "style::td::1::1" => "font-size:larger;",
          "text::td::1::1"  => "<br>Réservoir à eau<br>",
          "text::td::1::3"  => "<br>Balance connectée<br>",
          "text::td::1::2"  => '<img src="' . $imageUrl . '" height=85% width=85%>',
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
          "style::td::6::3" => "border-top:solid;border-bottom:solid;",
        ),
        "layout::dashboard"                      => "table",
        "layout::dashboard::table::nbLine"       => "8",
        "layout::dashboard::table::nbColumn"     => "3",
      );

      foreach ($display_map as $key => $map) {
        $r = cmd::byEqLogicIdAndLogicalId($eqLogic->getId(), $key);
        if ($r != null) {
          $displayStuff["layout::dashboard::table::cmd::" . $r->getId() . "::line"]   = $map[0];
          $displayStuff["layout::dashboard::table::cmd::" . $r->getId() . "::column"] = $map[1];
        }
      }

      foreach ($displayStuff as $key => $value) {
        $eqLogic->setDisplay($key, $value);
      }
      $eqLogic->save();
      log::add(__CLASS__, 'debug', 'eqlogic saved');

      if (method_exists($eqLogic, 'getThingDashboard')) {
        $eqLogic->getThingDashboard();
      }
    }

    log::add(__CLASS__, 'debug', 'processdetect end');
    return true;
  }

  public function searchForBBW()
  {
    return $this->getConfiguration('bbw');
  }

  public function updateDisplay($_cmd, $_key, $_value)
  {
    $cmd = $this->getCmd(null, $_cmd);
    if ($cmd != null) {
      $cmd->setDisplay($_key, $_value);
      $cmd->save();
    }
  }

  // ------------------------------------------------------------------
  // Daemon response processors
  // ------------------------------------------------------------------

  /**
   * Process schedule data from daemon.
   * ThingSchedulingSettings::to_dict() returns snake_case Python field names.
   * SmartWakeUpSleepSettings fields: smart_stand_by_enabled, smart_stand_by_minutes,
   * smart_stand_by_after, smart_wake_up_sleep_supported.
   */
  public static function processthingSchedule($eq, $arr)
  {
    $arr1 = json_decode($arr, true);
    if ($arr1 === null) return;

    if (!empty($arr1["smart_wake_up_sleep_supported"])) {
      $w = $arr1["smart_wake_up_sleep"];
      $eq->checkAndUpdateCmd('smartwakeup',               ($w["smart_stand_by_enabled"] ? 1 : 0));
      $eq->checkAndUpdateCmd('smartwakeupstandbyafter',    $w["smart_stand_by_after"]);    // SmartStandByType: "LastBrewing" | "PowerOn"
      $eq->checkAndUpdateCmd('smartwakeupstandbyminutes',  $w["smart_stand_by_minutes"]);
    }
  }

  /**
   * Process settings data from daemon.
   * ThingSettings::to_dict() — snake_case.
   * FirmwareSettings fields: type ("Machine"|"Gateway"), build_version.
   */
  public static function processthingSettings($eq, $arr)
  {
    $arr1 = json_decode($arr, true);
    if ($arr1 === null) return;

    $eq->checkAndUpdateCmd('plumbedin', !empty($arr1['is_plumbed_in']) ? 1 : 0);

    foreach (($arr1['actual_firmwares'] ?? array()) as $fw) {
      switch ($fw['type']) {
        case 'Gateway':
          $eq->checkAndUpdateCmd('gwversion', $fw['build_version']);
          break;
        case 'Machine':
          $eq->checkAndUpdateCmd('fwversion', $fw['build_version']);
          break;
      }
    }
  }

  public static function doRefreshDashboard($eq, $arr)
  {
    log::add(__CLASS__, 'debug', 'doRefreshDashboard eq=' . $eq->getId());
    $eq->RefreshThingDashboardInformation($eq, json_decode($arr, true));
  }

  /**
   * Update Jeedom commands from dashboard widget data.
   * ThingDashboardConfig::to_dict() produces a "widgets" array of Widget::to_dict().
   * Each widget has "code" (WidgetType string value) and "output" (snake_case fields).
   *
   * Key field names per widget type (from _config.py):
   *   CMMachineStatus   : status (MachineState), mode (MachineMode)
   *   CMCoffeeBoiler    : status (BoilerStatus), enabled (bool), target_temperature, ready_start_time
   *   CMSteamBoilerTemperature : same as CoffeeBoiler + target_temperature_supported
   *   CMBackFlush       : status (BackFlushStatus), last_cleaning_start_time (ISO datetime)
   *   CMNoWater         : allarm (bool) — note: intentional typo in pylamarzocco
   *   CMBrewByWeightDoses: scale_connected, mode (DoseMode), doses.dose_1.dose / doses.dose_2.dose
   *   CMPreBrewing      : mode (PreExtractionMode), times.pre_brewing[0].seconds.seconds_in/seconds_out
   *   ThingScale        : connected (bool), battery_level (float)
   */
  public static function RefreshThingDashboardInformation($eq, $arr1)
  {
    if ($arr1 === null) return false;

    // First reception: create all commands from widget list
    if ($eq->getConfiguration('init') == 1) {
      $eq->setConfiguration('init', 0);
      jee4lm5::DoCreateThing($eq, $arr1);
    }

    $autorefresh      = false;
    $last_autorefresh = (bool)$eq->getConfiguration('autorefresh');

    foreach ($arr1['widgets'] as $w) {
      $code   = $w['code'];
      $output = $w['output'];
      log::add(__CLASS__, 'debug', "RefreshThingDashboardInformation widget=$code");

      switch ($code) {

        // WidgetType::CM_MACHINE_STATUS = "CMMachineStatus"
        // MachineState: StandBy, PoweredOn, Brewing, Off
        // MachineMode:  BrewingMode, EcoMode, StandBy
        case "CMMachineStatus":
          $cmdOn  = $eq->getCmd(null, 'jee4lm_on');
          $cmdOff = $eq->getCmd(null, 'jee4lm_off');
          switch ($output['status']) {
            case "PoweredOn":
            case "Brewing":
              log::add(__CLASS__, 'debug', 'machine is ON');
              $eq->checkAndUpdateCmd('hbmode',      'heat');
              $eq->checkAndUpdateCmd('machinemode',  1);
              if (is_object($cmdOn))  { $cmdOn->setIsVisible(0);  $cmdOn->save(); }
              if (is_object($cmdOff)) { $cmdOff->setIsVisible(1); $cmdOff->save(); }
              $autorefresh = true;
              break;
            case "StandBy":
            case "Off":
              log::add(__CLASS__, 'debug', 'machine is OFF');
              $eq->checkAndUpdateCmd('machinemode',  0);
              $eq->checkAndUpdateCmd('hbmode',       'off');
              if (is_object($cmdOn))  { $cmdOn->setIsVisible(1);  $cmdOn->save(); }
              if (is_object($cmdOff)) { $cmdOff->setIsVisible(0); $cmdOff->save(); }
              $autorefresh = false;
              break;
          }
          break;

        // WidgetType::CM_COFFEE_BOILER = "CMCoffeeBoiler"
        // BoilerStatus: StandBy, HeatingUp, Ready, NoWater, Off
        case "CMCoffeeBoiler":
          $eq->checkAndUpdateCmd('coffeetarget', $output['target_temperature']);
          $eq->checkAndUpdateCmd('coffeeenabled', !empty($output['enabled']) ? 1 : 0);
          switch ($output['status']) {
            case "HeatingUp":
              $eq->checkAndUpdateCmd('coffeecurrent', 0);
              // ready_start_time is a Python datetime — serialized as ISO string by mashumaro
              $readyTs = isset($output['ready_start_time']) ? strtotime($output['ready_start_time']) : 0;
              $diffMin = $readyTs > 0 ? round((($readyTs - time()) / 60) * 2) / 2 : 0;
              if ($diffMin <= 0) {
                $display = '<span style="color:green"><br/>Prêt</span>';
              } elseif ($diffMin <= 1.5) {
                $diffSec = (int)($diffMin * 60);
                $display = '<span style="color:green"><br/>Prêt dans ' . $diffSec . 's</span>';
              } else {
                $display = '<span style="color:green"><br/>Prêt dans ' . $diffMin . 'min</span>';
              }
              $eq->checkAndUpdateCmd('displaycoffee', $display);
              break;
            case "Ready":
              $eq->checkAndUpdateCmd('coffeecurrent', $output['target_temperature']);
              $eq->checkAndUpdateCmd('displaycoffee', '<span style="color:green"><br/>Prêt</span>');
              break;
            default: // StandBy, Off, NoWater
              $eq->checkAndUpdateCmd('coffeecurrent', 0);
              $eq->checkAndUpdateCmd('displaycoffee', '<span style="color:red"><br/>Off</span>');
          }
          break;

        // WidgetType::CM_STEAM_BOILER_TEMPERATURE = "CMSteamBoilerTemperature"
        // FIX #12: field is 'enabled' (bool), not 'status == On'
        case "CMSteamBoilerTemperature":
          $eq->checkAndUpdateCmd('steamenabled', !empty($output['enabled']) ? 1 : 0);
          if (!empty($output['target_temperature_supported'])) {
            $eq->checkAndUpdateCmd('steamtarget', $output['target_temperature']);
          }
          $eq->checkAndUpdateCmd('displaysteam',
            empty($output['enabled'])
              ? '<br/>Off'
              : '<span style="color:green"><br/>Allumé</span>'
          );
          break;

        // WidgetType::CM_NO_WATER = "CMNoWater"
        // FIX: field is 'allarm' (intentional typo in pylamarzocco NoWater model)
        case "CMNoWater":
          $eq->checkAndUpdateCmd('tankStatus', !empty($output['allarm']) ? 1 : 0);
          break;

        // WidgetType::CM_BACK_FLUSH = "CMBackFlush"
        // BackFlushStatus: Requested, Cleaning, Off
        // last_cleaning_start_time is ISO datetime string from mashumaro
        case "CMBackFlush":
          $eq->checkAndUpdateCmd('backflush', ($output['status'] !== 'Off') ? 1 : 0);
          $lastTs = isset($output['last_cleaning_start_time']) ? strtotime($output['last_cleaning_start_time']) : 0;
          if ($lastTs > 0) {
            $daysDiff = (int)floor((time() - $lastTs) / 86400);
            $szDays = ($daysDiff == 0) ? "Aujourd'hui" : ($daysDiff == 1 ? "hier" : "il y a $daysDiff jours");
          } else {
            $szDays = "N/A";
          }
          $eq->checkAndUpdateCmd('last_backflush', $szDays);
          break;

        // WidgetType::CM_BREW_BY_WEIGHT_DOSES = "CMBrewByWeightDoses"
        // doses.dose_1.dose / doses.dose_2.dose  (BrewByWeightDoseSettings with alias Dose1/Dose2)
        // After to_dict() mashumaro uses Python field names: dose_1, dose_2
        // DoseMode: Continuous, PulsesType, Dose1, Dose2, MassType
        case "CMBrewByWeightDoses":
          $eq->checkAndUpdateCmd('isscaleconnected', !empty($output['scale_connected']) ? 1 : 0);
          $eq->checkAndUpdateCmd('bbwmode',  $output['mode']);
          $eq->checkAndUpdateCmd('bbwdoseA', $output['doses']['dose_1']['dose']);
          $eq->checkAndUpdateCmd('bbwdoseB', $output['doses']['dose_2']['dose']);
          $eq->checkAndUpdateCmd('bbwfree',  ($output['mode'] === 'Continuous') ? 1 : 0);
          $eq->updateDisplay('bbwdoseA', 'template', PLUGINNAME . ($output['mode'] === 'Dose1' ? "::bbw_dose" : "::bbw_dose_inactive"));
          $eq->updateDisplay('bbwdoseB', 'template', PLUGINNAME . ($output['mode'] === 'Dose2' ? "::bbw_dose" : "::bbw_dose_inactive"));
          break;

        // WidgetType::CM_PRE_BREWING = "CMPreBrewing"
        // PreExtractionMode: PreInfusion, PreBrewing, Disabled
        // times.pre_brewing[0].seconds.seconds_in / seconds_out  (SecondsInOut with alias In/Out)
        // After to_dict() mashumaro uses Python field names: seconds_in, seconds_out
        case "CMPreBrewing":
          $isPreBrew = ($output['mode'] === 'PreBrewing');
          $eq->checkAndUpdateCmd('prewet', $isPreBrew ? 1 : 0);
          if ($isPreBrew) {
            $eq->checkAndUpdateCmd('preinfusionmode', 0);
            $times = $output['times']['pre_brewing'][0]['seconds'] ?? null;
          } else {
            $eq->checkAndUpdateCmd('preinfusionmode', 1);
            $times = $output['times']['pre_infusion'][0]['seconds'] ?? null;
          }
          if ($times !== null) {
            $eq->checkAndUpdateCmd('prewettime',     $times['seconds_in']);
            $eq->checkAndUpdateCmd('prewetholdtime', $times['seconds_out']);
          }
          break;

        // WidgetType::THING_SCALE = "ThingScale"
        case "ThingScale":
          $eq->checkAndUpdateCmd('isscaleconnected', !empty($output['connected']) ? 1 : 0);
          if (!empty($output['connected']) && $output['battery_level'] > 0) {
            $eq->checkAndUpdateCmd('scalebattery', $output['battery_level']);
          }
          break;
      }
    }

    // FIX #2: drive dash loop via CoffeeMachineChangeMode instead of raw on/off daemon signal
    if ($autorefresh !== $last_autorefresh) {
      $eq->CoffeeMachineChangeMode($autorefresh);
      $eq->setConfiguration('autorefresh', $autorefresh ? 1 : 0);
      $eq->save();
    }

    return true;
  }

  public function getjee4lm()
  {
  }

  // ------------------------------------------------------------------
  // Widget templates
  // ------------------------------------------------------------------

  public static function templateWidget()
  {
    $r = array('action' => array('string' => array()), 'info' => array('string' => array()));

    $r['info']['numeric']['batterie'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# ==0',                  'state_light' => '<span style="font-size: 24px;color:red">-</span>',                                               'state_dark' => '<span style="font-size: 24px;color:red">-</span>'),
        array('operation' => '#value# > 0 && #value# <=10', 'state_light' => '<span style="font-size: 20px;color:red">#value#%</span>',                                        'state_dark' => '<span style="font-size: 20px;color:red">#value#%</span>'),
        array('operation' => '#value# > 10 && #value# <=70','state_light' => '<span style="font-size: 20px;color:orange">#value#%</span>',                                     'state_dark' => '<span style="font-size: 20px;color:orange">#value#%</span>'),
        array('operation' => '#value# > 70',                 'state_light' => '<span style="font-size: 20px;color:green">#value#%</span>',                                     'state_dark' => '<span style="font-size: 20px;color:green">#value#%</span>'),
      )
    );

    $r['info']['numeric']['temperature'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# == 0', 'state_light' => '<span style="font-size: 24px;color:gray">#value#</span><span style="font-size: 20px;color:black"> °C</span>', 'state_dark' => '<span style="font-size: 24px;color:gray">#value#</span><span style="font-size: 20px;color:white"> °C</span>'),
        array('operation' => '#value# >= 0', 'state_light' => '<span style="font-size: 20px;color:gray">#value#</span>',                                                    'state_dark' => '<span style="font-size: 20px;color:lightgray">#value#</span>'),
      )
    );

    $r['info']['numeric']['bbw_dose'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# == 0', 'state_light' => 'N/A', 'state_dark' => 'N/A'),
        array(
          'operation'   => '#value# >= 0',
          'state_light' => '<span style="display:inline-block;line-height:0px;border-radius:50%;font-size: 10px;background-color: gray;color:white;border-width:thick;border-color:red; border-style: solid;"><span style="display: inline-block;float:left;width:32px; padding-top: 50%;padding-bottom: 50%;margin-left: 8px; margin-right: 8px;">#value#g</span></span>',
          'state_dark'  => '<span style="display:inline-block;line-height:0px;border-radius:50%;font-size: 12px;background-color: gray;color:white;border-width:thick;border-color:red; border-style: solid;"><span style="display: inline-block; float:left;width:32px;padding-top: 50%;padding-bottom: 50%;margin-left: 8px; margin-right: 8px;">#value#g</span></span>',
        )
      )
    );

    $r['info']['numeric']['bbw_dose_inactive'] = array(
      'template' => 'tmplmultistate',
      'test' => array(
        array('operation' => '#value# == 0', 'state_light' => 'N/A', 'state_dark' => 'N/A'),
        array(
          'operation'   => '#value# >= 0',
          'state_light' => '<span style="display:inline-block;line-height:0px;border-radius:50%;font-size: 10px;background-color: gray;color:lightgray;border-width:thick;border-color:rgb(var(--panel-bg-color)); border-style: solid;"><span style="display: inline-block; float:left;width:32px;padding-top: 50%;padding-bottom: 50%;margin-left: 8px; margin-right: 8px;">#value#g</span></span>',
          'state_dark'  => '<span style="display:inline-block;line-height:0px;border-radius:50%;font-size: 12px;background-color: gray;color:lightgray;border-width:thick;border-color:lightgray; border-style: solid;"><span style="display: inline-block; float:left;width:32px;padding-top: 50%;padding-bottom: 50%;margin-left: 8px; margin-right: 8px;">#value#g</span></span>',
        )
      )
    );

    $r['info']['binary']['bbw_nodose'] = array(
      'template' => 'tmplicon',
      'display'  => array('icon' => 'null'),
      'replace'  => array(
        '#_icon_on_#'      => "<span style='display:inline-block;line-height:0px;border-radius:50%;font-size: 8px;background-color: gray;color:white;border-width:thick;border-color:red; border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/nodose_on.png' width='58px' height='57px'></span></span>",
        '#_icon_off_#'     => "<span style='display:inline-block;line-height:0px;border-radius:50%;font-size: 8px;background-color: gray;color:white;border-width:thick;border-color:lightgray; border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/nodose_off.png' width='58px' height='57px'></span></span>",
        "#_time_widget_#"  => "0",
      )
    );

    $r['action']['other']['main on off'] = array(
      'template' => 'tmplimg',
      'display'  => array('icon' => 'null'),
      'replace'  => array(
        '#_img_light_on_#' => "<span style='display: inline-block;margin-top:40px;line-height:0px;border-radius:50%;font-size: 8px;background-color: white;color:white;border-width:thick;border-color:white; border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/ic_status_header_on.png' width='80px' height='80px'></span></span>",
        '#_img_dark_on_#'  => "<span style='display: inline-block;margin-top:40px;line-height:0px;border-radius:50%;font-size: 8px;background-color: white;color:white;border-width:thick;border-color:rgb(25,25,25); border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/ic_status_header_on.png' width='80px' height='80px'></span></span>",
        '#_img_light_off_#'=> "<span style='display: inline-block;margin-top:40px;line-height:0px;border-radius:50%;font-size: 8px;background-color: white;color:white;border-width:thick;border-color:white; border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/ic_status_header_off.png' width='80px' height='80px'></span></span>",
        '#_img_dark_off_#' => "<span style='display: inline-block;margin-top:40px;line-height:0px;border-radius:50%;font-size: 8px;background-color: white;color:white;border-width:thick;border-color:rgb(25,25,25); border-style: solid;'><span style='display: inline-block;margin-left:-8px;margin-right:-8px;margin-top:-8px;margin-bottom:-8px'><img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/ic_status_header_off.png' width='80px' height='80px'></span></span>",
        "#_time_widget_#"  => "0",
      )
    );

    $r['action']['other']['steam on off'] = array(
      'template' => 'tmplimg',
      'display'  => array('icon' => 'null'),
      'replace'  => array(
        '#_img_light_on_#' => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/steam_on.png' width='64' height='64'>",
        '#_img_dark_on_#'  => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/steam_on.png' width='64' height='64'>",
        '#_img_light_off_#'=> "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/steam_off.png' width='64' height='64'>",
        '#_img_dark_off_#' => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/steam_off.png' width='64' height='64'>",
        "#_time_widget_#"  => "0",
      )
    );

    $r['action']['other']['backflush on off'] = array(
      'template' => 'tmplimg',
      'display'  => array('icon' => 'null'),
      'replace'  => array(
        '#_img_light_on_#' => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/backflush_on.png' width='64' height='64'>",
        '#_img_dark_on_#'  => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/backflush_on.png' width='64' height='64'>",
        '#_img_light_off_#'=> "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/backflush_off.png' width='64' height='64'>",
        '#_img_dark_off_#' => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/backflush_off.png' width='64' height='64'>",
        "#_time_widget_#"  => "0",
      )
    );

    $r['info']['binary']['tankStatus'] = array(
      'template' => 'tmplicon',
      'display'  => array('icon' => 'null'),
      'replace'  => array(
        '#_icon_on_#'     => "<span style='color:red;font-size:1.5em;font-style:bold;'><br>Remplir<br><br></span><img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/reservoir.png' width='64' height='64'>",
        '#_icon_off_#'    => "<span style='font-size:1.5em;font-style:bold;'><br>OK</span>",
        "#_time_widget_#" => "0",
      )
    );

    $r['info']['binary']['bbw'] = array(
      'template' => 'tmplicon',
      'display'  => array('icon' => 'null'),
      'replace'  => array(
        '#_icon_on_#'     => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/bbw_on.png' width='64' height='64'>",
        '#_icon_off_#'    => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/bbw_off.png' width='64' height='64'>",
        "#_time_widget_#" => "0",
      )
    );

    $r['info']['binary']['main'] = array(
      'template' => 'tmplicon',
      'display'  => array('icon' => 'null'),
      'replace'  => array(
        '#_icon_on_#'     => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/ic_status_header_on.png' width='64' height='64'>",
        '#_icon_off_#'    => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/ic_status_header_off.png' width='64' height='64'>",
        "#_time_widget_#" => "0",
      )
    );

    $r['info']['binary']['backflush'] = array(
      'template' => 'tmplicon',
      'display'  => array('icon' => 'null'),
      'replace'  => array(
        '#_icon_on_#'     => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/backflush_on.png' width='64' height='64'>",
        '#_icon_off_#'    => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/backflush_off.png' width='64' height='64'>",
        "#_time_widget_#" => "0",
      )
    );

    // FIX #13: missing closing </span> on PowerOn state_light
    // SmartStandByType: LastBrewing | PowerOn
    $r['info']['string']['smartwakeup'] = array(
      'template' => 'tmplmultistateline',
      'display'  => array('icon' => 'null'),
      'replace'  => array("#_time_widget_#" => "0"),
      'test' => array(
        array("operation" => "#value#=='LastBrewing'", "state_light" => "<span>Dernier Café</span>", "state_dark" => "<span>Dernier Café</span>"),
        array("operation" => "#value#=='PowerOn'",     "state_light" => "<span>Allumage</span>",     "state_dark" => "<span>Allumage</span>"),  // FIX #13: was "PoserOn" + missing </span>
      )
    );

    $r['info']['string']['machine'] = array(
      'template' => 'tmplmultistate',
      'display'  => array('icon' => 'null'),
      'replace'  => array(
        "#_desktop_width_#" => "",
        "#_mobile_width_#"  => "",
        "#_time_widget_#"   => "0",
      ),
      'test' => array(
        array(
          'operation'   => "#value# !=''",
          'state_light' => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/#value#.png' width='256' height='256'>",
          'state_dark'  => "<img class='img-responsive' src='/plugins/" . PLUGINNAME . "/core/config/img/#value#.png' width='256' height='256'>",
        )
      )
    );

    return $r;
  }

  // ------------------------------------------------------------------
  // Plugin version
  // ------------------------------------------------------------------

  public static function getPluginVersion()
  {
    $pluginVersion = '0.0.0';
    try {
      $infoFile = dirname(__FILE__) . '/../../plugin_info/info.json';
      if (!file_exists($infoFile)) {
        log::add(__CLASS__, 'warning', '[VERSION] fichier info.json manquant');
        return $pluginVersion;
      }
      $data = json_decode(file_get_contents($infoFile), true);
      if (!is_array($data)) {
        log::add(__CLASS__, 'warning', '[VERSION] Impossible de décoder info.json');
        return $pluginVersion;
      }
      $pluginVersion = $data['pluginVersion'] ?? $pluginVersion;
    } catch (Exception $e) {
      log::add(__CLASS__, 'warning', '[VERSION] ' . $e->getMessage());
    }
    log::add(__CLASS__, 'info', '[VERSION] ' . $pluginVersion);
    return $pluginVersion;
  }

  // ------------------------------------------------------------------
  // Daemon lifecycle
  // ------------------------------------------------------------------

  public static function deamon_info()
  {
    $return = array('log' => __CLASS__, 'launchable' => 'ok', 'state' => 'nok');
    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/' . PLUGINNAME . 'd.pid';
    if (file_exists($pid_file)) {
      $pid     = trim(file_get_contents($pid_file));
      $pid_int = intval($pid);
      $is_running = false;
      if ($pid_int > 0) {
        if (function_exists('posix_getsid')) {
          $is_running = (posix_getsid($pid_int) !== false);
        } else {
          $is_running = file_exists('/proc/' . $pid_int);
        }
      }
      if ($is_running) $return['state'] = 'ok';
    }
    return $return;
  }

  public static function deamon_start($_automatic = false)
  {
    self::deamon_stop();
    $deamon_info = self::deamon_info();
    if ($deamon_info['launchable'] !== 'ok') {
      log::add(__CLASS__, 'error', __('Daemon non lançable', __FILE__) . ' : ' . $deamon_info['launchable']);
      return false;
    }
    $path    = realpath(dirname(__FILE__) . '/../../resources/');
    $pythonpath = $path . '/python_venv/bin/python3';
    $cmd     = $pythonpath . ' ' . $path . '/' . PLUGINNAME . 'd/'. PLUGINNAME. 'd.py';
    $cmd    .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel(__CLASS__));
    $cmd    .= ' --socketport '  . JEEDOM_DAEMON_PORT;
    $cmd    .= ' --apikey '      . jeedom::getApiKey(__CLASS__);
    $cmd    .= ' --callback '    . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/' . PLUGINNAME . '/core/php/' . PLUGINNAME . '_ajax.php';
    $cmd    .= ' --pid '         . jeedom::getTmpFolder(__CLASS__) . '/' . PLUGINNAME . 'd.pid';
    log::add(__CLASS__, 'info', "start daemon: $cmd");
    $result = exec($cmd . ' >> ' . log::getPathToLog('' . PLUGINNAME . 'd') . ' 2>&1 &');
    log::add(__CLASS__, 'info', "exec result=$result");

    $i = 0;
    while ($i < 10) {
      $deamon_info = self::deamon_info();
      if ($deamon_info['state'] == 'ok') break;
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

  public static function deamon_stop()
  {
    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/' . PLUGINNAME . 'd.pid';
    if (file_exists($pid_file)) {
      $pid = intval(trim(file_get_contents($pid_file)));
      if ($pid > 0) {
        exec('kill -SIGTERM ' . $pid . ' 2>&1');
        sleep(1);
        if (file_exists('/proc/' . $pid)) {
          exec('kill -SIGKILL ' . $pid . ' 2>&1');
        }
      }
      unlink($pid_file);
    }
  }

  /**
   * Send a payload array to the daemon socket.
   * deamon_send handles json_encode internally — do NOT pre-encode the payload.
   */
  public static function deamon_send($_params)
  {
    $deamon_info = self::deamon_info();
    if ($deamon_info['state'] != 'ok')
      throw new Exception("deamon_send: daemon not started");
    $_params['apikey'] = jeedom::getApiKey(__CLASS__);
    $payLoad = json_encode($_params);
    log::add(__CLASS__, 'debug', 'deamon_send payload=' . $payLoad);
    $socket = socket_create(AF_INET, SOCK_STREAM, 0);
    if (!$socket) {
      log::add(__CLASS__, 'error', 'deamon_send: error opening socket');
      return;
    }
    if (!socket_connect($socket, '127.0.0.1', JEEDOM_DAEMON_PORT)) {
      log::add(__CLASS__, 'error', 'deamon_send: error connecting to daemon socket');
    } else {
      if (!socket_write($socket, $payLoad, strlen($payLoad)))
        log::add(__CLASS__, 'error', 'deamon_send: error writing payload');
    }
    socket_close($socket);
  }

  public static function backupExclude()
  {
    return array('resources/venv');
  }
}

// ------------------------------------------------------------------

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

  public function execute($_options = null)
  {
    $action = $this->getLogicalId();
    $eq     = $this->getEqLogic();
    log::add(__CLASS__, 'debug', "execute action=$action options=" . json_encode($_options));

    if (!($eq instanceof jee4lm5)) {
      log::add(__CLASS__, 'error', 'execute: unknown equipment id=' . $eq->getId());
      return false;
    }

    switch ($action) {
      case 'refresh':
      case 'getStatus':
        // FIX #5: was $$eq (variable variable)
        return $eq->getThingDashboard();

      case 'start_backflush':
        $eq->CoffeeMachineBackFlushStartCleaning();
        return true;

      case 'jee4lm_on':
      case 'jee4lm_auto':
        $eq->CoffeeMachineChangeMode(true);
        return true;

      case 'jee4lm_off':
        // FIX #6: was computing $b = $action == 'jee4lm_on' (always false, unused)
        $eq->CoffeeMachineChangeMode(false);
        return true;

      case 'jee4lm_steam_on':
      case 'jee4lm_steam_off':
        $eq->CoffeeMachineSettingSteamBoilerEnabled($action === 'jee4lm_steam_on');
        return true;

      case 'jee4lm_smartwakeup_after_lastbrew':
        $eq->CoffeeMachineSettingSmartStandByAfterLastBrew($eq, $_options);
        return true;

      case 'jee4lm_smartwakeup_after_poweron':
        // FIX #8: was 'jee4lm_smartwakeup__after_poweron' (double underscore)
        $eq->CoffeeMachineSettingSmartStandByAfterPowerOn($eq, $_options);
        return true;

      case 'jee4lm_prewet_on':
      case 'jee4lm_prewet_off':
        $eq->CoffeeMachineSettingPreWetEnabled($eq, $action === 'jee4lm_prewet_on');
        return true;

      case 'jee4lm_preextraction_on':
      case 'jee4lm_preextraction_off':
        $eq->CoffeeMachineSettingPreInfusionEnabled($eq, $action === 'jee4lm_preextraction_on');
        return true;

      case 'jee4lm_smartwakeup_on':
      case 'jee4lm_smartwakeup_off':
        // FIX #7: was comparing against 'jee4lm_smartstandby_on' (wrong name, always false)
        $b    = ($action === 'jee4lm_smartwakeup_on');
        $from = cmd::byEqLogicIdAndLogicalId($eq->getId(), 'smartwakeupstandbyafter')->execCmd();
        $eq->CoffeeMachineSettingSmartStandBy(
          $b,
          (int)cmd::byEqLogicIdAndLogicalId($eq->getId(), 'smartwakeupstandbyminutes')->execCmd(),
          (string)$from
        );
        return true;

      case 'jee4lm_smartwakeupstandbyminutes_slider':
        // TODO: implement smartstandby minutes slider
        return true;

      case 'jee4lm_coffee_slider':
        $eq->set_setpoint($_options, 'coffeetarget', "CoffeeBoiler");
        return true;

      case 'jee4lm_steam_slider':
        $eq->set_setpoint($_options, 'steamtarget', "SteamBoiler");
        return true;

      case 'jee4lm_doseA_slider':
        $eq->set_setpoint($_options, 'bbwdoseA', "BbwDose");
        return true;

      case 'jee4lm_doseB_slider':
        $eq->set_setpoint($_options, 'bbwdoseB', "BbwDose");
        return true;

      case 'jee4lm_prewet_slider':
        $eq->set_setpoint($_options, '', "PrewetIn");
        return true;

      case 'jee4lm_prewet_time_slider':
        $eq->set_setpoint($_options, '', "PrewetOut");
        return true;

      case 'jee4lm_bbwA':
      case 'jee4lm_bbwB':
        $eq->CoffeeMachineBrewByWeightChangeMode($action === 'jee4lm_bbwA' ? 'Dose1' : 'Dose2');
        return true;

      default:
        return true;
    }
  }
}