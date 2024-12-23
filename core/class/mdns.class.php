<?php

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

/**
 * mDNS class allows to search for broadcasts of advertizing 
 * it is used to search for la Marzocco local IP address advertizing
 */
class mDNS {
	
	private $mdnssocket; // Socket to listen to port 5353
  // type of records to be queried
  // A = 1;
	// PTR = 12;
	// SRV = 33;
	// TXT = 16;
        
    // query cache for the last query packet sent
     private $querycache = "";
	
	 public function __destruct() {
		if ($this->mdnssocket != null)
			socket_close($this->mdnssocket);
	 }
	public function __construct() {
		// Create $mdnssocket, bind to 5353 and join multicast group 224.0.0.251
		$this->mdnssocket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if ($this->mdnssocket != null) {
			if (PHP_OS === "Darwin" || PHP_OS === "FreeBSD") {
				socket_set_option($this->mdnssocket, SOL_SOCKET, SO_REUSEPORT, 1);
			} else {
				socket_set_option($this->mdnssocket,SOL_SOCKET,SO_REUSEADDR, 1);
			}
			//socket_set_option($this->mdnssocket, SOL_SOCKET, SO_BROADCAST, 1);
			socket_set_option($this->mdnssocket, IPPROTO_IP, MCAST_JOIN_GROUP, array('group'=>'224.0.0.251', 'interface'=>0));
			socket_set_option($this->mdnssocket, SOL_SOCKET,SO_RCVTIMEO,array("sec"=>1,"usec"=>0));
			if (!socket_bind($this->mdnssocket, "0.0.0.0", 5353)) {
        log::add('jee4lm', 'debug', 'create socket failed ');
				$this->mdnssocket = null;	
      }
		} else
    log::add('jee4lm', 'debug', 'create socket failed ');
	}
	
  public function query($_name, $_qclass, $_qtype, $_data = '') {
    log::add('jee4lm', 'debug', 'query start');
    if ($this->mdnssocket == null) {
      log::add('jee4lm', 'debug', 'cannot query as socket is null');
      return;
    }
    // Sends a query
    $p = new DNSPacket();
    $p->packetheader->setTransactionID(rand(1, 32767));
    $p->packetheader->setQuestions(1);
    $p->questions[] = new DNSQuestion($_name, $_qtype, $_qclass);
    $b = $p->makePacket();
    // Send the packet
    $data = $_data . implode('', array_map('chr', $b));
    log::add('jee4lm', 'debug', 'data=' . $data);
    $this->querycache = $data;
    return socket_sendto($this->mdnssocket, $data, strlen($data), 0, '224.0.0.251', 5353);
  }
        
 public function requery() {
		if ($this->mdnssocket ==null) return;
     // resend the last query
      return socket_sendto($this->mdnssocket, $this->querycache, strlen($this->querycache), 0, '224.0.0.251',5353);
  }
	
  public function readIncoming() {
    if ($this->mdnssocket === null) {
      log::add('jee4lm', 'debug', 'cannot read as socket is null');
      return null;
    }

    $response = '';
    try {
      $response = @socket_read($this->mdnssocket, 1024, PHP_BINARY_READ);
      if ($response === false) {
        throw new Exception(socket_strerror(socket_last_error($this->mdnssocket)));
      }
    } catch (Exception $e) {
      log::add('jee4lm', 'debug', 'cannot read socket: ' . $e->getMessage());
      return null;
    }

    if (strlen($response) < 1) {
      log::add('jee4lm', 'debug', 'empty answer');
      return null;
    }

    $bytes = array_map('ord', str_split($response));
    return new DNSPacket($bytes);
  }
  
  public function load($_data) {
    return new DNSPacket($_data);
  }
	
}
/**
 * Summary of DNSPacket
 */
class DNSPacket {
  // Represents and processes a DNS packet
  public $packetheader; // DNSPacketHeader
  public $questions; // array
  public $answerrrs; // array
  public $authorityrrs; // array
  public $additionalrrs; // array
  public $offset = 0;

  public function __construct($_data = null) {
    log::add('jee4lm', 'debug', 'build dns packet with= ' . json_encode($_data));
    $this->clear();
    if ($_data != null) 
      $this->load($_data);
  }

