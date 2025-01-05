import globals
import logging
import asyncio

from jeedomdaemon.base_daemon import BaseDaemon
from btlm import *

class Jee4LM(BaseDaemon):
    def __init__(self) -> None:
    # Standard initialisation
        super().__init__(on_start_cb=self.on_start, on_message_cb=self.on_message, on_stop_cb=self.on_stop)

    def istasks_from_id(self, id):
        tasks = asyncio.all_tasks()
        logging.debug(f'Searching for task with id {id}')
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
        logging.debug(f'on_message - daemon received command: {message["lm"]} for id {message["id"]}')
        match message['lm']:
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
            case 'bt':
                logging.debug(f'on_message - BT command {message["bt"]} for ID {message["id"]}')
                match message['bt']:
                    case 'login':
                        logging.debug(f'BT command u={message["username"]} t={message["token"]} s={message["serial"]} addr={message["dev"]}')
                        global lm
                        lm = LaMarzoccoBluetoothClient(message['username'], message['serial'], message['token'], '')
                        logging.debug('lm object created')
                        bledevices: list[BLEDevice] = await lm.discover_devices()
                        logging.debug('Scan finished')
                        for d in bledevices:
                            logging.debug(f'Found device {d}')
                    case 'scan':
                        logging.debug(f'BT command u={message["sc"]} t={message["token"]} s={message["serial"]} addr={message["dev"]}')
                    case 'switch':
                        logging.debug(f'BT command u={message["boiler"]} t={message["state"]}')
                    case 'temp':
                        logging.debug(f'BT command u={message["boiler"]} t={message["temp"]}')
            case _:
                logging.error('on_message - command not found')

    async def on_stop(self):
        logging.info('Received stop signal, cancelling tasks...')
        await self.cancel_all_tasks_from_id('*')
        logging.info('Exiting daemon')

Jee4LM().run()
