<?php
/**
 * A PHP class to use Sharepoint web interfaces (SOAP etc)
 * 
 * NOTE This class hasn't been properly tested and was originally meant for personal use only. If you find any bugs
 * or missing features, please fix those issues and send me the patches.
 *
 * @link http://msdn.microsoft.com/en-us/library/dd878586%28v=office.12%29.aspx
 * @link http://your.sharepoint.site/_vti_bin/Lists.asmx
 * 
 * @note SoapClient/cURL seem to have some issues with NTLM authentication. This class tries to circumvent those.
 * 
 * @author Tuomas Angervuori <tuomas.angervuori@gmail.com>
 */

class Sharepoint {
	
	const CHECKIN_MINOR = 0;
	const CHECKIN_MAJOR = 1;
	const CHECKIN_OVERWRITE = 2;
	
	protected $url;
	protected $user;
	protected $pass;
	
	protected $conn; //connection object to the defined sharepoint site
	protected $soapClients = array();
	protected $tmpWsdlFiles = array();
	
	public $tmpDir = '/tmp';
	public $debug = false; //Print debug informatio to console
	
	/**
	 * @param $url Base url for sharepoint site (eg. http://sharepoint.site.invalid/sites/Project Site/)
	 * @param $user Username for the site 
	 * @param $pass Password for the site
	 */
	public function __construct($url, $user = null, $pass = null) {
		$this->url = $url;
		$this->user = $user;
		$this->pass = $pass;
	}
	
	/**
	 * Clean up created tmp files
	 */
	public function __destruct() {
		foreach($this->tmpWsdlFiles as $file) {
			unlink($file);
		}
	}
	
	/**
	 * @returns SharepointConnection object that can handle NTLM authentication used in Sharepoint
	 */
	public function getConnection() {
		if(!$this->conn) {
			$urlParts = parse_url($this->url);
			if(!isset($urlParts['scheme']) || strtolower($urlParts['scheme']) == 'http') {
				$port = 80;
			}
			else if(strtolower($urlParts['scheme']) == 'https') {
				$port = 443;
			}
			else {
				throw new SharepointException("Unknown protocol '{$urlParts['scheme']}'");
			}
			$this->conn = new SharepointConnection($urlParts['host'], $this->user, $this->pass, $port);
		}
		$this->conn->debug = $this->debug;
		return $this->conn;
	}
	
	/**
	 * Returns the WSDL definition for the requested section
	 * 
	 * @param $section The section 
	 * @returns string WSDL xml 
	 * @link http://msdn.microsoft.com/en-us/library/dd878586%28v=office.12%29.aspx
	 */
	public function getWsdl($section = 'Lists') {
		$conn = $this->getConnection();
		$item = self::_getPath($this->url . '/_vti_bin/' . $section . '.asmx?WSDL');
		$response = $conn->get($item);
		return $response['body'];
	}
	
	/**
	 * @param $section The section
	 * @returns SharepointSoapClient SoapClient that communicates with Sharepoint
	 */
	public function getSoapClient($section = 'Lists') {
		if(!isset($this->soapClients[$section])) {
			if($this->user) {
				$settings['login'] = $this->user;
			}
			if($this->pass) {
				$settings['password'] = $this->pass;
			}
			//Load WSDL into tmp file, SoapClient doesn't handle NTLM auth
			if(!isset($this->tmpWsdlFiles[$section])) {
				$this->tmpWsdlFiles[$section] = tempnam($this->tmpDir,'ShareSoap_' . $section . '_');
				file_put_contents($this->tmpWsdlFiles[$section], $this->getWsdl($section));
			}
			$this->soapClients[$section] = new SharepointSoapClient($this->getConnection(), $this->tmpWsdlFiles[$section]);
		}
		return $this->soapClients[$section];
	}
	
	/**
	 * Returns the names and GUIDs for all lists in the site
	 * 
	 * @returns array Names and GUIDs for all the lists in the site
	 * 
	 * @link http://msdn.microsoft.com/en-us/library/lists.lists.getlistcollection%28v=office.12%29.aspx
	 */
	public function getListCollection() {
		$soap = $this->getSoapClient('Lists');
		$xml = $soap->GetListCollection()->GetListCollectionResult->any;
		$dom = new \DOMDocument();
		$dom->loadXML($xml);
		$result = array();
		foreach($dom->getElementsByTagName('Lists') as $lists) {
			foreach($lists->getElementsByTagName('List') as $list) {
				$id = $list->getAttribute('ID');
				$result[$id] = array();
				foreach($list->attributes as $attr) {
					$result[$id][$attr->name] = $attr->value;
				}
			}
		}
		return $result;
	}
	
