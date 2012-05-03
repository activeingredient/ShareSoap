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

namespace ShareSoap;

require_once(dirname(__FILE__) . '/includes/exceptions.php');
require_once(dirname(__FILE__) . '/includes/connection.php');
require_once(dirname(__FILE__) . '/includes/soapclient.php');

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
							///FIXME ChildXML does not work...
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
