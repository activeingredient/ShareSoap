<?php
/**
 * Own exception classes
 */
namespace ShareSoap;

class SharepointException extends \Exception { }
class SharepointConnectionException extends SharepointException { }
class SharepointSoapException extends SharepointException { }