  /**
   * Summary of clear
   * @return void
   */
  private function clear() {
    $this->packetheader = new DNSPacketHeader();
    $this->questions = [];
    $this->answerrrs = [];
    $this->authorityrrs = [];
    $this->additionalrrs = [];
  }

  /**
   * Summary of load
   * @param mixed $_data
   * @return void
   */
  private function load($_data) {
    // $data is an array of integers representing the bytes.
    // Load the data into the DNSPacket object.

    // Read the first 12 bytes and load into the packet header
    $headerbytes = array_slice($_data, 0, 12);
    $this->packetheader->load($headerbytes);
    $this->offset = 12;

    $this->readSections($_data, $this->packetheader->getQuestions(), $this->questions, 'readQuestion');
    $this->readSections($_data, $this->packetheader->getAnswerRRs(), $this->answerrrs, 'readRR');
    $this->readSections($_data, $this->packetheader->getAuthorityRRs(), $this->authorityrrs, 'readRR');
    $this->readSections($_data, $this->packetheader->getAdditionalRRs(), $this->additionalrrs, 'readRR');
  }

  /**
   * Summary of readSections
   * @param mixed $_data
   * @param int $count
   * @param mixed $section
   * @param mixed $method
   * @return void
   */
  private function readSections($_data, $count, &$section, $method) {
    for ($i = 0; $i < $count; $i++) {
      $section[] = $this->$method($_data);
    }
  }

  /**
   * Summary of readQuestion
   * @param mixed $_data
   * @return DNSQuestion
   */
  private function readQuestion($_data) {
    $name = $this->readName($_data);
    $qtype = ($_data[$this->offset] << 8) + $_data[$this->offset + 1];
    $qclass = ($_data[$this->offset + 2] << 8) + $_data[$this->offset + 3];
    $this->offset += 4;
    return new DNSQuestion($name, $qtype, $qclass);
  }

  /**
   * Summary of readRR
   * @param mixed $_data
   * @return DNSResourceRecord
   */
  public function readRR($_data) {
    $name = $this->readName($_data);
    $qtype = ($_data[$this->offset] << 8) + $_data[$this->offset + 1];
    $qclass = ($_data[$this->offset + 2] << 8) + $_data[$this->offset + 3];
    $this->offset += 4;
    $ttl = ($_data[$this->offset] << 24) + ($_data[$this->offset + 1] << 16) + ($_data[$this->offset + 2] << 8) + $_data[$this->offset + 3];
    $this->offset += 4;
    $dl = ($_data[$this->offset] << 8) + $_data[$this->offset + 1];
    $this->offset += 2;
    $ddata = array_slice($_data, $this->offset, $dl);
    $this->offset += $dl;
    if ($qtype == 12) { // qtype=12 -> PTR
        $ddata = $this->readName($_data, $this->offset - $dl);
    }
    return new DNSResourceRecord($name, $qtype, $qclass, $ttl, $ddata);
  }

  /**
   * Summary of readName
   * @param array $_data
   * @param int $startOffset
   * @return string
   */
  private function readName($_data, $startOffset = null) {
    if ($startOffset !== null) {
      $this->offset = $startOffset;
    }
    $name = "";
    $resetOffsetTo = 0;
    $firstReset = 0;

    while ($_data[$this->offset] != 0) {
      $size = $_data[$this->offset];

    if (($size & 0b11000000) == 0b11000000) {
      if ($firstReset == 0 && $resetOffsetTo != 0) {
        $firstReset = $resetOffsetTo;
      }
      $resetOffsetTo = $this->offset;
      $this->offset = ($_data[$this->offset + 1]) + (($size & 0b00111111) << 8);
      } else {
        $name .= substr(implode('', array_map('chr', array_slice($_data, $this->offset + 1, $size))), 0, $size) . '.';
        $this->offset += $size + 1;
      }
    }

    if ($firstReset != 0) {
      $resetOffsetTo = $firstReset;
    }
    if ($resetOffsetTo != 0) {
      $this->offset = $resetOffsetTo + 1;
    }

    $this->offset++;
    return rtrim($name, '.');
  }

