"""Bluetooth class for La Marzocco machines."""

import base64
import json
import logging
import asyncio

from typing import Any

from bleak import (
    BaseBleakScanner,
    BleakClient,
    BleakError,
    BleakScanner,
    BLEDevice,
)

from globals import (
    AUTH_CHARACTERISTIC,
    BT_MODEL_PREFIXES,
    SETTINGS_CHARACTERISTIC,
    BoilerType,
)

from exceptions import (
    BluetoothConnectionFailed,
)

class LaMarzoccoBluetoothClient:
    """Class to interact with machine via Bluetooth."""
    def __init__(
        self,
        username: str,
        serial_number: str,
        token: str,
        address_or_ble_device: BLEDevice | str,
    ) -> None:
        """Initializes a new LaMarzoccoBluetoothClient instance."""
        self._username = username
        self._serial_number = serial_number
        self._token = token
        self._address = (
            address_or_ble_device.address
            if isinstance(address_or_ble_device, BLEDevice)
            else address_or_ble_device
        )
        self._address_or_ble_device = address_or_ble_device
        self._client = BleakClient(address_or_ble_device)
        
    @staticmethod
    async def discover_devices(
        scanner: BaseBleakScanner | BleakScanner | None = None,
    ) -> list[BLEDevice]:
        """Find machines based on model name."""
        logging.debug('scanning devices')
        ble_devices: list[BLEDevice] = []
        if scanner is None:
            logging.debug('scanning with BleakScanner')
            scanner = BleakScanner()
            await scanner.start()
            logging.debug('wait 10s')
            await asyncio.sleep(10)
            logging.debug('scanning stopped')
            await scanner.stop()
            logging.debug('scanner initialized')
        logging.debug('scanner object created')
        assert hasattr(scanner, "discover")
        devices: list[BLEDevice] = await scanner.discover()
        logging.debug('end scanning devices')
        for device in devices:
            if device.name and device.name.startswith(BT_MODEL_PREFIXES):
                ble_devices.append(device)
        logging.debug('list filled with ble')
        return ble_devices

    @property
    def address(self) -> str:
        """Return the BT MAC address of the machine."""

        return self._address

    @property
    def connected(self) -> bool:
        """Return the connection status."""

        return self._client.is_connected

    async def set_power(self, enabled: bool) -> None:
        """Power on the machine."""
        i = 1 if enabled else 0
        logging.debug(f'set BT power {i}')

        mode = "BrewingMode" if enabled else "StandBy"
        data = {
            "name": "MachineChangeMode",
            "parameter": {
                "mode": mode,
            },
        }
        await self._write_bluetooth_json_message(data)

    async def set_steam(self, enabled: bool) -> None:
        """Power cycle steam."""
        i = 1 if enabled else 0
        logging.debug(f'set BT steam {i}')

        data = {
            "name": "SettingBoilerEnable",
            "parameter": {
                "identifier": "SteamBoiler",
                "state": enabled,
            },
        }
        await self._write_bluetooth_json_message(data)

    async def set_temp(self, boiler: BoilerType, temperature: float) -> None:
        """Set boiler temperature (in Celsius)"""
        logging.debug(f'set Boiler {boiler} temp to {temperature}')

        data = {
            "name": "SettingBoilerTarget",
            "parameter": {
                "identifier": boiler.value,
                "value": temperature,
            },
        }
        await self._write_bluetooth_json_message(data)

    async def _write_bluetooth_message(
        self, characteristic: str, message: bytes | str
    ) -> None:
        """Connect to machine and write a message."""

        if not self._client.is_connected:
            try:
                self._client = BleakClient(self._address_or_ble_device)
                await self._client.connect()
                await self._authenticate()
            except (BleakError, TimeoutError) as e:
                logging.debug('error writing BT message'+str(e))
                raise BluetoothConnectionFailed(
                    f"Failed to connect to machine with Bluetooth: {e}"
                ) from e

        # check if message is already bytes string
        if not isinstance(message, bytes):
            message = bytes(message, "utf-8")

        # append trailing zeros to settings message
        if characteristic == SETTINGS_CHARACTERISTIC:
            message += b"\x00"

        logging.debug(f'Sending bluetooth message: {message} to {characteristic}')

        await self._client.write_gatt_char(characteristic, message)

    async def _write_bluetooth_json_message(
        self,
        data: dict[str, Any],
        characteristic: str = SETTINGS_CHARACTERISTIC,
    ) -> None:
        """Write a json message to the machine."""

        await self._write_bluetooth_message(
            characteristic=characteristic,
            message=json.dumps(data, separators=(",", ":")),
        )

    async def _authenticate(self) -> None:
        """Build authentication string and send it to the machine."""

        user = self._username + ":" + self._serial_number
        user_bytes = user.encode("utf-8")
        token = self._token.encode("utf-8")
        auth_string = base64.b64encode(user_bytes) + b"@" + base64.b64encode(token)
        await self._write_bluetooth_message(
            characteristic=AUTH_CHARACTERISTIC,
            message=auth_string,
        )