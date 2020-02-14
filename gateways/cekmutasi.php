<?php

/**
 * WHMCS Cekmutasi Payment Gateway Module
 *
 * Cekmutasi Payment Gateway Module allow you to integrate Cekmutasi with the
 * WHMCS platform.
 *
 * This Module Information:
 --------------------------
 https://cekmutasi.co.id
 Version: 2.0.2
 Released: 2020-02-14
 --------------------------
 *
 * For more information, about this modules payment please kindly visit our website at cekmutasi.co.id
 *
 */
 
if( !defined("WHMCS") ) {
    die("This file cannot be accessed directly");
}

$configfile = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'cekmutasi' . DIRECTORY_SEPARATOR . 'config.php';

require_once $configfile;

if (!isset($CekmutasiConfigs)) {
	exit("There is no configs of included config file.");
}

//=============================================================
// Cekmutasi Unique Number Class
//=============================================================

class cekmutasi_unique
{
	protected $settings;

	function __construct($settings = null)
	{
		$this->initialize($settings);
	}

	function initialize($settings = array())
	{
		$this->settings = $settings;
	}
	
	function cekmutasi_get_new_unique_number()
	{
		$int_random = mt_rand($this->settings['cm_unique_starting'], $this->settings['cm_unique_stopping']);

		return (int) $int_random;
	}

	private function cekmutasi_check_new_unique($unique_number, $sql_string, $sql_table)
	{
		$rows = 0;
		$unique_number = (int) $unique_number;
		$sql = is_string($sql_string) ? $sql_string : '';

		if (strlen($sql) > 0)
		{
			$sql .= sprintf(" AND (unique_amount = '%d')", $unique_number);
			$sql .= " AND (trans_user != 0)";
		}

		$sql = str_replace('[#TABLE_UNIQUE#]', $sql_table, $sql);
		
		try
		{
			$sql_query = full_query($sql);
		}
		catch (Exception $ex)
		{
			throw $ex;
			return false;
		}

		$row_data = $sql_query->fetch(\PDO::FETCH_ASSOC);
		$rows = isset($row_data['value']) ? $row_data['value'] : 0;
		return $rows;
	}

	function cekmutasi_calculate_unique($sql_table, $timezone)
	{
		if ( strtolower($this->settings['cm_unique_status']) != 'on' )
		{
			return;
		}
		
		$unique_params = array();

		$Datezone = new DateTime();
		$Datezone->setTimezone(new DateTimeZone($timezone));
		
		$unique_params['unique_amount'] = $this->cekmutasi_generate_new_unique($this->settings['cm_unique_range_unit'], $this->settings['cm_unique_range_amount'], $sql_table, $timezone);

		if ($this->settings['cm_unique_type'] == 'decrease')
		{
			$unique_params['unique_amount'] = (int) -$unique_params['unique_amount'];
		}

		// Generate Insert Params
		$insert_params = array(
			'trans_seq'					=> $this->settings['invoicenum'],
			'trans_user'				=> (isset($this->settings['clientdetails']['userid']) ? $this->settings['clientdetails']['userid'] : 0),
			'trans_invoiceid'			=> $this->settings['invoiceid'],
			'unique_payment_gateway'	=> $this->settings['paymentmethod'],
			'unique_unit_name'			=> $this->settings['cm_unique_range_unit'],
			'unique_unit_amount'		=> $this->settings['cm_unique_range_amount'],
			'unique_label'				=> $this->settings['cm_unique_label'],
			'unique_amount'				=> $unique_params['unique_amount'],
			'unique_date'				=> $Datezone->format('Y-m-d'),
			'unique_datetime'			=> $Datezone->format('Y-m-d H:i:s'),
		);

		return $insert_params;
	}