	/**
	 * Returns a schema for the specified list
	 * 
	 * @param $list Name or GUID of the list
	 * @returns array Information from the list
	 * 
	 * @note Some re-thinking might be needed for this method...
	 * @link http://msdn.microsoft.com/en-us/library/lists.lists.getlist%28v=office.12%29.aspx
	 */
	public function getList($list) {
		$options = array(
			'listName' => $list
		);
		$soap = $this->getSoapClient('Lists');
		$xml = $soap->GetList($options)->GetListResult->any;
		$dom = new \DOMDocument();
		$dom->loadXML($xml);
		$result = array();
		foreach($dom->getElementsByTagName('List') as $list) {
			$result['Meta'] = array();
			foreach($list->attributes as $attr) {
				$result['Meta'][$attr->name] = $attr->value;
			}
			foreach($list->getElementsByTagName('Fields') as $fields) {
				$result['Fields'] = array();
				foreach($fields->getElementsByTagName('Field') as $field) {
					$id = $field->getAttribute('ID');
					$result['Fields'][$id] = array();
					foreach($field->attributes as $attr) {
						$result['Fields'][$id][$attr->name] = $attr->value;
						if($field->childNodes) {
							///FIXME ChildXML ei toimi
							$result['Fields'][$id]['ChildXML'] = array();
							foreach($field->childNodes as $node) {
								$result['Fields'][$id]['ChildXML'][$node->nodeName] = $node->nodeValue;
							}
						}
					}
				}
			}
			foreach($list->getElementsByTagName('RegionalSettings') as $regionalSettings) {
				$result['RegionalSettings'] = array();
				foreach($regionalSettings->childNodes as $node) {
					$result['RegionalSettings'][$node->nodeName] = $node->nodeValue;
				}
			}
			foreach($list->getElementsByTagName('ServerSettings') as $serverSettings) {
				$result['ServerSettings'] = array();
				foreach($serverSettings->childNodes as $node) {
					$result['ServerSettings'][$node->nodeName] = $node->nodeValue;
				}
			}
		}
		return $result;
	}
	
	/**
	 * Returns information about items in the list based on the specified query. 
	 * 
	 * @param $list List GUID or name (eq. {A279BA14-E7F0-4B3E-A0DE-0CA3AA534B85})
	 * @param $view Name of the view, null = default
	 * @param $options Other options (rowLimit, viewFields, queryOptions, WebID)
	 * @returns array Library contents
	 *
	 * @link http://msdn.microsoft.com/en-us/library/lists.lists.getlistitems%28v=office.12%29.aspx
	 * @bug Paging not supported (see ListItemCollectionPositionNext)
	 */
	public function getListItems($list, $view = null, array $options = null) {
		$soap = $this->getSoapClient('Lists');
		if(!$options) {
			$options = array();
		}
		$options['listName'] = $list;
		if($view) {
			$options['viewName'] = $view;
		}
		$xml = $soap->GetListItems($options)->GetListItemsResult->any;
		$dom = new \DOMDocument();
		$dom->loadXML($xml);
		$result = array();
		foreach($dom->getElementsByTagName('listitems') as $listItems) {
			foreach($listItems->getElementsByTagNameNS('urn:schemas-microsoft-com:rowset','data') as $data) {
				foreach($data->getElementsByTagNameNS('#RowsetSchema','row') as $row) {
					$id = $row->getAttribute('ows_ID');
					$result[$id] = array();
					foreach($row->attributes as $attr) {
						$name = $attr->name;
						if(substr($name,0,5) == 'ows__') {
							$name = substr($name,5);
						}
						else if(substr($name,0,4) == 'ows_') {
							$name = substr($name,4);
						}
						$result[$id][$name] = $attr->value;
					}
				}
			}
		}
		return $result;
	}
	
