import globals
import logging
import asyncio

from jeedomdaemon.base_daemon import BaseDaemon

class MyDaemon(BaseDaemon):
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
        pass

    async def istasks_from_id(id):
        tasks = asyncio.all_tasks()
        for t in tasks:
            if t.get_name()==id:
                return True
        return False

    async def cancel_all_tasks_from_id(id):
        tasks = asyncio.all_tasks()
        for t in tasks:
            if t.get_name()==id:
                t.cancel()
         
    async def stop_after(delay, what):
        globals.READY=False
        while 1:
            logging.debug(f'send eqID {what} to refresh to jeedom callback')
            await self.send_to_jeedom({'id':what})
            await asyncio.sleep(delay)
 
    async def on_message(self, message: list):
        logging.debug('on_message - daemon received command : '+str(message['cmd'])+ 'for id '+str(message['id']))
        if message['cmd'] == 'poll':
            if self.istasks_from_id(message['id']):
                logging.debug('on_message - start polling on id '+str(message['id']))
                task1 = asyncio.create_task(self.stop_after(5, message['id']),message['id'])
        elif message['cmd'] == 'stop':
            logging.debug('on_message - stop polling on id '+str(message['id']))
            if self.istasks_from_id(message['id']):
                self.cancel_all_tasks_from_id(message['id'])
            globals.READY=True
        else:
            logging.debug('on_message - command not found')

    async def on_stop(self):
        """
        This callback will be called when the daemon needs to stop`
        You need to close your remote connexions and cancel background tasks if any here.
        """
        # if you don't have specific action to do on stop, do not create this method
        pass

MyDaemon().run()
