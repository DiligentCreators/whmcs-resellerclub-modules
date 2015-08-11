<?php

/**
 * WHMCS Module for Resellerclub's VPS
 *
 * Some Functions names changed and added DCVPS in the end to avoid redeclaration
 * Credit DiligentCreators www.diligentcreators
 */
// AddOn Module Name
define('ADDON_MODULE_NAME', 'officialresellerclub');
include('hook.php');
// AddOn Module Path
$addon_module_file_path = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'addons' . DIRECTORY_SEPARATOR . ADDON_MODULE_NAME . DIRECTORY_SEPARATOR . ADDON_MODULE_NAME . '.php';
$addon_module_file_path = realpath($addon_module_file_path);

//Include AddOn Module if exists/ installed and get $resellerclub_config details
if (file_exists($addon_module_file_path)) {
    include_once( $addon_module_file_path );
    try {
        $resellerclub_config = _get_config_details_from_db();
    } catch (Exception $e) {
        $resellerclub_config = array();
        _display_error_block($e->getMessage());
    }
} else {
    _display_error_block("Please install Official ResellerClub Addon Module '" . ADDON_MODULE_NAME . "' before using product provisioning module.");
}

/**
 *  Initialize OrderboxAPI object
 */
global $orderbox;
$orderbox = new orderboxapi($resellerclub_config['resellerid'], $resellerclub_config['apikey'], $resellerclub_config['enable_log'], 'rc-whmcs-resellerclubvpslinux-us');

/**
 * WHMCS provisional module core functions
 */
function resellerclubvpsus_ConfigOptions() {

    global $orderbox;
    $vps_plan_names = array();
    $active_products = $orderbox->api('GET', '/products/category-keys-mapping.json', array(), $response, 'resellerclubvpslinuxus', 'configoptions');

    if (in_array('vpslinuxus', $active_products['hosting'])) {
        $plans = $orderbox->api('GET', '/products/plan-details.json', array(), $response, 'resellerclubvpslinuxus', 'configoptions');

        if (array_key_exists('vpslinuxus', $plans)) {
            $vps_plans = $plans['vpslinuxus'];
            foreach ($vps_plans as $plan_id => $plan) {
                $vps_plan_names[] = 'Linux ' . ' - ' . $plan['plan_name'] . ' - ' . $plan_id;
            }
        }

        $configarray = array(
            "Hosting Plan" => array("Type" => "dropdown", "Options" => implode(',', $vps_plan_names), "Description" => "Select a hosting plan to associate with this product"),
        );
    } else {
        $configarray = array(
            "Hosting Plan" => array("Description" => "No plans active for selected module"),
        );
    }

    return $configarray;
}

function resellerclubvpsus_CreateAccount($params) {
    global $orderbox;

    try {
        $client_details = $orderbox->api('GET', '/customers/details.json', array('username' => $params['clientsdetails']['email']), $response, 'resellerclubvpslinuxus', 'create');

        if (is_array($client_details) && strtolower($client_details['status']) == 'error') {
            $resellerclub_customer_id = _createCustomerDCVPS($params);
        } else {
            $resellerclub_customer_id = $client_details['customerid'];
        }

        //  add new order in resellerclub
        $plan_pieces = _get_plan_details($params['configoption1']);

        $billing_cycle = _get_order_billing_cycle($params['clientsdetails']['userid'], $params['serviceid'], $params['domain'], $params['pid']);
        $billing_cycle = strtolower($billing_cycle);
        $months = _get_order_duration_months($billing_cycle);

        $order_details = array('domain-name' => $params['domain'],
            'customer-id' => $resellerclub_customer_id,
            'months' => $months,
            'invoice-option' => 'NoInvoice',
            'plan-id' => $plan_pieces['id'],
        );

        if (count($params['configoptions']) >= 1) {

            foreach ($params['configoptions'] as $config_key => $config_value) {
                $config_key = trim(strtolower($config_key));

                if ($config_key == 'whmcs' && $config_value == 1) {
                    $order_details['addons'][] = 'whmcs';
                }

                if ($config_key == 'cpanel' && $config_value == 1) {
                    $order_details['addons'][] = 'cpanel';
                }

                if ($config_key == 'dedicated ip' && $config_value >= 1) {
                    $order_details['no-of-dedicated-ip'] = $config_value;
                }
            }
        }


        if ('windows' == $plan_pieces['type']) {
            $api_path_order_add = '/vps/windows/add.json';
        } else {
            $api_path_order_add = '/vps/linux/add.json';
        }

        $order_api_result = $orderbox->api('POST', $api_path_order_add, $order_details, $response, 'resellerclubvpslinuxus', 'create');

        if (is_array($order_api_result) && strtolower($order_api_result['status']) == 'error') {
            return $order_api_result['message'];
        }

        /**
         * Set cpanel auth details generated by WHMCS to blank to use the details set by Resellerclub
         */
        $sql_clear_cpanel_auth_details = "UPDATE tblhosting SET username = '', password = '' WHERE domain = '{$params['domain']}'";
        $res_clear_cpanel_auth_details = mysql_query($sql_clear_cpanel_auth_details);

        if ($res_clear_cpanel_auth_details === false) {
            $local_api_values = array(
                'serviceid' => $params['serviceid'],
                'serviceusername' => ' ',
                'servicepassword' => ' ',
            );
            $clear_cpanel_auth_details = localAPI('updateclientproduct', $local_api_values);
        }

        return 'success';
    } catch (Exception $e) {
        return "Customer sign up error - " . $e->getMessage();
    }
}