	private function cekmutasi_generate_new_unique($string_unit = 'day', $int_amount = 0, $sql_table, $timezone)
	{
		$string_unit = is_string($string_unit) ? strtolower($string_unit) : 'day';

		if ( !in_array($string_unit, array('minute', 'hour', 'day')) )
		{
			$string_unit = 'day';
		}

		$int_amount = (int) $int_amount;

		// Include libraries
		include_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'cekmutasi' . DIRECTORY_SEPARATOR . 'libs' . DIRECTORY_SEPARATOR . 'Datezone.php';

		$Lib_datezone = new Cekmutasi\Libs\Datezone();
		$date_stopping = new DateTime();
		$date_stopping->setTimezone(new DateTimeZone($timezone));

		switch (strtolower($string_unit))
		{
			case 'minute':
				$date_starting = $Lib_datezone->reduce_date_by('MINUTE', $int_amount, $date_stopping);
				break;

			case 'hour':
				$date_starting = $Lib_datezone->reduce_date_by('HOUR', $int_amount, $date_stopping);
				break;

			case 'day':
			default:
				$date_starting = $Lib_datezone->reduce_date_by('DAY', $int_amount, $date_stopping);
				break;
		}

		$sql = sprintf("SELECT COUNT(seq) AS value FROM %s WHERE (unique_unit_name = '%s' AND unique_unit_amount = '%d')",
			'[#TABLE_UNIQUE#]',
			$string_unit,
			$int_amount
		);

		switch (strtolower($string_unit))
		{
			case 'minute':
				$sql .= sprintf(" AND (unique_datetime BETWEEN '%s' AND '%s')",
					$date_starting->format('Y-m-d H:i'),
					$date_stopping->format('Y-m-d H:i')
				);
				break;

			case 'hour':
				$sql .= sprintf(" AND (unique_datetime BETWEEN '%s' AND '%s')",
					$date_starting->format('Y-m-d H'),
					$date_stopping->format('Y-m-d H')
				);
				break;

			case 'day':
			default:
				$sql .= sprintf(" AND (DATE(unique_datetime) BETWEEN '%s' AND '%s')",
					$date_starting->format('Y-m-d'),
					$date_stopping->format('Y-m-d')
				);
				break;
		}

		do
		{
			$unique_number = $this->cekmutasi_get_new_unique_number();
			$rows = $this->cekmutasi_check_new_unique($unique_number, $sql, $sql_table);
		} while ($rows > 0);

		return $unique_number;
	}
}

/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related capabilities and
 * settings.
 *
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 *
 * @return array
 */
function cekmutasi_MetaData()
{
    return array(
        'DisplayName' => 'Cekmutasi.co.id',
        'APIVersion' => '1.2', // Use API Version 1.2
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage' => false,
    );
}

//------------------------
// Include Cekmutasi Admin Lib
include_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'cekmutasi' . DIRECTORY_SEPARATOR . 'libs' . DIRECTORY_SEPARATOR . 'Admin.php';

if (!file_exists($configfile)) {
	exit("Required configs file does not exists.");
}

$CekmutasiAdmin = new Cekmutasi\Libs\Admin($CekmutasiConfigs);

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 * Supported field types include:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * Examples of each field type and their possible configuration parameters are
 * provided in the sample function below.
 *
 * @return array
 */
