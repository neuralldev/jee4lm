import globals
import logging
import asyncio

from jeedomdaemon.base_daemon import BaseDaemon

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
        pass

    def istasks_from_id(self, id):
        tasks = asyncio.all_tasks()
        for t in tasks:
            if t.get_name()==id or id=='*':
                return True
        return False

    async def cancel_all_tasks_from_id(self, id):
        tasks = asyncio.all_tasks()
        for t in tasks:
            if t.get_name()==id:
                t.cancel()
         
    async def stop_after(self, delay, what):
        globals.READY=False
        try:
            while 1:
                logging.debug(f'send eqID {what} to refresh to jeedom callback')
                await self.send_to_jeedom({'id':what})
                await asyncio.sleep(delay)
        except asyncio.CancelledError:
             logging.info('cancel loop');
        
    async def on_message(self, message: list):
        logging.debug('on_message - daemon received command : '+str(message['lm'])+ ' for id '+str(message['id']))
        if message['lm'] == 'poll':
            if self.istasks_from_id(message['id']):
                logging.debug('on_message - start polling on id '+str(message['id']))
                task1 = asyncio.create_task(await self.stop_after(5, message['id']))
            else:
                logging.debug('task already running for '+str(message['id']))
        elif message['lm'] == 'stop':
            logging.debug('on_message - stop polling on id '+str(message['id']))
            if self.istasks_from_id(message['id']):
                await self.cancel_all_tasks_from_id(message['id'])
            else:
                logging.debug('no task running for id'+str(message['id']))
            globals.READY=True
        else:
            logging.debug('on_message - command not found')

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
