<?php 
/* 	
If you see this text in your browser, PHP is not configured correctly on this hosting provider. 
Contact your hosting provider regarding PHP configuration for your site.

Koneksje
*/

require_once('form_throttle.php');

function process_form($form) {
	if ($_SERVER['REQUEST_METHOD'] != 'POST')
		die(get_form_error_response($form['resources']['unknown_method']));

	if (formthrottle_too_many_submissions($_SERVER['REMOTE_ADDR']))
		die(get_form_error_response($form['resources']['too_many_submissions']));
	
	// will die() if there are any errors
	check_required_fields($form);
	
	// will die() if there is a send email problem
	email_form_submission($form);
}

function get_form_error_response($error) {
	return get_form_response(false, array('error' => $error));
}

function get_form_response($success, $data) {
	if (!is_array($data))
		die('data must be array');
		
	$status = array();
	$status[$success ? 'FormResponse' : 'koneksjePHPFormResponse'] = array_merge(array('success' => $success), $data);
	
	return json_serialize($status);
}

function check_required_fields($form) {
	$errors = array();

	foreach ($form['fields'] as $field => $properties) {
		if (!$properties['required'])
			continue;

		if (!array_key_exists($field, $_REQUEST) || ($_REQUEST[$field] !== "0" && empty($_REQUEST[$field])))
			array_push($errors, array('field' => $field, 'message' => $properties['errors']['required']));
		else if (!check_field_value_format($form, $field, $properties))
			array_push($errors, array('field' => $field, 'message' => $properties['errors']['format']));
	}

	if (!empty($errors))
		die(get_form_error_response(array('fields' => $errors)));
}