  /**
   * Summary of makePacket
   * @return int[]
   */
  public function makePacket() {
    // For the current DNS packet produce an array of bytes to send.
    // Should make this support unicode, but currently it doesn't :(
    $bytes = array_merge($this->packetheader->getBytes(), $this->encodeQuestions());
    return $bytes;
  }

  /**
   * Summary of encodeQuestions
   * @return int[]
   */
  private function encodeQuestions() {
    $bytes = [];
    foreach ($this->questions as $question) {
      if (is_object($question)) {
        $undotted = $this->encodeName( $question->name);
        $bytes = array_merge($bytes, $undotted, $this->encodeTypeClass($question->qtype, $question->qclass));
      }
    }
    return $bytes;
  }

  /**
   * Summary of encodeName
   * @param string $name
   * @return int[]
   */
  private function encodeName($name) {
    $undotted = "";
    while (strpos($name, ".") > 0) {
      $undotted .= chr(strpos($name, ".")) . substr($name, 0, strpos($name, "."));
      $name = substr($name, strpos($name, ".") + 1);
    }
    $undotted .= chr(strlen($name)) . $name . chr(0);
    return array_map('ord', str_split($undotted));
  }

  /**
   * Summary of encodeTypeClass
   * @param int $qtype
   * @param int $qclass
   * @return int[]
   */
  private function encodeTypeClass($qtype, $qclass) {
    return [
      (int)($qtype / 256), $qtype % 256,
      (int)($qclass / 256), $qclass % 256
    ];
  }
}

class DNSPacketHeader {
	// Represents the 12 byte packet header of a DNS request or response
	private $contents; // Byte() - in reality use an array of integers here

  public function __construct() {
    $this->clear();
  }

	private function clear() {
		$this->contents = [0,0,0,0,0,0,0,0,0,0,0,0];
	}
	
	public function load($_data) {
		// Assume we're passed an array of bytes
		$this->clear();
		$this->contents = $_data;
	}

  /**
   * Summary of getBytes
   * @return array 
   */
	public function getBytes() {
		return $this->contents;
	}
		
	public function getTransactionID() {
        return ($this->contents[0] << 8) + $this->contents[1];
	}
	
	public function setTransactionID($_value) {
		$this->contents[0] = (int)($_value / 256);
		$this->contents[1] = $_value % 256;
	}
	
    private function getBitValue($byteIndex, $bitMask, $shiftRight) {
        return ($this->contents[$byteIndex] & $bitMask) >> $shiftRight;
    }

    private function setBitValue($byteIndex, $bitMask, $shiftLeft, $_value) {
        $this->contents[$byteIndex] = ($this->contents[$byteIndex] & ~$bitMask) | ($_value << $shiftLeft);
    }

    public function getMessageType() {
        return $this->getBitValue(2, 0b10000000, 7);
    }
    
    public function setMessageType($_value) {
        $this->setBitValue(2, 0b10000000, 7, $_value);
    }
    
    // As far as I know the opcode is always zero. But code it anyway (just in case)
    public function getOpCode() {
        return $this->getBitValue(2, 0b11111000, 3);
    }
    
    public function setOpCode($_value) {
        $this->setBitValue(2, 0b11111000, 3, $_value);
    }
    
    public function getAuthorative() {
        return $this->getBitValue(2, 0b00000100, 2);
    }
    
    public function setAuthorative($_value) {
        $this->setBitValue(2, 0b00000100, 2, $_value);
    }
    
    // We always want truncated to be 0 as this class doesn't support multi packet.
    // But handle the value anyway
    public function getTruncated() {
        return $this->getBitValue(2, 0b00000010, 1);
    }
    
    public function setTruncated($_value) {
        $this->setBitValue(2, 0b00000010, 1, $_value);
    }
	
	// We return this but we don't handle it!
	public function getRecursionDesired() {
		return ($this->contents[2] & 0b00000001);
	}
	
	public function setRecursionDesired($_value) { 
    $this->contents[2] = ($this->contents[2] & 0b10000000 ) | $_value;
	}
	
