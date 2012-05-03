<?php
/**
 * Own exception classes
 */
namespace ShareSoap;

class SharepointException extends \Exception { }
class SharepointResponseException extends SharepointException { }
class SharepointSoapException extends SharepointException { }
