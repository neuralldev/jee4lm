import globals
import logging
import asyncio

from jeedomdaemon.base_daemon import BaseDaemon
from btlm import *

"""
    Jee4LM is a daemon class that extends the BaseDaemon class. It provides methods to handle asynchronous tasks, 
    manage Bluetooth connections, and communicate with a remote system.

    Methods:
        __init__() -> None:
            Initializes the Jee4LM daemon with standard initialization and any additional setup required.

        async on_start():
            Called when the daemon starts. This method is used to create tasks, login to remote systems, etc.

        istasks_from_id(id: str) -> bool:
            Checks if there are any tasks with the given id. Returns True if a task is found, otherwise False.

        async cancel_all_tasks_from_id(id: str):
            Cancels all tasks with the given id. If id is '*', it cancels all tasks that start with 'lmtask'.

        async stop_after(delay: int, what: str):
            Periodically sends information to Jeedom every 'delay' seconds until the task is cancelled.

        async on_message(message: dict):
            Handles incoming messages and executes commands based on the message content. Supports 'poll', 'stop', and 'bt' commands.

        async on_stop():
            Called when the daemon needs to stop. This method closes remote connections and cancels background tasks.
    def __init__(self) -> None:
        # Standard initialisation
        super().__init__(on_start_cb=self.on_start, on_message_cb=self.on_message, on_stop_cb=self.on_stop)

        # Add here any initialisation your daemon would need

    async def on_start(self):
        
        This method will be called when your daemon starts.
        This is the place where you should create your tasks, login to remote system, etc.
"""
class Jee4LM(BaseDaemon):
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
        """
        This callback will be called when the daemon needs to stop.
        You need to close your remote connections and cancel background tasks if any here.
        """
        logging.info('Received stop signal, cancelling tasks...')
        await self.cancel_all_tasks_from_id('*')
        logging.info('Exiting daemon')

Jee4LM().run()