	// We also return this but we don't handle it
	public function getRecursionAvailable() {
        return $this->getBitValue(3, 0b10000000, 7);
	}
	
    public function setRecursionAvailable($_value) {
        $this->setBitValue(3, 0b01111111, 7, $_value);
    }
	
    public function getReserved() {
        return $this->getBitValue(3, 0b01000000, 6);
    }
    
    public function setReserved($_value) {
        $this->setBitValue(3, 0b01000000, 6, $_value);
    }
	// This always seems to be 0, but handle anyway
	public function getAnswerAuthenticated() {
		return ($this->contents[3] & 32) / 32;
	}
	
    public function setAnswerAuthenticated($_value) {
    $this->setBitValue(3, 0b00100000, 5, $_value);
    }
    
    // This always seems to be 0, but handle anyway
    public function getNonAuthenticatedData() {
    return $this->getBitValue(3, 0b00010000, 4);
    }
    
    public function setNonAuthenticatedData($_value) {
    $this->setBitValue(3, 0b00010000, 4, $_value);
    }
    
    // We want this to be zero
    // 0 : No error condition
    // 1 : Format error - The name server was unable to interpret the query.
    // 2 : Server failure - The name server was unable to process this query due to a problem with the name server.
    // 3 : Name Error - Meaningful only for responses from an authoritative name server, this code signifies that the domain name referenced in the query does not exist.
    // 4 : Not Implemented - The name server does not support the requested kind of query.
    // 5 : Refused - The name server refuses to perform the specified operation for policy reasons. You should set this field to 0, and should assert an error if you receive a response indicating an error condition. You should treat 3 differently, as this represents the case where a requested name doesn’t exist.
    public function getReplyCode() {
    return $this->getBitValue(3, 0b00001111, 0);
    }
    
    public function setReplyCode($_value) {
    $this->setBitValue(3, 0b00001111, 0, $_value);
    }
	
	// The number of Questions in the packet
	public function getQuestions() {
		return ($this->contents[4] * 256) + $this->contents[5];
	}
	
	public function setQuestions($_value) {
		$this->contents[4] = (int)($_value / 256);
		$this->contents[5] = $_value % 256;
	}
	
	// The number of AnswerRRs in the packet
	public function getAnswerRRs() {
   // log::add('jee4lm', 'debug', 'answer rr='.$this->contents[6] * 256 + $this->contents[7]);
		return ($this->contents[6] * 256) + $this->contents[7];
	}
	
	public function setAnswerRRs($_value) {
		$this->contents[6] = (int)($_value / 256);
		$this->contents[7] = $_value % 256;
	}
	
	// The number of AuthorityRRs in the packet
	public function getAuthorityRRs() {
		return ($this->contents[8] * 256) + $this->contents[9];
	}
	
	public function setAuthorityRRs($_value) {
		$this->contents[8] = (int)($_value / 256);
		$this->contents[9] = $_value % 256;
	}
	
	// The number of AdditionalRRs in the packet
	public function getAdditionalRRs() {
		return ($this->contents[10] * 256) + $this->contents[11];
	}
	
	public function setAdditionalRRs($_value) {
		$this->contents[10] = (int)($_value / 256);
		$this->contents[11] = $_value % 256;
	}
}
class DNSQuestion {
	public $name; // String
	public $qtype; // UInt16
	public $qclass; // UInt16

  /**
   * Summary of __construct
   * @param mixed $_name
   * @param integer $_qtype
   * @param integer $_qclass
   */
  public function __construct($_name='', $_qtype=0, $_qclass=0) {
    $this->name=$_name;
    $this->qtype=$_qtype;
    $this->qclass=$_qclass;
  }
}
class DNSResourceRecord
{
  public $name; // String
  public $qtype; // UInt16
  public $qclass; // UInt16
  public $ttl; // UInt32
  public $data; // Byte ()

  public function __construct($_name='', $_qtype=0, $_qclass=0, $_ttl=0, $_data=null) {
    $this->name = $_name;
    $this->qtype=  $_qtype;
    $this->qclass= $_qclass;
    $this->ttl=$_ttl;
    $this->data=$_data;
  }
}