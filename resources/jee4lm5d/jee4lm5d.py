import logging
import asyncio
import globals
import asyncio
import uuid
from pathlib import Path

from aiohttp import ClientSession

from pylamarzocco import LaMarzoccoCloudClient, LaMarzoccoMachine
from pylamarzocco.models import ThingDashboardWebsocketConfig
from pylamarzocco.util import InstallationKey, generate_installation_key
from mashumaro import field_options
from mashumaro.mixins.json import DataClassJSONMixin

from jeedomdaemon.base_daemon import BaseDaemon

class JeeCredential(DataClassJSONMixin):
    username:str=""
    password:str=""
    
    def isinit(self)->bool:
        return self.username != "" and self.username != ""

class Jee4LM(BaseDaemon):
    
    # set of variables to pass from jeedom interface
    serial:str =""
    credential:JeeCredential
    registration_required:bool         
    session:ClientSession 
    installation_key:InstallationKey
    machine:LaMarzoccoMachine
    
    def __init__(self) -> None:
    # Standard initialisation
        # adapter to match BaseDaemon expected signature ((list) -> Awaitable[None])
        async def _on_message_cb(payload):
            """
            Accept either a dict or a list/tuple where the first element is a dict,
            then forward a dict to the real on_message handler.
            """
            msg = {}
            if isinstance(payload, dict):
                msg = payload
            elif isinstance(payload, (list, tuple)) and payload:
                first = payload[0]
                if isinstance(first, dict):
                    msg = first
                else:
                    try:
                        msg = dict(first)
                    except Exception:
                        msg = {}
            await self.on_message(msg)

        super().__init__(on_message_cb=_on_message_cb, on_stop_cb=self.on_stop)
        self.connected = False

    async def on_start(self):
        self.session  = ClientSession()
        self.client = LaMarzoccoCloudClient(
            username=self.credential.username,
            password=self.credential.password,
            installation_key=self.installation_key,
            client=self.session,
            )
  
        if not self.getInstallKey(): # first registration
            self.generateInstallKey()
            logging.info("registration going on")
            self.client._installation_key = self.installation_key   #update client installation key for further calls
            await self.client.async_register_client()
        
        if not self.getCredential() or self.credential.isinit(): 
            logging.info("please enter credential (user password), then relaunch daemon")
            await self.stop()
        
 
    #######################################################################################

    def getInstallKey(self)->bool:
        installkey_file = Path("../data/installation_key.json")
        if not installkey_file.exists():
            return False
        with open(installkey_file, "r", encoding="utf-8") as f:
            self.installation_key = InstallationKey.from_json(f.read())
            f.close()
        return True    
        
    def getCredential(self)->bool:
        credential_file = Path("../data/credential.json")
        if not credential_file.exists():
            return False
        with open(credential_file, "r", encoding="utf-8") as f:
            self.credential = self.credential.from_json(f.read()) 
            f.close()
        return True
    
    def generateInstallKey(self):
        logging.debug("Generating new key material...")
        self.installation_key = generate_installation_key(str(uuid.uuid4()).lower())
        logging.debug("Generated key material:")
        installkey_file = Path("../data/installation_key.json")
        with open(installkey_file, "w", encoding="utf-8") as f:
            ser = str(self.installation_key.to_json())
            logging.debug("key material as ({ser})")
            f.write(ser)
            f.close()
            
            
    #######################################################################################
            
    def istasks_from_id(self, id):
        tasks = asyncio.all_tasks()
        logging.debug(f'Searching for task with id {id}')
        i=''
        for t in tasks:
            n = t.get_name()
            i = 'lmtask' + str(id)
            if n == i or id == '*':
                logging.debug(f'Found task {i}')
                return True
        logging.debug(f'Task {i} not found')
        return False

    async def cancel_all_tasks_from_id(self, id):
        tasks = asyncio.all_tasks()
        for t in tasks:
            n = t.get_name()
            i = 'lmtask' + str(id)
            if n == i or (id == '*' and n.startswith('lmtask')):
                t.cancel()
                logging.debug(f'Cancelled task {i}')
                try:
                    await t
                except asyncio.CancelledError:
                    logging.debug(f'Task {i} successfully cancelled')

    async def stop_after(self, delay, what):
        globals.READY = False
        try:
            while True:
                logging.info(f'Refreshing eqlogic {what} information every {delay} seconds')
                await self.send_to_jeedom({'id': what})
                await asyncio.sleep(delay)
        except asyncio.CancelledError:
            logging.info('Loop cancelled')

    async def on_message(self, message: dict):
        logging.debug(f'on_message - daemon received command: {message["command"]} for id {message["id"]}')
        match message['command']:
            case 'check':
                logging.debug(f'Check polling for id {message["id"]} already running')
                if self.istasks_from_id(message['id']):
                     await self.send_to_jeedom({'id':message["id"], 'run':1})
                else:
                    await self.send_to_jeedom({'id':message["id"], 'run':0})
            case 'poll':
                if not self.istasks_from_id(message['id']):
                    logging.debug(f'Start refreshing eqlogic id {message["id"]}')
                    task1 = asyncio.create_task(self.stop_after(10, message['id']))
                    task1.set_name('lmtask' + str(message['id']))
                else:
                    logging.debug(f'Task already running for id {message["id"]}')
            case 'stop':
                logging.info(f'Stop refreshing eqlogic id {message["id"]}')
                if self.istasks_from_id(message['id']):
                    await self.cancel_all_tasks_from_id(message['id'])
                else:
                    logging.debug(f'No task running for id {message["id"]}')
                globals.READY = True
            case 'lm':
                logging.debug(f'on_message - PyLM command {message["function"]} for ID {message["id"]}')
                match message['function']:
                    case 'detect':
                        logging.debug(f'BT command u={message["function"]} t={message["value"]}') # 
