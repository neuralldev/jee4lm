from globals import INSTALLKEYFILE, INSTALLCREDENTIALFILE
import asyncio
from asyncio import Task
import uuid
import json
from pathlib import Path
from dataclasses import dataclass
from typing import Optional

from pylamarzocco.const import PreExtractionMode, MachineMode, WidgetType
from pylamarzocco.models._config import MachineStatus  # pas nécessaire, juste pour référence
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

        # Build cloud client (pylamarzocco manages its own ClientSession)
        self.client = LaMarzoccoCloudClient(
            username=self.credential.username,
            password=self.credential.password,
            installation_key=self.installation_key,
        )

        if first_registration and self.credential.isinit():
            try:
                await self.client.async_register_client()
                self._logger.info("client registered with LM cloud")
            except Exception as e:
                self._logger.error(f"cloud registration failed: {e}")
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

    def _machine_is_on(self, machine: LaMarzoccoMachine) -> bool:
        """Return True if dashboard reports machine not in standby."""
        try:
            status = machine.dashboard.config.get(WidgetType.CM_MACHINE_STATUS)
            return status is not None and status.mode != MachineMode.STANDBY
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

        match fn:

            # ---- credentials ----------------------------------------
            case "login":
                self._save_credential(message["username"], message["password"])
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
                    await machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": machine.dashboard.to_json(),
                    })
                    # Drive the dash loop from power state
                    if enabled:
                        self._start_dash_loop(serial, eq_id)
                    else:
                        self._stop_dash_loop(serial)
                except Exception as e:
                    self._logger.error(f"set_power failed: {e}")

            case "CoffeeMachineSettingSteamBoilerEnabled":
                machine = self._get_machine(serial)
                try:
                    await machine.set_steam(bool(message.get("value", 0)))
                    await machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_steam failed: {e}")

            case "CoffeeMachineSettingCoffeeBoilerTargetTemperature":
                machine = self._get_machine(serial)
                try:
                    await machine.set_coffee_target_temperature(float(message["value"]))
                    await machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_coffee_target_temperature failed: {e}")

            case "CoffeeMachineSettingSteamBoilerTargetTemperature":
                machine = self._get_machine(serial)
                try:
                    await machine.set_steam_target_temperature(float(message["value"]))
                    await machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_steam_target_temperature failed: {e}")

            case "CoffeeMachinePreInfusionChangeMode":
                machine = self._get_machine(serial)
                mode = PreExtractionMode.PREINFUSION if message.get("value") else PreExtractionMode.DISABLED
                try:
                    await machine.set_pre_extraction_mode(mode)
                    await machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_pre_extraction_mode (infusion) failed: {e}")

            case "CoffeeMachinePreBrewingChangeMode":
                machine = self._get_machine(serial)
                mode = PreExtractionMode.PREBREWING if message.get("value") else PreExtractionMode.DISABLED
                try:
                    await machine.set_pre_extraction_mode(mode)
                    await machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_pre_extraction_mode (brewing) failed: {e}")

            case "CoffeeMachinePreBrewingChangeTimes":
                machine = self._get_machine(serial)
                try:
                    await machine.set_pre_extraction_times(
                        float(message["value"]),
                        float(message["value2"]),
                    )
                    await machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_pre_extraction_times failed: {e}")

            case "CoffeeMachineBrewByWeightSettingDoses":
                # TODO: implement when pylamarzocco exposes BBW dose setting
                self._logger.debug(
                    f"BBW doses: value={message.get('value')} value2={message.get('value2')}"
                )

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
                # TODO: implement when pylamarzocco exposes smartstandby API
                self._logger.debug(
                    f"smartstandby: enable={message.get('value')} "
                    f"minutes={message.get('value2')} after={message.get('value3')}"
                )

            case _:
                self._logger.error(f"unknown function: {fn}")


Jee4LM().run()