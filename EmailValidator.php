<?php

class EmailValidator {

  const VALID_NO = 0;
  const VALID_YES = 1;
  const VALID_MAYBE = 2;

  private $_from    = '';
  private $_emails  = array();
  private $_options = array();

  private $_domains = array();

  static public function validate($from, array $emails, array $options=array()) {
    $validator = new EmailValidator($from, $emails, $options);
    if ($validator->parse()) {
      return $validator->check();
    }
    return FALSE;
  }

  private function __construct($from, $emails, $options=array()) {
    $this->_from = $from;
    $this->_emails = $emails;
    $this->_options = stream_context_create($this->_options);
  }

  private function parse() {
    foreach ($this->_emails as $email) {
      // extract dns for each email address.
      $dns = explode('@', $email)[1];

      // if we first occured this dns, then we create the mx and base settings
      if (!isset($this->_domains[$dns])) {
	$mxrecords = array();
	$mxweights = array();

	$this->_domains[$dns] = array(
          'mx' => array(),
	  'emails' => array(),
        );

	if (getmxrr($dns, $mxrecords, $mxweights)) {
	  for ($n = 0; $n < count($mxrecords); $n++){
	    $this->_domains[$dns]['mx'][$mxrecords[$n]] = $mxweights[$n];
	  }
	  asort($this->_domains[$dns]['mx']);
	}
      }

      if (isset($this->_domains[$dns])) {
	// define mail as not valid by default.
	$this->_domains[$dns]['emails'][$email] = self::VALID_NO;
      }
    }
    return TRUE;
  }

  private function check() {
    $results = array();
    
    // check email through SMTP for each domain.
    foreach ($this->_domains as $domain) {
      foreach ($domain['emails'] as $email => $status) {
	$results[$email] = $status;
      }

      // check if we have MXs.
      if (isset($domain['mx']) && count($domain['mx'])) {
	foreach ($domain['mx'] as $mxrecord => $weight) {
	  $fp = @stream_socket_client(
            'tcp://'.$mxrecord.':25', $errno, $errstr, 20,
	    STREAM_CLIENT_CONNECT, $this->_options
          );

	  if ($fp !== FALSE) {
	    // we first connect, then send credentials.
	    fgets($fp, 1024)."\n";
	    fwrite($fp, "HELO helo\r\n");
	    fgets($fp, 1024)."\n";
	    fwrite($fp, "MAIL FROM: <".$this->_from.">\r\n");
	    fgets($fp, 1024)."\n";

	    // then, we ask for mail from this domains
	    foreach ($domain['emails'] as $email => $status) {
	      fwrite($fp, "RCPT TO: <".$email.">\r\n");
	      $reply = fgets($fp, 1024)."\n";

	      // check response code
	      preg_match('/^([0-9]{3}) /ims', $reply, $matches);
	      $code = isset($matches[1]) ? $matches[1] : '';

	      if ($code == '250') {
		// you received 250 so the email address was accepted
		$results[$email] = self::VALID_YES;
	      } elseif ($code == '451' || $code == '452') {
		// greylisted / assuming maybe
		$results[$email] = self::VALID_MAYBE;
	      } else {
		$results[$email] = self::VALID_NO;
	      }
	    }
	    fclose($fp);
	    break;
	  }
	}
      }
    }
    return $results;
  }
}

