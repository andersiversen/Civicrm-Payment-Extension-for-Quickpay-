<?php

/**
 +--------------------------------------------------------------------+
 | Quickpay CiviCRM Payment module
 +--------------------------------------------------------------------+
 | This file is based upon the Google payment processor, which is part
 | of CiviCRM and licensed under the GNU AGPL 
 | (http://www.gnu.org/licenses/agpl.html).
 |
 | Authored by Toke Høiland-Jørgensen (toke@toke.dk), 2010
 +--------------------------------------------------------------------+
*/

session_start( );

require_once 'civicrm.config.php';
require_once 'CRM/Core/Config.php';

$config = CRM_Core_Config::singleton();

require_once 'CRM/Core/Extensions/Extension.php';
$ext = new CRM_Core_Extensions_Extension( 'com.example.payment.quickpay' );
if ( !empty( $ext->path ) ) {
    // I think there's an error in civicrm 4.0's CRM/Core/Extensions/Extension.php line 72
    // $this->path = $config->extensionsDir . DIRECTORY_SEPARATOR . $key . DIRECTORY_SEPARATOR;
    // should be
    // $this->path = $config->extensionsDir . $key . DIRECTORY_SEPARATOR;
    // no mather - this file does get called by Quipay, but it fails heright here...
    require_once $ext->path . 'QuickpayIPN.php';
}

require_once 'QuickpayIPN.php';
$rawPostData = file_get_contents( 'php://input' );
com_example_payment_QuickpayIPN::main( $rawPostData, $_POST);
