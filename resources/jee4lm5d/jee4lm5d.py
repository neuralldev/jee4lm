from globals import INSTALLKEYFILE, INSTALLCREDENTIALFILE
import asyncio
from asyncio import Task
import uuid
import json
from pathlib import Path
from dataclasses import dataclass
from typing import Optional

from pylamarzocco.const import DoseMode, MachineMode, MachineState, PreExtractionMode, SmartStandByType, WidgetType
from pylamarzocco import LaMarzoccoCloudClient, LaMarzoccoMachine
from pylamarzocco.util import InstallationKey, generate_installation_key
from mashumaro.mixins.json import DataClassJSONMixin

from jeedomdaemon.base_daemon import BaseDaemon


@dataclass
class JeeCredential(DataClassJSONMixin):
    username: str = ""
    password: str = ""

    def isinit(self) -> bool:
        return bool(self.username and self.password)


class Jee4LM(BaseDaemon):

    def __init__(self) -> None:
        script_dir = Path(__file__).resolve().parent
        self.data_dir = script_dir.parent.parent / "data"

        super().__init__(
            on_message_cb=self.on_message,
            on_stop_cb=self.on_stop,
            on_start_cb=self.on_start,
        )

        self.credential = JeeCredential()
        self.installation_key: Optional[InstallationKey] = None
        self.client: Optional[LaMarzoccoCloudClient] = None
        # machine keyed by serial — supports multiple machines in theory
        self._machines: dict[str, LaMarzoccoMachine] = {}
        self._dash_tasks: dict[str, Task] = {}  # serial -> running loop task

    # ------------------------------------------------------------------
    # Lifecycle
    # ------------------------------------------------------------------

    async def on_start(self) -> None:
        self._logger.info("daemon starting")

        # Load or generate installation key
        first_registration = not self._load_install_key()
        if first_registration:
            if not self._save_install_key():
                self._logger.error("cannot write installation key — check permissions")
                await self.stop()
                return
            self._logger.info("installation key generated")

        # Load credentials — may be absent on first run, daemon stays up and waits
        if self._load_credential() and self.credential.isinit():
            self._logger.info("credentials loaded")
        else:
            self._logger.warning("no credentials — daemon waiting for login command")

        # Build cloud client only when credentials are available to avoid
        # spurious auth attempts against the LM cloud API.
        if self.credential.isinit():
            self.client = LaMarzoccoCloudClient(
                username=self.credential.username,
                password=self.credential.password,
                installation_key=self.installation_key,
            )
        else:
            self.client = None

        # Register if not already confirmed — required before any API call.
        # registered.json acts as a flag; if absent, registration is attempted
        # regardless of whether the key file already existed.
        already_registered = (self.data_dir / "registered.json").exists()
        if not already_registered and self.credential.isinit():
            try:
                await self.client.async_register_client()
                (self.data_dir / "registered.json").write_text('{"registered": true}')
                self._logger.info("client registered with LM cloud")
            except Exception as e:
                self._logger.error(f"cloud registration failed: {e}")
                # Delete install key to force fresh key+registration on next start
                install_key_path = self.data_dir / INSTALLKEYFILE
                if install_key_path.exists():
                    install_key_path.unlink()
                await self.stop()
                return

        self._logger.info("daemon ready")

    async def on_stop(self) -> None:
        self._logger.info("daemon stopping")
        for task in self._dash_tasks.values():
            if not task.done():
                task.cancel()
        self._dash_tasks.clear()

    # ------------------------------------------------------------------
    # Machine helper
    # ------------------------------------------------------------------

    def _get_machine(self, serial: str) -> LaMarzoccoMachine:
        """Return cached machine or create a new one for this serial."""
        if serial not in self._machines:
            self._logger.debug(f"creating machine object serial={serial}")
            self._machines[serial] = LaMarzoccoMachine(serial, self.client)
        return self._machines[serial]

    # ------------------------------------------------------------------
    # Dashboard polling loop
    # ------------------------------------------------------------------

    def _start_dash_loop(self, serial: str, eq_id: int) -> None:
        """Start polling loop for serial if not already running."""
        task = self._dash_tasks.get(serial)
        if task is None or task.done():
            self._logger.info(f"starting dashboard loop serial={serial} eq={eq_id}")
            self._dash_tasks[serial] = asyncio.create_task(
                self._dash_loop(serial, eq_id)
            )

    def _stop_dash_loop(self, serial: str) -> None:
        task = self._dash_tasks.pop(serial, None)
        if task and not task.done():
            self._logger.info(f"stopping dashboard loop serial={serial}")
            task.cancel()

    async def _dash_loop(self, serial: str, eq_id: int) -> None:
        machine = self._get_machine(serial)
        try:
            while True:
                try:
                    await machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "cmd": "dash_update",
                        "dash": machine.dashboard.to_json(),
                    })
                except asyncio.CancelledError:
                    raise
                except Exception as e:
                    self._logger.error(f"dashboard poll error serial={serial}: {e}")
                await asyncio.sleep(5)
        except asyncio.CancelledError:
            self._logger.info(f"dashboard loop cancelled serial={serial}")

    async def _await_command_confirmed(
        self,
        machine: LaMarzoccoMachine,
        pre_snapshot: str,
        interval: float = 2.5,
        timeout: float = 10.0,
    ) -> bool:
        """
        Poll get_dashboard() every `interval` seconds until the dashboard JSON
        differs from `pre_snapshot` (command applied server-side) or `timeout` elapses.
        The pre-snapshot must be taken BEFORE the command so the comparison detects
        when the server stops returning the old (Pending) state.
        """
        loop = asyncio.get_event_loop()
        deadline = loop.time() + timeout
        while loop.time() < deadline:
            await asyncio.sleep(interval)
            try:
                await machine.get_dashboard()
                if machine.dashboard.to_json() != pre_snapshot:
                    return True
            except asyncio.CancelledError:
                raise
            except Exception as e:
                self._logger.debug(f"confirmation poll error: {e}")
        return False

    def _machine_is_on(self, machine: LaMarzoccoMachine) -> bool:
        """Return True if the dashboard reports machine in brewing mode."""
        try:
            return machine.dashboard.config is not None and \
                machine.dashboard.machine_mode != MachineMode.STANDBY
        except Exception:
            return False

    # ------------------------------------------------------------------
    # Credential / install key persistence
    # ------------------------------------------------------------------

    def _load_install_key(self) -> bool:
        path = self.data_dir / INSTALLKEYFILE
        if not path.exists():
            return False
        try:
            self.installation_key = InstallationKey.from_dict(
                json.loads(path.read_text(encoding="utf-8"))
            )
            return True
        except Exception as e:
            self._logger.error(f"error reading installation key: {e}")
            return False

    def _save_install_key(self) -> bool:
        self.installation_key = generate_installation_key(str(uuid.uuid4()).lower())
        try:
            (self.data_dir / INSTALLKEYFILE).write_text(
                str(self.installation_key.to_json()), encoding="utf-8"
            )
            return True
        except Exception as e:
            self._logger.error(f"error writing installation key: {e}")
            return False

    def _load_credential(self) -> bool:
        path = self.data_dir / INSTALLCREDENTIALFILE
        if not path.exists():
            return False
        try:
            self.credential = JeeCredential.from_json(path.read_text(encoding="utf-8"))
            return True
        except Exception as e:
            self._logger.error(f"error reading credentials: {e}")
            return False

    def _save_credential(self, username: str, password: str) -> None:
        try:
            (self.data_dir / INSTALLCREDENTIALFILE).write_text(
                json.dumps({"username": username, "password": password}),
                encoding="utf-8",
            )
            self._logger.info("credentials saved")
        except Exception as e:
            self._logger.error(f"error saving credentials: {e}")

    # ------------------------------------------------------------------
    # Message dispatcher
    # ------------------------------------------------------------------

    async def on_message(self, message: dict) -> None:
        self._logger.debug(f"on_message: {message}")

        if message.get("command") != "lm":
            self._logger.error(f"unknown command: {message.get('command')}")
            return

        fn = message.get("function", "")
        eq_id = message.get("id")
        serial = message.get("serial", "")

        # Block all cloud-dependent commands if credentials haven't been provided yet.
        if fn != "login" and self.client is None:
            self._logger.error(f"no cloud client yet (missing credentials) — ignoring {fn}")
            return

        match fn:

            # ---- credentials ----------------------------------------
            case "login":
                username = message.get("username", "")
                password = message.get("password", "")
                if not username or not password:
                    self._logger.error("login: missing username or password")
                    return
                self._save_credential(username, password)
                self._logger.info("credentials updated — restarting daemon")
                await self.stop()

            # ---- discovery (no serial yet) --------------------------
            case "detect":
                try:
                    things = await self.client.list_things()
                    await self.send_to_jeedom({
                        "cmd": "detect",
                        "things": [t.to_dict() for t in things],
                    })
                except Exception as e:
                    self._logger.error(f"detect failed: {e}")

            # ---- one-shot reads (serial required) -------------------
            case "dash":
                machine = self._get_machine(serial)
                try:
                    await machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": machine.dashboard.to_json(),
                    })
                    # Auto-start loop if machine is on
                    if self._machine_is_on(machine):
                        self._start_dash_loop(serial, eq_id)
                except Exception as e:
                    self._logger.error(f"get_dashboard failed: {e}")

            case "settings":
                machine = self._get_machine(serial)
                try:
                    await machine.get_settings()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "settings": machine.settings.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"get_settings failed: {e}")

            case "schedule":
                machine = self._get_machine(serial)
                try:
                    await machine.get_schedule()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "schedule": machine.schedule.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"get_schedule failed: {e}")

            # ---- machine commands ------------------------------------
            case "CoffeeMachineChangeMode":
                machine = self._get_machine(serial)
                enabled = bool(message.get("value", 0))
                try:
                    await machine.set_power(enabled)
                    # Optimistic local update — server command is Pending, skip get_dashboard()
                    ms = machine.dashboard.config.get(WidgetType.CM_MACHINE_STATUS)
                    if ms is not None:
                        ms.status = MachineState.POWERED_ON if enabled else MachineState.STANDBY
                        ms.mode   = MachineMode.BREWING_MODE if enabled else MachineMode.STANDBY
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": machine.dashboard.to_json(),
                    })
                    if enabled:
                        self._start_dash_loop(serial, eq_id)
                    else:
                        self._stop_dash_loop(serial)
                except Exception as e:
                    self._logger.error(f"set_power failed: {e}")

            case "CoffeeMachineSettingSteamBoilerEnabled":
                machine = self._get_machine(serial)
                try:
                    pre_snapshot = machine.dashboard.to_json()
                    await machine.set_steam(bool(message.get("value", 0)))
                    await self.send_to_jeedom({"id": eq_id, "dash": machine.dashboard.to_json()})
                    confirmed = await self._await_command_confirmed(machine, pre_snapshot)
                    if not confirmed:
                        self._logger.warning("set_steam: server did not confirm within 10s")
                    await self.send_to_jeedom({"id": eq_id, "dash": machine.dashboard.to_json()})
                except Exception as e:
                    self._logger.error(f"set_steam failed: {e}")

            case "CoffeeMachineSettingCoffeeBoilerTargetTemperature":
                machine = self._get_machine(serial)
                try:
                    pre_snapshot = machine.dashboard.to_json()
                    await machine.set_coffee_target_temperature(float(message["value"]))
                    await self.send_to_jeedom({"id": eq_id, "dash": machine.dashboard.to_json()})
                    confirmed = await self._await_command_confirmed(machine, pre_snapshot)
                    if not confirmed:
                        self._logger.warning("set_coffee_target_temperature: server did not confirm within 10s")
                    await self.send_to_jeedom({"id": eq_id, "dash": machine.dashboard.to_json()})
                except Exception as e:
                    self._logger.error(f"set_coffee_target_temperature failed: {e}")

            case "CoffeeMachineSettingSteamBoilerTargetTemperature":
                machine = self._get_machine(serial)
                try:
                    pre_snapshot = machine.dashboard.to_json()
                    await machine.set_steam_target_temperature(float(message["value"]))
                    await self.send_to_jeedom({"id": eq_id, "dash": machine.dashboard.to_json()})
                    confirmed = await self._await_command_confirmed(machine, pre_snapshot)
                    if not confirmed:
                        self._logger.warning("set_steam_target_temperature: server did not confirm within 10s")
                    await self.send_to_jeedom({"id": eq_id, "dash": machine.dashboard.to_json()})
                except Exception as e:
                    self._logger.error(f"set_steam_target_temperature failed: {e}")

            case "CoffeeMachinePreInfusionChangeMode":
                machine = self._get_machine(serial)
                mode = PreExtractionMode.PREINFUSION if message.get("value") else PreExtractionMode.DISABLED
                try:
                    pre_snapshot = machine.dashboard.to_json()
                    await machine.set_pre_extraction_mode(mode)
                    await self.send_to_jeedom({"id": eq_id, "dash": machine.dashboard.to_json()})
                    confirmed = await self._await_command_confirmed(machine, pre_snapshot)
                    if not confirmed:
                        self._logger.warning("set_pre_extraction_mode (infusion): server did not confirm within 10s")
                    await self.send_to_jeedom({"id": eq_id, "dash": machine.dashboard.to_json()})
                except Exception as e:
                    self._logger.error(f"set_pre_extraction_mode (infusion) failed: {e}")

            case "CoffeeMachinePreBrewingChangeMode":
                machine = self._get_machine(serial)
                mode = PreExtractionMode.PREBREWING if message.get("value") else PreExtractionMode.DISABLED
                try:
                    pre_snapshot = machine.dashboard.to_json()
                    await machine.set_pre_extraction_mode(mode)
                    await self.send_to_jeedom({"id": eq_id, "dash": machine.dashboard.to_json()})
                    confirmed = await self._await_command_confirmed(machine, pre_snapshot)
                    if not confirmed:
                        self._logger.warning("set_pre_extraction_mode (brewing): server did not confirm within 10s")
                    await self.send_to_jeedom({"id": eq_id, "dash": machine.dashboard.to_json()})
                except Exception as e:
                    self._logger.error(f"set_pre_extraction_mode (brewing) failed: {e}")

            case "CoffeeMachinePreBrewingChangeTimes":
                machine = self._get_machine(serial)
                try:
                    pre_snapshot = machine.dashboard.to_json()
                    await machine.set_pre_extraction_times(
                        float(message["value"]),
                        float(message["value2"]),
                    )
                    await self.send_to_jeedom({"id": eq_id, "dash": machine.dashboard.to_json()})
                    confirmed = await self._await_command_confirmed(machine, pre_snapshot)
                    if not confirmed:
                        self._logger.warning("set_pre_extraction_times: server did not confirm within 10s")
                    await self.send_to_jeedom({"id": eq_id, "dash": machine.dashboard.to_json()})
                except Exception as e:
                    self._logger.error(f"set_pre_extraction_times failed: {e}")

            case "CoffeeMachineBrewByWeightSettingDoses":
                machine = self._get_machine(serial)
                try:
                    dose1 = float(message["value"])
                    dose2 = float(message["value2"])
                    self._logger.debug(f"BBW set doses: dose1={dose1} dose2={dose2}")
                    await machine.set_brew_by_weight_doses(dose1, dose2)
                    # No get_dashboard(): the command is Pending, server still has stale values.
                    # set_brew_by_weight_doses already updated local model optimistically.
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_brew_by_weight_doses failed: {e}")

            case "CoffeeMachineBrewByWeightChangeMode":
                machine = self._get_machine(serial)
                try:
                    mode = DoseMode(message["value"])
                    await machine.set_brew_by_weight_dose_mode(mode)
                    # Same: optimistic local update already done, skip stale get_dashboard().
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_brew_by_weight_dose_mode failed: {e}")

            case "CoffeeMachineBackFlushStartCleaning":
                machine = self._get_machine(serial)
                try:
                    await machine.start_backflush()
                    await machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"start_backflush failed: {e}")

            case "CoffeeMachineSettingSmartStandBy":
                machine = self._get_machine(serial)
                try:
                    enabled = bool(int(message.get("value", 0)))
                    minutes = int(message.get("value2", 0))
                    after_str = message.get("value3")
                    if after_str is None:
                        self._logger.warning("set_smart_standby: value3 absent, defaulting to LastBrewing")
                        after_str = "LastBrewing"
                    after = SmartStandByType(after_str)
                    await machine.set_smart_standby(enabled, minutes, after)
                    await machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_smart_standby failed: {e}")

            case _:
                self._logger.error(f"unknown function: {fn}")


Jee4LM().run()