	/**
	 * Checks out a file
	 * 
	 * @param $file URL to the file (eg. http://your.sharepoint.site/sites/Test Site/Shared Documents/Sample File.txt)
	 * @param $toLocal Is the file checked out for offline editing (default = true)
	 * @param $timestamp string or DateTime object for last modifying time for the file
	 * @returns bool Was the checkout successful
	 * 
	 * @link http://msdn.microsoft.com/en-us/library/lists.lists.checkoutfile%28v=office.12%29.aspx
	 */
	public function checkOutFile($file, $toLocal = true,  $timestamp = null) {
		$options = array(
			'pageUrl' => $file
		);
		if($toLocal) {
			$options['toLocal'] = 'True';
		}
		else {
			$options['toLocal'] = 'False';
		}
		if($timestamp) {
			if(!($timestamp instanceof \DateTime)) {
				$timestamp = new \DateTime($timestamp);
			}
			$options['lastmodified'] = $timestamp->format('d M Y H:i:s e');
		}
		$soap = $this->getSoapClient('Lists');
		return $soap->CheckOutFile($options)->CheckOutFileResult;
	}
	
	/**
	 * Checks in a file
	 * 
	 * @param $file URL to the file (eg. http://your.sharepoint.site/sites/Test Site/Shared Documents/Sample File.txt)
	 * @param $comment Comment for check in
	 * @param $type Check in type (CHECKIN_MINOR, CHECKIN_MAJOR (default), CHECKIN_OVERWRITE)
	 * @returns bool Was the check in successful
	 * 
	 * @link http://msdn.microsoft.com/en-us/library/lists.lists.checkinfile%28v=office.12%29.aspx
	 */
	public function checkInFile($file, $comment = null, $type = 1) {
		$options = array(
			'pageUrl' => $file,
			'CheckinType' => $type
		);
		if($comment) {
			$options['comment'] = $comment;
		}
		$soap = $this->getSoapClient('Lists');
		return $soap->CheckInFile($options)->CheckInFileResult;
	}
	
	/**
	 * Undo check out
	 * 
	 * @param $file URL to the file (eg. http://your.sharepoint.site/sites/Test Site/Shared Documents/Sample File.txt)
	 * @returns bool Was the undo check out successful
	 * 
	 * @link http://msdn.microsoft.com/en-us/library/lists.lists.undocheckout%28v=office.12%29.aspx
	 */
	public function undoCheckOut($file) {
		$options = array(
			'pageUrl' => $file
		);
		$soap = $this->getSoapClient('Lists');
		return $soap->UndoCheckOut($options)->UndoCheckOutResult;
	}
	
	/**
	 * Downloads a file
	 * 
	 * @param $file URL to the file (eg. http://your.sharepoint.site/sites/Test Site/Shared Documents/Sample File.txt)
	 * @returns data Contents of the file
	 */
	public function getFile($file) {
		$conn = $this->getConnection();
		$result = $conn->get($file);
		return $result['body'];
	}
	
	/**
	 * Gets file information
	 * 
	 * @param $file URL to the file (eg. http://your.sharepoint.site/sites/Test Site/Shared Documents/Sample File.txt)
	 * @returns array File information
	 */
	public function getFileInfo($file) {
		$conn = $this->getConnection();
		$result = $conn->head($file);
		return $result['headers'];
	}
	
	/**
	 * @todo file uploads
	 */
	/* 
	public function addAttachment($listId, $listItemId, $filename, $data) { 
		$options = array(
			'listName' => $listId,
			'listItemId' => $listItemId,
			'fileName' => $filename,
			'attachment' => base64_encode($data)
		);
		$soap = $this->getSoapClient('Lists');
		var_dump($soap->AddAttachment($options));
		exit;
	}
	*/
	
	
	/**
	 * Returns information about the collection of groups for the current site collection
	 * 
	 * @returns array List of groups
	 * 
	 * @link http://msdn.microsoft.com/en-us/library/ms774594%28v=office.12%29.aspx
	 */
	public function getGroupCollectionFromSite() {
		$soap = $this->getSoapClient('Usergroup');
		$soap->GetGroupCollectionFromSite(); //->GetGroupCollectionFromSiteResult->any['GetGroupCollectionFromSite'];
		$result = array();
		
		///FIXME for some strange reason I couldn't parse the response with SoapClient...
		$xml = $soap->__getLastResponse();
		$dom = new \DOMDocument();
		$dom->loadXML($xml);
		foreach($dom->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope','Envelope') as $envelope) {
			foreach($envelope->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope','Body') as $body) {
				foreach($body->getElementsByTagName('GetGroupCollectionFromSiteResponse') as $response) {
					foreach($response->getElementsByTagName('GetGroupCollectionFromSiteResult') as $siteResult) {
						foreach($siteResult->getElementsByTagName('GetGroupCollectionFromSite') as $collection) {
							foreach($collection->getElementsByTagName('Groups') as $groups) {
								foreach($groups->getElementsByTagName('Group') as $group) {
									$id = $group->getAttribute('ID');
									$result[$id] = array();
									foreach($group->attributes as $attr) {
										$result[$id][$attr->name] = $attr->value;
									}
								}
							}
						}
					}
				}
			}
		}
		return $result;
	}
	
