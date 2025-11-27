import logging
import asyncio
from globals import INSTALLKEYFILE, INSTALLCREDENTIALFILE, READY
import asyncio
import uuid
from pathlib import Path

from aiohttp import ClientSession

from pylamarzocco.const import PreExtractionMode
from pylamarzocco import LaMarzoccoCloudClient, LaMarzoccoMachine
from pylamarzocco.models import ThingDashboardWebsocketConfig
from pylamarzocco.util import InstallationKey, generate_installation_key
from mashumaro import field_options
from mashumaro.mixins.json import DataClassJSONMixin

from jeedomdaemon.base_daemon import BaseDaemon

class JeeCredential(DataClassJSONMixin):
    username:str
    password:str
    def __init__(self, u, p) -> None:
        self.username = u
        self.password = p
        
    def isinit(self)->bool:
        return self.username != "" and self.username != ""

class Jee4LM(BaseDaemon):
    
    # set of variables to pass from jeedom interface
    serial:str =""
    credential = JeeCredential("","")
    registration_required:bool         
    session:ClientSession 
    installation_key:InstallationKey 
    machine:LaMarzoccoMachine
    genrationofkey:bool =False
    
    def __init__(self) -> None:
        super().__init__(on_message_cb=self.on_message, on_stop_cb=self.on_stop, on_start_cb=self.on_start) 
        self.connected = False

    async def on_start(self):
        self._logger.info("python code starting")
        self.session  = ClientSession()
        if not self.getInstallKey(): # first registration
            self._logger.info("registration going on")
            self.genrationofkey = True
            self.generateInstallKey()

        self.client = LaMarzoccoCloudClient(
            username=self.credential.username,
            password=self.credential.password,
            installation_key=self.installation_key,
            client=self.session,
            )
  
        if  self.genrationofkey: # first registration
            self._logger.info("registration moving on")
            self.client._installation_key = self.installation_key   #update client installation key for further calls
            await self.client.async_register_client()
        else:
            self._logger.info("installation key found")
        
        if not self.getCredential() or self.credential.isinit(): 
            self._logger.info("please enter credential (user password)")
            #await self.stop()
        else:
            self._logger.info("credential found")
        self._logger.info("python part correctly started")
            
 
    #######################################################################################

    def getInstallKey(self)->bool:
        installkey_file = Path(INSTALLKEYFILE)
        if not installkey_file.exists():
            return False
        with open(installkey_file, "r", encoding="utf-8") as f:
            self.installation_key = InstallationKey.from_json(f.read())
            f.close()
        return True    
        
    def getCredential(self)->bool:
        credential_file = Path(INSTALLCREDENTIALFILE)
        if not credential_file.exists():
            return False
        with open(credential_file, "r", encoding="utf-8") as f:
            self.credential = self.credential.from_json(f.read()) 
            f.close()
        return True
    
    def generateInstallKey(self):
        self._logger.debug("Generating new key material...")
        self.installation_key = generate_installation_key(str(uuid.uuid4()).lower())
        self._logger.debug("Generated key material:")
        installkey_file = Path(INSTALLKEYFILE)
        try:
            with open(installkey_file, "w", encoding="utf-8") as f:
                ser = str(self.installation_key.to_json())
                self._logger.debug(f"key material as ({ser})")
                f.write(ser)
                f.close()
        except Exception as e:
            self._logger.info(f"error saving installation key file : {e}")
    
    def saveCredential(self, u, p): 
        self._logger.debug("saving credential to file...")
        credential_file = Path(INSTALLCREDENTIALFILE)
        with open(credential_file, "w", encoding="utf-8") as f:
            c = "{" + f'"username" : {u}, "password" : {p}' + "}"
            self._logger.debug(f"credential material as ({c})")
            f.write(c)
            f.close()
            
    #######################################################################################
            
    def istasks_from_id(self, id):
        tasks = asyncio.all_tasks()
        self._logger.debug(f'Searching for task with id {id}')
        i=''
        for t in tasks:
            n = t.get_name()
            i = 'lmtask' + str(id)
            if n == i or id == '*':
                self._logger.debug(f'Found task {i}')
                return True
        self._logger.debug(f'Task {i} not found')
        return False

    async def cancel_all_tasks_from_id(self, id):
        tasks = asyncio.all_tasks()
        for t in tasks:
            n = t.get_name()
            i = 'lmtask' + str(id)
            if n == i or (id == '*' and n.startswith('lmtask')):
                t.cancel()
                self._logger.debug(f'Cancelled task {i}')
                try:
                    await t
                except asyncio.CancelledError:
                    self._logger.debug(f'Task {i} successfully cancelled')

    async def stop_after(self, delay, what):
        READY = False
        try:
            while True:
                self._logger.info(f'Refreshing eqlogic {what} information every {delay} seconds')
                #await self.send_to_jeedom({'id': what})
                await asyncio.sleep(delay)
        except asyncio.CancelledError:
            self._logger.info('Loop cancelled')

    async def on_message(self, message: dict):
        self._logger.debug(f'on_message - daemon received command: {message["command"]}')
        match message['command']:
            case 'check':
                self._logger.debug(f'Check polling for id {message["id"]} already running')
                if self.istasks_from_id(message['id']):
                     await self.send_to_jeedom({'id':message["id"], 'run':1})
                else:
                    await self.send_to_jeedom({'id':message["id"], 'run':0})
            case 'poll':
                if not self.istasks_from_id(message['id']):
                    self._logger.debug(f'Start refreshing eqlogic id {message["id"]}')
                    task1 = asyncio.create_task(self.stop_after(10, message['id']))
                    task1.set_name('lmtask' + str(message['id']))
                else:
                    self._logger.debug(f'Task already running for id {message["id"]}')
            case 'stop':
                self._logger.info(f'Stop refreshing eqlogic id {message["id"]}')
                if self.istasks_from_id(message['id']):
                    await self.cancel_all_tasks_from_id(message['id'])
                else:
                    self._logger.debug(f'No task running for id {message["id"]}')
                READY = True
            case 'lm':
                self._logger.debug(f'on_message - PyLM command {message["function"]}')
                match message['function']:
                    case 'detect':
                        self._logger.debug(f'BT command u={message["function"]} t={message["value"]}') # 
                        async with self.session:
                            l = self.client.list_things()
                            await self.send_to_jeedom({'id':message["id"], 'things': l})
                    case 'login':
                        self._logger.debug(f'BT command u={message["function"]} u={message["value"]} p={message["value2"]} ') # 
                        self.saveCredential(message["value"], message["value2"])
                        self._logger.info("credential changed, daemon must restart")
                        await self.stop()
                    case 'dash':
                        self._logger.debug(f'BT command u={message["function"]} m={message["serial"]}') # 
                        m = message["serial"] # serial nb
                        if not self.machine:
                            self.machine = LaMarzoccoMachine(m, self.client)
                            await self.machine.get_dashboard()
                            r = self.machine.dashboard.to_dict()
                            await self.send_to_jeedom({'id':message["id"], 'dash': r})                            
                    case 'settings':
                        self._logger.debug(f'BT command u={message["function"]} m={message["serial"]}') # 
                        m = message["serial"] # serial nb
                        if not self.machine:
                            self.machine = LaMarzoccoMachine(m, self.client)
                            await self.machine.get_settings()
                            r = self.machine.settings.to_dict()
                            await self.send_to_jeedom({'id':message["id"], 'settings': r})                            
                    case 'schedule':
                        self._logger.debug(f'BT command u={message["function"]} m={message["serial"]}') # 
                        m = message["serial"] # serial nb
                        if not self.machine:
                            self.machine = LaMarzoccoMachine(m, self.client)
                            await self.machine.get_schedule()
                            r = self.machine.schedule.to_dict()
                            await self.send_to_jeedom({'id':message["id"], 'schedule': r})                            
                    case 'CoffeeMachineChangeMode':
                        self._logger.debug(f'BT command u={message["function"]} t={message["value"]}') # 
                        v = message["value"] # 0=OFF 1=ON
                        m = message["serial"] # serial nb
                        self._logger.debug(f'command s={m} c={v}')
                        if not self.machine:
                            self.machine = LaMarzoccoMachine(m, self.client)
                            await self.machine.set_power(True if v == 1 else False)
                            await self.machine.get_dashboard()
                            r = self.machine.dashboard.to_dict()
                            await self.send_to_jeedom({'id':message["id"], 'dash': r})                            
                    case 'CoffeeMachineSettingSteamBoilerEnabled': # steam boiler on/off
                        self._logger.debug(f'BT command u={message["function"]} t={message["value"]}') # 
                        v = message["value"] # 0=OFF 1=ON
                        m = message["serial"] # serial nb
                        self._logger.debug(f'command s={m} c={v}')
                        if not self.machine:
                            self.machine = LaMarzoccoMachine(m, self.client)
                            await self.machine.set_steam(True if v == 1 else False)
                            await self.machine.get_dashboard()
                            r = self.machine.dashboard.to_dict()
                            await self.send_to_jeedom({'id':message["id"], 'dash': r})                            
                            #self.send_to_jeedom({'id':message["id"], 'steam': v})                            
                    case 'CoffeeMachineSettingCoffeeBoilerTargetTemperature': # coffee boiler temperature
                        self._logger.debug(f'BT command u={message["function"]} t={message["value"]}') # 
                        v = message["value"] #  float for temperature
                        m = message["serial"] # serial
                        self._logger.debug(f'command s={m} c={v}')
                        if not self.machine:
                            self.machine = LaMarzoccoMachine(m, self.client)
                            await self.machine.set_coffee_target_temperature(v)
                            await self.machine.get_dashboard()
                            r = self.machine.dashboard.to_dict()
                            await self.send_to_jeedom({'id':message["id"], 'dash': r})                            
                            #self.send_to_jeedom({'id':message["id"], 'cofeetarget': v})                            
                    case 'CoffeeMachineSettingSteamBoilerTargetTemperature': # steam boiler temperature
                        self._logger.debug(f'BT command u={message["function"]} t={message["value"]}') # 
                        v = message["value"] #  float for temperature
                        m = message["serial"] # serial
                        self._logger.debug(f'command s={m} c={v}')
                        if not self.machine:
                            self.machine = LaMarzoccoMachine(m, self.client)
                            await self.machine.set_steam_target_temperature(v)
                            await self.machine.get_dashboard()
                            r = self.machine.dashboard.to_dict()
                            await self.send_to_jeedom({'id':message["id"], 'dash': r})                            
                            #self.send_to_jeedom({'id':message["id"], 'steamtarget': v})                            
                    case 'CoffeeMachinePreInfusionChangeMode': # plumb in on off
                        v = message["value"] #  mode
                        m = message["serial"] # serial
                        self._logger.debug(f'command s={m} c={v}')
                        if not self.machine:
                            self.machine = LaMarzoccoMachine(m, self.client)
                            await self.machine.set_pre_extraction_mode(PreExtractionMode.DISABLED if v==0 else PreExtractionMode.PREINFUSION)
                            await self.machine.get_dashboard()
                            r = self.machine.dashboard.to_dict()
                            await self.send_to_jeedom({'id':message["id"], 'dash': r})                            
                            #self.send_to_jeedom({'id':message["id"], 'preinfusion': v})                            
                        self._logger.debug(f'BT command u={message["function"]} t={message["value"]}') # 
                    case 'CoffeeMachinePreBrewingChangeMode': # prebrew on off
                        self._logger.debug(f'BT command u={message["function"]} t={message["value"]}') # 
                        v = message["value"] #  mode
                        m = message["serial"] # serial
                        self._logger.debug(f'command s={m} c={v}')
                        if not self.machine:
                            self.machine = LaMarzoccoMachine(m, self.client)
                            await self.machine.set_pre_extraction_mode(PreExtractionMode.DISABLED if v==0 else PreExtractionMode.PREBREWING)
                            await self.machine.get_dashboard()
                            r = self.machine.dashboard.to_dict()
                            await self.send_to_jeedom({'id':message["id"], 'dash': r})                            
                            #self.send_to_jeedom({'id':message["id"], 'prebrewing': v})                            
                    case 'CoffeeMachineBrewByWeightSettingDoses': # change dose from bbq
                        self._logger.debug(f'BT command u={message["function"]} t={message["value"]} t2={message["value2"]}') # 
                        v = float(message["value"]) #  start
                        v1 = float(message["value2"]) # stop
                        m = message["serial"] # serial
                        self._logger.debug(f'command s={m} c={v} c1={v1}')
                        if not self.machine:
                            self.machine = LaMarzoccoMachine(m, self.client)
                            await self.machine.get_dashboard()
                            r = self.machine.dashboard.to_dict()
                            #self.send_to_jeedom({'id':message["id"], 'dash': r})                            
                    case 'CoffeeMachineBrewByWeightChangeMode': # change active dose from bbw
                        self._logger.debug(f'BT command u={message["function"]} t={message["value"]}') #                         
                        m = message["serial"] # serial
                        v = float(message["value"]) #  start
                        v1 = float(message["value2"]) # stop
                        m = message["serial"] # serial
                    case 'CoffeeMachinePreBrewingChangeTimes': # change preinfusion/prebrewing times
                        self._logger.debug(f'BT command u={message["function"]} t={message["value"]} t2={message["value2"]}') # 
                        v = float(message["value"]) #  start
                        v1 = float(message["value2"]) # stop
                        m = message["serial"] # serial
                        self._logger.debug(f'command s={m} c={v} c1={v1}')
                        if not self.machine:
                            self.machine = LaMarzoccoMachine(m, self.client)
                            await self.machine.set_pre_extraction_times(v,v1)
                            await self.machine.get_dashboard()
                            r = self.machine.dashboard.to_dict()
                            await self.send_to_jeedom({'id':message["id"], 'dash': r})                            
                    case 'CoffeeMachineBackFlushStartCleaning': # start backflush
                        self._logger.debug(f'BT command u={message["function"]}') #        
                        m = message["serial"] # serial nb
                        self._logger.debug(f'command s={m}')
                        if not self.machine:
                            self.machine = LaMarzoccoMachine(m, self.client)
                            await self.machine.start_backflush()
                            await self.machine.get_dashboard()
                            r = self.machine.dashboard.to_dict()
                            await self.send_to_jeedom({'id':message["id"], 'dash': r})                            
                    case 'CoffeeMachineSettingSmartStandBy': # change smartstandby settings and activation
                        self._logger.debug(f'BT command u={message["function"]} t={message["value"]} t2={message["value2"]} t2={message["value3"]}') # 
            case _:
                self._logger.error('on_message - command not found')

    async def on_stop(self):
        self._logger.info('Received stop signal, cancelling tasks...')
        await self.cancel_all_tasks_from_id('*')
        self._logger.info('Exiting daemon')

Jee4LM().run()