#                        async with ClientSession() as self.session:
                    case 'CoffeeMachineChangeMode':
                        logging.debug(f'BT command u={message["function"]} t={message["value"]}') # 
                        v = message["value"] # 0=OFF 1=ON
                        m = message["serial"] # serial nb
                        logging.debug(f'command s={m} c={v}')
                        if not self.machine:
                            self.machine = LaMarzoccoMachine(m, self.client)
                            await self.machine.set_power(True if v == 1 else False)
#                            status = self.machine.get_dashboard()
                            self.send_to_jeedom({'id':message["id"], 'power': v})                            
                    case 'CoffeeMachineSettingSteamBoilerEnabled': # steam boiler on/off
                        logging.debug(f'BT command u={message["function"]} t={message["value"]}') # 
                    case 'CoffeeMachineSettingCoffeeBoilerTargetTemperature': # coffee boiler temperature
                        logging.debug(f'BT command u={message["function"]} t={message["value"]}') # 
                    case 'CoffeeMachineSettingSteamBoilerTargetTemperature': # steam boiler temperature
                        logging.debug(f'BT command u={message["function"]} t={message["value"]}') # 
                    case 'CoffeeMachinePreInfusionChangeMode': # plumb in on off
                        logging.debug(f'BT command u={message["function"]} t={message["value"]}') # 
                    case 'CoffeeMachinePreBrewingChangeMode': # prebrew on off
                        logging.debug(f'BT command u={message["function"]} t={message["value"]}') # 
                    case 'CoffeeMachineBrewByWeightSettingDoses': # change dose from bbq
                        logging.debug(f'BT command u={message["function"]} t={message["value"]} t2={message["value2"]}') # 
                    case 'CoffeeMachineBrewByWeightChangeMode': # change active dose from bbw
                        logging.debug(f'BT command u={message["function"]} t={message["value"]}') #                         
                    case 'CoffeeMachinePreBrewingChangeTimes': # change preinfusion/prebrewing times
                        logging.debug(f'BT command u={message["function"]} t={message["value"]} t2={message["value2"]}') # 
                    case 'CoffeeMachineBackFlushStartCleaning': # start backflush
                        logging.debug(f'BT command u={message["function"]}') #        
                    case 'CoffeeMachineSettingSmartStandBy': # change smartstandby settings and activation
                        logging.debug(f'BT command u={message["function"]} t={message["value"]} t2={message["value2"]} t2={message["value3"]}') # 

            case _:
                logging.error('on_message - command not found')

    async def on_stop(self):
        logging.info('Received stop signal, cancelling tasks...')
        await self.cancel_all_tasks_from_id('*')
        logging.info('Exiting daemon')

Jee4LM().run()
