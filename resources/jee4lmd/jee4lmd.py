import globals

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


    async def on_message(self, message: list):
        """
        This function will be called once a message is received from Jeedom; check on api key is done already, just care about your logic
        You must implement the different actions that your daemon can handle.
        """
        pass

    async def on_stop(self):
        """
        This callback will be called when the daemon needs to stop`
        You need to close your remote connexions and cancel background tasks if any here.
        """
        # if you don't have specific action to do on stop, do not create this method
        pass

MyDaemon().run()
