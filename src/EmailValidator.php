<?php

namespace EmailValidator;

class EmailValidator {

  const VALID_NO = 0;
  const VALID_YES = 1;
  const VALID_MAYBE = 2;

  private $_from    = '';
  private $_emails  = array();
  private $_options = array();
  private $_domains = array();

  private $_fp = NULL;
  private $_delay = 2; // delay in second before each call.

  static public function validate($from, array $emails, array $options=array()) {
    $validator = new EmailValidator($from, $emails, $options);
    if ($validator->parse()) {
      return $validator->check();
    }
    return FALSE;
  }

  private function __construct($from, array $emails, array $options=array()) {
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

  private function connect($host) {
    $this->_fp = @stream_socket_client(
      'tcp://'.$host.':25', $errno, $errstr, 20,
      STREAM_CLIENT_CONNECT, $this->_options
    );

    if (!$this->connected()) {
      throw new Exception('Failed to connect to '.$host);
    }

    $result = stream_set_timeout($this->_fp, 60);
    if (!$result) {
      throw new Exception('Failed to set timeout to '.$host);
    }
    stream_set_blocking($this->_fp, TRUE);
    return TRUE;
  }

  private function disconnect() {
    if (!$this->connected()) {
      return;
    }

    $this->send('QUIT');
    sleep(2);
    fclose($this->_fp);
  }

  private function connected() {
    return ($this->_fp && is_resource($this->_fp));
  }

  private function send($message) {
    if (!$this->connected()) {
      throw new Exception('Can\'t send on not connected host');
    }

    sleep($this->_delay);
    
    $result = @fwrite($this->_fp, $message."\r\n");
    if ($result === false) {
      throw new Exception('Failed to write to socket for message : '.$message);
    }
    
    $text = $line = $this->recv();
    while (preg_match("/^[0-9]+-/", $line) || !strncmp($line, '220', 3)) {
      $line = $this->recv();
      $text .= $line;
    }

    sscanf($line, '%d%s', $code, $text);
    return $code ? $code : '500';
  }

  private function recv() {
    if (!$this->connected()) {
      throw new Exception('Can\'t send on not connected host');
    }

    stream_set_timeout($this->_fp, 50);
    return @fgets($this->_fp, 1024);
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

	  try {
	    if ($this->connect($mxrecord)) {
	      // TODO replace foo.com with fromemail domain.
	      $this->send('EHLO foo.com');
	      $this->send('NOOP');
	      foreach ($domain['emails'] as $email => $status) {
		$this->send('MAIL FROM: <'.$this->_from.'>');
		$reply = $this->send('RCPT TO: <'.$email.'>');
		if ($reply) {
		  // you received 250 so the email address was accepted
		  if ($reply == '250') {
		    $results[$email] = self::VALID_YES;

		  // greylisted / assuming maybe
		  } elseif ($reply == '451' || $reply == '452') {
		    $results[$email] = self::VALID_MAYBE;
		    
		  // else, this is not a valid email.
		  } else {
		    $results[$email] = self::VALID_NO;
		  }
		}
		$this->send('RSET');
	      }
	      $this->disconnect();
	      break;
	    }
	  } catch (Exception $e) { }
	}
      }
    }
    return $results;
  }
}