function cekmutasi_config()
{
    $configs = array(

        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Cekmutasi',
			'Description' => 'Sistem Validasi Pembayaran Otomatis dan Pengelolaan Rekening Terintegrasi.',
        ),

		'cm_description' => array(
			'FriendlyName' => 'Deskripsi',
			'Type' => 'textarea',
			'Rows' => '8',
            'Cols' => '72',
			'Default' => 'Silahkan lakukan pembayaran ke rekening berikut. Pembayaran Anda akan divalidasi otomatis dalam waktu 5-15 menit.
			
Bank BCA 123456789 a/n Penerima
Bank BRI 123456789 a/n Penerima',
			'Description' => 'Isi deskripsi dengan informasi rekening tujuan transfer yang telah terdaftar di Cekmutasi.co.id',
		),

		'cm_api_key' => array(
            'FriendlyName' => 'API Key',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => '<br/>Dapatkan API Key di <a href="https://cekmutasi.co.id/app/integration" target="_new" style="text-decoration:underline">https://cekmutasi.co.id/app/integration</a>',
        ),

		'cm_api_signature' => array(
			'FriendlyName' => 'API Signature',
			'Type' => 'text',
			'Size' => '25',
			'Default' => '',
			'Description' => '<br/>Dapatkan API Signature di <a href="https://cekmutasi.co.id/app/integration" target="_new" style="text-decoration:underline">https://cekmutasi.co.id/app/integration</a>',
		),

		'cm_timezone' => array(
			'FriendlyName' => 'Zona Waktu',
            'Type' => 'dropdown',
            'Options' => Cekmutasi\Libs\Admin::timezoneDropdown(),
            'Description' => '<br/>Zona waktu sistem. Akan berpengaruh dengan rentang tanggal data yang diambil. Harap sesuaikan dengan zona waktu di akun cekmutasi. Lihat di <a href="https://cekmutasi.co.id/app/setting" target="_new" style="text-decoration:underline">https://cekmutasi.co.id/app/setting</a>',
            'Default'	=> date_default_timezone_get()
		),

        'cm_enable_log' => array(
            'FriendlyName' => "Gunakan Logger pada Log Transaksi? (Tagihan &amp; Log Gateway)", 
			'Type' => "yesno",
			'Description' => "Centang untuk mengaktifkan Logging", 
        ),

		'cm_unique_status' => array(
			'FriendlyName' => "Aktifkan Nominal Unik?",
			'Type' => "yesno",
			'Default' => 'on',
			'Description' => "Centang untuk mengaktifkan fitur penambahan 3 angka unik di setiap akhir pesanan / order. Sebagai pembeda dari order satu dengan yang lainnya.",
		),

		'cm_unique_label' => array(
			'FriendlyName'	=> 'Label Nominal Unik',
			'Type'			=> 'text',
			'Size'			=> '22',
			'Default'		=> 'Kode Unik',
			'Description'	=> '<br/>Label yang akan muncul di form checkout',
		),

		'cm_unique_starting' => array(
			'FriendlyName'	=> 'Batas Awal Angka Unik',
			'Type'			=> 'text',
			'Size'			=> '22',
			'Default'		=> '1',
			'Description'	=> '<br/>Masukan batas awal angka unik',
		),

		'cm_unique_stopping' => array(
			'FriendlyName'	=> 'Batas Akhir Angka Unik',
			'Type'			=> 'text',
			'Size'			=> '22',
			'Default'		=> '999',
			'Description'	=> '<br/>Masukan batas akhir angka unik',
		),

		'cm_unique_type' => array(
			'FriendlyName' => 'Tipe Kalkulasi',
            'Type' => 'dropdown',
            'Options' => array(
                'increase'      => 'Plus (+)',
                'decrease'      => 'Minus (-)',
            ),
            'Description' => '<br/>Plus (+) = Menambah angka unik ke total harga<br/>Minus (-) = Mengurangi total harga dengan angka unik',
		),

		'cm_unique_range_unit' => array(
			'FriendlyName' => 'Satuan Masa Aktif Angka Unik',
            'Type' => 'dropdown',
            'Options' => array(
                'day'			=> 'Hari',
				'hour'			=> 'Jam',
				'minute'		=> 'Menit',
            ),
            'Description' => '<br/>Batas masa aktif perhitungan angka unik, default menggunakan satuan Hari',
		),

		'cm_unique_range_amount' => array(
			'FriendlyName'	=> 'Nilai Satuan Masa Aktif Angka Unik',
			'Type'			=> 'text',
			'Size'			=> '22',
			'Default'		=> '1',
			'Description'	=> '<br/>Jika 1 Hari berarti nominal unik akan berlaku selama 1 Hari (24 jam)',
		),

		'cm_change_day' => array(
			'FriendlyName' => 'Perubahan Status Di Hari Ke?',
            'Type' => 'dropdown',
            'Options' => array(
                '1'				=> 'H+1',
                '2'      		=> 'H+2',
                '3'      		=> 'H+3',
                '4'      		=> 'H+4',
                '5'      		=> 'H+5',
                '6'      		=> 'H+6',
                '7'      		=> 'H+7',
            ),
            'Default'   => '1',
            'Description' => '<br/>Setelah konsumen checkout dan belum bayar, pilih hari ke berapa status order berubah otomatis dari ON-HOLD ke PENDING',
		),

		'cm_enable_payment_check' => array(
			'FriendlyName' => "Aktifkan Pemeriksaan Pembayaran Saat Notifikasi dan Pengalihan", 
			'Type' => "yesno",
			'Description' => "Centang untuk mengaktifkan pemeriksaan pembayaran (disarankan untuk mode Production/transaksi riil)", 
		),

		'cm_local_api_admin_username' => array(
			'FriendlyName'	=> 'Nama Pengguna Admin WHMCS untuk menggunakan WHMCS::localAPI()',
			'Type'			=> 'text',
			'Size'			=> '22',
			'Default'		=> '',
			'Description'	=> '<br/>Masukkan username Admin WHMCS untuk menggunakan WHMCS::localAPI().',
		),

		'cm_notify_url'	=> array(
			"FriendlyName"	=> "Masukkan URL IPN/Callback IPN ini ke dalam rekening yang terdaftar di Cekmutasi.co.id", 
			"Type" 			=> 'hidden',
			"Size"			=> "64",
			"Value"			=> Cekmutasi\Libs\Admin::$notify,
			"Default"		=> Cekmutasi\Libs\Admin::$notify,
			"Description"	=> Cekmutasi\Libs\Admin::$notify,
		),

		'cm_server_ip'	=> array(
			"FriendlyName"	=> 'Masukkan IP ini ke dalam kolom Whitelist IP di <a href="https://cekmutasi.co.id/app/integration" target="_new" style="text-decoration:underline">https://cekmutasi.co.id/app/integration</a>', 
			"Type" 			=> 'hidden',
			"Size"			=> "64",
			"Value"			=> Cekmutasi\Libs\Admin::$clientIP,
			"Default"		=> Cekmutasi\Libs\Admin::$clientIP,
			"Description"	=> Cekmutasi\Libs\Admin::$clientIP,
		),

    );

	$tableExists = false;

	try
	{
	    // check if table exists
		$check = sprintf("DESCRIBE `%s`", CEKMUTASI_TABLE_TRANSACTION_IPN);
		$check = full_query($check);
		
		if( $check !== false ) {
		    $tableExists = true;
		}
	}
	catch (Exception $ex)
	{
        exit(__FILE__." (".$ex->getLine()."): ".$ex->getMessage());
	}

	if( $tableExists === false )
	{
		try
		{
		    // create IPN table
		    $createTableIPN = sprintf("CREATE TABLE `%s` (
    			`seq` int(11) NOT NULL AUTO_INCREMENT,
    			`payment_method` varchar(50) NOT NULL,
    			`input_data` text NOT NULL,
    			`input_datetime` datetime NOT NULL,
    			PRIMARY KEY (`seq`),
    			KEY `payment_method` (`payment_method`)
    			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    			CEKMUTASI_TABLE_TRANSACTION_IPN
    		);
		
			if( full_query($createTableIPN) === false ) {
			    throw new Exception("Cannot create table `".CEKMUTASI_TABLE_TRANSACTION_IPN."`");
			}
		}
		catch (Exception $ex)
		{
			exit(__FILE__." (".$ex->getLine()."): ".$ex->getMessage());
		}
	}
	else
	{
		try
		{
			// check if column `payment_bank` not exists
			if( full_query(sprintf("SELECT `payment_bank` FROM `%s`", CEKMUTASI_TABLE_TRANSACTION_IPN)) !== false )
			{
    			//====================================================
    			// Modify table for v1.0.0 -> v2.0.0
    			//====================================================
    			$renameColumn = sprintf("ALTER TABLE `%s` CHANGE `payment_bank` `payment_method` varchar(50) NOT NULL;", CEKMUTASI_TABLE_TRANSACTION_IPN);
    			if( full_query($renameColumn) === false ) {
    			    throw new Exception("Failed to rename column");
    			}
    			
    			$disableForeignKeyCheck = "SET FOREIGN_KEY_CHECKS = 0;";
    			if( full_query($disableForeignKeyCheck) === false ) {
    			    throw new Exception("Failed to disable Foreign Key check");
    			}
    			
    			$dropIndex = sprintf("ALTER TABLE `%s` DROP INDEX `payment_bank`;", CEKMUTASI_TABLE_TRANSACTION_IPN);
    			if( full_query($dropIndex) === false ) {
    			    throw new Exception("Failed to drop index");
    			}
    			
    			$createIndex = sprintf("ALTER TABLE `%s` ADD INDEX `payment_method` (`payment_method`);", CEKMUTASI_TABLE_TRANSACTION_IPN);
    			if( full_query($createIndex) === false ) {
    			    throw new Exception("Failed to create new index");
    			}
    			
    			$enableForeignKeyCheck = "SET FOREIGN_KEY_CHECKS = 1;";
    			if( full_query($enableForeignKeyCheck) === false ) {
    			    throw new Exception("Failed to enable Foreign Key check");
    			}
			}
		}
		catch (Exception $ex)
		{
			exit(__FILE__." (".$ex->getLine()."): ".$ex->getMessage());
		}
	}

	try
	{
	    if( full_query(sprintf("DESCRIBE `%s`", CEKMUTASI_TABLE_TRANSACTION_UNIQUE)) === false )
	    {
	        // create unique table
    	    $sql_table_unique = sprintf("CREATE TABLE IF NOT EXISTS `%s` (
        		`seq` int(11) NOT NULL AUTO_INCREMENT,
        		`trans_seq` int(11) NOT NULL,
        		`trans_user` int(11) NOT NULL DEFAULT '0',
        		`trans_invoiceid` int(11) NOT NULL DEFAULT '0',
        		`unique_payment_gateway` varchar(64) NOT NULL DEFAULT 'cekmutasi',
        		`unique_unit_name` enum('day','hour','minute') NOT NULL DEFAULT 'day',
        		`unique_unit_amount` smallint(4) NOT NULL,
        		`unique_label` tinytext NOT NULL,
        		`unique_amount` smallint(4) NOT NULL,
        		`unique_date` date DEFAULT NULL,
        		`unique_datetime` datetime DEFAULT NULL,
        		PRIMARY KEY (`seq`),
        		KEY `trans_seq` (`trans_seq`),
        		KEY `trans_seq_trans_user` (`trans_seq`,`trans_user`),
        		KEY `unique_datetime` (`unique_datetime`)
        		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        		CEKMUTASI_TABLE_TRANSACTION_UNIQUE
        	);
    	
    		if( full_query($sql_table_unique) === false ) {
    		    throw new Exception("Cannot create table `".CEKMUTASI_TABLE_TRANSACTION_UNIQUE."`");
    		}
	    }
	}
	catch (Exception $ex)
	{
		exit(__FILE__." (".$ex->getLine()."): ".$ex->getMessage());
	}

	return $configs;
}

