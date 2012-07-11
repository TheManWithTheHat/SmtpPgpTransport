<?php
class EmailConfig {

	public $smtppgp = array(
		'transport' => 'SmtpPgp',
		'from' => ,
		'pgppassword' => '', // Password for the PGP Key
		'host' => '',
		'port' => 25,
		'timeout' => 30,
		'username' => '',
		'password' => '', // Password for the Email Account
		'client' => null,
		'log' => false,
		'charset' => 'utf-8',
		'headerCharset' => 'utf-8',
	);

}