function check_field_value_format($form, $field, $properties) {
	$value = get_form_field_value($field, $properties, $form['resources'], false);

	switch($properties['type']) {
		case 'checkbox':
		case 'string':
		case 'captcha':
			// no format to validate for those fields
			return true;

		case 'checkboxgroup':
			if (!array_key_exists('optionItems', $properties))
				die(get_form_error_response(sprintf($form['resources']['invalid_form_config'], $properties['label'])));

			// If the value received is not an array, treat it as invalid format
			if (!isset($value))
				return false;

			// Check each option to see if it is a valid value
			foreach($value as $checkboxValue) {
				if (!in_array($checkboxValue, $properties['optionItems']))
					return false;
			}

			return true;

		case 'radiogroup':
			if (!array_key_exists('optionItems', $properties))
				die(get_form_error_response(sprintf($form['resources']['invalid_form_config'], $properties['label'])));

			//check list of real radio values
			return in_array($value, $properties['optionItems']);
	
		case 'recaptcha':
			if (!array_key_exists('recaptcha', $form) || !array_key_exists('private_key', $form['recaptcha']) || empty($form['recaptcha']['private_key']))
				die(get_form_error_response($form['resources']['invalid_reCAPTCHA_private_key']));
			$resp = recaptcha_check_answer($form['recaptcha']['private_key'], $_SERVER["REMOTE_ADDR"], $_POST["recaptcha_challenge_field"], $_POST["recaptcha_response_field"]);
			return $resp->is_valid;

		case 'recaptcha2':
			if (!array_key_exists('recaptcha2', $form) || !array_key_exists('private_key', $form['recaptcha2']) || empty($form['recaptcha2']['private_key']))
				die(get_form_error_response($form['resources']['invalid_reCAPTCHA2_private_key']));

			$resp = recaptcha2_check_answer($form['recaptcha2']['private_key'], $_POST["g-recaptcha-response"], $_SERVER["REMOTE_ADDR"]);
			return $resp["success"];

		case 'email':
			return 1 == preg_match('/^[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*@(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $value);

		case 'radio': // never validate the format of a single radio element; only the group gets validated
		default:
			die(get_form_error_response(sprintf($form['resources']['invalid_field_type'], $properties['type'])));
	}
}

/**
 * Returns an object with following properties:
 *	"success": true|false,
 *	"challenge_ts": timestamp,  // timestamp of the challenge load (ISO format yyyy-MM-dd'T'HH:mm:ssZZ)
 *	"hostname": string,         // the hostname of the site where the reCAPTCHA was solved
 *	"error-codes": [...]        // optional; possibe values:
 *									missing-input-secret - The secret parameter is missing
 *									invalid-input-secret - The secret parameter is invalid or malformed
 *									missing-input-response - The response parameter is missing
 *									invalid-input-response - The response parameter is invalid or malformed
 */
function recaptcha2_check_answer($secret, $response, $remoteIP) {
	$url = 'https://www.google.com/recaptcha/api/siteverify';
	$data = array(
		'secret' => $secret,
		'response' => $response,
		'remoteip' => $remoteIP
	);

	$options = array(
		'http' => array(
			'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
			'method'  => 'POST',
			'content' => http_build_query($data)
		)
	);
	
	$context = stream_context_create($options);
	$contents = file_get_contents($url, false, $context);
	if ($contents === FALSE) {
		die(get_form_error_response($form['resources']['invalid_reCAPTCHA2_server_response']));
	}

	$result = (array) json_decode($contents);
	return $result;
}

function email_form_submission($form) {
	if(!defined('PHP_EOL'))
		define('PHP_EOL', '\r\n');

	$form_email = ((array_key_exists('Email', $_REQUEST) && !empty($_REQUEST['Email'])) ? cleanup_email($_REQUEST['Email']) : '');

	$to = $form['email']['to'];
	$subject = $form['subject'];
	$message = get_email_body($subject, $form['heading'], $form['fields'], $form['resources']);
	$headers = get_email_headers($to, $form_email);	

	$sent = @mail($to, $subject, $message, $headers);
	
	if(!$sent)
		die(get_form_error_response($form['resources']['failed_to_send_email']));
	
	$success_data = array(
		'redirect' => $form['success_redirect']
    );
	
	echo get_form_response(true, $success_data);
}

function get_email_headers($to_email, $form_email) {
	$headers = 'From: ' . $to_email . PHP_EOL;
	$headers .= 'Reply-To: ' . $form_email . PHP_EOL;
	$headers .= 'X-Mailer: Koneksje with PHP' . PHP_EOL;
	$headers .= 'Content-type: text/html; charset=utf-8' . PHP_EOL;
	
	return $headers;
}

function get_email_body($subject, $heading, $fields, $resources) {
	$message = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
	$message .= '<html xmlns="http://www.w3.org/1999/xhtml">';
	$message .= '<head><meta http-equiv="Content-Type" content="text/html;charset=UTF-8"/><title>' . encode_for_form($subject) . '</title></head>';
	$message .= '<body style="background-color: #ffffff; color: #000000; font-style: normal; font-variant: normal; font-weight: normal; font-size: 12px; line-height: 18px; font-family: helvetica, arial, verdana, sans-serif;">';
	$message .= '<h2 style="background-color: #eeeeee;">' . $heading . '</h2>';
	$message .= '<table cellspacing="0" cellpadding="0" width="100%" style="background-color: #ffffff;">'; 

	$sorted_fields = array();
	
	foreach ($fields as $field => $properties) {
		// Skip reCAPTCHA from email submission
		if ('recaptcha' == $properties['type'] || 'recaptcha2' == $properties['type'])
			continue;

		array_push($sorted_fields, array('field' => $field, 'properties' => $properties));
	}

	// sort fields
	usort($sorted_fields, 'field_comparer');

	foreach ($sorted_fields as $field_wrapper)
		$message .= '<tr><td valign="top" style="background-color: #ffffff;"><b>' . encode_for_form($field_wrapper['properties']['label']) . ':</b></td><td>' . get_form_field_value($field_wrapper['field'], $field_wrapper['properties'], $resources, true) . '</td></tr>';

	$message .= '</table>';
	$message .= '<br/><br/>';
	$message .= '<div style="background-color: #eeeeee; font-size: 10px; line-height: 11px;">' . sprintf($resources['submitted_from'], encode_for_form($_SERVER['SERVER_NAME'])) . '</div>';
	$message .= '<div style="background-color: #eeeeee; font-size: 10px; line-height: 11px;">' . sprintf($resources['submitted_by'], encode_for_form($_SERVER['REMOTE_ADDR'])) . '</div>';
	$message .= '</body></html>';

	return cleanup_message($message);
}

function field_comparer($field1, $field2) {
	if ($field1['properties']['order'] == $field2['properties']['order'])
		return 0;

	return (($field1['properties']['order'] < $field2['properties']['order']) ? -1 : 1);
}

function is_assoc_array($arr) {
	if (!is_array($arr))
		return false;
	
	$keys = array_keys($arr);
	foreach (array_keys($arr) as $key)
		if (is_string($key)) return true;

	return false;
}

function json_serialize($data) {

	if (is_assoc_array($data)) {
		$json = array();
	
		foreach ($data as $key => $value)
			array_push($json, '"' . $key . '": ' . json_serialize($value));
	
		return '{' . implode(', ', $json) . '}';
	}
	
	if (is_array($data)) {
		$json = array();
	
		foreach ($data as $value)
			array_push($json, json_serialize($value));
	
		return '[' . implode(', ', $json) . ']';
	}
	
	if (is_int($data) || is_float($data))
		return $data;
	
	if (is_bool($data))
		return $data ? 'true' : 'false';
	
	return '"' . encode_for_json($data) . '"';
}

function encode_for_json($value) {
	return preg_replace(array('/([\'"\\t\\\\])/i', '/\\r/i', '/\\n/i'), array('\\\\$1', '\\r', '\\n'), $value);
}

function encode_for_form($text) {
	$text = stripslashes($text);
	return htmlentities($text, ENT_QUOTES, 'UTF-8');// need ENT_QUOTES or webpro.js jQuery.parseJSON fails
}

function get_form_field_value($field, $properties, $resources, $forOutput) {
	$value = $_REQUEST[$field];
	
	switch($properties['type']) {
		case 'checkbox':
			return (($value == '1' || $value == 'true') ? $resources['checkbox_checked'] : $resources['checkbox_unchecked']);
		
		case 'checkboxgroup':
			if (!is_array($value))
				return NULL;

			$outputValue = array();

			foreach ($value as $checkboxValue)
				array_push($outputValue, $forOutput ? encode_for_form($checkboxValue) : stripslashes($checkboxValue));
			
			if ($forOutput)
				$outputValue = implode(', ', $outputValue);

			return $outputValue;
		
		case 'radiogroup':
			return ($forOutput ? encode_for_form($value) : stripslashes($value));
		
		case 'string':
		case 'captcha':
		case 'recaptcha':
		case 'recaptcha2':
		case 'email':
			return encode_for_form($value);

		case 'radio': // never validate the format of a single radio element; only the group gets validated
		default:
			die(get_form_error_response(sprintf($resources['invalid_field_type'], $properties['type'])));
	}
}

function cleanup_email($email) {
	$email = encode_for_form($email);
	$email = preg_replace('=((<CR>|<LF>|0x0A/%0A|0x0D/%0D|\\n|\\r)\S).*=i', null, $email);
	return $email;
}

function cleanup_message($message) {
	$message = wordwrap($message, 70, "\r\n");
	return $message;
}
?>
