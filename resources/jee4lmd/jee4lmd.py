import globals
import logging
import asyncio

from jeedomdaemon.base_daemon import BaseDaemon
from btlm import *


class Jee4LM(BaseDaemon):
    def __init__(self) -> None:
        # Standard initialisation
        super().__init__(on_start_cb=self.on_start, on_message_cb=self.on_message, on_stop_cb=self.on_stop)

        # Add here any initialisation your daemon would need

    async def on_start(self):
        """
        This method will be called when your daemon starts.
        This is the place where you should create your tasks, login to remote system, etc
        """
        # if you don't have specific action to do on start, do not create this method
   

    def istasks_from_id(self, id):
        tasks = asyncio.all_tasks()
        logging.debug(f'search {id}')
        for t in tasks:
            n = t.get_name()
            i = 'lmtask'+str(id)
            # logging.debug(f'try {n}')
            if n==i or id=='*':
                logging.debug(f'found {i}')
                return True
        logging.debug(f'not found {i}')
        return False

    async def cancel_all_tasks_from_id(self, id):
        tasks = asyncio.all_tasks()
        for t in tasks:
            n = t.get_name()
            i = 'lmtask'+str(id)
            if (n==i) or (id=='*' and n[:6]=='lmtask'):
                t.cancel()
                logging.debug(f'killed {i}')            
         
    async def stop_after(self, delay, what):
        globals.READY=False
        try:
            while 1:
                logging.debug(f'send eqID {what} to refresh to jeedom callback every {delay} seconds')
                await self.send_to_jeedom({'id':what})
                await asyncio.sleep(delay)
        except asyncio.CancelledError:
             logging.info('cancel loop');
        
    async def on_message(self, message: list):
        global lm
        logging.debug('on_message - daemon received command : '+str(message['lm'])+ ' for id '+str(message['id']))
        match message['lm']:
            case 'poll':
                if not self.istasks_from_id(message['id']):
                    logging.debug('on_message - start polling on id '+str(message['id']))
                    task1 = asyncio.create_task(self.stop_after(10, message['id']))
                    task1.set_name('lmtask'+str(message['id']))
                else:
                    logging.debug('task already running for '+str(message['id']))
            case 'stop':
                logging.debug('on_message - stop polling on id '+str(message['id']))
                if self.istasks_from_id(message['id']):
                    await self.cancel_all_tasks_from_id(message['id'])
                else:
                    logging.debug('no task running for id '+str(message['id']))
                globals.READY=True
            case 'bt':
                logging.debug('on_message - BT command '+message['bt']+' for ID '+str(message['id'])+' bt='+message['bt'])
                match message['bt']:
                    case 'login':
                        logging.debug('BT command u='+message['username']+' t='+message['token']+ ' s='+message['serial']+' addr='+message['dev'])
                        global lm
                        lm = LaMarzoccoBluetoothClient(message['username'],message['serial'],message['token'],'')
                        devices = lm.discover_devices()
                        await devices
                        logging.debug('object created')
                        for d in devices:
                            logging.debug('found device')
                    case 'scan':
                        logging.debug('BT command u='+message['sc']+' t='+message['token']+' s='+message['serial']+' addr='+message['dev'])
                    case 'switch':
                        logging.debug('BT command u='+message['boiler']+'  t='+message['state'])
                    case 'temp':
                        logging.debug('BT command u='+message['boiler']+'  t='+message['temp'])                        
            case _:
                logging.error('on_message - command not found')
        
    async def on_stop(self):
        """
        This callback will be called when the daemon needs to stop`
        You need to close your remote connexions and cancel background tasks if any here.
        """
        # if you don't have specific action to do on stop, do not create this method
        logging.info('received stop signal, cancelling tasks...')
        await self.cancel_all_tasks_from_id('*')
        logging.info('exiting daemon')    

Jee4LM().run()