	/**
	 * Returns information about the collection of users in the specified group
	 * 
	 * @param $group Group name
	 * @returns array List of users
	 * 
	 * @link http://msdn.microsoft.com/en-us/library/ms772554%28v=office.12%29.aspx
	 */
	public function getUserCollectionFromGroup($group) {
		$options = array(
			'groupName' => $group
		);
		$soap = $this->getSoapClient('Usergroup');
		$xml = $soap->GetUserCollectionFromGroup($options); //->GetUserCollectionFromGroupResult->any['GetUserCollectionFromGroup'];
			///FIXME for some strange reason I couldn't parse the response with SoapClient...
		$xml = $soap->__getLastResponse();
		$dom = new \DOMDocument();
		$dom->loadXML($xml);
		foreach($dom->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope','Envelope') as $envelope) {
			foreach($envelope->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope','Body') as $body) {
				foreach($body->getElementsByTagName('GetUserCollectionFromGroupResponse') as $response) {
					foreach($response->getElementsByTagName('GetUserCollectionFromGroupResult') as $siteResult) {
						foreach($siteResult->getElementsByTagName('GetUserCollectionFromGroup') as $collection) {
							foreach($collection->getElementsByTagName('Users') as $users) {
								foreach($users->getElementsByTagName('User') as $user) {
									$id = $user->getAttribute('ID');
									$result[$id] = array();
									foreach($user->attributes as $attr) {
										$result[$id][$attr->name] = $attr->value;
									}
								}
							}
						}
					}
				}
			}
		}
		return $result;
	}
	
	/**
	 * Returns information about the specified user
	 * 
	 * @param $login User login (eg. DOMAIN\login)
	 * @returns array User info
	 * 
	 * @link http://msdn.microsoft.com/en-us/library/ms774637%28v=office.12%29.aspx
	 */
	public function getUserInfo($login) {
		$options = array(
			'userLoginName' => $login
		);
		$soap = $this->getSoapClient('Usergroup');
		$soap->GetUserInfo($options); //->GetUserInfoResult->any['GetUserInfo'];
		$result = array();
		
		///FIXME for some strange reason I couldn't parse the response with SoapClient...
		$xml = $soap->__getLastResponse();
		$dom = new \DOMDocument();
		$dom->loadXML($xml);
		foreach($dom->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope','Envelope') as $envelope) {
			foreach($envelope->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope','Body') as $body) {
				foreach($body->getElementsByTagName('GetUserInfoResponse') as $response) {
					foreach($response->getElementsByTagName('GetUserInfoResult') as $siteResult) {
						foreach($siteResult->getElementsByTagName('GetUserInfo') as $info) {
							foreach($info->getElementsByTagName('User') as $user) {
								foreach($user->attributes as $attr) {
									$result[$attr->name] = $attr->value;
								}
							}
						}
					}
				}
			}
		}
		return $result;
	}
	
	/**
	 * Returns the path component and parameters from the url
	 */
	protected static function _getPath($url) {
		$url = parse_url($url);
		$path = $url['path'];
		if(isset($url['query'])) {
			$path .= '?' . $url['query'];
		}
		$path = str_replace('//','/',$path);
		return $path;
	}
}

/**
 * SoapClient object tuned to work with NTLM authentication
 */
class SharepointSoapClient extends \SoapClient {
	
	protected $conn;
	
