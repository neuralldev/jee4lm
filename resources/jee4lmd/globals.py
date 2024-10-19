import time
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

