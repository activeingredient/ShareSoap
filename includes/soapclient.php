<?php
/**
 * SoapClient object tuned to work with NTLM authentication
 */
namespace ShareSoap;

require_once(dirname(__FILE__) . '/exceptions.php');

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