	public function __construct(SharepointConnection $conn, $wsdl, array $options = array()) {
		$this->conn = $conn;
		$settings = array(
			'soap_version' => \SOAP_1_2,
			'exceptions' => true,
			'trace' => 1
		);
		foreach($options as $key => $value) {
			$settings[$key] = $value;
		}
		parent::__construct($wsdl, $settings);
	}
	
	public function __doRequest($request, $location, $action, $version) {
		$url = parse_url($location);
		$item = $url['path'];
		if(isset($url['query'])) {
			$item .= '?' . $url['query'];
		}
		if($version == \SOAP_1_2) {
			$headers = array(
				'Content-Type' => 'application/soap+xml; charset=utf-8'
			);
		}
		else {
			$headers = array(
				'Content-Type' => 'text/xml; charset=utf-8',
				'SOAPAction' => '"' . $action . '"'
			);
		}
		
		$this->__last_request_headers = array();
		foreach($headers as $key => $value) {
			$this->__last_request_headers[] = $key . ': ' . $value;
		}
		
		try {
			$result = $this->conn->post($item, $request, $headers);
		}
		catch(SharepointResponseException $e) {
			$dom = new \DOMDocument();
			$dom->loadXML($e->getMessage());
			$str = 'SOAP returned an error: ';
			foreach($dom->getElementsByTagName('errorstring') as $element) {
				$str .= $element->nodeValue;
			}
			throw new SharepointSoapException(trim($str), $e->getCode());
		}
		return $result['body'];
	}
}

/**
 * A class for HTTP connections to Sharepoint
 * 
 * For some reason Curl couldn't handle NTLM authentication...
 * 
 * Modified the code from http://forums.fedoraforum.org/showthread.php?t=230535
 */
class SharepointConnection {
	
	/**
	 * Copyless: DJ Maze http://dragonflycms.org/
	 *
	 * http://davenport.sourceforge.net/ntlm.html
	 * http://www.dereleased.com/2009/07/25/post-via-curl-under-ntlm-auth-learn-from-my-pain/
	 */
	
	//flags
	const FLAG_UNICODE        = 0x00000001; // Negotiate Unicode
	const FLAG_OEM            = 0x00000002; // Negotiate OEM
	const FLAG_REQ_TARGET     = 0x00000004; // Request Target
//		const FLAG_               = 0x00000008; // unknown
	const FLAG_SIGN           = 0x00000010; // Negotiate Sign
	const FLAG_SEAL           = 0x00000020; // Negotiate Seal
	const FLAG_DATAGRAM       = 0x00000040; // Negotiate Datagram Style
	const FLAG_LM_KEY         = 0x00000080; // Negotiate Lan Manager Key
	const FLAG_NETWARE        = 0x00000100; // Negotiate Netware
	const FLAG_NTLM           = 0x00000200; // Negotiate NTLM
//	const FLAG_               = 0x00000400; // unknown
	const FLAG_ANONYMOUS      = 0x00000800; // Negotiate Anonymous
	const FLAG_DOMAIN         = 0x00001000; // Negotiate Domain Supplied
	const FLAG_WORKSTATION    = 0x00002000; // Negotiate Workstation Supplied
	const FLAG_LOCAL_CALL     = 0x00004000; // Negotiate Local Call
	const FLAG_ALWAYS_SIGN    = 0x00008000; // Negotiate Always Sign
	const FLAG_TYPE_DOMAIN    = 0x00010000; // Target Type Domain
	const FLAG_TYPE_SERVER    = 0x00020000; // Target Type Server
	const FLAG_TYPE_SHARE     = 0x00040000; // Target Type Share
	const FLAG_NTLM2          = 0x00080000; // Negotiate NTLM2 Key
	const FLAG_REQ_INIT       = 0x00100000; // Request Init Response
	const FLAG_REQ_ACCEPT     = 0x00200000; // Request Accept Response
	const FLAG_REQ_NON_NT_KEY = 0x00400000; // Request Non-NT Session Key
	const FLAG_TARGET_INFO    = 0x00800000; // Negotiate Target Info
//	const FLAG_               = 0x01000000; // unknown
//	const FLAG_               = 0x02000000; // unknown
//	const FLAG_               = 0x04000000; // unknown
//	const FLAG_               = 0x08000000; // unknown
//	const FLAG_               = 0x10000000; // unknown
	const FLAG_128BIT         = 0x20000000; // Negotiate 128
	const FLAG_KEY_EXCHANGE   = 0x40000000; // Negotiate Key Exchange
	const FLAG_56BIT          = 0x80000000; // Negotiate 56
	