function resellerclubvpsus_SuspendAccount($params) {

    global $orderbox;

    try {
        $api_path = '/vps/linux/orderid.json';
        $order_id_result = $orderbox->api('GET', $api_path, array('domain-name' => $params['domain']), $response, 'resellerclubvpslinuxus', 'suspendaccount');

        if (is_array($order_id_result) && array_key_exists('status', $order_id_result) && strtolower($order_id_result['status']) == 'error') {
            return $order_id_result['message'];
        } else {
            $order_id = $order_id_result;

            $order_suspend_result = $orderbox->api('POST', '/orders/suspend.json', array('order-id' => $order_id, 'reason' => $params['suspendreason']), $response, 'resellerclubvpslinuxus', 'suspendaccount');

            if (is_array($order_suspend_result) && array_key_exists('status', $order_suspend_result)) {
                $status = strtolower($order_suspend_result['status']);
                if ($status == 'success') {
                    return 'success';
                } else {
                    return $order_suspend_result['message'];
                }
            }
        }
    } catch (Exception $e) {
        return "Order suspend error - " . $e->getMessage();
    }
}

function resellerclubvpsus_UnsuspendAccount($params) {

    global $orderbox;

    try {
        $api_path = '/vps/linux/orderid.json';
        $order_id_result = $orderbox->api('GET', $api_path, array('domain-name' => $params['domain']), $response, 'resellerclubvpslinuxus', 'unsuspendaccount');

        if (is_array($order_id_result) && array_key_exists('status', $order_id_result) && strtolower($order_id_result['status']) == 'error') {
            return $order_id_result['message'];
        } else {
            $order_id = $order_id_result;

            $order_unsuspend_result = $orderbox->api('POST', '/orders/unsuspend.json', array('order-id' => $order_id), $response, 'resellerclubvpslinuxus', 'unsuspendaccount');

            if (is_array($order_unsuspend_result) && array_key_exists('status', $order_unsuspend_result)) {
                $status = strtolower($order_unsuspend_result['status']);
                if ($status == 'success') {
                    return 'success';
                } else {
                    return $order_unsuspend_result['message'];
                }
            }
        }
    } catch (Exception $e) {
        return "Order unsuspend error - " . $e->getMessage();
    }
}

function resellerclubvpsus_TerminateAccount($params) {

    global $orderbox;

    try {

        $api_path = '/vps/linux/orderid.json';
        $order_id_result = $orderbox->api('GET', $api_path, array('domain-name' => $params['domain']), $response);

        if (is_array($order_id_result) && array_key_exists('status', $order_id_result) && strtolower($order_id_result['status']) == 'error') {
            return $order_id_result['message'];
        } else {
            $order_id = $order_id_result;

            $api_path = '/vps/linux/delete.json';
            $order_delete_result = $orderbox->api('POST', $api_path, array('order-id' => $order_id), $response);

            if (is_array($order_delete_result) && array_key_exists('status', $order_delete_result)) {
                $status = strtolower($order_delete_result['status']);
                if ($status == 'success') {
                    return 'success';
                } else {
                    return $order_delete_result['message'];
                }
            }
        }
    } catch (Exception $e) {
        return "Order terminate error - " . $e->getMessage();
    }
}

