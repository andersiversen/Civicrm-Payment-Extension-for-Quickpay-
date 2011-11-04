<?php
/*
 +--------------------------------------------------------------------+
 | Quickpay CiviCRM Payment module
 +--------------------------------------------------------------------+
 | This file is based upon the Quickpay Drupal module, which is 
 | licensed under the GNU GPL (http://www.gnu.org/licenses/gpl.html).
 |
 | Authored by Toke Høiland-Jørgensen (toke@toke.dk), 2010
 +--------------------------------------------------------------------+
*/


require_once("CRM/Core/Error.php");


define('QUICKPAY_VERSION', '3');
if(!defined('QUICKPAY_ALWAYS_TEST')) {
	// If already defined in the quickpay module, do not redefine.
	define('QUICKPAY_ALWAYS_TEST', false);
}


function quickpay_check_signature($fields, $secret) {
    static $md5_order = array(
        'msgtype',
        'ordernumber',
        'amount',
        'currency',
        'time',
        'state',
        'qpstat',
        'qpstatmsg',
        'chstat',
        'chstatmsg',
        'merchant',
        'merchantemail',
        'transaction',
        'cardtype',
        'cardnumber',
    );

    $md5_string = "";
    foreach ($md5_order as $field) {
		if(array_key_exists($field, $fields)) {
			$md5_string .= $fields[$field];
		}
    }
    return (md5($md5_string . $secret) == $fields['md5check']);
}

function quickpay_sign($fields, $secret) {
    $md5_order = array(
        'protocol',
        'msgtype',
        'merchant',
        'language',
        'ordernumber',
        'amount',
        'currency',
        'continueurl',
        'cancelurl',
        'callbackurl',
        'autocapture',
        'cardtypelock',
        'description',
        'ipaddress',
        'testmode',
    );

    // Check that it validates.
    $md5_string = "";
    foreach ($md5_order as $field) {
		if(array_key_exists($field, $fields)) {
			$md5_string .= $fields[$field];
		}
    }
    return md5($md5_string . $secret);
}

/**
 * Validates that the request fields is formatted as expected by QuickPay.
 * @param $data Associative array of params.
 * @returns boolean TRUE if the data is valid.
 */
function quickpay_validate_data($data) {
    static $fields =
        array(
            'protocol' => '/^3$/',
            'msgtype' => '/^(authorize|subscribe)$/',
            'merchant' => '/^[0-9]{8}$/',
            'language' => '/^[a-z]{2}$/',
            'ordernumber' => '/^[a-zA-Z0-9]{4,20}$/',
            'amount' => '/^[0-9]{1,9}$/',
            'continueurl' => '!^https?://!',
            'cancelurl' => '!^https?://!',
            'callbackurl' => '!^https?://!',
            'currency' => '/^[A-Z]{3}$/',
            'autocapture' => '/^(0|1)$/',
            'testmode' => '/^(0|1)$/',
            'cardnumber' => '/^[0-9]{13,19}$/',
            'expirationdate' => '/^[0-9]{4}$/',
            'cvd' => '/^[0-9]{0,4}$/',
            'cardtypelock' => '/^[a-zA-Z,]{0,128}$/',
            'transaction' => '/^[0-9]{1,32}$/',
            'description' => '/^[\w _\-\.]{0,20}$/',
            'md5check' => '/^[a-z0-9]{32}$/',
            'CUSTOM_' => '/^[\w _\-\.]{0,255}$/'
        );

    foreach ($data as $field => $value) {
        if (is_null($value)) {
            return FALSE;
        } elseif (array_key_exists($field, $fields)) {
            if (!preg_match($fields[$field], $value)) {
                return FALSE;
            }
        } elseif (preg_match('/^CUSTOM_/', $field)) {
            if (!preg_match($fields['CUSTOM_'], $value)) {
                return FALSE;
            }
        } else {
            return FALSE;
        }
    }
    return TRUE;
}

/**
 * quickpay_copy_keys: Copy keys from one array to another
 * with an optional prefix.
 */
function quickpay_copy_keys($keys, $from, &$to, $prefix = '')
{
    if($keys === NULL) {
        $keys = array_keys($from);
    }

    foreach($keys as $key) {
        $to_key = ($prefix != '') ? $prefix.$key : $key;
        $to[$to_key] = $from[$key];
    }
}

/**
 * quickpay_extract_custom_fields: Extract custom fields from an array
 * and remove the CUSTOM_ prefix.
 */
function quickpay_extract_custom_fields($data)
{
    $fields = array();
    foreach($data as $key => $value) {
        if(substr($key, 0, 7) == 'CUSTOM_') {
            $fields[substr($key,7)] = $value;
        }
    }
    return $fields;
}

/**
 * quickpay_check_result: Translate return codes to a status.
 */
function quickpay_check_result($code) {
    if ($code === FALSE) {
        return 'error';
    }
    switch ($code) {
    case '000': // Accepted
        return 'success';
        break;
    case '001': // Rejected
    case '003': // Expired
    case '008': // Bad parameters sent to quickpay (could be user error)
        // Handled as failed.
        return 'failed';
        break;
    case '002': // Communication error
    case '004': // Wrong status (not authorized)
    case '005': // Authorization expired
    case '006': // Error at PBS
    case '007': // Error at QuickPay
        // All these are handled as internal error.
        return 'error';
    default:
        return 'unknown';
    }
}

/**
 * quickpay_currency_codes: Currency codes and their multiplier.
 */
function quickpay_currency_codes() {
    static $currencies = array('DKK' => 100, 'USD' => 100, 'EUR' => 100,
        'GBP' => 100);
    return $currencies;
}

/**
 * quickpay_currency_multiplier: Get a multiplier for a currency
 * code (usually *100).
 */
function quickpay_currency_multiplier($currency) {
    $currencies = quickpay_currency_codes();
    return CRM_Utils_Array::value($currency, $currencies, 100);
}

function quickpay_convert_amount($amount, $currency) {
    if (!$currency) {
		CRM_Core_Error::fatal(ts('Missing currency code'));
	}
    $multiplyer = quickpay_currency_multiplier($currency);
    if (!$multiplyer)
        return array(FALSE, FALSE);
    return array((function_exists('bcmul') ?
        bcmul($amount, $multiplyer, 0) :
        $amount * $multiplyer), $currency);
}