	protected $user;
	protected $password;
	protected $domain;
	protected $workstation;
	
	protected $host;
	protected $port;
	protected $socket;
	protected $msg1;
	protected $msg3;
	
	public $last_send_headers;
	
	public $debug = false;
	
	function __construct($host, $user, $password, $port = 80, $domain='', $workstation='') {
		
		if (!function_exists($function='mcrypt_encrypt')) {
			throw new SharepointException('NTLM Error: the required "mcrypt" extension is not available');
		}
		if($port == 443) {
			$socketHost = 'ssl://' . $host;
		}
		else {
			$socketHost = $host;
		}
		if (!$this->socket = fsockopen($socketHost, $port, $errno, $errstr, 30)) {
			throw new SharepointException("NTLM_HTTP failed to open. Error {$errno}: {$errstr}");
		}
		$userData = explode('@',$user);
		if(isset($userData[1])) {
			$domain = $userData[1];
		}
		
		$this->host = $host;
		$this->port = $port;
		$this->user = $userData[0];
		$this->password = $password;
		$this->domain = $domain;
		$this->workstation = $workstation;
	}
	
	function __destruct() {
		if ($this->socket) {
			fclose($this->socket);
			$this->socket = null;
		}
	}
	
	public function get($uri, array $headers = array()) {
		return $this->_request($uri, 'get', null, $headers);
	}
	
	public function post($uri, $data, array $headers = array()) {
		return $this->_request($uri, 'post', $data, $headers);
	}
	
	public function head($uri, array $headers = array()) {
		return $this->_request($uri, 'head', null, $headers);
	}
	
	protected function _request($uri, $method = null, $data = null, array $headers = array()) {
		
		if(!$method) {
			if($data) {
				$method = 'post';
			}
			else {
				$method = 'get';
			}
		}
		if(strtolower($method) == 'head') {
			$hasBody = false;
		}
		else {
			$hasBody = true;
		}
		
		$sendHeaders = $headers;
		if($this->msg3) {
			$sendHeaders['Authorization'] = 'NTLM ' . $this->msg3;
		}
		if($data) {
			$sendHeaders['Content-Length'] = strlen($data);
		}
		$this->_sendHeaders($uri, $sendHeaders, $method);
		
		if($data) {
			$this->_sendData($data);
		}
		
		$response = $this->_getResponse($hasBody);
		
		if (401 === $response['status']) {
			$this->msg3 = null;
			if(!$response['NTLM']) {
				$sendHeaders = $headers;
				// Send The Type 1 Message
				$sendHeaders['Authorization'] = 'NTLM ' . $this->TypeMsg1();
				if($data) {
					$sendHeaders['Content-Length'] = 0;
				}
				$this->_sendHeaders($uri, $sendHeaders, $method);
				$response = $this->_getResponse($hasBody);
				if(!$response['NTLM']) {
					throw new SharepointException('NTLM Authorization failed at step 1');
				}
			}
			if($response['NTLM']) {
				$sendHeaders = $headers;
				// Send The Type 3 Message
				$sendHeaders['Authorization'] = 'NTLM ' . $this->TypeMsg3($response['NTLM']);
				if($data) {
					$sendHeaders['Content-Length'] = strlen($data);
				}
				$this->_sendHeaders($uri, $sendHeaders, $method);
				if($data) {
					$this->_sendData($data);
				}
				
				$response = $this->_getResponse($hasBody);
			}
		}
		
		if ($response['status'] >= 400) {
			throw new SharepointResponseException($response['body'], $response['status']);
		}
		
		return $response;
	}
	
