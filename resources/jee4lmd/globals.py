import time
DAEMON_VERSION = '1.0'
JEEDOM_COM = ''
SCAN_INTERVAL = 5
START_TIME = int(time.time())
SEEN_DEVICES = {}
READY = False
PRESENT={}
PENDING_ACTION = False
PENDING_TIME = int(time.time())
log_level = "error"
pidfile = '/tmp/jee4lmd.pid'
apikey = ''
callback = ''
cycle = 0.3
daemonname=''
socketport=''
sockethost=''