/**
 * Payment link.
 *
 * Required by third party payment gateway modules only.
 *
 * Defines the HTML output displayed on an invoice. Typically consists of an
 * HTML form that will take the user to the payment gateway endpoint.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *
 * @return string
 */
function cekmutasi_link($params)
{
	$htmlPrint = "";
	$sql_where = array(
		'trans_seq'						=> (isset($params['invoicenum']) ? $params['invoicenum'] : 0),
		'trans_user'					=> (isset($params['clientdetails']['userid']) ? $params['clientdetails']['userid'] : 0),
		'trans_invoiceid'				=> $params['invoiceid'],
		'unique_payment_gateway'		=> $params['paymentmethod'],
	);

	try
	{
		$sql_result = select_query(CEKMUTASI_TABLE_TRANSACTION_UNIQUE, '*', $sql_where, 'unique_datetime', 'DESC', '0,1');
	}
	catch (Exception $ex)
	{
		throw $ex;
		return false;
	}

	$unique_data = $sql_result->fetch(\PDO::FETCH_ASSOC);

	if ( !isset($unique_data['seq']) )
	{
		$insert_unique_seq = cm_calculate_unique($params);

		if ( intval($insert_unique_seq) > 0 )
		{
			$sql_where = array("seq" => $insert_unique_seq);
			$sql_result = select_query(CEKMUTASI_TABLE_TRANSACTION_UNIQUE, '*', $sql_where);
			$unique_data = $sql_result->fetch(\PDO::FETCH_ASSOC);
		}
		else
		{
			$unique_data = false;
		}

		// Add Unique Data to Update Invoice - AS PG FEE
		if ($unique_data != false)
		{
			$unique_data_as_items = array(
				'invoiceid'				=> $params['invoiceid'],
				'userid'				=> $params['clientdetails']['userid'],
				'type'					=> (isset($params['cm_unique_label']) ? $params['cm_unique_label'] : 'Fee'),
				'relid'					=> '0', // relid?
				'description'			=> (isset($params['description']) ? $params['description'] : '-'),
				'amount'				=> $unique_data['unique_amount'],
				'taxed'					=> '0',
				'duedate'				=> isset($params['dueDate']) ? $params['dueDate'] : date('Y-m-d', (time()+(24*60*60))),
				'paymentmethod'			=> $params['paymentmethod'],
				'notes'					=> (isset($params['cm_unique_type']) ? $params['cm_unique_type'] : 'gateway_unique'),
			);

			$unique_data_as_items['description'] .= " " . (isset($params['cm_unique_label']) ? $params['cm_unique_label'] : 'Fee');

			if (strtolower($params['cm_unique_type']) == 'decrease')
			{
				$unique_data_as_items['amount'] = (int) -$unique_data_as_items['amount'];
			}

			insert_query("tblinvoiceitems", $unique_data_as_items);
			updateInvoiceTotal($params['invoiceid']);
		}
	}

	//================
	// Get InvoiceData
	try
	{
		$invoiceData = localAPI('GetInvoice', array('invoiceid' => $params['invoiceid']), $params['cm_local_api_admin_username']);
	}
	catch (Exception $ex)
	{
		throw $ex;
		exit("Cannot get invoices data.");
	}

	if (!isset($invoiceData['result'])) {
		exit("Invoice data not-found.");
	}

	//---- Build Config For Lib_cekmutasi
	$cekmutasi_configs = array();
	$cekmutasi_configs['cm_api_key'] = $params['cm_api_key'];
	$cekmutasi_configs['cm_api_signature'] = $params['cm_api_signature'];
	$cekmutasi_configs['cm_change_day'] = (int) $params['cm_change_day'];

    $htmlPrint = str_replace("\r\n", "\n", $params['cm_description']);
    $htmlPrint = str_replace("\n", "<br/>", $htmlPrint);

	return $htmlPrint;
}