	protected function _getResponse($hasBody = true) {
		$response = array(
			'status' => null,
			'headers' => array(),
			'NTLM' => null,
			'body' => null
		);
		
		$isHead = true;
		$isHttpStatus = true;
		$contentLength = null;
		$contentLoaded = 0;
		$maxContent = 1024;
		
		while(!feof($this->socket)) {
			
			//HTTP response headers section
			if($isHead) {
				
				$line = fgets($this->socket, $maxContent);
				if($this->debug) {
					echo $line;
				}
				
				$line = trim($line);
				//First line contains the HTTP status code
				if($isHttpStatus) {
					$parts = explode(' ', $line);
					$response['status'] = (int)$parts[1];
					$isHttpStatus = false;
				}
				else {
					if($line == '') {
						$isHead = false;
					}
					else {
						list($name, $value) = explode(': ',$line,2);
						if(strtolower($name) == 'content-length') {
							$contentLength = (int)$value;
						}
						if(strtolower($name) == 'www-authenticate' && substr($value,0,4) == 'NTLM') {
							$response['NTLM'] = substr($value,5);
						}
						
						if(isset($response['headers'][$name])) {
							if(!is_array($response['headers'][$name])) {
								$response['headers'][$name] = array($response['headers'][$name]);
							}
							$response['headers'][$name][] = $value;
						}
						else {
							$response['headers'][$name] = $value;
						}
					}
				}
			}
			
			//Response body
			else {
				//No body in HTTP response
				if($contentLength == 0 || !$hasBody) {
					break;
				}
				
				if(is_null($response['body'])) {
					$response['body'] = '';
				}
				
				
				$loadLen = $maxContent;
				if($contentLength) {
					$loadLen = $contentLength - strlen($response['body']) + 1;
					if($loadLen > $maxContent) {
						$loadLen = $maxContent;
					}
				}
				
				$line = fgets($this->socket, $loadLen);
				if($this->debug) {
					echo $line;
				}
				
				$response['body'] .= $line;
				if($contentLength) {
					if($contentLength <= strlen($response['body'])) {
						break;
					}
				}
			}
		}
		return $response;
	}
	
	protected function _getHeaderString($uri, array $headers, $method = 'get') {
		$headerString = strtoupper($method) . ' ' . $uri . " HTTP/1.1\r\n";
		$headerString .= 'Host: ' . $this->host . "\r\n";
		
		if($headers) {
			foreach($headers as $key => $value) {
				if(is_array($value)) {
					foreach($value as $subValue) {
						$headerString .= $key . ': ' . $subValue . "\r\n";
					}
				}
				else {
					$headerString .= $key . ': ' . $value . "\r\n";
				}
			}
		}
		
		return trim($headerString);
	}
	
	protected function _sendHeaders($uri, array $headers, $method = 'get') {
		$headerString = $this->_getHeaderString($uri, $headers, $method);
		$this->last_send_headers = $headerString;
		if($this->debug) {
			echo $headerString . "\r\n\r\n";
		}
		return fwrite($this->socket, $headerString . "\r\n\r\n");
	}
	
	protected function _sendData($data) {
		if($this->debug) {
			echo $data;
		}
		return fwrite($this->socket, $data);
	}
	
	public function TypeMsg1() {
		if (!$this->msg1) {
			$flags = self::FLAG_UNICODE | self::FLAG_OEM | self::FLAG_REQ_TARGET | self::FLAG_NTLM;
//			self::FLAG_ALWAYS_SIGN | self::FLAG_NTLM2 | self::FLAG_128BIT | self::FLAG_56BIT;
			$offset = 32;
			$d_length = strlen($this->domain);
			$d_offset = $d_length ? $offset : 0;
			if ($d_length) {
				$offset += $d_length;
				$flags |= self::FLAG_DOMAIN;
			}
			
			$w_length = strlen($this->workstation);
			$w_offset = $w_length ? $offset : 0;
			if ($w_length) {
				$offset += $w_length;
				$flags |= self::FLAG_WORKSTATION;
			}
			
			$this->msg1 = base64_encode(
				"NTLMSSP\0".
				"\x01\x00\x00\x00". // Type 1 Indicator
				pack('V',$flags).
				pack('vvV',$d_length,$d_length,$d_offset).
				pack('vvV',$w_length,$w_length,$w_offset).
//				"\x00\x00\x00\x0f". // OS Version ???
				$this->workstation.
				$this->domain
			);
		}
		return $this->msg1;
	}
	
