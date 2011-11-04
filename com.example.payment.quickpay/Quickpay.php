<?php

require_once 'CRM/Core/Payment.php';
require_once 'quickpay/quickpay.php';

class com_example_payment_quickpay extends CRM_Core_Payment {
  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = null;

  /**
   * mode of operation: live or test
   *
   * @var object
   * @static
   */
  static protected $_mode = null;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct( $mode, &$paymentProcessor ) {
    $this->_mode             = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName    = ts('Quickpay');
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return object
   * @static
   *
   */
  static function &singleton( $mode, &$paymentProcessor ) {
      $processorName = $paymentProcessor['name'];
      if (self::$_singleton[$processorName] === null ) {
          self::$_singleton[$processorName] = new com_example_payment_quickpay ( $mode, $paymentProcessor );
      }
      return self::$_singleton[$processorName];
  }

  /**
   * This function checks to see if we have the right config values
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig( ) {
    $config = CRM_Core_Config::singleton();

    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "Bill To ID" is not set in the Administer CiviCRM Payment Processor.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  function doDirectPayment(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /**
   * Sets appropriate parameters for checking out to UCM Payment Collection
   *
   * @param array $params  name value pair of contribution datat
   *
   * @return void
   * @access public
   *
   */
  function doTransferCheckout( &$params, $component ) {

        $config =& CRM_Core_Config::singleton( );

        $component = strtolower( $component );
        $notifyURL = $config->userFrameworkResourceURL . "extern/QuickpayNotify.php";
        $submitURL = rtrim( $this->_paymentProcessor['url_site'], '/' ) . '/form/';
		$order_prefix = $this->_paymentProcessor['signature'];
		$quickpay_params = array('protocol' => QUICKPAY_VERSION,
			'msgtype' => 'authorize', // Subscription not supported
          	        'merchant' => $this->_paymentProcessor['user_name'], // Merchant ID
			'testmode' => ( $this->_mode == 'test' || QUICKPAY_ALWAYS_TEST ) ? '1' : '0',
			'callbackurl' => $notifyURL,
			'language' => substr($config->lcMessages, 0, 2),
			'autocapture' => '1', // Nothing is shippable, so always autocapture
			'ordernumber' => sprintf("%s%05d", $order_prefix, $params['contributionID']),
		);
		list($quickpay_params['amount'],
			$quickpay_params['currency']) = quickpay_convert_amount(
							$params['amount'], $params['currencyID']);
        $md5_secret = $this->_paymentProcessor['password']; // Merchant Key
	$custom_vars = array();
        
        if ( $component == "event" ) {
			quickpay_copy_keys(array(
				'contactID',
				'contributionID',
				'contributionTypeID',
				'eventID',
				'participantID',
				'invoiceID'), $params, $custom_vars);
        } elseif ( $component == "contribute" ) {
	    quickpay_copy_keys(array(
			'contactID',
			'contributionID',
			'contributionTypeID',
			'invoiceID'), $params, $custom_vars);
            $membershipID = CRM_Utils_Array::value( 'membershipID', $params );
            if ( $membershipID ) {
				$custom_vars['membershipID'] = $membershipID;
            }
            $relatedContactID = CRM_Utils_Array::value( 'related_contact', $params );
            if ( $relatedContactID ) {
				$custom_vars['relatedContactID'] = $relatedContactID;
                $onBehalfDupeAlert = CRM_Utils_Array::value( 'onbehalf_dupe_alert', $params );
                if ( $onBehalfDupeAlert ) {
					$custom_vars['onBehalfDupeAlert'] = $onBehalfDupeAlert;
                }
            }
        }

	quickpay_copy_keys(NULL, $custom_vars, $quickpay_params, 'CUSTOM_');
        
        // Allow further manipulation of the arguments via custom hooks ..
        CRM_Utils_Hook::alterPaymentProcessorParams( $this, $params, $quickpay_params );
        $url = ( $component == 'event' ) ? 'civicrm/event/register' : 'civicrm/contribute/transact';
        $cancel = ( $component == 'event' ) ? '_qf_Register_display' : '_qf_Main_display';
        $returnURL = CRM_Utils_System::url(  'civicrm/event/register', //$url,
                                            "_qf_ThankYou_display=1&qfKey={$params['qfKey']}",
                                            true, null, false );
        $cancelURL = CRM_Utils_System::url( $url,
                                            "$cancel=1&cancel=1&qfKey={$params['qfKey']}",
                                            true, null, false );
		$quickpay_params['continueurl'] = $returnURL;
		$quickpay_params['cancelurl'] = $cancelURL;
		$quickpay_params['md5check'] = quickpay_sign($quickpay_params, $md5_secret);
		if(!quickpay_validate_data($quickpay_params))
			CRM_Core_Error::fatal( ts( 'Field validation error' ) );
		$this->output_redirect($quickpay_params, $submitURL);
        exit( );
    }
	/**
	 * output_redirect: Output form + javascript to redirect user.
	 */
	function output_redirect($data, $url)
	{
		$fields = "";
		foreach($data as $key=>$value) $fields .= $this->form_field($key, $value);
		echo '<html><head><title>Redirecting to Quickpay...</title></head>
			<body>
			<h1>Redirecting to Quickpay...</h1>
			<p>Click "Continue" to continue to Quickpay if you are not automatically redirected...</p>
			<form action="'.$url.'" method="post" id="quickpay_form"
				onsubmit="document.getElementById(\'cont_btn\').disabled = \'disabled\'">
			'.$fields.'
			<input type="submit" value="Continue" id="cont_btn" />
			</form>
			<script language="javascript">
				document.getElementById("quickpay_form").submit();
			</script>
			</body>
			</html>';
	}
	/**
	 * form_field: Create a hidden form field for a key/value pair.
	 */
	function form_field($key, $value)
	{
		return '<input type="hidden" name="'.$key.'" value="'.$value.'" />'."\n";
	}
}
