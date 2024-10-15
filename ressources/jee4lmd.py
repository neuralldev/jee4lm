import subprocess
import os,re
import logging
import sys
import argparse
import time
import datetime
import signal
import json
import traceback
import threading
import globals

try:
	from jeedom.jeedom import *
except ImportError:
	print("Error: importing module from jeedom folder")
	sys.exit(1)

try:
	import queue
except ImportError:
	import Queue as queue

def listen():
	global scanner
	globals.PENDING_ACTION=False
	jeedom_socket.open()
	threading.Thread( target=read_socket, args=('socket',)).start()
	logging.debug('listen : Read Socket Thread Launched')
	globals.JEEDOM_COM.send_change_immediate({'started' : 1,'source' : globals.daemonname,'version' : globals.DAEMON_VERSION});
	while not globals.READY:
		time.sleep(1)
	try:
		while 1:
			try:
				while globals.PENDING_ACTION:
					time.sleep(0.01)
				globals.PENDING_ACTION=True
				for id in globals.SEEN_DEVICES:
					if id[1]+ globals.SCAN_INTERVAL > int(time.time()):
						logging.debug('looping on '+str(id[0]))
					else:
						globals.PENDING_ACTION=False
						logging.debug('ask jeedom to refresh, reset counter for '+str(id[0]))
						id[1] = int(time.time())
			except Exception as e:
				logging.error("listener Exception : %s" % str(e))
				logging.info("listener Shutting down due to errors")
				globals.JEEDOM_COM.send_change_immediate({'halted' : 1,'source' : globals.daemonname,'version' : globals.DAEMON_VERSION});
#				globals.JEEDOM_COM.send_change_immediate({'learn_mode' : 0,'source' : globals.daemonname});
				time.sleep(2)
				shutdown()
			time.sleep(0.02)
	except KeyboardInterrupt:
		logging.error("listener : KeyboardInterrupt, shutdown")
		shutdown()

def read_socket(name):
	while 1:
		try:
			global JEEDOM_SOCKET_MESSAGE
			if not JEEDOM_SOCKET_MESSAGE.empty():
				logging.debug("readsocket - Message received")
				message = JEEDOM_SOCKET_MESSAGE.get().decode('utf-8')
				message =json.loads(message)
				if message['apikey'] != globals.apikey:
					logging.error("readsocket - Invalid apikey from socket : " + str(message))
					return
				logging.debug('readsocket - Received command from jeedom : '+str(message['cmd']))
				if message['cmd'] == 'poll':
					logging.debug('readsocket - start polling every 5 seconds on id '+str(message['id']))
				elif message['cmd'] == 'stop':
					logging.debug('readsocket - stop polling every 5 seconds on id '+str(message['id']))
				elif message['cmd'] == 'logdebug':
					logging.info('readsocket - force debug mode')
					log = logging.getLogger()
					for hdlr in log.handlers[:]:
						log.removeHandler(hdlr)
					jeedom_utils.set_log_level('debug')
				elif message['cmd'] == 'lognormal':
					logging.info('readsocket - back to current log level')
					log = logging.getLogger()
					for hdlr in log.handlers[:]:
						log.removeHandler(hdlr)
					jeedom_utils.set_log_level(globals.log_level)
				elif message['cmd'] == 'halt':
					logging.info('halt daemon on request')
					globals.JEEDOM_COM.send_change_immediate({'learn_mode' : 0,'source' : globals.daemonname});
					time.sleep(2)
					shutdown()
				elif message['cmd'] == 'ready':
					logging.debug('Daemon is ready')
					globals.READY = True
		except Exception as e:
			logging.error("SOCKET-READ------Exception on socket : %s" % str(e))
		time.sleep(0.3)

def handler(signum=None, frame=None):
	logging.debug("GLOBAL------Signal %i caught, exiting..." % int(signum))
	shutdown()

def shutdown():
	logging.debug("GLOBAL------Shutdown")
	logging.debug("GLOBAL------Removing PID file " + str(globals.pidfile))
	sys.stdout.flush()
	os._exit(0)
 
_log_level = "debug"
#_log_level = globals.log_level
_socket_port = 55444 # à modifier
_socket_host = 'localhost'
_cycle = 5
_pidfile = '/tmp/demond.pid'
# apikey and callback are sent from php as parameters
_apikey = ''
_callback = ''

for arg in sys.argv:
    if arg.startswith("--loglevel="):
        temp, _log_level = arg.split("=")
    elif arg.startswith("--socketport="):
        temp, _socket_port = arg.split("=")
    elif arg.startswith("--sockethost="):
        temp, _socket_host = arg.split("=")
    elif arg.startswith("--pidfile="):
        temp, _pidfile = arg.split("=")
    elif arg.startswith("--apikey="):
        temp, _apikey = arg.split("=")
    elif arg.startswith("--callback="):
        temp, _callback = arg.split("=")

_socket_port = int(_socket_port)
jeedom_utils.set_log_level(_log_level)

logging.info('Start demond')
logging.info('Log level : '+str(_log_level))
logging.info('Socket port : '+str(_socket_port))
logging.info('Socket host : '+str(_socket_host))
logging.info('PID file : '+str(_pidfile))
logging.info('Apikey : '+str(_apikey))

signal.signal(signal.SIGINT, handler)
signal.signal(signal.SIGTERM, handler)

try:
    jeedom_utils.write_pid(str(_pidfile))
    jeedom_com = jeedom_com(apikey = _apikey,url = _callback,cycle=_cycle)
    if not jeedom_com.test():
        logging.error('Network communication issues. Please fixe your Jeedom network configuration.')
        shutdown()
    jeedom_socket = jeedom_socket(port=_socket_port,address=_socket_host)
    listen()
except Exception as e:
    logging.error('Fatal error : '+str(e))
    shutdown()