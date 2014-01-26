<?php
	require_once 'bitpay/bp_lib.php';

	$adminMail = ""; //the address the confirmation mail is being sent from
	$db_host = "localhost";
	$db_username = "root";
	$db_password = "";
	$db_name = "beyondbtc";
	$basic_price = 279;

	//connect to mysql server
	$mysqli = new mysqli($db_host, $db_username, $db_password, $db_name);
	//$mysqli->report_mode = MYSQLI_REPORT_ALL;

	if ( isset($_POST['email']) AND isset($_POST['firstName']) AND isset($_POST['lastName']) ) {
		// creating the db entry for the signup
		$sql = "INSERT INTO 
	                signups (firstname, lastname, email, status, message, creationtime)
	            VALUES 
	                (?, ?, ?, 'new' , ?, ?)";

	    $stmt = $mysqli->prepare($sql);
		$stmt->bind_param("ssssi",
			$_POST['firstName'],
			$_POST['lastName'],
			$_POST['email'],
			$_POST['message'],
			time());

		$stmt->execute();
		$stmt->close();
	    $newEntryId = $mysqli->insert_id;

		// create the invoice at bitpay
		$options = array(
			"buyerEmail" => $_POST['email'],
			"buyerName" => $_POST['firstName'] . " " . $_POST['lastName'],
			"itemDesc" => "Signup-fee for the Bitshares Developers Conference in Las Vegas: July 12-14",
			"currency" => "USD",
			"notificationURL" => "https://invictus.io/lib/devcon_signup.php"
		);
		$invoice = bpCreateInvoice(1, $basic_price, $newEntryId, $options);

		// add the invoice-data to our
		// we don't know if the timezome of BitPay
		// server aligns with our server, so we calculate the difference
		$timeDelta = $invoice['currentTime'] - (time()*1000);
		$expirationTime = round(($invoice['expirationTime'] - $timeDelta)/1000 + 1);
		$sql = "UPDATE
	                signups
	            SET
	                invoiceid = ?,
	                expirationtime = ?
	            WHERE
	            	id = ?";
		$stmt = $mysqli->prepare($sql);
		$stmt->bind_param("sii",
			$invoice['id'],
			$expirationTime,
			$newEntryId);
		$stmt->execute();
		$stmt->close();

		// send email to the person signing up
		$to      = $_POST['email'];
		$subject = "Bitshares Developers Conference Invoice";
		// TODO: Write a proper mail-body
		$message = 'Hi ' . $_POST['firstName'].',\n\nThank you for signing up for Beyong Bitcoin.\nIn order to cofirm your registration, you have to pay the invoice within the next 15 minutes,\nif you don\'t pay the invoice in time, the registration will automatically be canceled. \nYou can view your invoice here: '.$invoice["url"].'\n\n\nAll the best\nYour Beyong Bitcoin Team';
		$headers = 'From: ' . $adminMail . "\r\n" .
		    'Reply-To: ' . $adminMail . "\r\n" .
		    'X-Mailer: PHP/' . phpversion();

		mail($to, $subject, $message, $headers);

		// return the data to the site to render payment-iframe
		print json_encode($invoice);
	} else { // else just check the current signups for changes, most likely this was triggered by the BitPay-server
		$updatedInvoice = bpVerifyNotification();
		if (is_string($updatedInvoice)) {
			print "{\"error\":{\"type\":\"missingParameter\",\"message\":\"The request is missing either the email, first and last name or a valid, signed request from BitPay.\"}} ";
			//return;
			//
			// TEST:
			// $updatedInvoice = json_decode('{"id":"SjmB4Z5fDCngGyA3x8zqnW","url":"https:\/\/bitpay.com\/invoice?id=SjmB4Z5fDCngGyA3x8zqnW","posData":4,"status":"confirmed","btcPrice":"0.0001","price":0.01,"currency":"USD","invoiceTime":1390412029304,"expirationTime":1390412929304,"currentTime":1390412029471}', true);
		}

		$entryId = $updatedInvoice['posData'];

		// update our DB to the new state of the invoice
		$sql = "UPDATE
                signups
            SET
                status = ?
            WHERE
            	id = ?
            AND
            	invoiceid = ?";

		$stmt = $mysqli->prepare($sql);
		$stmt->bind_param("sis",
			$updatedInvoice['status'],
			$entryId,
			$updatedInvoice['id']);
		$stmt->execute();
		$stmt->close();

		// if the status is confirmed, send out an email.
		if ( $updatedInvoice['status'] == "confirmed") {
			$sql = "SELECT * FROM signups WHERE id = ".$entryId;
			$entry = $mysqli->query($sql)->fetch_assoc();

			// send email to the person signing up
			$to      = $entry['email'];
			$subject = "Bitshares Developers Conference Ticket Confirmation";
			// TODO: Write a proper mail-body
			$message = 'Hi ' . $entry['firstname'] . '!\n\n Your payment for the conference ticket just got confirmed, we are looking forward to see you at the Beyond Bitcoin Conference in Las Vegas in July!\n\nDetails:\nName:'.$entry['firstname'].' '.$entry['lastname'].'\nTicket-ID:'.$entry['invoiceid'].'\n\n\nAll the best\nYour Beyong Bitcoin Team';
			$headers = 'From: ' . $adminMail . "\r\n" .
			    'Reply-To: ' . $adminMail . "\r\n" .
			    'X-Mailer: PHP/' . phpversion();

			mail($to, $subject, $message, $headers);
		}
	}
?>