function cm_calculate_unique($params)
{
	$cekmutasi_unique = new cekmutasi_unique($params);
	$insert_params = $cekmutasi_unique->cekmutasi_calculate_unique(CEKMUTASI_TABLE_TRANSACTION_UNIQUE, $params['cm_timezone']);
	try
	{
		$new_unique_seq = insert_query(CEKMUTASI_TABLE_TRANSACTION_UNIQUE, $insert_params);
	}
	catch (Exception $ex)
	{
		throw $ex;
		return false;
	}

	return $new_unique_seq;
}
/**
 * Refund transaction.
 *
 * Called when a refund is requested for a previously successful transaction.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/refunds/
 *
 * @return array Transaction response status
 */
function cekmutasi_refund($params)
{
    // Gateway Configuration Parameters
    $accountId = $params['accountID'];
    $secretKey = $params['secretKey'];
    $testMode = $params['testMode'];
    $dropdownField = $params['dropdownField'];
    $radioField = $params['radioField'];
    $textareaField = $params['textareaField'];

    // Transaction Parameters
    $transactionIdToRefund = $params['transid'];
    $refundAmount = $params['amount'];
    $currencyCode = $params['currency'];

    // Client Parameters
    $firstname = $params['clientdetails']['firstname'];
    $lastname = $params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $address1 = $params['clientdetails']['address1'];
    $address2 = $params['clientdetails']['address2'];
    $city = $params['clientdetails']['city'];
    $state = $params['clientdetails']['state'];
    $postcode = $params['clientdetails']['postcode'];
    $country = $params['clientdetails']['country'];
    $phone = $params['clientdetails']['phonenumber'];

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    // perform API call to initiate refund and interpret result

    return array(
        // 'success' if successful, otherwise 'declined', 'error' for failure
        'status' => 'success',
        // Data to be recorded in the gateway log - can be a string or array
        'rawdata' => $responseData,
        // Unique Transaction ID for the refund transaction
        'transid' => $refundTransactionId,
        // Optional fee amount for the fee value refunded
        'fees' => $feeAmount,
    );
}

/**
 * Cancel subscription.
 *
 * If the payment gateway creates subscriptions and stores the subscription
 * ID in tblhosting.subscriptionid, this function is called upon cancellation
 * or request by an admin user.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/subscription-management/
 *
 * @return array Transaction response status
 */
function cekmutasi_cancelSubscription($params)
{
    // Gateway Configuration Parameters
    $accountId = $params['accountID'];
    $secretKey = $params['secretKey'];
    $testMode = $params['testMode'];
    $dropdownField = $params['dropdownField'];
    $radioField = $params['radioField'];
    $textareaField = $params['textareaField'];

    // Subscription Parameters
    $subscriptionIdToCancel = $params['subscriptionID'];

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    // perform API call to cancel subscription and interpret result

    return array(
        // 'success' if successful, any other value for failure
        'status' => 'success',
        // Data to be recorded in the gateway log - can be a string or array
        'rawdata' => $responseData,
    );
}