function resellerclubvpsus_Renew($params) {

    global $orderbox;

    try {
        $api_path = '/vps/linux/orderid.json';
        $order_id_result = $orderbox->api('GET', $api_path, array('domain-name' => $params['domain']), $response, 'resellerclubvpslinuxus', 'renew');

        if (is_array($order_id_result) && array_key_exists('status', $order_id_result) && strtolower($order_id_result['status']) == 'error') {
            return $order_id_result['message'];
        } else {
            $order_id = $order_id_result;

            $api_path = '/vps/linux/renew.json';

            $billing_cycle = _get_order_billing_cycle($params['clientsdetails']['userid'], $params['serviceid'], $params['domain'], $params['pid']);
            $billing_cycle = strtolower($billing_cycle);
            $months = _get_order_duration_months($billing_cycle);

            $order_details = array('order-id' => $order_id,
                'months' => $months,
                'invoice-option' => 'NoInvoice'
            );

            $order_api_result = $orderbox->api('POST', $api_path, $order_details, $response, 'resellerclubvpslinuxus', 'renew');

            if (is_array($order_api_result) && strtolower($order_api_result['status']) == 'error') {
                return $order_api_result['message'];
            }

            return 'success';
        }
    } catch (Exception $e) {
        return "Order renew error - " . $e->getMessage();
    }
}

function resellerclubvpsus_manualrenew($params) {

    //Renew the order
    $renew_result = resellerclubvpsus_Renew($params);

    if ($renew_result == "success") {
        // Get order details
        $client_order_details = _get_order_detailsDCVPSDCVPS($params['clientsdetails']['userid'], $params['serviceid'], $params['domain'], $params['pid']);

        // Current Due Date of order
        $curr_duedate = $client_order_details['products']['product'][0]['nextduedate'];
        $curr_duedate_time = strtotime($curr_duedate);

        // Current Duration of order
        $billing_cycle = $client_order_details['products']['product'][0]['billingcycle'];
        $billing_cycle = strtolower($billing_cycle);
        $months = _get_order_duration_months($billing_cycle);

        // Calculate new due date
        $new_duedate = date('Y-m-d', strtotime("+{$months} months", $curr_duedate_time));

        // Update nextduedate and nextinvoicedate in database
        $update_duedate_sql = "UPDATE tblhosting SET nextduedate = '{$new_duedate}', nextinvoicedate = '{$new_duedate}' WHERE id = " . $params['serviceid'];
        $update_duedate_res = mysql_query($update_duedate_sql);
        if ($update_duedate_res == false) {
            return "Order manual renew error - Cannot update due date : " . mysql_error();
        }

        // Create invoice for the Manual Renew
        $local_api_values = array(
            'userid' => $params['clientsdetails']['userid'],
            'date' => date('Ymd'),
            'duedate' => date('Ymd'),
            'paymentmethod' => $client_order_details['products']['product'][0]['paymentmethod'],
            'sendinvoice' => true,
            'itemdescription1' => "Manual Renew - {$client_order_details['products']['product'][0]['name']} - {$client_order_details['products']['product'][0]['domain']}",
            'itemamount1' => $client_order_details['products']['product'][0]['recurringamount'],
            'itemtaxed1' => 0,
            'note' => 'This invoice is for manual renewal of the order.'
        );

        $client_orders = localAPI('createinvoice', $local_api_values);
    }

    return $renew_result;
}

function resellerclubvpsus_ChangePackage($params) {

    global $orderbox;

    try {

        $api_path = '/vps/linux/orderid.json';
        $order_id_result = $orderbox->api('GET', $api_path, array('domain-name' => $params['domain']), $response, 'resellerclubvpslinuxus', 'changepackage');

        if (is_array($order_id_result) && array_key_exists('status', $order_id_result) && strtolower($order_id_result['status']) == 'error') {
            return $order_id_result['message'];
        } else {
            $order_id = $order_id_result;

            $plan_pieces = _get_plan_details($params['configoption1']);

            $billing_cycle = _get_order_billing_cycle($params['clientsdetails']['userid'], $params['serviceid'], $params['domain'], $params['pid']);
            $billing_cycle = strtolower($billing_cycle);
            $months = _get_order_duration_months($billing_cycle);

            $order_details = array('order-id' => $order_id,
                'new-plan-id' => $plan_pieces['id'],
                'months' => $months,
            );

            $api_path = '/vps/linux/modify-pricing.json';
            $order_api_result = $orderbox->api('GET', $api_path, $order_details, $response, 'resellerclubvpslinuxus', 'renew');

            if (is_array($order_api_result) && strtolower($order_api_result['status']) == 'error') {
                return $order_api_result['message'];
            }
            return 'success';
        }
    } catch (Exception $e) {
        return "Order Upgrade/Downgrade error - " . $e->getMessage();
    }
}

