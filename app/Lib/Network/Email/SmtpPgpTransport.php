<?php
/**
 * @since	Created on 10 Jul 2012
 * @package	SmtpPgpTransport
 * @author 	Thies Wandschneider <thies@wandschneider.de>
 * @license 	GNU/LGPL, see COPYING
 * @link 	http://themanwiththehat.wordpress.com
 *
 * This file is part of the SmtpPgpTransport for cakePHP 2.x
 *
 * The project is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * The project is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this project.  If not, see <http://www.gnu.org/licenses/>.
 */

App::uses('CakeSocket', 'Network');

class SmtpPgpTransport extends AbstractTransport {

	protected $_socket;

	protected $_cakeEmail;

	protected $_content;

	public function send(CakeEmail $email) {
		$this->_cakeEmail = $email;
		$this->_connect();
		$this->_auth();
		$this->_sendRcpt();
		$this->_sendData();
		$this->_disconnect();
		return $this->_content;
	}

/**
 * Set the configuration
 *
 * @param array $config
 * @return void
 */
	public function config($config = array()) {
		$default = array(
			'host' => 'localhost',
			'port' => 25,
			'timeout' => 30,
			'username' => null,
			'password' => null,
			'client' => null
		);
		$this->_config = $config + $default;
	}

/**
 * Connect to SMTP Server
 *
 * @return void
 * @throws SocketException
 */
	protected function _connect() {
		$this->_generateSocket();
		if (!$this->_socket->connect()) {
			throw new SocketException(__d('cake_dev', 'Unable to connect to SMTP server.'));
		}
		$this->_smtpSend(null, '220');

		if (isset($this->_config['client'])) {
			$host = $this->_config['client'];
		} elseif ($httpHost = env('HTTP_HOST')) {
			list($host) = explode(':', $httpHost);
		} else {
			$host = 'localhost';
		}

		try {
			$this->_smtpSend("EHLO {$host}", '250');
		} catch (SocketException $e) {
			try {
				$this->_smtpSend("HELO {$host}", '250');
			} catch (SocketException $e2) {
				throw new SocketException(__d('cake_dev', 'SMTP server did not accept the connection.'));
			}
		}
	}

/**
 * Send authentication
 *
 * @return void
 * @throws SocketException
 */
	protected function _auth() {
		if (isset($this->_config['username']) && isset($this->_config['password'])) {
			$authRequired = $this->_smtpSend('AUTH LOGIN', '334|503');
			if ($authRequired == '334') {
				if (!$this->_smtpSend(base64_encode($this->_config['username']), '334')) {
					throw new SocketException(__d('cake_dev', 'SMTP server did not accept the username.'));
				}
				if (!$this->_smtpSend(base64_encode($this->_config['password']), '235')) {
					throw new SocketException(__d('cake_dev', 'SMTP server did not accept the password.'));
				}
			} elseif ($authRequired != '503') {
				throw new SocketException(__d('cake_dev', 'SMTP does not require authentication.'));
			}
		}
	}

/**
 * Send emails
 *
 * @return void
 * @throws SocketException
 */
	protected function _sendRcpt() {
		$from = $this->_cakeEmail->from();
		$this->_smtpSend('MAIL FROM:<' . key($from) . '>');

		$to = $this->_cakeEmail->to();
		$cc = $this->_cakeEmail->cc();
		$bcc = $this->_cakeEmail->bcc();
		$emails = array_merge(array_keys($to), array_keys($cc), array_keys($bcc));
		foreach ($emails as $email) {
			$this->_smtpSend('RCPT TO:<' . $email . '>');
		}
	}

/**
 * Send Data
 *
 * @return void
 * @throws SocketException
 */
	protected function _sendData() {
		$this->_smtpSend('DATA', '354');
		$headers = $this->_cakeEmail->getHeaders(array('from', 'sender', 'replyTo', 'readReceipt', 'returnPath', 'to', 'cc', 'subject'));
		$to = $headers['To'];
		$headers = $this->_headersToString($headers);
		$message = implode("\r\n", $this->_cakeEmail->message());
		require_once(APP . 'Vendor/pear/PEAR.php');
		require_once(APP . 'Vendor/Crypt/GPG.php');
		$gpg = new Crypt_GPG(array('homedir' => APP . 'Config/.gnupg'));
		$gpg->addSignKey($this->_config['username'], $this->_config['pgppassword']);
		$key_exists = $gpg->getKeys($to);
		$message = wordwrap($message, 70);
		if(!empty($key_exists)) {
			$gpg->addEncryptKey($to);
			$message_signed = $gpg->encryptAndSign($message, Crypt_GPG::SIGN_MODE_CLEAR);
		} else {	
			$message_signed = $gpg->sign($message, Crypt_GPG::SIGN_MODE_CLEAR);
		}
		$this->_smtpSend($headers . "\r\n\r\n" . $message_signed . "\r\n\r\n\r\n.");
		$this->_content = array('headers' => $headers, 'message' => $message);
	}

/**
 * Disconnect
 *
 * @return void
 * @throws SocketException
 */
	protected function _disconnect() {
		$this->_smtpSend('QUIT', false);
		$this->_socket->disconnect();
	}

/**
 * Helper method to generate socket
 *
 * @return void
 * @throws SocketException
 */
	protected function _generateSocket() {
		$this->_socket = new CakeSocket($this->_config);
	}

/**
 * Protected method for sending data to SMTP connection
 *
 * @param string $data data to be sent to SMTP server
 * @param mixed $checkCode code to check for in server response, false to skip
 * @return void
 * @throws SocketException
 */
	protected function _smtpSend($data, $checkCode = '250') {
		if (!is_null($data)) {
			$this->_socket->write($data . "\r\n");
		}
		while ($checkCode !== false) {
			$response = '';
			$startTime = time();
			while (substr($response, -2) !== "\r\n" && ((time() - $startTime) < $this->_config['timeout'])) {
				$response .= $this->_socket->read();
			}
			if (substr($response, -2) !== "\r\n") {
				throw new SocketException(__d('cake_dev', 'SMTP timeout.'));
			}
			$responseLines = explode("\r\n", rtrim($response, "\r\n"));
			$response = end($responseLines);

			if (preg_match('/^(' . $checkCode . ')(.)/', $response, $code)) {
				if ($code[2] === '-') {
					continue;
				}
				return $code[1];
			}
			throw new SocketException(__d('cake_dev', 'SMTP Error: %s', $response));
		}
	}

}
