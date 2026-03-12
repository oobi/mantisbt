<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as phpmailerException;

require_once( __DIR__ . '/../../core/classes/EmailSender.class.php' );
require_once( __DIR__ . '/../../core/classes/EmailMessage.class.php' );

/**
 * Custom email sender that delivers HTML-formatted emails via PHPMailer.
 * Registered via EVENT_EMAIL_CREATE_SEND_PROVIDER.
 */
class BetterEmailSender extends EmailSender {

	/** @var PHPMailer|null Reusable PHPMailer instance (mirrors core pattern) */
	private static $s_mailer = null;

	public function send( EmailMessage $p_message ) : bool {

		if( is_null( self::$s_mailer ) ) {
			if( PHPMAILER_METHOD_SMTP == config_get( 'phpMailer_method' ) ) {
				register_shutdown_function( [ $this, 'close' ] );
			}

			self::$s_mailer = new PHPMailer( true );
			PHPMailer::$validator = 'html5';
		}

		$mail = self::$s_mailer;

		if( !empty( $p_message->hostname ) ) {
			$mail->Hostname = $p_message->hostname;
		}

		$mail->setLanguage( lang_get( 'phpmailer_language', $p_message->lang ) );

		switch( config_get( 'phpMailer_method' ) ) {
			case PHPMAILER_METHOD_MAIL:
				$mail->isMail();
				break;
			case PHPMAILER_METHOD_SENDMAIL:
				$mail->isSendmail();
				break;
			case PHPMAILER_METHOD_SMTP:
				$mail->isSMTP();
				$mail->SMTPKeepAlive = true;
				if( !is_blank( config_get( 'smtp_username' ) ) ) {
					$mail->SMTPAuth = true;
					$mail->Username = config_get( 'smtp_username' );
					$mail->Password = config_get( 'smtp_password' );
				}
				if( is_blank( config_get( 'smtp_connection_mode' ) ) ) {
					$mail->SMTPAutoTLS = false;
				} else {
					$mail->SMTPSecure = config_get( 'smtp_connection_mode' );
				}
				$mail->Port = config_get( 'smtp_port' );
				break;
		}

		$mail->CharSet  = $p_message->charset;
		$mail->Host     = config_get( 'smtp_host' );
		$mail->From     = config_get( 'from_email' );
		$mail->Sender   = config_get( 'return_path_email' );
		$mail->FromName = config_get( 'from_name' );
		$mail->WordWrap = 80;
		$mail->Encoding = 'quoted-printable';

		foreach( $p_message->cc  as $cc  ) { $mail->addCC( $cc );  }
		foreach( $p_message->bcc as $bcc ) { $mail->addBCC( $bcc ); }

		try {
			foreach( $p_message->to as $recipient ) {
				$mail->addAddress( $recipient );
			}
		} catch( phpmailerException $e ) {
			log_event( LOG_EMAIL, 'BetterEmailSender: bad address – ' . $mail->ErrorInfo );
			$this->reset( $mail );
			return false;
		}

		$mail->Subject = $p_message->subject;

		// Build HTML body
		$plugin    = plugin_get( 'BetterEmail' );
		$bug_id    = $this->extract_bug_id( $p_message );
		$html_body = $plugin->build_html( $p_message->text, $bug_id );

		// Build plain-text AltBody for EmailReporting compatibility.
		// EmailReplyParser (used by EmailReporting) strips everything below
		// a "-- reply above --" style separator, so the user's reply zone
		// is cleanly above and the original content is quoted below.
		$bug_ref  = $bug_id ? " issue #{$bug_id}" : '';
		$alt_body = "\r\n"
		          . "-- Reply above this line to add a comment to{$bug_ref} --\r\n"
		          . "\r\n"
		          . $p_message->text;

		$mail->isHTML( true );
		$mail->Body    = $html_body;
		$mail->AltBody = $alt_body;

		// Headers – already processed (Message-ID wrapped in <>, etc.) by
		// email_send() in core/email_api.php, so pass them straight through
		// just like EmailSenderPhpMailer does.
		foreach( $p_message->headers as $key => $value ) {
			switch( strtolower( $key ) ) {
				case 'message-id':
					$mail->set( 'MessageID', $value );
					break;
				default:
					$mail->addCustomHeader( $key . ': ' . $value );
					break;
			}
		}

		$success = false;
		try {
			$success = $mail->send();
		} catch( phpmailerException $e ) {
			log_event( LOG_EMAIL, 'BetterEmailSender: send failed – ' . $mail->ErrorInfo );
		}

		$this->reset( $mail );
		return $success;
	}

	/**
	 * Try to find a bug ID in the email subject (pattern: [Project 0001234]: ...).
	 */
	private function extract_bug_id( EmailMessage $p_message ) : ?int {
		if( preg_match( '/\b0*(\d+)\]/', $p_message->subject, $m ) ) {
			return (int) $m[1];
		}
		return null;
	}

	private function reset( PHPMailer $mail ) : void {
		$mail->clearAllRecipients();
		$mail->clearAttachments();
		$mail->clearReplyTos();
		$mail->clearCustomHeaders();
	}

	public function close() : void {
		if( !is_null( self::$s_mailer ) ) {
			$smtp = self::$s_mailer->getSMTPInstance();
			if( $smtp->connected() ) {
				$smtp->quit();
				$smtp->close();
			}
			self::$s_mailer = null;
		}
	}
}
