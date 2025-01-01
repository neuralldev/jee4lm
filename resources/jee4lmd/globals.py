import time
"""
This module defines global constants and enumerations for the La Marzocco Coffee Machine.

Constants:
    DAEMON_VERSION (str): Version of the daemon.
    READY (bool): Indicates if the system is ready.
    PENDING_ACTION (bool): Indicates if there is a pending action.
    BT_MODEL_PREFIXES (tuple): Tuple of Bluetooth model prefixes.
    SETTINGS_CHARACTERISTIC (str): UUID for settings characteristic.
    AUTH_CHARACTERISTIC (str): UUID for authentication characteristic.

Enumerations:
    BoilerType (StrEnum): Enumeration for La Marzocco Coffee Machine Boilers.
        - COFFEE: Represents the coffee boiler.
        - STEAM: Represents the steam boiler.

    BLEFilters (StrEnum): Enumeration for Bluetooth Low Energy filters.
        - MACHINE: Regex pattern for machine filters.
        - SCALE: Regex pattern for scale filters.

    DoseType (StrEnum): Enumeration for dose settings of La Marzocco Coffee Machine Boilers.
        - MASS_TYPE: Represents mass type dosing.
        - PULSES_TYPE: Represents pulses type dosing.

    Dose (StrEnum): Enumeration for doses of La Marzocco Coffee Machine Boilers.
        - CONTINUOUS: Represents continuous dosing.
        - DOSE_A: Represents dose A.
        - DOSE_B: Represents dose B.

    Power (StrEnum): Enumeration for power states.
        - ON: Represents brewing mode.
        - OFF: Represents standby mode.
"""
from enum import IntEnum, StrEnum

DAEMON_VERSION = '1.0'
READY = False
PENDING_ACTION = False
BT_MODEL_PREFIXES = ("MICRA", "MINI", "GS3")
SETTINGS_CHARACTERISTIC = "050b7847-e12b-09a8-b04b-8e0922a9abab"
AUTH_CHARACTERISTIC = "090b7847-e12b-09a8-b04b-8e0922a9abab"

class BoilerType(StrEnum):
    """La Marzocco Coffee Machine Boilers."""
    COFFEE = "CoffeeBoiler1"
    STEAM = "SteamBoiler"

class BLEFilters(StrEnum):
    MACHINE = "/(?:gs3|linea(?!PB)|mini|micra|pico)/i"
    SCALE = "^(LMZ-).*$/i"

class DoseType(StrEnum):
    """La Marzocco Coffee Machine Boilers dose settings."""
    MASS_TYPE = "MassType"
    PULSES_TYPE = "PulsesType"

class Dose(StrEnum):
    """La Marzocco Coffee Machine Boilers doses."""
    CONTINUOUS = "Continous"
    DOSE_A = "DoseA"
    DOSE_B = "DoseB"

class Power(StrEnum):
    ON = "BrewingMode"
    OFF = "StandBy"