function resellerclubvpsus_ClientAreaCustomButtonArray() {
    
}

function resellerclubvpsus_AdminCustomButtonArray() {
    return array("Execute Manual Renew" => "manualrenew");
}

function resellerclubvpsus_ClientArea($params) {

    if (isset($_POST['cplogin']) && strlen(trim($_POST['cplogin'])) > 0) {
        $cplogin_action = strtolower(trim($_POST['cplogin']));
        switch ($cplogin_action) {
            case 'cpanel': _redirect_to_webhosting_control_panelDCVPS($params);
                break;
            
        }
    }

    global $smarty;
    global $orderbox;

    try {
        $is_processing = false;
        $api_path = '/vps/linux/orderid.json';
        $order_id_result = $orderbox->api('GET', $api_path, array('domain-name' => $params['domain']), $response, 'resellerclubvpslinuxus', 'clientarea');


        if (is_array($order_id_result) && array_key_exists('status', $order_id_result) && strtolower($order_id_result['status']) == 'error') {
            $is_processing = true;
        } else {
            $order_id = $order_id_result;
            $api_path = '/vps/linux/details.json';
            $order_details = $orderbox->api('GET', $api_path, array('order-id' => $order_id), $response, 'resellerclubvpslinuxus', 'clientarea');

            if (is_array($order_details) && array_key_exists('status', $order_details)) {
                $status = strtolower($order_details['status']);
                if ($status != 'success') {
                    $is_processing = true;
                }
            }
        }

        $smarty->assign('is_processing', $is_processing);

        if ($is_processing) {
            $smarty->assign('vps_status', 'Processing...');
        } else {
//            if ('plesk' == $order_details['panel']) {
//                $cp_url = 'http://' . $order_details['ipaddress'] . ':8880';
//            } else {
//                $cp_url = 'http://' . $order_details['ipaddress'] . '/cpanel';
//            }
            $ssl_ip_address = "";
            foreach ($order_details['addon_details'] as $addon_detail) {
                if ('whmcs' == $addon_detail['name']) {
                    $whmcs_licence_key = $addon_detail['licenseKey'];
                    $smarty->assign('is_whmcs_enabled', true);
                }
                if ('ssl' == $addon_detail['name']) {
                    $ssl_ip_address.=$addon_detail['ipAddress'].', ';
                    $smarty->assign('is_ssl_enabled', true);
                }
                if ('cpanel' == $addon_detail['name']) {
                    $cp_login_url.=$addon_detail['loginURL'];
                    $cp_username.=$addon_detail['username'];
                    $smarty->assign('is_cpanel_enabled', true);
                }
                if ('virtuozzo' == $addon_detail['name']) {
                    $virtuozzo_login_url.=$addon_detail['loginURL'];
                    $virtuozzo_username.=$addon_detail['username'];
                }
            }

            $smarty->assign('vps_status', $order_details['currentstatus']);
            $smarty->assign('vps_webhosting_panel', _display_webhosting_panel_formDCVPS());
            
            $smarty->assign('vps_whmcs_key', $whmcs_licence_key);
            $smarty->assign('vps_ssl_ip_address', rtrim($ssl_ip_address,', '));
            
            $smarty->assign('vps_virtuozzo_url', $virtuozzo_login_url);
            $smarty->assign('vps_virtuozzo_username', $virtuozzo_username);
            
            $smarty->assign('vps_cp_url', $cp_login_url);
            $smarty->assign('vps_cp_username', $cp_username);
            $smarty->assign('vps_cp_password', $order_details['siteadminpassword']);
            

            $smarty->assign('vps_diskspace', $order_details['space'] == '-1' ? 'Unlimited' : $order_details['space'] );
            $smarty->assign('vps_bandwidth', $order_details['bandwidth'] == '-1' ? 'Unlimited' : $order_details['bandwidth'] );
            $smarty->assign('vps_cpu', $order_details['cpu'] );
            $smarty->assign('vps_ram', $order_details['ram'] );
            
        }
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function resellerclubvpsus_LoginLink($params) {
    echo "<strong>Do Not Modify</strong>" . _display_control_panel_linkDCVPS($params);
}

/**
 *  Make Orderbox API Calls
 *  Functions name _createCustomer changed to _createCustomerDCVPS
 *  Credit DiligentCreators www.diligentcreators
 */
function _createCustomerDCVPS($params) {
    global $orderbox;
    $customer_password = 'qwe' . rand(5000, 10000) . 'dsa';
    //TODO :: Set phone country code (phone-cc) appropriately
    $customer_details = array(
        'username' => $params['clientsdetails']['email'],
        'passwd' => $customer_password,
        'name' => $params['clientsdetails']['firstname'] . ' ' . $params['clientsdetails']['lastname'],
        'company' => strlen(trim($params['clientsdetails']['companyname'])) ? $params['clientsdetails']['companyname'] : '-',
        'address-line-1' => $params['clientsdetails']['address1'],
        'address-line-2' => $params['clientsdetails']['address2'],
        'address-line-3' => '',
        'city' => $params['clientsdetails']['city'],
        'state' => $params['clientsdetails']['state'],
        'country' => $params['clientsdetails']['country'],
        'zipcode' => $params['clientsdetails']['postcode'],
        'phone-cc' => '1', //phonenumber - country code
        'phone' => $params['clientsdetails']['phonenumber'],
        'lang-pref' => 'en'
    );

    $create_customer_result = $orderbox->api('POST', '/customers/signup.json', $customer_details, $response_headers);

    if (is_array($create_customer_result) && strtolower($create_customer_result['status']) == 'error') {
        throw new Exception($create_customer_result['message']);
    }
    return $create_customer_result;
}

if( !function_exists('_get_control_panel_link') ) {
    function _get_control_panel_link( $params ) {

    global $orderbox;
    $error = '';

    $client_details = $orderbox->api( 'GET' , '/customers/details.json' , array( 'username' => $params['clientsdetails']['email'] ) , $response , 'resellerclubvpslinuxus' , 'clientarea' );
    if( is_array($client_details) && strtolower( $client_details['status'] ) == 'error' ) {
        $error = "Customer ({$params['clientsdetails']['email']}) not found at Resellerclub";
    } else {
        $customer_id = $client_details['customerid'];
        $customer_temp_password = $orderbox->api( 'GET' , '/customers/temp-password.json' , array( 'customer-id' => $customer_id ) , $response , 'resellerclubvpslinuxus' , 'clientarea' );
        $resellerclub_customer_password = $customer_temp_password;
    }

    if( $error == '' ) {
        // get orderid from resellerclub
        $plan_pieces = _get_plan_details( $params['configoption1']);
        if( 'windows' == $plan_pieces['type'] ) {
            $api_path_orderid_from_domain = '/vps/windows/orderid.json';
        } else {
            $api_path_orderid_from_domain = '/vps/linux/orderid.json';
        }
        $order_id_result = $orderbox->api( 'GET' , $api_path_orderid_from_domain , array( 'domain-name' => $params['domain'] ) , $response , 'resellerclubvpslinuxus' , 'clientarea' );

        if( is_array( $order_id_result ) && array_key_exists( 'status', $order_id_result ) && strtolower( $order_id_result['status'] ) == 'error'  ) {
            $error = "Hosting order ({$params['domain']}) not found at Resellerclub";
        } else {
            $order_id = $order_id_result;
        }
    }

     if( $error == '' ) {
        // get reseller branded url
        $reseller_details = $orderbox->api( 'GET' , '/resellers/details.json' , array() , $response , 'resellerclubvpslinuxus' , 'clientarea' );
        if( is_array($reseller_details) && strtolower( $reseller_details['status'] ) == 'error' ) {
            $error = "Reseller not found at Resellerclub";
        } else {
            $reseller_branding_url = $reseller_details['brandingurl'];
        }
    }

    if( $error == '' ) {
        // generate authentication token
        $ip = $_SERVER['REMOTE_ADDR'];

        $authentication_token_result = $orderbox->api( 'GET' , '/customers/generate-token.json' , array( 'username' => $params['clientsdetails']['email'] , 'passwd' => $resellerclub_customer_password , 'ip' => $ip ) , $response , 'resellerclubvpslinuxus' , 'clientarea');

        if( is_array( $authentication_token_result ) && array_key_exists( 'status', $authentication_token_result ) && strtolower( $authentication_token_result['status'] ) == 'error' ) {
            $authentication_token = '';
        } else {
            $authentication_token = $authentication_token_result;
        }
        return $control_panel_url = "http://" . $reseller_branding_url . "/servlet/ManageServiceServletForAPI?auth-token={$authentication_token}&orderid={$order_id}";
    } else {
        throw new Exception( $error );
    }
}
}

/**
 * Helper functions
 */
if (!function_exists('_get_plan_details')) {

    function _get_plan_details($plan_name) {

        $plan_name_pieces = explode('-', $plan_name);
        $plan_details['type'] = trim(strtolower(array_shift($plan_name_pieces)));
        $plan_details['id'] = trim(array_pop($plan_name_pieces));
        $plan_details['name'] = trim(implode('-', $plan_name_pieces));
        return $plan_details;
    }

}

/**
 *  Functions name _get_order_detailsDCVPS changed to _get_order_detailsDCVPSDCVPS
 *  Credit DiligentCreators www.diligentcreators
 *
 */

function _get_order_detailsDCVPSDCVPS($user_id, $service_id, $domain, $product_id) {
    $local_api_values = array(
        'clientid' => $user_id,
        'serviceid' => $service_id,
        'domain' => $domain,
        'pid' => $product_id
    );
    $client_order_details = localAPI('getclientsproducts', $local_api_values);

    return $client_order_details;
}

if (!function_exists('_get_order_billing_cycle')) {

    function _get_order_billing_cycle($user_id, $service_id, $domain, $product_id) {
        $client_order_details = _get_order_detailsDCVPS($user_id, $service_id, $domain, $product_id);
        $billing_cycle = $client_order_details['products']['product'][0]['billingcycle'];
        return $billing_cycle;
    }

}
if (!function_exists('_get_order_duration_months')) {

    function _get_order_duration_months($billing_cycle) {
        switch ($billing_cycle) {
            case 'quarterly': $months = 3;
                break;
            case 'semi-annually': $months = 6;
                break;
            case 'annually': $months = 12;
                break;
            case 'biennially': $months = 24;
                break;
            case 'triennially': $months = 36;
                break;
            default: $months = 1;
                break;
        }
        return $months;
    }

}

/**
 *  Functions name _display_control_panel_link changed to _display_control_panel_linkDCVPS
 *  Credit DiligentCreators www.diligentcreators
 */
function _display_control_panel_linkDCVPS($params) {

    try {
        $control_panel_url = _get_control_panel_link($params);
        return "<input type='button' name='custom_control_panel_login' value='Login to control panel' onclick='javascript:window.open(\"{$control_panel_url}\")' />";
    } catch (Exception $e) {
        $error_message = $e->getMessage();
        return "<input type='button' name='custom_control_panel_login' value='Login to control panel' onclick='javascript:alert(\"{$error_message}\");' />";
    }
}

/**
 *  Functions name _redirect_to_webhosting_control_panel changed to _redirect_to_webhosting_control_panelDCVPS
 *  Credit DiligentCreators www.diligentcreators
 */
function _redirect_to_webhosting_control_panelDCVPS($params) {
    $control_panel_url = _get_control_panel_link($params) . '&service-name=webhosting';
    header("location: " . $control_panel_url);
    exit;
}

/**
 *  Functions name _display_webhosting_panel_form changed to _display_webhosting_panel_formDCVPS
 *  Credit DiligentCreators www.diligentcreators
 */
function _display_webhosting_panel_formDCVPS() {
    $form_action_url = $_SERVER['REQUEST_URI'];
    $id = isset($_GET['id']) ? $_GET['id'] : ( isset($_POST['id']) ? $_POST['id'] : '' );
    $cp_form = "<form method=\"post\" action=\"{$form_action_url}\" target=\"_blank\">";
    $cp_form .= "<input type=\"hidden\" name=\"id\" value=\"" . $id . "\">";
    $cp_form .= "<input type=\"hidden\" name=\"cplogin\" value=\"cpanel\">";
    $cp_form .= "<input type=\"submit\" name=\"btn_cplogin\" value=\"Cpanel Login\">";
    $cp_form .= "</form>";
    return $cp_form;
}


?>
