from globals import INSTALLKEYFILE, INSTALLCREDENTIALFILE
import asyncio
from asyncio import Task
import uuid
import os
import json
from pathlib import Path

from dataclasses import dataclass
from typing import Optional

from pylamarzocco.const import PreExtractionMode
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

        self.serial: str = ""
        self.credential = JeeCredential()
        self.installation_key: Optional[InstallationKey] = None
        self.client: Optional[LaMarzoccoCloudClient] = None
        self.machine: Optional[LaMarzoccoMachine] = None
        self.dashboard_task: Optional[Task] = None
        self._connected: bool = False

    # ------------------------------------------------------------------
    # Lifecycle
    # ------------------------------------------------------------------

    async def on_start(self) -> None:
        self._logger.info("daemon starting")

        # 1. Load or generate installation key
        first_registration = not self._load_install_key()
        if first_registration:
            self._logger.info("no installation key — generating")
            if not self._save_install_key():
                self._logger.error("cannot write installation key, check permissions")
                await self.stop()
                return

        # 2. Load credentials (may be absent on very first run)
        has_creds = self._load_credential() and self.credential.isinit()
        if not has_creds:
            self._logger.warning("no credentials yet — waiting for login command")

        # 3. Build cloud client (pylamarzocco manages its own ClientSession)
        self.client = LaMarzoccoCloudClient(
            username=self.credential.username,
            password=self.credential.password,
            installation_key=self.installation_key,
        )

        # 4. Register on first run
        if first_registration:
            self._logger.info("registering client with LM cloud")
            try:
                await self.client.async_register_client()
            except Exception as e:
                self._logger.error(f"registration failed: {e}")
                await self.stop()
                return

        self._logger.info("daemon ready")

    async def on_stop(self) -> None:
        self._logger.info("daemon stopping")
        self._cancel_dash_loop()

    # ------------------------------------------------------------------
    # Dashboard polling loop
    # ------------------------------------------------------------------

    async def _dash_loop(self, eq_id: int) -> None:
        """Poll dashboard every 5 s and push updates to Jeedom."""
        self._logger.info(f"dashboard loop started for eq {eq_id}")
        try:
            while True:
                try:
                    await self.machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "cmd": "dash_update",
                        "dash": self.machine.dashboard.to_json(),
                    })
                except asyncio.CancelledError:
                    raise
                except Exception as e:
                    self._logger.error(f"dashboard loop poll error: {e}")
                await asyncio.sleep(5)
        except asyncio.CancelledError:
            self._logger.info("dashboard loop cancelled")

    def _ensure_dash_loop(self, eq_id: int) -> None:
        """Start dash loop if not already running or if previous task is dead."""
        if self.dashboard_task is None or self.dashboard_task.done():
            self._logger.info(f"starting dashboard loop for eq {eq_id}")
            self.dashboard_task = asyncio.create_task(self._dash_loop(eq_id))

    def _cancel_dash_loop(self) -> None:
        if self.dashboard_task and not self.dashboard_task.done():
            self.dashboard_task.cancel()
            self.dashboard_task = None

    # ------------------------------------------------------------------
    # Machine helper — ensure machine object exists
    # ------------------------------------------------------------------

    def _ensure_machine(self, serial: str) -> None:
        if self.machine is None or self.machine.serial_number != serial:
            self._logger.debug(f"creating machine object for serial {serial}")
            self.machine = LaMarzoccoMachine(serial, self.client)

    # ------------------------------------------------------------------
    # Credential / install key persistence
    # ------------------------------------------------------------------

    def _load_install_key(self) -> bool:
        path = self.data_dir / INSTALLKEYFILE
        if not path.exists():
            return False
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            self.installation_key = InstallationKey.from_dict(data)
            return True
        except Exception as e:
            self._logger.error(f"error reading installation key: {e}")
            return False

    def _save_install_key(self) -> bool:
        self.installation_key = generate_installation_key(str(uuid.uuid4()).lower())
        path = self.data_dir / INSTALLKEYFILE
        try:
            path.write_text(str(self.installation_key.to_json()), encoding="utf-8")
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
        path = self.data_dir / INSTALLCREDENTIALFILE
        try:
            payload = json.dumps({"username": username, "password": password})
            path.write_text(payload, encoding="utf-8")
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

            # ---- discovery ------------------------------------------
            case "detect":
                try:
                    things = await self.client.list_things()
                    await self.send_to_jeedom({
                        "cmd": "detect",
                        "things": [t.to_dict() for t in things],
                    })
                except Exception as e:
                    self._logger.error(f"detect failed: {e}")

            # ---- dashboard loop control (machine power state) --------
            case "on":
                # Machine reported as ON: ensure loop is running
                self._ensure_machine(serial)
                self._ensure_dash_loop(eq_id)

            case "off":
                # Machine reported as OFF: stop loop
                self._cancel_dash_loop()

            # ---- one-shot reads --------------------------------------
            case "dash":
                self._ensure_machine(serial)
                try:
                    await self.machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": self.machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"get_dashboard failed: {e}")

            case "settings":
                self._ensure_machine(serial)
                try:
                    await self.machine.get_settings()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "settings": self.machine.settings.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"get_settings failed: {e}")

            case "schedule":
                self._ensure_machine(serial)
                try:
                    await self.machine.get_schedule()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "schedule": self.machine.schedule.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"get_schedule failed: {e}")

            # ---- machine commands ------------------------------------
            case "CoffeeMachineChangeMode":
                self._ensure_machine(serial)
                enabled = bool(message.get("value", 0))
                try:
                    await self.machine.set_power(enabled)
                    await self.machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": self.machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_power failed: {e}")

            case "CoffeeMachineSettingSteamBoilerEnabled":
                self._ensure_machine(serial)
                enabled = bool(message.get("value", 0))
                try:
                    await self.machine.set_steam(enabled)
                    await self.machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": self.machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_steam failed: {e}")

            case "CoffeeMachineSettingCoffeeBoilerTargetTemperature":
                self._ensure_machine(serial)
                try:
                    await self.machine.set_coffee_target_temperature(float(message["value"]))
                    await self.machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": self.machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_coffee_target_temperature failed: {e}")

            case "CoffeeMachineSettingSteamBoilerTargetTemperature":
                self._ensure_machine(serial)
                try:
                    await self.machine.set_steam_target_temperature(float(message["value"]))
                    await self.machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": self.machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_steam_target_temperature failed: {e}")

            case "CoffeeMachinePreInfusionChangeMode":
                self._ensure_machine(serial)
                mode = PreExtractionMode.DISABLED if not message.get("value") else PreExtractionMode.PREINFUSION
                try:
                    await self.machine.set_pre_extraction_mode(mode)
                    await self.machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": self.machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_pre_extraction_mode (infusion) failed: {e}")

            case "CoffeeMachinePreBrewingChangeMode":
                self._ensure_machine(serial)
                mode = PreExtractionMode.DISABLED if not message.get("value") else PreExtractionMode.PREBREWING
                try:
                    await self.machine.set_pre_extraction_mode(mode)
                    await self.machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": self.machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_pre_extraction_mode (brewing) failed: {e}")

            case "CoffeeMachinePreBrewingChangeTimes":
                self._ensure_machine(serial)
                try:
                    await self.machine.set_pre_extraction_times(
                        float(message["value"]),
                        float(message["value2"]),
                    )
                    await self.machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": self.machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"set_pre_extraction_times failed: {e}")

            case "CoffeeMachineBrewByWeightSettingDoses":
                self._ensure_machine(serial)
                # doses are sent as a pair; machine API may vary — log only for now
                self._logger.debug(
                    f"BBW doses: value={message.get('value')} value2={message.get('value2')}"
                )

            case "CoffeeMachineBackFlushStartCleaning":
                self._ensure_machine(serial)
                try:
                    await self.machine.start_backflush()
                    await self.machine.get_dashboard()
                    await self.send_to_jeedom({
                        "id": eq_id,
                        "dash": self.machine.dashboard.to_json(),
                    })
                except Exception as e:
                    self._logger.error(f"start_backflush failed: {e}")

            case "CoffeeMachineSettingSmartStandBy":
                self._ensure_machine(serial)
                self._logger.debug(
                    f"smartstandby: enable={message.get('value')} "
                    f"minutes={message.get('value2')} after={message.get('value3')}"
                )
                # TODO: implement when pylamarzocco exposes the call

            case _:
                self._logger.error(f"unknown function: {fn}")


Jee4LM().run()