	protected function TypeMsg3($ntlm_response) {
		if (!$this->msg3) {
			//Handel the server Type 2 Message
			$msg2 = base64_decode($ntlm_response);
			$headers = unpack('a8ID/Vtype/vtarget_length/vtarget_space/Vtarget_offset/Vflags/a8challenge/a8context/vtargetinfo_length/vtargetinfo_space/Vtargetinfo_offset/cOS_major/cOS_minor/vOS_build', $msg2);
			if ($headers['ID'] != 'NTLMSSP') {
				throw new SharepointException('Incorrect NTLM Type 2 Message');
				return false;
			}
			$headers['challenge'] = substr($msg2,24,8);
//			$headers['challenge'] = str_pad($headers['challenge'],8,"\0");
			
			//Build Type 3 Message
			$flags  = self::FLAG_UNICODE | self::FLAG_NTLM | self::FLAG_REQ_TARGET;
			$offset = 64;
			$challenge = $headers['challenge'];
			
			$target = self::ToUnicode($this->domain);
			$target_length  = strlen($target);
			$target_offset  = $offset;
			$offset += $target_length;
			
			$user = self::ToUnicode($this->user);
			$user_length = strlen($user);
			$user_offset  = $user_length ? $offset : 0;
			$offset += $user_length;
			
			$workstation = self::ToUnicode($this->workstation);
			$workstation_length = strlen($workstation);
			$workstation_offset = $workstation_length ? $offset : 0;
			$offset += $workstation_length;
			
			$lm = ''; // self::DESencrypt(str_pad(self::LMhash($this->password),21,"\0"), $challenge);
			$lm_length = strlen($lm);
			$lm_offset = $lm_length ? $offset : 0;
			$offset += $lm_length;
			
			$password = self::ToUnicode($this->password);
//			$ntlm = self::DESencrypt(str_pad(mhash(MHASH_MD4,$password,true),21,"\0"), $challenge);
			$ntlm = self::DESencrypt(str_pad(hash('md4',$password,true),21,"\0"), $challenge);
			$ntlm_length = strlen($ntlm);
			$ntlm_offset = $ntlm_length ? $offset : 0;
			$offset += $ntlm_length;
			
			$session = '';
			$session_length = strlen($session);
			$session_offset = $session_length ? $offset : 0;
			$offset += $session_length;
			
			$this->msg3 = base64_encode(
				"NTLMSSP\0".
				"\x03\x00\x00\x00".
				pack('vvV',$lm_length,$lm_length,$lm_offset).
				pack('vvV',$ntlm_length,$ntlm_length,$ntlm_offset).
				pack('vvV',$target_length,$target_length,$target_offset).
				pack('vvV',$user_length,$user_length,$user_offset).
				pack('vvV',$workstation_length,$workstation_length,$workstation_offset).
				pack('vvV',$session_length,$session_length,$session_offset).
				pack('V',$flags).
				$target.
				$user.
				$workstation.
				$lm.
				$ntlm
			);
		}
		return $this->msg3;
	}
	
	protected static function LMhash($str) {
		$string = strtoupper(substr($str,0,14));
		return self::DESencrypt($str);
	}
	
	protected static function DESencrypt($str, $challenge='KGS!@#$%') {
		$is = mcrypt_get_iv_size(MCRYPT_DES, MCRYPT_MODE_ECB);
		$iv = mcrypt_create_iv($is, MCRYPT_RAND);
		
		$des = '';
		$l = strlen($str);
		$str = str_pad($str,ceil($l/7)*7,"\0");
		for ($i=0; $i<$l; $i+=7) {
			$bin = '';
			for ($p=0; $p<7; ++$p) {
				$bin .= str_pad(decbin(ord($str[$i+$p])),8,'0',STR_PAD_LEFT);
			}
			
			$key = '';
			for ($p=0; $p<56; $p+=7) {
				$s = substr($bin,$p,7);
				$key .= chr(bindec($s.((substr_count($s,'1') % 2) ? '0' : '1')));
			}
			
			$des .= mcrypt_encrypt(MCRYPT_DES, $key, $challenge, MCRYPT_MODE_ECB, $iv);
		}
		return $des;
	}
	
	protected static function ToUnicode($ascii) {
		return mb_convert_encoding($ascii,'UTF-16LE','auto');
		$str = '';
		for ($a=0; $a<strlen($ascii); ++$a) { $str .= substr($ascii,$a,1)."\0"; }
		return $str;
	}
}

/**
 * Own exception classes
 */
class SharepointException extends \Exception { }
class SharepointResponseException extends SharepointException { }
class SharepointSoapException extends SharepointException { }
