B1;3801;0c# PHP EmailValidator

The class retrieves MX records for the email domain and then connects to the
domain's SMTP server to try figuring out if the address really exists.

### Some features (see the source for more)

* Not really sending a message, gracefully resetting the session when done
* Command-specific communication timeouts implemented per relevant RFCs
* Catch-all account detection
* Batch mode processing supported
* MX query support on Windows without requiring any PEAR packages
* Logging and debugging support

### Installation
```php
composer require rookmoot/email_validator
```

### Basic example
```php
<?php

require_once dirname(__FILE_).'/vendor/autoload.php';

$from = 'anaddress@example.com'; // for SMTP FROM:<> command
$emails = array(
  'test1@example.com',
  'test2@example.com',
  'willneverreachanything',
);


$result = EmailValidator\EmailValidator::validate($from, $emails);
var_dump($results);
```

You can also specify options as the third arguements of the validate method.
Those options correspond to stream_socket_client context.
